<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🚨 다른 차의 NICE 원본이 저장된 차량 정리 (jin 2026-11-05 «데이터는 비우는 걸로, 일괄 재조회는 하지 말고»).
 *
 * 원인 = 차량 패널 편집 열기가 직전 조회 원본(niceRaw)을 안 비워, 다음에 저장한 차에 앞 차 원본이 들어갔다
 * (3사 830대, ssancarerp 01부5398 에 모닝 제원번호 A011-00048-0140-1414). 10-02 백필(sync-nice-spec-columns)이
 * 그 원본으로 빈 제원 칸(제원관리번호·형식·최대출력·기통수·검사 시작/종료)을 채워 서류에까지 찍혔다.
 *
 * 대상 = 원본 차대번호(resVehicleIdNo) ≠ 그 차 차대번호(nice_reg_vin) — 둘 다 있을 때만(확실한 것만).
 *   nice_raw 를 비우고, 제원 6칸은 **그 원본에서 나온 값과 같을 때만** 비운다(사람이 고친 값은 둔다).
 * 비운 칸은 실무자가 다시 NICE 조회한다(재조회는 건당 과금이라 일괄로 하지 않는다 — jin).
 * dry-run 기본, --apply 로 실제. raw update(모델 훅 무관) + 차량마다 감사로그. 멱등.
 */
class ClearMismatchedNiceData extends Command
{
    protected $signature = 'vehicles:clear-mismatched-nice {--apply : 실제로 비움 (미지정=dry-run)}';

    protected $description = '다른 차의 NICE 원본이 저장된 차량 — 원본과 그 원본에서 온 제원 칸을 비운다(차대번호 불일치만)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $norm = fn ($s) => strtoupper(preg_replace('/\s+/', '', (string) $s));
        $vehicles = 0;
        $cols = array_fill_keys(['nice_raw', 'nice_spec_control_no', 'nice_spec_form_name', 'nice_spec_max_power', 'nice_spec_cylinders', 'nice_inspection_start', 'nice_inspection_end'], 0);

        Vehicle::query()->withTrashed()->whereNotNull('nice_raw')->orderBy('id')->chunkById(300, function ($chunk) use ($apply, $norm, &$vehicles, &$cols) {
            foreach ($chunk as $v) {
                $raw = is_array($v->nice_raw) ? $v->nice_raw : [];
                $rawVin = $norm($raw['resVehicleIdNo'] ?? '');
                $ownVin = $norm($v->nice_reg_vin);
                if ($rawVin === '' || $ownVin === '' || $rawVin === $ownVin) {
                    continue;
                }
                $vehicles++;

                // 그 (틀린) 원본에서 나온 값과 같은 칸만 — 백필 규칙과 같은 파서(DocValue)
                [$start, $end] = DocValue::validPeriodDates($raw['resValidPeriod'] ?? null);
                $fromRaw = [
                    'nice_spec_control_no' => trim((string) ($raw['resSpecControlNo'] ?? '')),
                    'nice_spec_form_name' => trim((string) ($raw['fomNm'] ?? '')),
                    'nice_spec_max_power' => trim((string) ($raw['maxPower'] ?? '')),
                    'nice_spec_cylinders' => DocValue::cylindersFromEngineSpec($raw['engineSpec'] ?? null),
                    'nice_inspection_start' => $start,
                    'nice_inspection_end' => $end,
                ];
                $changes = ['nice_raw' => null];
                // 🔑 칸이 그 원본에서 왔다는 증거 = 제원관리번호가 그 원본 값과 같다(10-02 백필이 한 번에 채웠다).
                //    다르면 그 차 자기 조회값이다 — 기통수 「4」처럼 우연히 같은 값을 지우지 않는다.
                $cur = (string) $v->nice_spec_control_no;
                if ($fromRaw['nice_spec_control_no'] === '' || $cur !== $fromRaw['nice_spec_control_no']) {
                    $fromRaw = [];
                }
                foreach ($fromRaw as $col => $val) {
                    $cur = $v->{$col};
                    $cur = $cur instanceof \DateTimeInterface ? $cur->format('Y-m-d') : $cur;
                    if ($val !== null && $val !== '' && (string) $cur === (string) $val) {
                        $changes[$col] = null;
                    }
                }
                foreach (array_keys($changes) as $c) {
                    $cols[$c]++;
                }
                if (! $apply) {
                    continue;
                }
                DB::transaction(function () use ($v, $changes, $raw, $fromRaw) {
                    DB::table('vehicles')->where('id', $v->id)->update($changes);
                    // 🔒 원본 통째는 감사로그에 안 남긴다(소유자명 등이 들어 있다) — 어느 차 것이었는지만.
                    $note = '다른 차 NICE 원본(차대번호 '.($raw['resVehicleIdNo'] ?? '?').' · '.($raw['commCarName'] ?? '?').')';
                    foreach (array_keys($changes) as $c) {
                        AuditLog::create([
                            'user_id' => null, 'auditable_type' => Vehicle::class, 'auditable_id' => $v->id,
                            'action' => 'nice_mismatch_cleared', 'column_name' => $c,
                            'old_value' => $c === 'nice_raw' ? $note : (string) $fromRaw[$c], 'new_value' => null,
                        ]);
                    }
                });
            }
        });

        $this->info(sprintf('%s · 다른 차 NICE 원본 %d대', $apply ? '적용' : 'dry-run', $vehicles));
        foreach ($cols as $c => $n) {
            $this->line(sprintf('  %-24s %d건', $c, $n));
        }

        return self::SUCCESS;
    }
}
