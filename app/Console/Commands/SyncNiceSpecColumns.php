<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NICE 제원 전용 컬럼 백필 (2026-10-02) — `nice_raw` 에만 있던 값을 새 편집 칸으로 옮긴다.
 *
 * 대상 = nice_raw 가 있는 차량. **빈 칸만 채운다** — 사람이 이미 고친 값(수기)은 그대로 둔다.
 * 파싱 규칙은 DocValue 의 static 파서(서류 폴백·NiceApiService::transform 과 같은 함수) — 여기 옮겨 적지 않는다.
 * raw update 로 쓴다(모델 훅·updated_at 무변경 — 파생 캐시가 이 컬럼을 안 본다).
 * dry-run 기본, --apply 로 실제 기입. 멱등 — 두 번 돌리면 두 번째는 0건.
 */
class SyncNiceSpecColumns extends Command
{
    protected $signature = 'vehicles:sync-nice-spec-columns {--apply : 실제 기입 (미지정=dry-run)}';

    protected $description = 'nice_raw → 제원관리번호·형식·최대출력·기통수·검사 시작/종료 컬럼 백필 (빈 칸만)';

    /** 컬럼 => raw 에서 값을 뽑는 함수 */
    private const EXTRACT = [
        'nice_spec_control_no' => 'resSpecControlNo',
        'nice_spec_form_name' => 'fomNm',
        'nice_spec_max_power' => 'maxPower',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $filled = array_fill_keys([...array_keys(self::EXTRACT), 'nice_spec_cylinders', 'nice_inspection_start', 'nice_inspection_end'], 0);
        $vehicles = 0;
        $touched = 0;

        Vehicle::query()->whereNotNull('nice_raw')->orderBy('id')->chunkById(500, function ($chunk) use ($apply, &$filled, &$vehicles, &$touched) {
            foreach ($chunk as $v) {
                $vehicles++;
                $changes = [];
                foreach (self::EXTRACT as $col => $rawKey) {
                    $raw = trim((string) DocValue::niceRaw($v, $rawKey));
                    if (blank($v->{$col}) && $raw !== '') {
                        $changes[$col] = $raw;
                    }
                }
                $cyl = DocValue::cylindersFromEngineSpec(DocValue::niceRaw($v, 'engineSpec'));
                if (blank($v->nice_spec_cylinders) && $cyl !== null) {
                    $changes['nice_spec_cylinders'] = (int) $cyl;
                }
                [$start, $end] = DocValue::validPeriodDates(DocValue::niceRaw($v, 'resValidPeriod'));
                if (! $v->nice_inspection_start && $start !== null) {
                    $changes['nice_inspection_start'] = $start;
                }
                if (! $v->nice_inspection_end && $end !== null) {
                    $changes['nice_inspection_end'] = $end;
                }
                if (empty($changes)) {
                    continue;
                }
                $touched++;
                foreach (array_keys($changes) as $col) {
                    $filled[$col]++;
                }
                if ($apply) {
                    DB::table('vehicles')->where('id', $v->id)->update($changes);
                }
            }
        });

        $this->info(sprintf('%s · nice_raw 있는 차량 %d대 · 채울 차량 %d대', $apply ? '적용' : 'dry-run', $vehicles, $touched));
        foreach ($filled as $col => $n) {
            $this->line(sprintf('  %-24s %d건', $col, $n));
        }

        return self::SUCCESS;
    }
}
