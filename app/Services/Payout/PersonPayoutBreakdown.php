<?php

namespace App\Services\Payout;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use Illuminate\Support\Collection;

/**
 * 🧮 **사람 한 명의 월정산 계산 — 단일 출처** (월정산 v3, jin 2026-10-08 확정 · 2026-10-09 구현).
 *
 * 정산관리 담당자별 카드 · 월정산 카드 · 폰 승인 링크 · 관리자 대시보드가 **전부 이것만** 부른다(§8 #44·#45 — 공식 복제 금지).
 *
 * 사내직원(employee)
 *   환산 합계   = 당월 프리랜서 공식 정산(판매환율) + ① 1차 환차 + 이월(가상) + 수기 추가정산          … 「프리랜서였다면 벌어 갔을 돈」
 *   실지급액    = 급여 지급합계 + 정산금(건당·차등, Σactual_payout) + 수기 조정 + 추가 인센티브
 *   급여공제후 마진 = 환산 합계 − 실지급액                                                        … 성과 판단(jin)
 *   초과 배율   = (환산 합계 − 실지급액) ÷ 실지급액  (부호 항상, 0 = 본전)
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
 * karaba 는 정산 공식이 달라(구간표) `unsupported` 로 돌려준다 — 2사 먼저(jin).
 */
final class PersonPayoutBreakdown
{
    /**
     * @param  Collection<int, Settlement>  $settlements  이 사람의 이번 배치(또는 달) 정산. vehicle.finalPayments·receivableHistories 로드 권장
     * @param  Collection<int, SettlementPayoutAdjustment>  $adjustments  이 사람의 배치 조정(kind 포함)
     * @param  int|null  $payrollTotal  그 달 급여 지급합계(null = 미입력). 생략하면 PayrollEntry 에서 읽는다
     */
    public static function build(Salesman $sm, string $month, Collection $settlements, Collection $adjustments, ?int $payrollTotal = null, bool $loadPayroll = true): array
    {
        $type = $sm->type ?? 'employee';
        if ($payrollTotal === null && $loadPayroll && $type !== 'freelance') {
            $payrollTotal = PayrollEntry::totalFor($sm->id, $month);
        }
        $payroll = $type === 'freelance' ? 0 : (int) ($payrollTotal ?? 0);

        $adjOf = fn (string $kind): int => (int) $adjustments->where('kind', $kind)->sum('amount');
        $incentive = $adjOf(SettlementPayoutAdjustment::KIND_INCENTIVE);
        $adjCarry = $adjOf(SettlementPayoutAdjustment::KIND_CARRYOVER);
        $adjManual = $adjOf(SettlementPayoutAdjustment::KIND_MANUAL) + $adjOf(SettlementPayoutAdjustment::KIND_LOSS);

        $vehicles = $settlements->map(fn (Settlement $s) => [
            'id' => $s->id,
            'vehicle_number' => $s->vehicle?->vehicle_number ?? ('#'.$s->vehicle_id),
            'actual_payout' => (int) $s->actual_payout,
            'total_margin' => (int) $s->total_margin,
            'margin_rate' => $s->margin_rate,
            'is_domestic' => (bool) $s->is_domestic,
        ])->values()->all();

        $base = [
            'salesman_id' => $sm->id, 'name' => $sm->name, 'type' => $type, 'month' => $month,
            'count' => $settlements->count(), 'vehicles' => $vehicles,
            'payroll' => $type === 'freelance' ? null : $payrollTotal,
            'incentive' => $incentive, 'adj_manual' => $adjManual, 'adj_carry' => $adjCarry,
            'unsupported' => false,
        ];

        if ($type === 'inspector') {
            return $base + [
                'equiv_sale_rate' => null, 'fx_primary' => null, 'carry' => null, 'fx_secondary' => null, 'equiv_total' => null,
                'settlement_pay' => 0, 'payout' => $payroll + $incentive + $adjManual,
                'total_margin' => 0, 'shipping' => 0, 'company_contribution' => null,
                'margin_after_pay' => null, 'excess_ratio' => null,
            ];
        }

        if (Setting::isKaraba()) {
            $payout = (int) $settlements->sum(fn (Settlement $s) => (int) $s->actual_payout) + $adjManual + $adjCarry + $incentive + $payroll;

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
            $equivTotal = $equivEffective + $carryIn + $adjCarry + $adjManual;   // = Σactual_payout + Σ조정 (닫힘)
            $payout = $equivTotal + $incentive;

            return $base + [
                'equiv_sale_rate' => $equivSaleRate, 'fx_primary' => $fxPrimary, 'carry' => $carry, 'fx_secondary' => $closedFx,
                'equiv_total' => $equivTotal, 'settlement_pay' => $equivTotal, 'payout' => $payout,
                'total_margin' => $totalMargin, 'shipping' => $shipping,
                'company_contribution' => $totalMargin - $payout - $shipping,
                'margin_after_pay' => null, 'excess_ratio' => null,
            ];
        }

        // 사내직원
        $settlementPay = (int) $settlements->sum(fn (Settlement $s) => (int) $s->actual_payout);
        $equivTotal = $equivEffective + $closedCarry + $adjManual;
        $payout = $payroll + $settlementPay + $adjManual + $adjCarry + $incentive;
        $marginAfterPay = $equivTotal - $payout;

        return $base + [
            'equiv_sale_rate' => $equivSaleRate, 'fx_primary' => $fxPrimary, 'carry' => $closedCarry, 'fx_secondary' => $closedFx,
            'equiv_total' => $equivTotal, 'settlement_pay' => $settlementPay, 'payout' => $payout,
            'total_margin' => $totalMargin, 'shipping' => $shipping,
            'company_contribution' => $totalMargin - $payout - $shipping,
            'margin_after_pay' => $marginAfterPay,
            'excess_ratio' => $payout > 0 ? round($marginAfterPay / $payout, 1) : null,
        ];
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
