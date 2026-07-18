<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use Illuminate\Console\Command;

/**
 * jin 2026-07-18 — 마감된 달로 뒤늦게 귀속된 "미지급" 정산을 현재 열린 달로 이월.
 *
 * 배경: 귀속월 = 완납월(fullPaymentMonth)로 back-date 되던 탓에, 이미 마감(배치 지급)된
 *   달로 6월자 잔금이 7월에 입력되면 마감된 6월 그룹에 사후 합류했다. 사용자 규칙(2026-07-18):
 *   "마감된 달은 그 순간 동결. 늦게 완성된 건은 완성된 달(현재 열린 달)에 포함."
 *
 * 대상(안전): settlement_status ∈ {pending, confirmed} && payout_batch_id IS NULL
 *   && attributed_month 의 달이 마감(승인된 배치 존재).
 *   ⇒ 이미 배치·지급(paid)된 정산은 절대 안 건드림 (마감 배치 동결 보존).
 *
 * dry-run 기본. --apply 로 실제 이월. 순수 attributed_month 컬럼만 갱신(모델 이벤트 우회).
 */
class ReanchorClosedMonthSettlements extends Command
{
    protected $signature = 'settlements:reanchor-closed-month {--apply : 실제 이월 (미지정=dry-run)}';

    protected $description = '마감된 달로 뒤늦게 귀속된 미지급 정산을 현재 열린 달로 이월';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // 현재 열린 달 = 이번 달부터 마감 안 된 첫 달.
        $cursor = now()->startOfMonth();
        while (SettlementPayoutBatch::isMonthClosed($cursor->format('Y-m'))) {
            $cursor->addMonth();
        }
        $openMonth = $cursor->format('Y-m-d');

        $rows = Settlement::whereNull('payout_batch_id')
            ->whereIn('settlement_status', ['pending', 'confirmed'])
            ->whereNotNull('attributed_month')
            ->get(['id', 'salesman_id', 'attributed_month', 'settlement_status']);

        $targets = $rows->filter(
            fn (Settlement $s) => SettlementPayoutBatch::isMonthClosed($s->attributed_month->format('Y-m'))
                && $s->attributed_month->format('Y-m-d') !== $openMonth
        );

        if ($targets->isEmpty()) {
            $this->info('이월 대상 없음 (마감월로 귀속된 미지급 정산 없음).');

            return self::SUCCESS;
        }

        $this->info(sprintf('현재 열린 달 = %s. 이월 대상 %d건 (귀속월별):', substr($openMonth, 0, 7), $targets->count()));
        $byMonth = $targets->groupBy(fn (Settlement $s) => $s->attributed_month->format('Y-m'));
        foreach ($byMonth->sortKeys() as $ym => $group) {
            $this->line(sprintf('  %s → %s : %d건 (id: %s)', $ym, substr($openMonth, 0, 7), $group->count(), $group->pluck('id')->implode(',')));
        }

        if (! $apply) {
            $this->warn('[dry-run] 실제 이월하려면 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        $ids = $targets->pluck('id')->all();
        Settlement::whereIn('id', $ids)->update(['attributed_month' => $openMonth]);
        $this->info(sprintf('✅ %d건 → %s 이월 완료.', count($ids), substr($openMonth, 0, 7)));

        return self::SUCCESS;
    }
}
