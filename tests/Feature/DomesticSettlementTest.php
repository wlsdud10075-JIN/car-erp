<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 내수(국내 판매) 정산 — jin 2026-09-08.
 *
 *   기준액   = 총판매가 − (매입가 + 말소비 + 탁송비)
 *   프리랜서 = 기준액 × 정산비율 − 서류비 50,000
 *   사내직원 = 기존 tier 그대로, 입력만 기준액
 *   회사 몫  = 기준액 − 실지급액
 *
 * 🔑 분기는 `Settlement::total_margin` **한 곳**에만 둔다 — 정산액·서류비·실지급액·회사몫이
 *    전부 그걸 통해 계산되므로 별도 경로를 만들면 반드시 갈린다(SKILLS §8 #45).
 */
class DomesticSettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function salesman(string $type, bool $tier = false): Salesman
    {
        return Salesman::create([
            'name' => 'S'.++$this->n, 'is_active' => true, 'type' => $type,
            'per_unit_tier_enabled' => $tier,
        ]);
    }

    private function buyer(Salesman $s, bool $domestic = true): Buyer
    {
        return Buyer::create([
            'name' => 'B'.++$this->n, 'is_active' => true,
            'salesman_id' => $s->id, 'is_domestic' => $domestic,
        ]);
    }

    /** 매입 1,000만 · 말소 2.4만 · 탁송 20만 · 판매가 지정. 완납까지 만들어 정산을 띄운다. */
    private function soldVehicle(
        Salesman $s,
        Buyer $b,
        int $salePrice = 13_000_000,
        string $currency = 'KRW',
        array $extra = [],
    ): Vehicle {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => '22가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'currency' => $currency,
            'exchange_rate' => 1,
            'dhl_request' => false,
            'salesman_id' => $s->id,
            'buyer_id' => $b->id,
            'purchase_price' => 10_000_000,
            'cost_deregistration' => 24_000,
            'cost_towing' => 200_000,
            'sale_price' => $salePrice,
            'sale_date' => now()->toDateString(),
        ], $extra));

        // 완납시킨다 — 정산 자동생성 조건(완납 + 담당자 + 운임게이트).
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance',
            'amount' => $v->fresh()->sale_total_amount,
            'exchange_rate' => 1,
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
        ]);

        return $v->fresh();
    }

    private function settlementOf(Vehicle $v): Settlement
    {
        $s = $v->settlements()->first();
        $this->assertNotNull($s, '완납되면 정산이 자동 생성돼야 한다');

        return $s;
    }

    // ── 기준액 ───────────────────────────────────────────────────────────

    public function test_domestic_base_is_sale_total_minus_purchase_and_two_costs(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $v = $this->soldVehicle($sm, $this->buyer($sm));
        $s = $this->settlementOf($v);

        $this->assertTrue($s->is_domestic, '내수 바이어면 정산에 내수가 박제돼야 한다');
        // 13,000,000 − (10,000,000 + 24,000 + 200,000)
        $this->assertSame(2_776_000, $s->total_margin);
    }

    /** 🚫 비용 10칸 중 말소·탁송 두 칸만 이다 — 나머지를 넣어도 기준액이 안 변한다. */
    public function test_other_eight_cost_columns_do_not_touch_the_domestic_base(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $v = $this->soldVehicle($sm, $this->buyer($sm), extra: [
            'cost_license' => 500_000, 'cost_carry' => 300_000, 'cost_shoring' => 100_000,
            'cost_insurance' => 70_000, 'cost_transfer' => 60_000,
            'cost_extra1' => 50_000, 'cost_extra2' => 40_000,
        ]);

        $this->assertSame(2_776_000, $this->settlementOf($v)->total_margin);
    }

    /** 🚫 매도비(selling_fee)는 기준액에서 빼지 않는다 (jin 명시). */
    public function test_selling_fee_is_not_subtracted_from_the_domestic_base(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $v = $this->soldVehicle($sm, $this->buyer($sm), extra: ['selling_fee' => 300_000]);

        $this->assertSame(2_776_000, $this->settlementOf($v)->total_margin);
    }

    /** 🚫 부가세마진도 ×0.9 도 안 탄다 — 수출 공식과 완전히 별개다. */
    public function test_domestic_skips_vat_margin_and_the_ten_percent_deduction(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $export = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm, domestic: false)));
        $domestic = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm)));

        // 수출: (판매마진 2,776,000 + 부가세마진 900,000) × 0.9 = 3,308,400
        $this->assertSame(3_308_400, $export->total_margin);
        $this->assertSame(2_776_000, $domestic->total_margin);
    }

    // ── 정산액 ───────────────────────────────────────────────────────────

    public function test_freelancer_takes_half_and_pays_the_document_fee(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm)));

        $this->assertSame(1_388_000, $s->settlement_amount);   // 2,776,000 × 50%
        $this->assertSame(50_000, $s->document_fee);
        $this->assertSame(1_338_000, $s->actual_payout);
        $this->assertSame(1_438_000, $s->company_net);         // 2,776,000 − 1,338,000
    }

    /** 차등정산 직원 — 기존 tier 를 그대로 타되 입력만 내수 기준액이다. */
    public function test_tiered_employee_uses_the_same_tier_on_the_domestic_base(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('employee', tier: true);
        // 기준액 2,776,000 ≥ 100만 → 20만
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm)));
        $this->assertSame(200_000, $s->settlement_amount);
        $this->assertSame(0, $s->document_fee, '사내직원은 서류비를 안 뗀다');

        // 기준액 224,000 (< 100만) → 10만
        $s2 = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm), salePrice: 10_448_000));
        $this->assertSame(224_000, $s2->total_margin);
        $this->assertSame(100_000, $s2->settlement_amount);

        // 매입합계 1억 이상 + 기준액 0 이상 → 25%  (매입합계는 매입가+매도비 — 내수에서도 이 축은 종전대로)
        $s3 = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm),
            salePrice: 103_000_000, extra: ['purchase_price' => 100_000_000]));
        $this->assertSame(2_776_000, $s3->total_margin);         // 103,000,000 − 100,224,000
        $this->assertSame(694_000, $s3->settlement_amount);      // × 25%
    }

    /** 차등이 꺼진 사내직원은 종전대로 건당 10만원 고정이다 — 내수에서도 같다. */
    public function test_plain_employee_gets_the_flat_per_unit_amount(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('employee');
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm)));

        $this->assertSame(2_776_000, $s->total_margin);
        $this->assertSame(100_000, $s->settlement_amount);
    }

    /** 손해면 프리랜서는 그만큼 물고, 사내직원은 0 이다. */
    public function test_a_loss_is_shared_by_the_freelancer_and_zero_for_the_employee(): void
    {
        $this->actingAs($this->finance());
        $free = $this->salesman('freelance');
        $emp = $this->salesman('employee');

        $sf = $this->settlementOf($this->soldVehicle($free, $this->buyer($free), salePrice: 9_000_000));
        $this->assertSame(-1_224_000, $sf->total_margin);
        $this->assertSame(-612_000, $sf->settlement_amount);

        $se = $this->settlementOf($this->soldVehicle($emp, $this->buyer($emp), salePrice: 9_000_000));
        $this->assertSame(-1_224_000, $se->total_margin);
        $this->assertSame(0, $se->settlement_amount, '사내직원은 손실을 안 짊어진다');
    }

    // ── 박제 ─────────────────────────────────────────────────────────────

    /**
     * 🔑 바이어의 내수 체크를 나중에 풀어도 과거 정산은 안 뒤집힌다.
     *    매번 바이어를 보고 판정하면 조용히 일반정산으로 바뀐다(담당자 승계에서 겪은 형태).
     */
    public function test_unchecking_the_buyer_later_does_not_flip_past_settlements(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $b = $this->buyer($sm);
        $s = $this->settlementOf($this->soldVehicle($sm, $b));
        $this->assertSame(2_776_000, $s->total_margin);

        $b->update(['is_domestic' => false]);

        $this->assertSame(2_776_000, $s->fresh()->total_margin, '과거 정산은 내수 그대로여야 한다');
        $this->assertTrue($s->fresh()->is_domestic);
    }

    // ── 원화 전용 ─────────────────────────────────────────────────────────

    public function test_domestic_buyer_cannot_be_used_on_a_foreign_currency_vehicle(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $b = $this->buyer($sm);
        $v = new Vehicle([
            'vehicle_number' => '99가9999', 'sales_channel' => 'export', 'currency' => 'EUR',
            'exchange_rate' => 1500, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'sale_price' => 10_000, 'sale_date' => now()->toDateString(),
        ]);

        $this->expectException(ValidationException::class);
        $v->guardDomesticCurrency();
    }

    /** 원화면 통과한다 — 무관한 저장을 막지 않는다. */
    public function test_krw_vehicle_with_a_domestic_buyer_passes_the_guard(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $v = $this->soldVehicle($sm, $this->buyer($sm));

        $v->guardDomesticCurrency();
        $this->assertTrue(true);
    }

    /**
     * 적재·시드가 가드를 우회해 외화 차량에 내수 바이어를 붙였더라도,
     * 정산에는 내수를 안 찍는다 — 외화 금액이 원화로 오인돼 계산되는 걸 막는다.
     */
    public function test_foreign_currency_is_never_stamped_as_domestic(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        // 외화는 운임 게이트가 인코텀즈를 요구한다 — FOB 로 통과시켜 정산까지 만든다.
        $v = $this->soldVehicle($sm, $this->buyer($sm), salePrice: 13_000, currency: 'EUR',
            extra: ['incoterms' => 'FOB']);
        $s = $this->settlementOf($v);

        $this->assertFalse($s->is_domestic);
    }

    /** 마진율 KPI 의 분모 — 내수는 총판매가가 곧 매출이다(환율·커미션 분해 없음). */
    public function test_domestic_sales_amount_is_the_total_sale_price(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm)));

        $this->assertSame(13_000_000, $s->sales_amount_krw);
    }

    /**
     * 회사이익은 세 화면에 복제돼 있지만 전부 `company_net`/`total_margin` 을 통해 계산된다 —
     * 내수 분기가 그 하나에만 있으므로 세 곳이 갈릴 수 없다. 그 불변식을 여기서 박제한다.
     */
    public function test_company_net_follows_the_domestic_base_without_a_second_formula(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm)));

        $this->assertSame(
            $s->total_margin - $s->actual_payout - $s->shipping_fee,
            $s->company_net,
        );
        $this->assertSame(2_776_000, $s->total_margin + 0);
        $this->assertSame(0, $s->shipping_fee, '내수는 EMS·DHL 발송이 없어 발송비가 0 이다');
    }
}
