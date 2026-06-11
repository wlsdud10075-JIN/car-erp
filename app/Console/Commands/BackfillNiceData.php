<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\NiceApiService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * NICE 미조회 차량 일괄 백필 (2026-06-11).
 *
 * 배경: vehicles:import(엑셀 일괄)는 마스터+재무만 적재하고 NICE 조회를 하지 않아
 *   nice_raw·제원(형식·차종·배기량·기통·길이/너비/높이 등)이 비어 통관 서류가 공란으로 나옴.
 *   소유자명(nice_reg_owner_name)은 엑셀에서 적재되므로 NICE 입력(차량번호+소유자명)이 갖춰져 있음
 *   → 일괄 조회로 빈 제원을 채운다. (NICE 건당 과금 — 차량 1대=1콜)
 *
 * 매핑은 UI(lookupNiceApi)와 동일 — NiceApiService::transform 이 돌려주는 registration/spec 키가
 *   곧 Vehicle 컬럼명. 기본은 **빈 칸만 채움**(import 의 소유자/주행거리/연식/브랜드 보존), --overwrite 시 전부.
 *   nice_raw 는 항상 저장(성공 시) → 멱등: 재실행은 nice_raw 없는 실패분만 다시 시도.
 *
 *   php artisan vehicles:nice-backfill --dry-run        # 대상 수만 (무과금)
 *   php artisan vehicles:nice-backfill --limit=3        # 처음 3대만 (테스트, 3콜)
 *   php artisan vehicles:nice-backfill                  # 전체 (nice_raw 없는 차량)
 */
class BackfillNiceData extends Command
{
    protected $signature = 'vehicles:nice-backfill
        {--dry-run : API 호출 없이 대상 차량 수만 리포트}
        {--limit= : 처음 N대만 처리 (테스트)}
        {--ids= : 특정 차량 ID만 (콤마구분, 테스트)}
        {--overwrite : 기존 값도 덮어쓰기 (기본=빈 칸만 채움)}
        {--sleep=0 : 콜 간 대기(ms) — NICE 부하 완화}';

    protected $description = 'NICE 미조회 차량(엑셀 import 등)을 일괄 NICE 조회해 제원/등록정보 채움 (건당 과금)';

    public function handle(): int
    {
        $overwrite = (bool) $this->option('overwrite');

        $q = Vehicle::query()
            ->whereNotNull('vehicle_number')->where('vehicle_number', '!=', '')
            ->whereNotNull('nice_reg_owner_name')->where('nice_reg_owner_name', '!=', '');
        if (! $overwrite) {
            $q->whereNull('nice_raw');   // 멱등: 이미 NICE 채워진 차량 제외
        }
        if ($ids = $this->option('ids')) {
            $q->whereIn('id', array_filter(array_map('trim', explode(',', $ids))));
        }
        $q->orderBy('id');
        if ($limit = $this->option('limit')) {
            $q->limit((int) $limit);
        }
        $vehicles = $q->get();

        // 소유자명 없어 조회 불가한 차량(안내용)
        $noOwner = Vehicle::query()->whereNull('nice_raw')
            ->where(fn ($w) => $w->whereNull('nice_reg_owner_name')->orWhere('nice_reg_owner_name', ''))
            ->count();

        $this->info("대상 {$vehicles->count()}대".($overwrite ? ' [overwrite]' : ' [빈 칸만]')." / 소유자명 없어 제외 {$noOwner}대");

        if ($this->option('dry-run')) {
            foreach ($vehicles->take(10) as $v) {
                $this->line("  · #{$v->id} {$v->vehicle_number} / {$v->nice_reg_owner_name}");
            }
            if ($vehicles->count() > 10) {
                $this->line('  … 외 '.($vehicles->count() - 10).'대');
            }
            $this->warn('--dry-run — API 호출/저장 없음 (비용 0)');

            return self::SUCCESS;
        }

        $svc = NiceApiService::fromConfig();
        $sleep = (int) $this->option('sleep');
        $ok = 0;
        $fail = 0;
        $failList = [];

        $isEmpty = fn ($v): bool => $v === null || $v === '' || (is_numeric($v) && (float) $v === 0.0);

        foreach ($vehicles as $v) {
            $result = $svc->lookupVehicle((string) $v->vehicle_number, (string) $v->nice_reg_owner_name);

            if ($result === null) {
                $this->error('NICE 엔드포인트 미설정(.env NICE_PROVIDE_URL/TOKEN) — 중단.');

                return self::FAILURE;
            }
            if (($result['success'] ?? false) !== true) {
                $fail++;
                $msg = $result['message'] ?? '실패';
                $failList[] = "#{$v->id} {$v->vehicle_number} ({$v->nice_reg_owner_name}): {$msg}";
                $this->line("  ✗ #{$v->id} {$v->vehicle_number} — {$msg}");
                if ($sleep > 0) {
                    usleep($sleep * 1000);
                }

                continue;
            }

            $fields = array_merge($result['registration'] ?? [], $result['spec'] ?? []);
            $fillable = $v->getFillable();
            $applied = 0;
            Model::withoutEvents(function () use ($v, $fields, $fillable, $result, $overwrite, $isEmpty, &$applied) {
                foreach ($fields as $key => $val) {
                    if (! in_array($key, $fillable, true)) {
                        continue;   // 미지 키 방어
                    }
                    if (! $overwrite && ! $isEmpty($v->$key)) {
                        continue;   // 빈 칸만 — import 값 보존
                    }
                    $v->$key = $val;
                    $applied++;
                }
                $v->nice_raw = $result['raw'] ?? [];
                $v->save();
            });
            $ok++;
            $this->line("  ✓ #{$v->id} {$v->vehicle_number} — {$applied}칸 채움");
            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        $this->newLine();
        $this->info("완료: 성공 {$ok} / 실패 {$fail}");
        if (! empty($failList)) {
            $this->warn('실패 목록 (소유자명 불일치 등 — 수기 보정 대상):');
            foreach ($failList as $f) {
                $this->line("  - {$f}");
            }
        }

        return self::SUCCESS;
    }
}
