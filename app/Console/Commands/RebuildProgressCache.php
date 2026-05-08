<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

class RebuildProgressCache extends Command
{
    protected $signature = 'vehicles:rebuild-progress-cache';

    protected $description = '모든 차량의 progress_status_cache 컬럼을 현재 상태로 재계산';

    public function handle(): int
    {
        $count = 0;
        Vehicle::with(['finalPayments', 'purchaseBalancePayments'])
            ->chunk(200, function ($vehicles) use (&$count) {
                foreach ($vehicles as $vehicle) {
                    \DB::table('vehicles')->where('id', $vehicle->id)
                        ->update(['progress_status_cache' => $vehicle->progress_status]);
                    $count++;
                }
            });

        $this->info("재계산 완료: {$count}건");

        return self::SUCCESS;
    }
}
