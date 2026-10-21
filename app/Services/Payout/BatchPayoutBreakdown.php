<?php

namespace App\Services\Payout;

use App\Models\ExternalSettlementPay;
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
 *   transfer_total     송금 총액 = Σ실지급액(급여·검차·헤이맨 몫 포함) + Σ퇴직연금 전환액   … 통장에서 나갈 돈
 *   pension_total      Σ퇴직연금 1/12 전환액(2026-10-21) — 사람에게 안 가고 퇴직연금으로 간다
 *   external_total     Σ헤이맨 정산금(2026-10-21) — 송금엔 있고 회사이익엔 없다. 「총마진 − 송금 총액 + 이것 = 회사이익」으로 닫힌다
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

        // 🚪 퇴사 배치는 그 한 사람을 반드시 그린다(정산 0건·이월만일 수 있다) (2026-10-15)
        return self::assemble($batch->month, $settlements, $adjustments, $batch->isLeaverBatch() && $batch->salesman_id ? [(int) $batch->salesman_id] : []);
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
    private static function assemble(string $month, Collection $settlements, Collection $adjustments, array $forceIds = []): array
    {
        $bySalesman = $settlements->groupBy('salesman_id');
        $adjBySalesman = $adjustments->groupBy('salesman_id');

        $ids = $bySalesman->keys()->merge($adjBySalesman->keys())->merge($forceIds)->filter()->map(fn ($i) => (int) $i);
        // 그 달 급여가 입력된 사내직원·검차직원 — 정산이 0건이어도 월급은 나간다(jin 2026-10-06 → v3 급여 항목)
        //   🚪 퇴사자(퇴사일 있음)는 비활성이어도 들어간다 — 마지막 달 급여는 종전대로 월정산 10일에 나간다(jin 2026-10-15).
        //      재직 표시 없이 그냥 비활성인 옛 행(관리자 계정 미러 등)은 종전대로 빠진다.
        $payrollIds = PayrollEntry::query()->where('month', $month)->distinct()->pluck('salesman_id')->map(fn ($i) => (int) $i);
        $salaried = Salesman::query()->whereIn('id', $payrollIds)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('left_at'))
            ->where('payout_excluded', false)
            ->where('type', '!=', 'freelance')
            ->pluck('id');
        // 💱 다른 회사(heymanerp) 정산금만 있는 사람도 그린다(2026-10-21) — 송금에 들어가는 돈이다.
        $externalIds = ExternalSettlementPay::query()->where('month', $month)->distinct()->pluck('salesman_id')->map(fn ($i) => (int) $i);
        $ids = $ids->merge($salaried)->merge($externalIds)->unique()->values();

        $people = Salesman::query()->whereIn('id', $ids)->get()->keyBy('id');
        $payrollTotals = PayrollEntry::query()->where('month', $month)->whereIn('salesman_id', $ids)
            ->selectRaw('salesman_id, SUM(amount) as total')->groupBy('salesman_id')->pluck('total', 'salesman_id');
        $payrollItems = PersonPayoutBreakdown::payrollItemsFor($ids->all(), $month);   // 카드 펼침용 기입 항목(한 쿼리)
        $external = ExternalSettlementPay::totalsFor($ids->all(), $month);

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
                $payroll, false, $payrollItems[$id] ?? [], (int) ($external[$id] ?? 0),
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
        $pensionTotal = 0;
        $externalTotal = 0;
        foreach ($rows as $r) {
            $externalTotal += (int) ($r['heyman_pay'] ?? 0);
            $transfer += (int) $r['payout'] + (int) ($r['pension'] ?? 0);
            $pensionTotal += (int) ($r['pension'] ?? 0);
            $vehicles += (int) $r['count'];
            $totalMargin += (int) $r['total_margin'];
            $equivSum += (int) ($r['equiv_total'] ?? 0);
            if (in_array($r['type'], Salesman::PAYROLL_ONLY_TYPES, true)) {   // 검차직원·직원 = 공통 인건비
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
                'pension_total' => $pensionTotal,
                'external_total' => $externalTotal,
                'contribution_sum' => $contributionSum,
                'common_labor' => $commonLabor,
                'company_net' => $contributionSum - $commonLabor,
            ],
        ];
    }
}
