<?php

namespace App\Services\Payout;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use Illuminate\Support\Collection;

/**
 * 🧾 **월정산 한 건(또는 제출 전 미리보기)의 사람별 카드 + 합계** (월정산 v3, 2026-10-09).
 *
 * 사람 = 정산이 있는 담당자 ∪ 조정이 있는 담당자 ∪ 그 달 급여가 입력된 사내직원·검차직원(재직·지급 대상).
 * 각 사람은 PersonPayoutBreakdown — 공식은 거기 한 곳.
 *
 * 합계
 *   transfer_total     송금 총액 = Σ실지급액(급여·검차 포함)                       … 통장에서 나갈 돈
 *   contribution_sum   Σ회사 기여(검차 제외)                                      … 지분율 분모
 *   common_labor       Σ검차직원 실지급(공통 인건비)
 *   company_net        회사 순이익 = contribution_sum − common_labor
 *   share              사람별 지분율 = 회사 기여 ÷ |contribution_sum| (음수 그대로, 합이 0 이면 null)
 *   🚫 settlement_total(= 배치 total_payout, 정산+조정만) 은 종전 숫자 — 승인 금액·알림톡 「총액」이 계속 이것을 쓴다.
 */
final class BatchPayoutBreakdown
{
    public static function forBatch(SettlementPayoutBatch $batch): array
    {
        $settlements = $batch->relationLoaded('settlements') ? $batch->settlements
            : $batch->settlements()->with(['salesman', 'vehicle.finalPayments', 'vehicle.receivableHistories'])->get();
        $adjustments = $batch->relationLoaded('adjustments') ? $batch->adjustments : $batch->adjustments()->with('salesman')->get();

        return self::assemble($batch->month, $settlements, $adjustments);
    }

    /**
     * 제출 전 미리보기(정산관리 카드·제출 모달) — 조정 초안은 [['salesman_id','amount','kind','reason'], …].
     *
     * @param  Collection<int, Settlement>  $settlements
     */
    public static function forMonthPreview(string $month, Collection $settlements, array $adjustmentsDraft = []): array
    {
        $adjustments = collect($adjustmentsDraft)->map(fn ($a) => new SettlementPayoutAdjustment([
            'salesman_id' => (int) ($a['salesman_id'] ?? 0),
            'amount' => (int) ($a['amount'] ?? 0),
            'kind' => (string) ($a['kind'] ?? SettlementPayoutAdjustment::KIND_MANUAL),
            'reason' => (string) ($a['reason'] ?? ''),
        ]));

        return self::assemble($month, $settlements, $adjustments);
    }

    /**
     * @param  Collection<int, Settlement>  $settlements
     * @param  Collection<int, SettlementPayoutAdjustment>  $adjustments
     */
    private static function assemble(string $month, Collection $settlements, Collection $adjustments): array
    {
        $bySalesman = $settlements->groupBy('salesman_id');
        $adjBySalesman = $adjustments->groupBy('salesman_id');

        $ids = $bySalesman->keys()->merge($adjBySalesman->keys())->filter()->map(fn ($i) => (int) $i);
        // 그 달 급여가 입력된 사내직원·검차직원 — 정산이 0건이어도 월급은 나간다(jin 2026-10-06 → v3 급여 항목)
        $payrollIds = PayrollEntry::query()->where('month', $month)->distinct()->pluck('salesman_id')->map(fn ($i) => (int) $i);
        $salaried = Salesman::query()->whereIn('id', $payrollIds)
            ->where('is_active', true)->where('payout_excluded', false)
            ->where('type', '!=', 'freelance')
            ->pluck('id');
        $ids = $ids->merge($salaried)->unique()->values();

        $people = Salesman::query()->whereIn('id', $ids)->get()->keyBy('id');
        $payrollTotals = PayrollEntry::query()->where('month', $month)->whereIn('salesman_id', $ids)
            ->selectRaw('salesman_id, SUM(amount) as total')->groupBy('salesman_id')->pluck('total', 'salesman_id');

        $rows = [];
        foreach ($ids as $id) {
            $sm = $people->get($id);
            if (! $sm) {
                continue;
            }
            $payroll = $payrollTotals->has($id) ? (int) $payrollTotals->get($id) : null;
            $rows[] = PersonPayoutBreakdown::build(
                $sm, $month,
                ($bySalesman->get($id) ?? collect())->values(),
                ($adjBySalesman->get($id) ?? collect())->values(),
                $payroll, false,
            );
        }

        // 정렬 — 회사 기여 큰 순(검차는 맨 뒤)
        usort($rows, fn ($a, $b) => [$b['company_contribution'] !== null, $b['company_contribution'] ?? PHP_INT_MIN]
            <=> [$a['company_contribution'] !== null, $a['company_contribution'] ?? PHP_INT_MIN]);

        $contributionSum = 0;
        $commonLabor = 0;
        $transfer = 0;
        $vehicles = 0;
        $equivSum = 0;
        $totalMargin = 0;
        foreach ($rows as $r) {
            $transfer += (int) $r['payout'];
            $vehicles += (int) $r['count'];
            $totalMargin += (int) $r['total_margin'];
            $equivSum += (int) ($r['equiv_total'] ?? 0);
            if ($r['type'] === 'inspector') {
                $commonLabor += (int) $r['payout'];
            } elseif ($r['company_contribution'] !== null) {
                $contributionSum += (int) $r['company_contribution'];
            }
        }
        foreach ($rows as &$r) {
            // 분모 = 기여 합(jin 2026-10-08 — 음수가 있으면 나머지 합이 100% 를 넘는다). 합이 음수면 |합| 로 나눠 부호를 지킨다. 0 이면 없음.
            $r['share'] = ($r['company_contribution'] !== null && $contributionSum !== 0)
                ? round($r['company_contribution'] / abs($contributionSum) * 100, 1)
                : null;
        }
        unset($r);

        return [
            'month' => $month,
            'people' => $rows,
            'totals' => [
                'vehicles' => $vehicles,
                'total_margin' => $totalMargin,
                'equiv_sum' => $equivSum,
                'transfer_total' => $transfer,
                'contribution_sum' => $contributionSum,
                'common_labor' => $commonLabor,
                'company_net' => $contributionSum - $commonLabor,
            ],
        ];
    }
}
