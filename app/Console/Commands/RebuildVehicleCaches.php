<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildVehicleCaches extends Command
{
    /**
     * 기존 명령 vehicles:rebuild-progress-cache는 alias로 남김 (CLAUDE.md/SKILLS.md 호환).
     */
    protected $signature = 'vehicles:rebuild-caches {--legacy}';

    protected $description = '모든 차량의 progress_status_cache + receivable_risk + sale_unpaid_amount_krw_cache 재계산';

    public function handle(): int
    {
        $count = 0;
        // ⚠️ buyer 를 빼지 말 것 — 위험도가 바이어별 게이트 임계를 읽는다(2026-09-14). 빼면 4,700대 N+1.
        Vehicle::with(['finalPayments', 'purchaseBalancePayments', 'receivableHistories', 'buyer'])
            ->chunk(200, function ($vehicles) use (&$count) {
                foreach ($vehicles as $vehicle) {
                    $krw = $vehicle->sale_unpaid_amount_krw;
                    DB::table('vehicles')->where('id', $vehicle->id)->update([
                        'progress_status_cache' => $vehicle->progress_status,
                        'receivable_risk' => $vehicle->receivable_risk_computed,
                        'sale_unpaid_amount_krw_cache' => $krw !== null ? (int) round($krw) : null,
                    ]);
                    $count++;
                }
            });

        $this->info("재계산 완료: {$count}건");

        return self::SUCCESS;
    }
}
