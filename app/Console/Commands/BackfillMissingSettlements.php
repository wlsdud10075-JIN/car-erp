<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 거래완료인데 정산이 없는 차량에 pending 정산 백필 (2026-06-24 jin).
 *
 *   php artisan settlements:backfill-missing            # 드라이런 (대상만 출력)
 *   php artisan settlements:backfill-missing --force    # 실제 생성
 *
 * 갭 원인: 정산 자동 생성은 Vehicle::saved 훅이 거래완료 진입 시 만드는데,
 *   `auth()->check()` 가 false 인 CLI/import(헤이맨 엑셀 일괄적재 등) 경로에선 건너뛴다.
 *   → 거래완료 차량인데 settlements 행이 없어 정산 목록에 안 보이는 누락이 생김.
 *
 * 이 커맨드는 그 훅(app/Models/Vehicle.php ~675행)의 생성 페이로드를 그대로 미러링.
 *   대상 = bl_document 있음(=거래완료, v4 cascade #1) + 영업담당자 있음 + 정산 없음.
 *   멱등 — 이미 정산 있으면 건너뜀. import 직후 재실행 권장.
 */
class BackfillMissingSettlements extends Command
{
    protected $signature = 'settlements:backfill-missing
                            {--force : 실제 생성 (없으면 드라이런)}';

    protected $description = '거래완료인데 정산 누락된 차량에 pending 정산 백필 (import/CLI 자동생성 갭 보정). 멱등.';

    public function handle(): int
    {
        // 거래완료 판정 = bl_document 존재 (v4 cascade #1, 캐시 문자열보다 robust).
        $targets = Vehicle::query()
            ->whereNotNull('bl_document')
            ->whereNotNull('salesman_id')
            ->whereDoesntHave('settlements')
            ->with('salesman')
            ->get();

        $noSalesman = Vehicle::query()
            ->whereNotNull('bl_document')
            ->whereNull('salesman_id')
            ->whereDoesntHave('settlements')
            ->count();

        $this->info("거래완료 + 정산없음 + 영업담당자 있음 : {$targets->count()}대 (백필 대상)");
        if ($noSalesman > 0) {
            $this->warn("거래완료 + 정산없음 + 영업담당자 없음 : {$noSalesman}대 (담당자 지정 필요 — 백필 불가)");
        }

        foreach ($targets as $v) {
            $this->line("  #{$v->id} {$v->vehicle_number} | {$v->salesman->name} | ".$v->salesman->defaultSettlementType());
        }

        if ($targets->isEmpty()) {
            $this->info('백필할 대상 없음.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('⚠️  --force 없이 실행됨 → 드라이런(미생성). --force 로 실제 생성.');

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($targets as $v) {
            // Vehicle::saved 자동생성 훅과 동일 페이로드 (app/Models/Vehicle.php ~675행).
            $v->settlements()->create([
                'salesman_id' => $v->salesman_id,
                'settlement_type' => $v->salesman->defaultSettlementType(),
                'settlement_ratio' => null,
                'per_unit_amount' => null,
                'settlement_status' => 'pending',
                'note' => '백필 — 거래완료인데 정산 누락 (import/CLI 자동생성 갭)',
            ]);
            $created++;
        }

        $this->info("✓ {$created}건 pending 정산 백필 완료. 재무가 확정하면 됨.");

        return self::SUCCESS;
    }
}
