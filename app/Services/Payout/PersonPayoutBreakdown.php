<?php

namespace App\Services\Payout;

use App\Models\Buyer;
use App\Models\ExternalSettlementPay;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use Illuminate\Support\Collection;

/**
 * 🧮 **사람 한 명의 월정산 계산 — 단일 출처** (월정산 v3, jin 2026-10-08 확정 · 2026-10-09 구현).
 *
 * 정산관리 담당자별 카드 · 월정산 카드 · 폰 승인 링크 · 관리자 대시보드가 **전부 이것만** 부른다(§8 #44·#45 — 공식 복제 금지).
 *
 * 사내직원(employee)
 *   환산 합계   = 당월 프리랜서 공식 정산(판매환율) + ① 1차 환차 + 이월(가상) + 수기 추가정산          … 「프리랜서였다면 벌어 갔을 돈」
 *   실지급액    = 급여 지급합계 + 정산금(건당·차등, Σactual_payout) + 수기 조정 + 추가 인센티브
 *   급여공제후 마진 = 환산 합계 − 실지급액                                                        … 성과 판단(jin 10-07)
 *   초과 배율   = (환산 합계 − 실지급액) ÷ 실지급액  (부호 항상, 0 = 본전)
 *   🔀 2026-10-12 (jin) — 위 두 값은 **사내직원 카드에서 뺐다**(「환차니 뭐니 너무 복잡」). 값은 계속 계산한다(합계 띠의
 *      「프리랜서 환산 합계」와 옛 박제가 읽는다). 카드가 보여 주는 것 = 회사이익(=회사 기여) 과 **이익배수**:
 *   인센티브 전 회사이익 = 총마진 − (급여 + 정산금) − 발송비        … 인센티브·수기·이월을 정하기 **전**의 회사 몫
 *   이익배수            = 인센티브 전 회사이익 ÷ (급여 + 정산금)    … jin: 「받을 돈 대비 회사에 이익을 얼마나 줬냐를 보고 추가 인센티브를 준다」
 *                         ⇒ 인센티브를 줘도 배수는 안 움직인다(판단 근거가 판단 결과에 따라 흔들리지 않게). 1자리, 분모 ≤ 0 이면 null
 *   ① 1차 환차 = Σ[환산(실효환율) − 환산(판매환율)] — 08-06 부터 1차 정산에 녹아 있는 몫을 따로 떼어 보여 준다
 *   이월(가상) = 이 달에 2차 마감된 이 사람의 정산에서 「프리랜서였다면 받았을 차액」 = Σ(Δ총마진 × 비율). ② 2차 환차는 그중 환차분(정보).
 *   🚫 수기 조정(manual·loss)은 **실제로 나간 돈**이라 지급 쪽에도 넣는다 — 환산 쪽에만 넣으면 실지급이 배치 총액과 어긋난다.
 *
 * 프리랜서(freelance)
 *   환산 합계 = 당월(판매환율) + ① + 이월(실제 carryover_in + 이월 조정) + 수기 조정 = **Σactual_payout + Σ조정**(닫힘 항등식, §8 #64)
 *   실지급액 = 환산 합계 + 추가 인센티브. 급여공제후 마진·배율은 없다(항상 0).
 *
 * 검차직원(inspector) = 급여 지급합계만. 회사 기여 없음(공통 인건비).
 *
 * 회사 기여(지분율 기준, M1) = Σ총마진(실효) − 실지급액 − Σ발송비. 검차는 null.
 *
 * 🔀 2026-10-21 (jin) — 급여대장의 「판매수당」·헤이맨 정산금·퇴직연금 1/12
 *   - 판매수당 = 급여대장이 정산금(+조정, 헤이맨 몫 포함 − 1/12)을 급여에 실은 줄이다. ERP 는 정산금을 따로 더하므로
 *     **사내직원 급여 합계에서 판매수당을 뺀다**(안 빼면 같은 돈이 두 번 — 9월 8명 23,446,196원). 판매수당은 **대조용**으로만 남긴다.
 *   - 헤이맨 정산금(ExternalSettlementPay) = 다른 회사에서 정산된 몫을 이 회사가 같이 보낸다 → 송금엔 넣고 회사이익엔 안 넣는다.
 *   - 퇴직연금 1/12(사람별 토글) = (급여(판매수당 제외) + 정산금 + 헤이맨 + 조정·인센티브) ÷ 12 를 퇴직연금으로 돌린다.
 *     회사가 내는 돈은 그대로라 회사이익은 안 바뀌고, 그 사람 통장으로 가는 송금(payout)만 줄어든다(송금 총액엔 연금 몫도 포함).
 *   - 판매수당 대조 = 급여대장 판매수당 − (정산금 + 헤이맨 + 조정·인센티브 − 1/12). 0 이 아니면 어느 쪽이 틀린 것이다
 *     (9월 무사백: 엑셀이 640을 650으로·전월소급 −24만 누락 → 311,666 차이).
 * karaba 는 정산 공식이 달라(구간표) `unsupported` 로 돌려준다 — 2사 먼저(jin).
 */
