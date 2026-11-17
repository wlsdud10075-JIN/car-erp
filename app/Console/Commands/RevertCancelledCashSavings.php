<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\BuyerCashFee;
use App\Models\SavingsStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 💳 **이미 취소된 「현금 → 적립금 전환」의 현금 차감 줄을 지워 현금을 돌려놓는다** (jin 2026-11-17).
 *
 * 2026-11-17 전엔 적립금을 취소해도 현금 차감(`buyer_cash_fees` kind=savings)이 남아 그 돈이 현금에도 적립금에도
 * 없었다(실측 heymanerp R.S.H 331마3285 2,400 EUR). 이제 취소가 같이 되돌리지만, 그 전에 취소된 것은 이 명령으로 고친다.
 *
 * 대상 = `savings_statuses.buyer_cash_fee_id` 로 이어진 EARNED 중 **CANCELLED 짝이 있는 것**의 현금 차감 줄.
 * 기본은 dry-run(목록만), `--apply` 로 지운다(배분 cascade → 현금 복귀, 감사로그 1줄씩).
 */
class RevertCancelledCashSavings extends Command
{
    protected $signature = 'buyer-cash:revert-cancelled-savings {--apply : 실제로 지운다(없으면 목록만)}';

    protected $description = '취소된 현금→적립금 전환의 현금 차감 줄을 지워 현금을 돌려놓는다(기본 dry-run)';

    public function handle(): int
    {
        $cancelledIds = SavingsStatus::where('transaction_type', 'CANCELLED')
            ->whereNotNull('original_transaction_id')->pluck('original_transaction_id');

        $earned = SavingsStatus::with('buyer:id,name')
            ->where('transaction_type', 'EARNED')
            ->whereNotNull('buyer_cash_fee_id')
            ->whereIn('id', $cancelledIds)
            ->orderBy('id')->get();

        if ($earned->isEmpty()) {
            $this->info('대상 0건.');

            return self::SUCCESS;
        }

        foreach ($earned as $s) {
            $this->line(sprintf('적립 #%d · %s · %s %s · 현금 차감 #%d', $s->id, $s->buyer?->name ?? $s->buyer_id,
                number_format((float) $s->savings, 2), $s->currency, $s->buyer_cash_fee_id));
        }

        if (! $this->option('apply')) {
            $this->warn("dry-run — {$earned->count()}건. 지우려면 --apply");

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($earned as $s) {
            DB::transaction(function () use ($s, &$done) {
                $fee = BuyerCashFee::where('kind', BuyerCashFee::KIND_SAVINGS)->find($s->buyer_cash_fee_id);
                if ($fee) {
                    AuditLog::recordEvent($fee, 'buyer_cash_savings_reverted');
                    $fee->allocations()->delete();   // DB cascade 와 같은 일
                    $fee->delete();
                    $done++;
                }
            });
        }
        $this->info("현금 복귀 {$done}건.");

        return self::SUCCESS;
    }
}
