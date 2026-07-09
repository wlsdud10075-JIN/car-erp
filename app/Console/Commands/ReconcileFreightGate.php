<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use Illuminate\Console\Command;

/**
 * 운임 확정 게이트 배포 후속 (jin 2026-07-09) — 신 규칙 위반 조기 정산 정리.
 *
 * 구 규칙(완납이면 무조건 정산)로 자동 생성됐지만, 신 게이트
 *   (Vehicle::isFreightConfirmedForSettlement = FOB 또는 CFR+운임>0)로는 아직 뜨면 안 되는
 *   "운임 미확정 상태(incoterms NULL / CFR+운임0)"의 정산을 정리한다.
 *
 * - pending  : 삭제 대상 (재무 미확정 → 무해, 운임 확정 시 재트리거로 자동 재생성).
 * - confirmed/paid/closed : 삭제 불가(회계 잠금·Settlement::deleting 가드) → 리포트만.
 *     특히 CFR+운임0 이 confirmed→batched→paid 되면 운임비 후입력 시 미수가 새므로 수동 검토 필요.
 *
 * ⚠️ 반드시 vehicles:backfill-incoterms-cfr **다음에** 실행 (CFR 차량 오삭제 방지).
 * dry-run 기본, --apply.
 */
class ReconcileFreightGate extends Command
{
    protected $signature = 'settlements:reconcile-freight-gate {--apply : 실제 삭제 (미지정=dry-run)}';

    protected $description = '운임 미확정인데 조기 생성된 pending 정산 정리 (backfill-incoterms-cfr 後 실행)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $premature = Settlement::with('vehicle')->get()
            ->filter(fn (Settlement $s) => $s->vehicle && ! $s->vehicle->isFreightConfirmedForSettlement());

        $pending = $premature->where('settlement_status', 'pending');
        $locked = $premature->whereNotIn('settlement_status', ['pending']);

        $this->info("운임 미확정 정산 총 {$premature->count()}건");
        $this->line("  ├ pending (삭제 대상)      : {$pending->count()}건");
        $this->line("  └ confirmed/paid/closed    : {$locked->count()}건 (삭제 불가 — 수동 검토)");

        if ($locked->isNotEmpty()) {
            $this->warn('── 수동 검토 필요 (이미 확정/지급/마감된 운임 미확정 정산) ──');
            foreach ($locked as $s) {
                $this->line(sprintf(
                    '  정산#%d · 차량 %s · 상태 %s · incoterms=%s · 운임 %s',
                    $s->id,
                    $s->vehicle->vehicle_number ?? '?',
                    $s->settlement_status,
                    $s->vehicle->incoterms ?? 'NULL',
                    number_format((float) ($s->vehicle->transport_fee ?? 0)),
                ));
            }
        }

        if ($pending->isEmpty()) {
            $this->info('삭제할 pending 정산 없음.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn("[dry-run] pending {$pending->count()}건 삭제하려면 --apply 를 붙이세요.");

            return self::SUCCESS;
        }

        $n = 0;
        foreach ($pending as $s) {
            $s->delete();   // Settlement::deleting 가드 통과 (pending 만 삭제 허용)
            $n++;
        }
        $this->info("✅ pending 정산 {$n}건 삭제 완료. (운임 확정 시 자동 재생성)");

        return self::SUCCESS;
    }
}
