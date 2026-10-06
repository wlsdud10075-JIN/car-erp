<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 💴 **「2차 가능」 소급 표시** — 배포 전부터 2차 대기 중이던 정산에 `secondary_ready_at` 을 채운다 (jin 2026-10-06).
 *
 * 훅(`Vehicle::updated` → `Settlement::markSecondaryReadyForVehicle`)은 배포 **뒤** 저장부터만 돈다.
 * 배포 전에 이미 비용을 기입한 차(실측 ssancarerp 2차 대기 431건 중 다수)는 감사로그로 되짚는다:
 *   그 차량의 **비용 칸(10개) 변경 기록**이 정산의 `paid_at` **뒤**에 있으면 「2차 가능」, 첫 기록 시각으로 표시.
 *
 * 🚫 이미 값이 있는 행은 덮지 않는다. 🚫 마감(closed)·지급 전 정산은 대상이 아니다.
 * ⚠️ §8 #101 — 배포만으로는 화면이 안 바뀐다. 3사 모두 `--apply` 를 한 번 돌릴 것(멱등).
 *
 *   php artisan settlements:backfill-secondary-ready            # dry-run (기본)
 *   php artisan settlements:backfill-secondary-ready --apply
 */
class BackfillSecondaryReady extends Command
{
    protected $signature = 'settlements:backfill-secondary-ready {--apply : 실제로 기록한다 (없으면 dry-run)}';

    protected $description = '2차 대기 정산 중 지급 뒤 비용 칸이 기입된 건을 「2차 가능」으로 소급 표시 (감사로그 기준)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $costCols = Vehicle::DISPLAY_COST_FIELDS;

        $targets = Settlement::query()
            ->secondaryWaiting()
            ->whereNotNull('paid_at')
            ->get(['id', 'vehicle_id', 'paid_at']);

        $ready = 0;
        $waiting = 0;
        foreach ($targets as $s) {
            $first = AuditLog::query()
                ->where('auditable_type', Vehicle::class)
                ->where('auditable_id', $s->vehicle_id)
                ->whereIn('column_name', $costCols)
                ->where('created_at', '>', $s->paid_at)
                ->min('created_at');

            if ($first === null) {
                $waiting++;

                continue;
            }
            $ready++;
            if ($apply) {
                Settlement::whereKey($s->id)->update(['secondary_ready_at' => $first]);
            }
        }

        $this->line(sprintf('2차 대기(비용 대기) %d건 → 2차 가능 %d · 비용 대기 유지 %d%s',
            $targets->count(), $ready, $waiting, $apply ? '  [적용됨]' : '  [dry-run — --apply 로 기록]'));

        return self::SUCCESS;
    }
}
