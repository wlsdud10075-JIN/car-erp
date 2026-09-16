<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
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

    /** 내수 토글은 [관리] 이상만 본다(정산 공식을 바꾸므로) — 재무로는 그 블록이 아예 안 그려진다. */
    private function approver(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
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

    // ── 통화가 아직 원화가 아닐 때 ────────────────────────────────────────

    /**
     * 🔀 **저장은 된다** (jin 2026-09-16).
     *
     * 예전엔 외화 차량에 내수 바이어를 붙이면 저장이 막혔다. 그런데 jin 의 실제 작업 순서가
     * 그 반대였다 — *«입력된것이 usd로 해놓은게 있거든 … 어차피 정산으로 되기전까지는 krw로 바뀔거거든?»*
     * 먼저 내수로 묶어 두고 통화는 나중에 정리한다. 막으면 그 순서가 통째로 불가능해진다.
     *
     * 🚫 저장 차단을 되살리지 말 것 — 돈은 정산 박제가 지킨다(아래 테스트).
     */
    public function test_a_foreign_currency_vehicle_can_be_saved_with_a_domestic_buyer(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $b = $this->buyer($sm);

        $v = Vehicle::create([
            'vehicle_number' => '99가9999', 'sales_channel' => 'export', 'currency' => 'EUR',
            'exchange_rate' => 1500, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'sale_price' => 10_000, 'sale_date' => now()->toDateString(),
        ]);

        $this->assertTrue($b->fresh()->is_domestic, '전제가 안 선다 — 내수 바이어여야 한다');
        $this->assertDatabaseHas('vehicles', ['id' => $v->id, 'currency' => 'EUR', 'buyer_id' => $b->id]);
        $this->assertTrue($v->fresh()->isDomesticAwaitingKrw(), '「원화 대기」 상태로 표시돼야 한다');
    }

    /** 통화를 원화로 바꾸면 대기 상태가 풀린다 — 이게 jin 이 말한 「정산 전에 바뀐다」이다. */
    public function test_switching_the_currency_to_krw_clears_the_pending_state(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $v = $this->soldVehicle($sm, $this->buyer($sm));

        $this->assertFalse($v->fresh()->isDomesticAwaitingKrw(), '원화 차량은 대기 상태가 아니다');
        $this->assertTrue($v->fresh()->isDomesticSale());
    }

    /** 내수 바이어가 아니면 통화가 외화여도 대기 상태가 아니다 — 무관한 차에 경고가 붙으면 안 된다. */
    public function test_a_plain_export_vehicle_is_never_marked_pending(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $b = Buyer::create(['name' => '수출바이어', 'is_active' => true, 'salesman_id' => $sm->id]);

        $v = Vehicle::create([
            'vehicle_number' => '99가8888', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'sale_price' => 9_000, 'sale_date' => now()->toDateString(),
        ]);

        $this->assertFalse($v->fresh()->isDomesticAwaitingKrw());
    }

    /**
     * 🖥️ **화면이 그 상태를 말한다** — 막지 않기로 한 대가다(SKILLS §8 #60).
     *
     * 막던 시절엔 사람이 저장에 실패해서 알았다. 이제는 저장이 되므로, 화면이 말하지 않으면
     * 「내수로 묶어 놨는데 수출로 정산됐다」를 **정산이 나온 뒤에야** 알게 된다.
     */
    public function test_the_vehicle_list_marks_a_domestic_buyer_still_in_foreign_currency(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');

        $pending = Vehicle::create([
            'vehicle_number' => '99가7777', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $this->buyer($sm)->id,
            'sale_price' => 9_000, 'sale_date' => now()->toDateString(),
        ]);
        $settled = $this->soldVehicle($sm, $this->buyer($sm));   // 원화 — 평범한 내수

        $html = Volt::actingAs($this->finance())->test('erp.vehicles.index')->html();

        $this->assertTrue(str_contains($html, $pending->vehicle_number), '전제가 안 선다 — 목록에 있어야 한다');
        $this->assertTrue(str_contains($html, '내수(원화대기)'),
            '통화가 외화인 내수 차에 「원화대기」 표시가 없다 — 수출로 정산되는 걸 아무도 모른다');
        $this->assertTrue(str_contains($html, $settled->vehicle_number));
    }

    /** 바이어 화면도 같다 — 몇 대가 남았는지 그 자리에서 말한다. */
    public function test_the_buyer_screen_says_how_many_vehicles_are_still_foreign(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $b = $this->buyer($sm);
        Vehicle::create([
            'vehicle_number' => '99가6666', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'sale_price' => 9_000, 'sale_date' => now()->toDateString(),
        ]);

        $c = Volt::actingAs($this->approver())->test('erp.buyers.index')->call('openEdit', $b->id);

        $this->assertSame(1, $c->instance()->domesticForeignCount);
        $this->assertTrue(str_contains($c->html(), '원화가 아닌 차량이 1대'),
            '바이어 화면이 남은 대수를 말하지 않는다');
    }

    /**
     * 🔒 **저장 차단이 되살아나지 못하게** — 되돌려도 화면은 정상이고 「저장이 안 된다」는
     * 제보로만 드러난다. 그래서 정적으로 막는다.
     */
    public function test_the_save_time_block_is_not_reintroduced(): void
    {
        foreach (['app/Models/Vehicle.php',
            'resources/views/livewire/erp/vehicles/index.blade.php',
            'resources/views/livewire/erp/buyers/index.blade.php'] as $f) {
            $src = file_get_contents(base_path($f));
            $this->assertFalse(str_contains($src, 'guardDomesticCurrency'),
                "내수 원화 저장 차단이 되살아났다({$f}) — jin 2026-09-16 에 걷어낸 것이다");
            $this->assertFalse(str_contains($src, 'domestic.krw_only'),
                "막는 문구가 되살아났다({$f}) — 지금은 알리는 문구(awaiting_krw)만 쓴다");
        }
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

    // ── 0 원이면 정산 자체가 없다 ─────────────────────────────────────────

    /**
     * 🔑 내수는 「본전」이 기본이다 — 차액이 0 이면 **정산 행을 만들지 않는다** (jin 2026-09-08).
     *    0 원짜리 행이 담당자 카드·월배치에 쌓이면 확정할 것도 없는 행만 늘어난다.
     */
    public function test_no_settlement_is_created_when_the_domestic_base_is_exactly_zero(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        // 판매가 = 매입가 + 말소비 + 탁송비 → 차액 0
        $v = $this->soldVehicle($sm, $this->buyer($sm), salePrice: 10_224_000);

        $this->assertSame(0, $v->domestic_margin);
        $this->assertSame(0, $v->settlements()->count(), '차액 0 이면 정산이 생기면 안 된다');
    }

    /** 수출은 종전대로 — 0 원 규칙은 내수에만 적용된다. */
    public function test_zero_rule_does_not_touch_export_vehicles(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $v = $this->soldVehicle($sm, $this->buyer($sm, domestic: false), salePrice: 10_224_000);

        $this->assertSame(1, $v->settlements()->count(), '수출은 차액과 무관하게 정산이 생긴다');
    }

    /** 나중에 비용이 정정돼 차액이 생기면 그때 정산이 만들어진다. */
    public function test_a_later_cost_change_creates_the_settlement_that_was_skipped(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');
        $v = $this->soldVehicle($sm, $this->buyer($sm), salePrice: 10_224_000);
        $this->assertSame(0, $v->settlements()->count());

        $v->update(['cost_towing' => 100_000]);   // 탁송비가 실측으로 줄었다 → 차액 +10만

        $this->assertSame(100_000, $v->fresh()->domestic_margin);
        $this->assertSame(1, $v->settlements()->count());
    }

    // ── 사내직원 최소 지급선 ───────────────────────────────────────────────

    /**
     * 사내직원은 **차액이 건당 금액(10만원)에 못 미치면 0 원**이다 (jin 2026-09-08).
     * 차액 5만원에 10만원을 주면 회사가 손해다.
     */
    public function test_employee_gets_nothing_when_the_base_is_below_the_per_unit_amount(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('employee');

        // 차액 50,000 (< 10만) → 0 원
        $low = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm), salePrice: 10_274_000));
        $this->assertSame(50_000, $low->total_margin);
        $this->assertSame(0, $low->settlement_amount);

        // 차액 100,000 (= 10만) → 10만원. 회사 몫 0.
        $edge = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm), salePrice: 10_324_000));
        $this->assertSame(100_000, $edge->total_margin);
        $this->assertSame(100_000, $edge->settlement_amount);
        $this->assertSame(0, $edge->company_net);
    }

    /** 수출 사내직원은 종전대로 — 최소 지급선은 내수에만 있다. */
    public function test_the_minimum_does_not_apply_to_export_employees(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('employee');
        // 수출 총마진이 작아도 건당 10만원은 그대로 나간다(종전 동작).
        // 수출 총마진 = (판매마진 −800,000 + 부가세마진 900,000) × 0.9 = 90,000
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm, domestic: false), salePrice: 9_424_000));

        $this->assertSame(90_000, $s->total_margin);
        $this->assertSame(100_000, $s->settlement_amount);
    }

    /** 프리랜서는 차액이 작아도 서류비를 그대로 문다 — 절반이 5만이면 실지급 0 원. */
    public function test_freelancer_document_fee_can_wipe_out_a_small_share(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');

        // 차액 100,000 → 절반 50,000 − 서류비 50,000 = 0
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm), salePrice: 10_324_000));
        $this->assertSame(50_000, $s->settlement_amount);
        $this->assertSame(0, $s->actual_payout);

        // 차액 −200,000 → 절반 −100,000 − 서류비 50,000 = −150,000
        $loss = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm), salePrice: 10_024_000));
        $this->assertSame(-100_000, $loss->settlement_amount);
        $this->assertSame(-150_000, $loss->actual_payout);
    }

    /**
     * 차등 25% 구간은 최소 지급선에 안 걸린다 — 비율이라 기준액을 넘을 수 없기 때문이다.
     * (jin 2026-09-08 *"매입 1억↑ & 기준액 0↑ 25% 는 그대로"*)
     */
    public function test_the_percentage_tier_survives_the_minimum_rule(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('employee', tier: true);

        // 매입 1억 · 차액 50,000 → 건당이었으면 0 이지만 25% 라 12,500 이 나간다.
        $s = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm),
            salePrice: 100_274_000, extra: ['purchase_price' => 100_000_000]));

        $this->assertSame(50_000, $s->total_margin);
        $this->assertSame(12_500, $s->settlement_amount);
        $this->assertSame(37_500, $s->company_net);
    }

    // ── 미리보기(드로어) ↔ 실제 정산 정합 ────────────────────────────────

    /**
     * 🚨 정산 화면 드로어는 마진 공식을 **복제해서 다시 계산**한다(`marginData`).
     *    내수 분기를 거기 안 넣었더니 목록은 −200,000 인데 드로어는 **315,000** 을 보여줬다
     *    (수출 체인을 그대로 돌아 부가세마진 +900,000 이 붙은 값 — jin 2026-09-08 제보).
     *    예외도 로그도 없이 **숫자만 두 개**가 된다.
     *
     * 🧭 그래서 셋(총마진·정산액·실지급액)을 모델과 **직접 대조**한다.
     *    새 정산 유형을 만들 때 이 테스트를 같이 늘릴 것.
     */
    public function test_drawer_preview_matches_the_model_for_domestic_and_export(): void
    {
        $this->actingAs($this->finance());
        $free = $this->salesman('freelance');
        $emp = $this->salesman('employee');

        $cases = [
            $this->settlementOf($this->soldVehicle($free, $this->buyer($free))),                       // 내수 이익
            $this->settlementOf($this->soldVehicle($free, $this->buyer($free), salePrice: 10_024_000)), // 내수 손실
            $this->settlementOf($this->soldVehicle($emp, $this->buyer($emp), salePrice: 10_274_000)),   // 내수 0원
            $this->settlementOf($this->soldVehicle($free, $this->buyer($free, domestic: false))),       // 수출
        ];

        foreach ($cases as $s) {
            $m = Volt::test('erp.settlements.index')->call('openEdit', $s->id)->instance()->marginData;

            $plate = $s->vehicle->vehicle_number;
            $this->assertSame((bool) $s->is_domestic, $m['isDomestic'], "{$plate} 내수 판정 불일치");
            $this->assertSame($s->total_margin, $m['totalMargin'], "{$plate} 총마진이 목록과 드로어에서 갈렸다");
            $this->assertSame($s->settlement_amount, $m['settlementAmount'], "{$plate} 정산액 불일치");
            $this->assertSame($s->actual_payout, $m['actualPayout'], "{$plate} 실지급액 불일치");
        }
    }

    /**
     * 내수 드로어는 「적용 비용 내역」을 안 보여준다 (jin 2026-09-08).
     * 그 표는 수출 마진에서만 빠지는 값이라, 내수에 두면 합계만 덩그러니 남아
     * 「이 574,000 이 뭐랑 더해지나」가 된다. 말소비·탁송비는 위 기준액 블록에 이미 있다.
     */
    public function test_domestic_drawer_hides_the_cost_breakdown_that_it_does_not_use(): void
    {
        $this->actingAs($this->finance());
        $sm = $this->salesman('freelance');

        $domestic = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm)));
        $html = Volt::test('erp.settlements.index')->call('openEdit', $domestic->id)->html();
        // ⚠️ 문구 안에서 그 블록 이름을 언급하면 이 단언이 헛돈다 — 실제로 한 번 그랬다.
        $this->assertStringNotContainsString(__('settlement.section_costs'), $html);
        $this->assertStringContainsString(__('settlement.domestic.base'), $html, '대신 내수 기준액 명세가 보여야 한다');

        // 수출은 종전 그대로 — 숨기는 게 내수에만 걸렸는지 확인한다.
        $export = $this->settlementOf($this->soldVehicle($sm, $this->buyer($sm, domestic: false)));
        $exportHtml = Volt::test('erp.settlements.index')->call('openEdit', $export->id)->html();
        $this->assertStringContainsString(__('settlement.section_costs'), $exportHtml);
    }
}
