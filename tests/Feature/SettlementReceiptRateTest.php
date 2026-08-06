<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 1차 정산을 **실제 입금환율**로 계산 (jin 2026-08-06).
 *
 * 구: 1차는 판매환율로 계산하고 실입금과의 차이는 2차 마감에서 환차로 1:1 가산.
 * 신: 판매금원화의 환율 자체가 실효 입금환율 → 환차가 마진공식(×0.9×비율)을 그대로 통과.
 *
 * 기준 케이스는 운영(ssancarerp) 실차 **18누0304** 다. jin/재무가 "373,259원이 맞다"고 확정한 건이라
 * 합성 데이터가 아니라 이 숫자를 그대로 박아 둔다.
 *
 *   EUR / 판매환율 1716 / 잔금 5,000 + 3,996 전부 @1718 (완납)
 *   판매가 7,837 · 비용 71,390 · 매입 13,200,000 + 매도비 440,000 · 프리랜서 50%
 *
 *   7,837 × 1718 = 13,463,966 → −71,390 → −13,640,000 → +1,188,000(부가세마진)
 *   → ×0.9 = 846,518(총마진) → ×50% = 423,259(정산액) → −50,000(서류비) = 373,259(실지급액)
 */
class SettlementReceiptRateTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    /** 18누0304 재현 — 잔금 환율만 파라미터로 뺀다. */
    private function makeVehicle(array $overrides = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => 'RCP-'.++$this->counter,
            'sales_channel' => 'export',
            'dhl_request' => false,
            'currency' => 'EUR',
            'exchange_rate' => 1716,
            'sale_price' => 7837,
            'transport_fee' => 1159,
            'sale_date' => '2026-08-01',
            'purchase_date' => '2026-07-01',
            'purchase_price' => 13_200_000,
            'selling_fee' => 440_000,
            'cost_deregistration' => 24_000,
            'cost_license' => 14_740,
            'cost_towing' => 25_000,
            'cost_insurance' => 7_650,
        ], $overrides));
    }

    private function pay(Vehicle $v, float $amount, ?float $rate): void
    {
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'type' => 'balance',
            'amount' => $amount,
            'exchange_rate' => $rate,
            'payment_date' => '2026-08-05',
            'confirmed_at' => now(),
        ]);
    }

    private function settle(Vehicle $v, string $type = 'ratio'): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => $type,
            'settlement_ratio' => $type === 'ratio' ? 50 : null,
            'per_unit_amount' => $type === 'per_unit' ? 100_000 : null,
            'settlement_status' => 'pending',
        ]);
    }

    public function test_reproduces_the_confirmed_figures_of_18nu0304(): void
    {
        $v = $this->makeVehicle();
        $this->pay($v, 5000, 1718);
        $this->pay($v, 3996, 1718);
        $v->refresh();

        $this->assertSame(0.0, (float) $v->sale_unpaid_amount, '완납이어야 실효환율이 적용된다');
        $this->assertSame(1718.0, round($v->settlement_exchange_rate, 4));

        $s = $this->settle($v);

        $this->assertSame(13_463_966, $s->sales_amount_krw);
        $this->assertSame(846_518, $s->total_margin);
        $this->assertSame(423_259, $s->settlement_amount);
        $this->assertSame(373_259, $s->actual_payout, 'jin/재무 확정값');
    }

    /** 판매환율 그대로였다면 366,205 — 차액 +7,054 가 환차의 1차 반영분이다. */
    public function test_sale_rate_baseline_for_comparison(): void
    {
        $v = $this->makeVehicle();
        $this->pay($v, 5000, 1716);
        $this->pay($v, 3996, 1716);
        $v->refresh();

        $s = $this->settle($v);

        $this->assertSame(1716.0, round($v->settlement_exchange_rate, 4));
        $this->assertSame(366_205, $s->actual_payout);
    }

    /**
     * 🚨 미완납이면 판매환율로 떨어져야 한다.
     * 실효환율(실입금÷총판매가)을 그대로 쓰면 절반만 입금됐을 때 환율이 반토막 나
     * 원금 미수가 환율로 둔갑한다.
     */
    public function test_falls_back_to_sale_rate_while_unpaid(): void
    {
        $v = $this->makeVehicle();
        $this->pay($v, 4000, 1718);   // 8,996 중 4,000 만
        $v->refresh();

        $this->assertGreaterThan(0, (float) $v->sale_unpaid_amount);
        $this->assertSame(1716.0, round($v->settlement_exchange_rate, 4), '미완납인데 실효환율을 썼다');
        $this->assertSame(366_205, $this->settle($v)->actual_payout);
    }

    /** 입금이 하나도 없을 때 0 으로 나누지 않는다 — 3사 정산 목록이 통째로 죽는 지점. */
    public function test_no_division_by_zero_without_any_receipt(): void
    {
        $v = $this->makeVehicle();

        $this->assertSame(1716.0, round($v->settlement_exchange_rate, 4));
        $this->assertSame(366_205, $this->settle($v)->actual_payout);
    }

    /** 판매가가 없어 총판매가 0 이어도 안전해야 한다. */
    public function test_no_division_by_zero_without_sale_amount(): void
    {
        $v = $this->makeVehicle(['sale_price' => 0, 'transport_fee' => 0, 'sale_date' => null]);

        $this->assertSame(1716.0, round($v->settlement_exchange_rate, 4));
    }

    /** KRW 차량은 환차 개념이 없다 — 판매환율(1) 고정. */
    public function test_krw_vehicle_keeps_sale_rate(): void
    {
        $v = $this->makeVehicle(['currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 5_000_000, 'transport_fee' => 0]);
        $this->pay($v, 5_000_000, null);
        $v->refresh();

        $this->assertSame(1.0, round($v->settlement_exchange_rate, 4));
    }

    /** 잔금에 환율이 없으면 판매환율로 평가된다 — 실효환율도 판매환율이 된다(변동 없음). */
    public function test_null_payment_rate_evaluates_at_sale_rate(): void
    {
        $v = $this->makeVehicle();
        $this->pay($v, 5000, null);
        $this->pay($v, 3996, null);
        $v->refresh();

        $this->assertSame(1716.0, round($v->settlement_exchange_rate, 4));
        $this->assertSame(366_205, $this->settle($v)->actual_payout);
    }

    /**
     * 사내직원(건당제)은 정산액이 총마진과 무관해 환율이 바뀌어도 지급액이 안 움직인다.
     * jin 2026-08-06: "사내직원은 건당 고정이니 그대로지뭐."
     */
    public function test_per_unit_payout_is_unaffected_by_receipt_rate(): void
    {
        $v = $this->makeVehicle();
        $this->pay($v, 5000, 1718);
        $this->pay($v, 3996, 1718);
        $v->refresh();

        $s = $this->settle($v, 'per_unit');

        $this->assertSame(100_000, $s->settlement_amount);
        $this->assertSame(100_000, $s->actual_payout);
    }

    /** 운임비(1,159 EUR)의 환차는 정산 base 밖이라 담당자에게 안 간다 = 회사 몫. */
    public function test_transport_fee_fx_gain_stays_with_the_company(): void
    {
        $v = $this->makeVehicle();
        $this->pay($v, 5000, 1718);
        $this->pay($v, 3996, 1718);
        $v->refresh();

        // 총 실현 환차 = 8,996 × 2 = 17,992 (운임비 포함)
        $realised = $v->sale_received_krw_accumulated - (int) ($v->sale_total_amount * 1716);
        $this->assertSame(17_992, $realised);

        // 1차에 반영된 몫은 정산 base(7,837)분 뿐 — 담당자 지급 증가분은 7,054.
        $this->assertSame(373_259 - 366_205, 7_054);
    }
}
