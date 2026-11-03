<?php

namespace App\Services\Payout;

use App\Models\DomesticFeeStatement;
use App\Models\ExternalSettlementPay;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
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

        // 🧾 내수직원 월정산(2026-11-03) — 그 달 평가수수료 표가 있는 내수직원만. 정산·조정·외부 정산금은 없다.
        if ($batch->isDomesticBatch()) {
            return self::assemble($batch->month, collect(), collect(), self::domesticIdsOf($batch)->all(), true);
        }

        // 🚪 퇴사 배치는 그 한 사람을 반드시 그린다(정산 0건·이월만일 수 있다) (2026-10-15)
        return self::assemble($batch->month, $settlements, $adjustments, $batch->isLeaverBatch() && $batch->salesman_id ? [(int) $batch->salesman_id] : []);
    }

    /** 그 달 평가수수료 표가 있고 재직(또는 퇴사일 있음)·지급 대상인 내수직원 id — 급여 인원(assemble)과 같은 조건. */
    public static function domesticSalesmanIds(string $month): Collection
    {
        return Salesman::query()->where('type', 'domestic_staff')
            ->whereIn('id', DomesticFeeStatement::query()->where('month', $month)->select('salesman_id'))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('left_at'))
            ->where('payout_excluded', false)
            ->orderBy('name')->pluck('id')->map(fn ($i) => (int) $i);
    }

    /** 내수직원 월정산 배치의 사람 — 지금 그 달 평가수수료 표가 있는 내수직원(끝난 배치는 박제를 쓰므로 진행 중일 때만 의미가 있다). */
    public static function domesticIdsOf(SettlementPayoutBatch $batch): Collection
    {
        return self::domesticSalesmanIds($batch->month);
    }

    /** 내수직원 월정산 제출 전 미리보기 — 그 달 평가수수료 표가 올라온 내수직원 카드. */
    public static function forDomesticPreview(string $month): array
    {
        return self::assemble($month, collect(), collect(), self::domesticSalesmanIds($month)->all(), true);
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
    private static function assemble(string $month, Collection $settlements, Collection $adjustments, array $forceIds = [], bool $domesticOnly = false): array
    {
        $bySalesman = $settlements->groupBy('salesman_id');
        $adjBySalesman = $adjustments->groupBy('salesman_id');

        $ids = $bySalesman->keys()->merge($adjBySalesman->keys())->merge($forceIds)->filter()->map(fn ($i) => (int) $i);
        // 🧾 내수직원 월정산이 켜진 회사(2026-11-03) — 내수직원은 별도 결재라 사내직원 월정산 사람에서 뺀다(합계 한 줄만 남긴다).
        $splitDomestic = ! $domesticOnly && Setting::domesticPayoutEnabled();
        // 그 달 급여가 입력된 사내직원·검차직원 — 정산이 0건이어도 월급은 나간다(jin 2026-10-06 → v3 급여 항목)
        //   🚪 퇴사자(퇴사일 있음)는 비활성이어도 들어간다 — 마지막 달 급여는 종전대로 월정산 10일에 나간다(jin 2026-10-15).
        //      재직 표시 없이 그냥 비활성인 옛 행(관리자 계정 미러 등)은 종전대로 빠진다.
        $payrollIds = PayrollEntry::query()->where('month', $month)->distinct()->pluck('salesman_id')->map(fn ($i) => (int) $i);
        $salaried = Salesman::query()->whereIn('id', $payrollIds)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('left_at'))
            ->where('payout_excluded', false)
            ->where('type', '!=', 'freelance')
            ->when($splitDomestic, fn ($q) => $q->where('type', '!=', 'domestic_staff'))
            ->pluck('id');
        // 💱 다른 회사(heymanerp) 정산금만 있는 사람도 그린다(2026-10-21) — 송금에 들어가는 돈이다.
        //    지급 대상 아님(payout_excluded)은 급여 인원과 같이 뺀다.
        $externalIds = Salesman::query()->whereIn('id', ExternalSettlementPay::query()->where('month', $month)->select('salesman_id'))
            ->where('payout_excluded', false)->pluck('id')->map(fn ($i) => (int) $i);
        $ids = $domesticOnly ? $ids->unique()->values() : $ids->merge($salaried)->merge($externalIds)->unique()->values();

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

        // 정렬 — 회사 기여 큰 순, 급여만 받는 사람은 맨 뒤에 검차직원 → 내수직원 → 직원 순(jin 2026-11-03 «검차직원 아래쪽에 내수직원»).
        $payrollRank = fn (array $r): int => ['inspector' => 0, 'domestic_staff' => 1][$r['type']] ?? 2;
        usort($rows, fn ($a, $b) => [$b['company_contribution'] !== null, $b['company_contribution'] ?? PHP_INT_MIN, $payrollRank($a)]
            <=> [$a['company_contribution'] !== null, $a['company_contribution'] ?? PHP_INT_MIN, $payrollRank($b)]);

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

        // 🧾 내수직원 회사수익(jin 2026-11-03 (가) «총 회사수익으로 더해줘») — 켜진 회사의 사내직원 월정산에서만.
        //    엑셀 「총 회사수익」 = 컨설팅비 + 매도비 − 총 평가사수수료(클레임 반영) — 평가사에게 주는 돈(내수직원 지급합계)을
        //    이미 뺀 값이라 지급합계를 또 빼면 이중 차감이다. 지급 자체는 내수직원 월정산 결재로 나가고 송금 총액엔 안 넣는다.
        $domesticFee = $splitDomestic ? self::domesticFeeTotalForMonth($month) : null;
        $domesticProfit = $domesticFee ? self::profitOfFeeTotal($domesticFee) : 0;

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
                'domestic_profit' => $domesticProfit,
                'company_net' => $contributionSum - $commonLabor + $domesticProfit,
            ],
            // 🧾 내수직원 합계 줄(jin 2026-11-03 — 엑셀 G74:P74) = 이번 달 카드에 들어간 내수직원 표의 열 합. 없으면 null.
            //    켜진 회사의 사내직원 월정산은 사람 카드가 없으므로 그 달 표에서 바로 더한다(표시 전용 — 송금·회사이익 무관, jin «합계 한 줄이면 족해»).
            'domestic_fee_total' => $splitDomestic ? $domesticFee : self::domesticFeeTotal($rows),
            'domestic_split' => $splitDomestic,
            // 그 달 내수직원 월정산 결재 상태(승인·결재 중, 없으면 null) — 한 줄 옆 뱃지. 내수가 먼저 끝나는 흐름이라 확인용.
            'domestic_batch_status' => $splitDomestic
                ? SettlementPayoutBatch::domestic()->where('month', $month)
                    ->whereIn('status', [SettlementPayoutBatch::STATUS_PENDING, SettlementPayoutBatch::STATUS_APPROVED])
                    ->orderByDesc('id')->value('status')
                : null,
        ];
    }

    /**
     * 내수직원 카드들의 평가수수료 표를 열마다 더한다 — 항목 이름은 첫 사람 것(같은 파일이면 같다). 내수직원이 없으면 null.
     *
     * @return array{items: list<array{label:string, value:int|float|null}>, count:int}|null
     */
    public static function domesticFeeTotal(array $rows): ?array
    {
        return self::sumFeeTables(array_values(array_filter(array_map(fn ($r) => $r['domestic_fee'] ?? null, $rows))));
    }

    /** 그 달 내수직원 월정산에 들어갈 사람들의 평가수수료 표 합(사내직원 월정산의 합계 한 줄). */
    public static function domesticFeeTotalForMonth(string $month): ?array
    {
        $ids = self::domesticSalesmanIds($month);

        return self::sumFeeTables(DomesticFeeStatement::query()->where('month', $month)->whereIn('salesman_id', $ids)
            ->get()->map(fn (DomesticFeeStatement $st) => $st->items())->all());
    }

    /**
     * 🧾 그 달 내수직원 「총 회사수익」 합 — 내수직원 월정산이 켜진 회사만(꺼지면 0, 종전 공식).
     * 회사 순이익을 내는 세 곳(월정산 카드 · 관리자 대시보드 · 월결산 알림톡)이 같이 쓴다(§8 #72 — 한 곳만 고치면 대표 화면끼리 갈린다).
     * `$ids` = 담당자 스코프(업무관리자 화면) — 주면 그 안의 내수직원 것만.
     */
    public static function domesticProfitForMonth(string $month, ?array $ids = null): int
    {
        if (! Setting::domesticPayoutEnabled()) {
            return 0;
        }
        $people = self::domesticSalesmanIds($month);
        if ($ids !== null) {
            $people = $people->intersect(array_map('intval', $ids))->values();
        }
        $total = self::sumFeeTables(DomesticFeeStatement::query()->where('month', $month)->whereIn('salesman_id', $people)
            ->get()->map(fn (DomesticFeeStatement $st) => $st->items())->all());

        return $total ? self::profitOfFeeTotal($total) : 0;
    }

    /** 평가수수료 합계 표 → 회사수익(「총 회사수익」, 없으면 「회사수익」). 평가사 지급을 이미 뺀 값이다. */
    private static function profitOfFeeTotal(array $total): int
    {
        return (int) round((float) (self::feeItem($total, '총 회사수익') ?? self::feeItem($total, '회사수익') ?? 0));
    }

    /** 합계 표에서 항목 하나(이름으로). 없으면 null. */
    private static function feeItem(array $total, string $label): int|float|null
    {
        return collect($total['items'] ?? [])->firstWhere('label', $label)['value'] ?? null;
    }

    private static function sumFeeTables(array $tables): ?array
    {
        if ($tables === []) {
            return null;
        }
        $items = [];
        foreach ($tables[0] as $i => $it) {
            $sum = 0;
            foreach ($tables as $t) {
                $sum += (float) ($t[$i]['value'] ?? 0);
            }
            $items[] = ['label' => $it['label'], 'value' => $sum + 0];
        }

        return ['items' => $items, 'count' => count($tables)];
    }
}