final class PersonPayoutBreakdown
{
    /**
     * 그 달 기입된 급여 항목 — 사람별 [label, amount] 목록(고정 항목 순서 → 직접 추가 순). 한 쿼리로 여러 사람.
     *
     * @param  list<int>  $salesmanIds
     * @return array<int, list<array{label:string, amount:int}>>
     */
    public static function payrollItemsFor(array $salesmanIds, string $month): array
    {
        if ($salesmanIds === []) {
            return [];
        }
        $out = [];
        $rows = PayrollEntry::query()->where('month', $month)->whereIn('salesman_id', $salesmanIds)
            ->orderBy('salesman_id')->orderBy('sort')->orderBy('id')->get(['salesman_id', 'label', 'amount']);
        foreach ($rows as $r) {
            $out[(int) $r->salesman_id][] = ['label' => $r->label, 'amount' => (int) $r->amount];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Settlement>  $settlements  이 사람의 이번 배치(또는 달) 정산. vehicle.finalPayments·receivableHistories 로드 권장
     * @param  Collection<int, SettlementPayoutAdjustment>  $adjustments  이 사람의 배치 조정(kind 포함)
     * @param  int|null  $payrollTotal  그 달 급여 지급합계(null = 미입력). 생략하면 PayrollEntry 에서 읽는다
     * @param  list<array{label:string, amount:int}>|null  $payrollItems  기입된 급여 항목(순서대로). 생략하면 PayrollEntry 에서 읽는다 —
     *                                                                    카드 펼침에 「기입된 항목만」 보여 준다(jin 2026-10-14 «18개 다 기입되는 경우는 드무니까»)
     * @param  int|null  $externalPay  그 달 다른 회사(heymanerp) 정산금. 생략하면 ExternalSettlementPay 에서 읽는다
     */
    public static function build(Salesman $sm, string $month, Collection $settlements, Collection $adjustments, ?int $payrollTotal = null, bool $loadPayroll = true, ?array $payrollItems = null, ?int $externalPay = null): array
    {
        $type = $sm->type ?? 'employee';
        if ($payrollTotal === null && $loadPayroll && $type !== 'freelance') {
            $payrollTotal = PayrollEntry::totalFor($sm->id, $month);
        }
        if ($payrollItems === null && $loadPayroll && $type !== 'freelance') {
            $payrollItems = self::payrollItemsFor([$sm->id], $month)[$sm->id] ?? [];
        }
        $payroll = $type === 'freelance' ? 0 : (int) ($payrollTotal ?? 0);
        // 판매수당(급여대장) — 사내직원만 합계에서 뺀다(정산금과 같은 돈). 검차·직원은 그대로(정산이 없다).
        $salesCommission = null;
        if ($type === 'employee') {
            foreach ($payrollItems ?? [] as $it) {
                if (($it['label'] ?? '') === PayrollEntry::SALES_COMMISSION) {
                    $salesCommission = (int) ($salesCommission ?? 0) + (int) $it['amount'];
                }
            }
            $payroll -= (int) ($salesCommission ?? 0);
        }
        $heymanPay = (int) ($externalPay ?? ($loadPayroll ? (ExternalSettlementPay::totalsFor([$sm->id], $month)[$sm->id] ?? 0) : 0));
        $pensionOn = (bool) $sm->pension_split_enabled && ! in_array($type, Salesman::PAYROLL_ONLY_TYPES, true);

        $adjOf = fn (string $kind): int => (int) $adjustments->where('kind', $kind)->sum('amount');
        $incentive = $adjOf(SettlementPayoutAdjustment::KIND_INCENTIVE);
        $adjCarry = $adjOf(SettlementPayoutAdjustment::KIND_CARRYOVER);
        $adjManual = $adjOf(SettlementPayoutAdjustment::KIND_MANUAL) + $adjOf(SettlementPayoutAdjustment::KIND_LOSS);
        // 💰 예치금 반환(퇴사 정산) — 송금엔 들어가고(payout) 회사이익(company_contribution)·이익배수에선 뺀다. 그 사람 돈이다.
        $adjDeposit = $adjOf(SettlementPayoutAdjustment::KIND_DEPOSIT);

        $vehicles = $settlements->map(fn (Settlement $s) => [
            'id' => $s->id,
            'vehicle_number' => $s->vehicle?->vehicle_number ?? ('#'.$s->vehicle_id),
            'actual_payout' => (int) $s->actual_payout,
            // 🧾 월정산 제출 때 실지급(2026-10-21) — 다르면 「제출 뒤 바뀐 정산」. 제출 전(미리보기)·옛 배치는 null.
            'submitted_payout' => $s->payout_submitted_krw,
            'total_margin' => (int) $s->total_margin,
            'margin_rate' => $s->margin_rate,
            'is_domestic' => (bool) $s->is_domestic,
            // 승인 판단용 — 정산방식(승인 페이지가 종전부터 보여 주던 것)
            'type_label' => $s->isBuyerRatio()
                ? __('payout_batch.type_buyer_ratio', ['split' => Buyer::SETTLEMENT_RATIO_OPTIONS[$s->effective_ratio] ?? $s->effective_ratio.'%'])
                : ($s->settlement_type === 'ratio' ? __('payout_batch.type_ratio', ['ratio' => $s->effective_ratio]) : __('payout_batch.type_per_unit')),
        ])->values()->all();

        $base = [
            'salesman_id' => $sm->id, 'name' => $sm->name, 'type' => $type, 'type_label' => $sm->type_label, 'month' => $month,
            'count' => $settlements->count(), 'vehicles' => $vehicles,
            'payroll' => $type === 'freelance' ? null : $payrollTotal,
            'payroll_items' => $type === 'freelance' ? [] : ($payrollItems ?? []),   // 기입된 항목만(행 없는 항목은 안 실린다)
            'incentive' => $incentive, 'adj_manual' => $adjManual, 'adj_carry' => $adjCarry, 'adj_deposit' => $adjDeposit,
            'deposit' => $type === 'freelance' ? $sm->deposit_krw : null,   // 보유액 표시(월정산에선 지급액 무관 · 퇴사 정산은 adj_deposit 줄로 반환)
            'tier' => (bool) $sm->per_unit_tier_enabled,
            'margin_rate' => Settlement::marginRateOf($settlements),   // Σ총마진 ÷ Σ판매금원화 — 월정산·승인 두 화면이 같은 식
            'unsupported' => false,
            // 2026-10-21 — 헤이맨 정산금·퇴직연금·판매수당 대조. 옛 박제엔 이 키들이 없다(카드는 없으면 안 그린다).
            'heyman_pay' => $heymanPay, 'pension_on' => $pensionOn, 'pension' => 0,
            'sales_commission' => $salesCommission, 'sales_commission_expected' => null, 'sales_commission_diff' => null,
        ];

        if (in_array($type, Salesman::PAYROLL_ONLY_TYPES, true)) {   // 검차직원·직원 = 급여만
            return $base + [
                'equiv_sale_rate' => null, 'fx_primary' => null, 'carry' => null, 'fx_secondary' => null, 'equiv_total' => null,
                'settlement_pay' => 0, 'payout' => $payroll + $incentive + $adjManual + $adjDeposit,
                'total_margin' => 0, 'shipping' => 0, 'company_contribution' => null,
                'margin_after_pay' => null, 'excess_ratio' => null,
            ];
        }

        if (Setting::isKaraba()) {
            $payout = (int) $settlements->sum(fn (Settlement $s) => (int) $s->actual_payout) + $adjManual + $adjCarry + $incentive + $payroll + $adjDeposit + $heymanPay;

            return $base + [
                'unsupported' => true,
                'equiv_sale_rate' => null, 'fx_primary' => null, 'carry' => null, 'fx_secondary' => null, 'equiv_total' => null,
                'settlement_pay' => (int) $settlements->sum(fn (Settlement $s) => (int) $s->actual_payout), 'payout' => $payout,
                'total_margin' => (int) $settlements->sum(fn (Settlement $s) => (int) $s->total_margin),
                'shipping' => (int) $settlements->sum(fn (Settlement $s) => (int) $s->shipping_fee),
                'company_contribution' => null, 'margin_after_pay' => null, 'excess_ratio' => null,
            ];
        }

        $totalMargin = (int) $settlements->sum(fn (Settlement $s) => (int) $s->total_margin);
        $shipping = (int) $settlements->sum(fn (Settlement $s) => (int) $s->shipping_fee);
        $equivSaleRate = (int) $settlements->sum(fn (Settlement $s) => $s->freelanceEquivalentPayout((float) ($s->vehicle?->exchange_rate ?? 0)));
        $equivEffective = (int) $settlements->sum(fn (Settlement $s) => $s->freelanceEquivalentPayout());
        $fxPrimary = $equivEffective - $equivSaleRate;

        [$closedCarry, $closedFx] = self::closedInMonth($sm, $month, $type);

        if ($type === 'freelance') {
            $carryIn = (int) $settlements->sum(fn (Settlement $s) => (int) round((float) ($s->carryover_in_krw ?? 0)));
            $carry = $carryIn + $adjCarry;
            $equivTotal = $equivEffective + $carryIn + $adjCarry + $adjManual;   // = Σactual_payout + Σ조정(예치금 반환 제외) (닫힘)
            $payoutCost = $equivTotal + $incentive;            // 회사가 부담하는 지급
            $pension = $pensionOn ? self::pensionOf($payoutCost + $heymanPay) : 0;
            $payout = $payoutCost + $heymanPay - $pension + $adjDeposit;   // 실제 송금 = 거기에 헤이맨 몫·예치금 반환, 연금 몫은 따로

            return array_replace($base, ['pension' => $pension, 'payout_cost' => $payoutCost]) + [
                'equiv_sale_rate' => $equivSaleRate, 'fx_primary' => $fxPrimary, 'carry' => $carry, 'fx_secondary' => $closedFx,
                'equiv_total' => $equivTotal, 'settlement_pay' => $equivTotal, 'payout' => $payout,
                'total_margin' => $totalMargin, 'shipping' => $shipping,
                'company_contribution' => $totalMargin - $payoutCost - $shipping,
                'margin_after_pay' => null, 'excess_ratio' => null,
            ];
        }

        // 사내직원
        $settlementPay = (int) $settlements->sum(fn (Settlement $s) => (int) $s->actual_payout);
        $equivTotal = $equivEffective + $closedCarry + $adjManual;
        $payoutCost = $payroll + $settlementPay + $adjManual + $adjCarry + $incentive;   // 회사가 부담하는 지급(판매수당 뺀 급여 — 위)
        $settleSide = $settlementPay + $heymanPay + $adjManual + $adjCarry + $incentive;  // 급여대장 「판매수당」에 실리는 몫(1/12 전)
        $pension = $pensionOn ? self::pensionOf($payroll + $settleSide) : 0;
        $payout = $payoutCost + $heymanPay - $pension + $adjDeposit;                     // 그 사람 통장으로 가는 송금(헤이맨 몫 포함 · 연금 몫 제외)
        $marginAfterPay = $equivTotal - $payoutCost;
        $expected = $settleSide - $pension;

        return array_replace($base, [
            'payroll' => $payrollTotal === null ? null : $payroll,
            'pension' => $pension,
            'payout_cost' => $payoutCost,   // 회사이익 산출에서 빼는 지급 = 실지급 − 헤이맨 몫 + 연금 몫 (예치금 반환 제외)
            'sales_commission_expected' => $expected,
            'sales_commission_diff' => $salesCommission === null ? null : $salesCommission - $expected,
        ]) + [
            'equiv_sale_rate' => $equivSaleRate, 'fx_primary' => $fxPrimary, 'carry' => $closedCarry, 'fx_secondary' => $closedFx,
            'equiv_total' => $equivTotal, 'settlement_pay' => $settlementPay, 'payout' => $payout,
            'total_margin' => $totalMargin, 'shipping' => $shipping,
            'company_contribution' => $totalMargin - $payoutCost - $shipping,
            'margin_after_pay' => $marginAfterPay,
            'excess_ratio' => $payoutCost > 0 ? round($marginAfterPay / $payoutCost, 1) : null,
            'base_pay' => $payroll + $settlementPay,
            'profit_before_incentive' => $totalMargin - ($payroll + $settlementPay) - $shipping,
            'profit_multiple' => self::profitMultiple($totalMargin - ($payroll + $settlementPay) - $shipping, $payroll + $settlementPay),
        ];
    }

    /**
     * 이익배수 = 인센티브 전 회사이익 ÷ (급여 + 정산금) (jin 2026-10-12).
     * 「이 담당자가 받을 돈(급여+정산금) 대비 회사에 이익을 얼마나 줬나」 — 추가 인센티브를 정하는 근거라 **인센티브·수기·이월을 빼기 전**으로 본다.
     * 분모 ≤ 0 이면 null(「—」). 1자리.
     * 🔑 카드·정산관리 요약·관리자 대시보드가 전부 이걸 부른다. 옛 박제(`breakdown_snapshot`)엔 이 키가 없어 카드가 payroll·settlement_pay·total_margin·shipping 으로 되살린다.
     */
    /**
     * 🔎 급여대장 판매수당 대조 (jin 2026-10-21) — 업로드 미리보기가 부른다. 월정산 카드의 `sales_commission_diff` 와 **같은 식**.
     *
     * ERP 기대값 = 그 달 (정산금 + 헤이맨 정산금 + 조정·인센티브) − 퇴직연금 1/12(토글 켠 사람만, 기준에 급여대장의 판매수당 뺀 급여 포함).
     * 그 달 월정산(진행 중·승인)이 있으면 그 배치로, 없으면 제출 전 미리보기(제출 대상 정산)로 센다.
     *
     * @param  array<int, array<string,int>>  $sheets  salesman_id => 급여대장 항목(label => amount)
     * @return array<int, array{sheet:int, expected:int, diff:int, pension_on:bool, pension:int}> 판매수당 줄이 있는 사내직원만
     */
    public static function commissionCheckFor(string $month, array $sheets): array
    {
        return self::commissionCheckWith(self::commissionBaseFor($month), $sheets);
    }

    /**
     * 그 달 사내직원별 대조 기준 — salesman_id => [settle(정산금 + 헤이맨 + 조정·인센티브), pension_on].
     * 한 달 배치를 통째로 계산하므로 **무겁다** — 급여대장 미리보기는 파일을 읽을 때 한 번만 부르고 배열로 들고 있는다(§8 #96-C).
     *
     * @return array<int, array{settle:int, pension_on:bool}>
     */
    public static function commissionBaseFor(string $month): array
    {
        $batch = SettlementPayoutBatch::monthly()->where('month', $month)
            ->whereIn('status', [SettlementPayoutBatch::STATUS_PENDING, SettlementPayoutBatch::STATUS_APPROVED])
            ->orderByDesc('id')->first();
        $bd = $batch
            ? BatchPayoutBreakdown::forBatch($batch)
            : BatchPayoutBreakdown::forMonthPreview($month, Settlement::query()
                ->whereIn('id', SettlementPayoutBatch::eligibleSettlementIds($month))
                ->with(['salesman', 'vehicle.finalPayments', 'vehicle.receivableHistories'])->get());
        $byId = collect($bd['people'])->keyBy('salesman_id');
        $external = ExternalSettlementPay::query()->where('month', $month)->selectRaw('salesman_id, SUM(amount) as t')->groupBy('salesman_id')->pluck('t', 'salesman_id');

        $out = [];
        foreach (Salesman::query()->where('type', 'employee')->get(['id', 'pension_split_enabled']) as $sm) {
            $p = $byId->get($sm->id);
            $settle = $p ? (int) $p['settlement_pay'] + (int) $p['adj_manual'] + (int) $p['adj_carry'] + (int) $p['incentive'] : 0;
            $out[(int) $sm->id] = ['settle' => $settle + (int) ($external[$sm->id] ?? 0), 'pension_on' => (bool) $sm->pension_split_enabled];
        }

        return $out;
    }

    /**
     * 기준(commissionBaseFor) × 급여대장 항목 → 대조. 계산만 한다(쿼리 없음).
     *
     * @param  array<int, array{settle:int, pension_on:bool}>  $base
     * @param  array<int, array<string,int>>  $sheets
     * @return array<int, array{sheet:int, expected:int, diff:int, pension_on:bool, pension:int}>
     */
    public static function commissionCheckWith(array $base, array $sheets): array
    {
        $out = [];
        foreach ($sheets as $id => $items) {
            if (! isset($base[$id]) || ! array_key_exists(PayrollEntry::SALES_COMMISSION, $items)) {
                continue;
            }
            $sheet = (int) $items[PayrollEntry::SALES_COMMISSION];
            $payrollEx = (int) array_sum($items) - $sheet;
            $on = $base[$id]['pension_on'];
            $pension = $on ? self::pensionOf($payrollEx + $base[$id]['settle']) : 0;
            $expected = $base[$id]['settle'] - $pension;
            $out[(int) $id] = ['sheet' => $sheet, 'expected' => $expected, 'diff' => $sheet - $expected, 'pension_on' => $on, 'pension' => $pension];
        }

        return $out;
    }

    /** 퇴직연금 전환액 = 기준 ÷ 12 (원 단위 반올림). 9월 존 7,109,642 → 592,470(급여대장과 1원까지 같음). 기준 ≤ 0 이면 0. */
    public static function pensionOf(int $base): int
    {
        return $base > 0 ? (int) round($base / 12) : 0;
    }

    public static function profitMultiple(?int $profitBeforeIncentive, ?int $basePay): ?float
    {
        if ($profitBeforeIncentive === null || $basePay === null || $basePay <= 0) {
            return null;
        }

        return round($profitBeforeIncentive / $basePay, 1);
    }

    /**
     * 이 달에 2차 마감된 이 사람의 정산 — [이월, 그중 환차].
     * 프리랜서 = 실제 secondaryBreakdown(fx 는 정보, 이월은 호출부가 carryover_in 으로 센다) → [0, Σfx]
     * 사내직원 = 가상(프리랜서였다면) = [Σ(Δ총마진 × 비율), Σ(Δ판매금원화×0.9 × 비율)]
     *
     * @return array{0:int,1:int}
     */
    private static function closedInMonth(Salesman $sm, string $month, string $type): array
    {
        $rows = Settlement::query()
            ->where('salesman_id', $sm->id)
            ->where('secondary_status', 'closed')
            ->whereNotNull('secondary_closed_at')
            ->where('secondary_closed_at', '>=', $month.'-01 00:00:00')
            ->where('secondary_closed_at', '<', date('Y-m-01 00:00:00', strtotime($month.'-01 +1 month')))
            ->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'salesman'])
            ->get();

        $carry = 0;
        $fx = 0;
        foreach ($rows as $s) {
            if ($type === 'freelance') {
                $fx += (int) ($s->secondaryBreakdown()['fx'] ?? 0);

                continue;
            }
            $d = $s->secondaryDeltas();
            if ($d === null) {
                continue;
            }
            $ratio = $s->effective_ratio / 100;
            $carry += (int) round($d['all'] * $ratio);
            $fx += (int) round($d['fx'] * $ratio);
        }

        return [$carry, $fx];
    }
}
