<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 매입 가능 금액 (jin 2026-07-29) — 구 "보증금 여력"을 대체.
 *
 *   한도 = 선적 전 진행중 차량의 **입금액** × 50%
 *   사용 = 그 차량들의 **매입 지급**(확정 PBP)
 *   여력 = 한도 − 사용
 *
 * 🚩 순수 표시용이다. 아무것도 차단하지 않는다 —
 *    매입등록 게이트(C5)는 ratio 를 보므로 이 값들을 바꿔도 락은 움직이면 안 된다.
 */
class BuyerPurchasingRoomTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function buyer(): Buyer
    {
        return Buyer::create(['name' => 'B'.(++$this->seq), 'is_active' => true]);
    }

    /** 판매 100,000원(KRW) 차량 + 입금 $paid + 확정 매입지급 $purchasePaid. */
    private function vehicle(Buyer $b, int $salePrice, int $paid, int $purchasePaid = 0, array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'PR'.(++$this->seq),
            'sales_channel' => 'export',
            'buyer_id' => $b->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_date' => '2026-07-01',
            'sale_price' => $salePrice,
            'purchase_date' => '2026-06-01',
            'purchase_price' => 50_000_000,
        ], $extra));

        if ($paid > 0) {
            $v->finalPayments()->create([
                'amount' => $paid, 'type' => 'balance', 'payment_date' => '2026-07-05',
                'exchange_rate' => 1, 'confirmed_at' => now(),
            ]);
        }
        if ($purchasePaid > 0) {
            $v->purchaseBalancePayments()->create([
                'amount' => $purchasePaid, 'type' => 'balance', 'payment_date' => '2026-06-05',
                'confirmed_at' => now(),
            ]);
        }
        $v->refresh();
        $v->refreshProgressCache();

        return $v->fresh();
    }

    private function gauge(Buyer $b): array
    {
        return $b->fresh()->receivableGauge();
    }

    public function test_limit_is_half_of_received_not_half_of_sale_total(): void
    {
        // 판매 1억인데 입금은 2천만 → 한도는 1천만(입금의 50%)이지, 5천만(총액의 50%)이 아니다.
        $b = $this->buyer();
        $this->vehicle($b, 100_000_000, 20_000_000);

        $g = $this->gauge($b);

        $this->assertSame(20_000_000, $g['paid_krw']);
        $this->assertSame(10_000_000, $g['limit_krw'], '한도 = 입금액 × 50%');
        $this->assertSame(10_000_000, $g['available_krw']);
    }

    public function test_purchase_payment_consumes_the_room(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 100_000_000, 20_000_000, purchasePaid: 4_000_000);

        $g = $this->gauge($b);

        $this->assertSame(4_000_000, $g['used_krw'], '사용 = 매입 지급');
        $this->assertSame(6_000_000, $g['available_krw'], '한도 1천만 − 지급 4백만');
    }

    public function test_more_sale_payment_restores_the_room(): void
    {
        $b = $this->buyer();
        $v = $this->vehicle($b, 100_000_000, 20_000_000, purchasePaid: 9_000_000);
        $this->assertSame(1_000_000, $this->gauge($b)['available_krw']);

        // 판매 잔금이 더 들어오면 한도가 늘어 여력이 회복된다
        $v->finalPayments()->create([
            'amount' => 30_000_000, 'type' => 'balance', 'payment_date' => '2026-07-20',
            'exchange_rate' => 1, 'confirmed_at' => now(),
        ]);
        $v->refresh();
        $v->refreshProgressCache();

        $g = $this->gauge($b);
        $this->assertSame(50_000_000, $g['paid_krw']);
        $this->assertSame(25_000_000, $g['limit_krw']);
        $this->assertSame(16_000_000, $g['available_krw'], '한도 2천5백만 − 지급 9백만');
    }

    public function test_room_never_goes_negative(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 100_000_000, 10_000_000, purchasePaid: 40_000_000);

        $g = $this->gauge($b);

        $this->assertSame(5_000_000, $g['limit_krw']);
        $this->assertSame(0, $g['available_krw'], '초과해도 음수로 안 내려간다(표시용)');
    }

    /** 미확정 매입 지급은 아직 나간 돈이 아니다 — SKILLS §13 확정분만 합산. */
    public function test_unconfirmed_purchase_payment_does_not_consume_room(): void
    {
        $b = $this->buyer();
        $v = $this->vehicle($b, 100_000_000, 20_000_000);
        $v->purchaseBalancePayments()->create([
            'amount' => 7_000_000, 'type' => 'balance', 'payment_date' => '2026-06-05',
            'confirmed_at' => null,
        ]);

        $g = $this->gauge($b);

        $this->assertSame(0, $g['used_krw']);
        $this->assertSame(10_000_000, $g['available_krw']);
    }

    /** 출고(선적 후)·거래완료 차량은 진행중이 아니므로 한도·사용 어디에도 안 잡힌다. */
    public function test_shipped_out_vehicle_is_excluded_from_both_sides(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 100_000_000, 20_000_000, purchasePaid: 3_000_000);
        $this->vehicle($b, 200_000_000, 200_000_000, purchasePaid: 90_000_000, extra: [
            'warehouse_out_date' => '2026-07-10',
        ]);

        $g = $this->gauge($b);

        $this->assertSame(20_000_000, $g['paid_krw'], '출고 차량 입금은 한도에 안 들어간다');
        $this->assertSame(3_000_000, $g['used_krw'], '출고 차량 매입지급도 사용에 안 잡힌다');
    }

    // -- 매입 탭 노출 (jin 2026-07-29) --

    /** 매입비를 더 써도 되는지 판단하는 자리가 매입 탭이라 거기에도 같은 숫자가 떠야 한다. */
    public function test_purchase_tab_shows_the_room_for_the_selected_buyer(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $b = $this->buyer();
        $v = $this->vehicle($b, 100_000_000, 20_000_000, purchasePaid: 4_000_000);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('purchasingRoom.limit_krw', 10_000_000)
            ->assertSet('purchasingRoom.used_krw', 4_000_000)
            ->assertSet('purchasingRoom.available_krw', 6_000_000);
    }

    /** 일반재고(바이어 미정 투기매입)는 기준이 없으므로 아예 안 띄운다 - 0원으로 오해시키지 않는다. */
    public function test_purchase_tab_shows_nothing_without_a_buyer(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $v = Vehicle::create([
            'vehicle_number' => 'NOBUYER1', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1,
            'purchase_date' => '2026-06-01', 'purchase_price' => 50_000_000,
        ]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('purchasingRoom', null);
    }

    /**
     * 🚩 회귀 방지 핵심 — 표시용 재정의가 매입등록 락을 건드리면 안 된다.
     * ratio 는 여전히 (미수 / 선적전 총액) 이고 limit/used/available 과 무관하다.
     */
    public function test_gate_ratio_is_untouched_by_the_display_redefinition(): void
    {
        Setting::updateOrCreate(['key' => 'lock_threshold_purchase_registration'], ['value' => '0.5', 'type' => 'string']);

        $b = $this->buyer();
        // 판매 1억 · 입금 2천만 → 미수 8천만 → ratio 0.8 (임계 0.5 초과 = 락 발동 조건)
        $this->vehicle($b, 100_000_000, 20_000_000, purchasePaid: 1_000_000);

        $g = $this->gauge($b);

        $this->assertSame(100_000_000, $g['total_krw']);
        $this->assertSame(80_000_000, $g['unpaid_krw']);
        $this->assertEqualsWithDelta(0.8, $g['ratio'], 0.0001, 'ratio 는 미수/총액 그대로여야 한다');
        $this->assertTrue($g['ratio'] > 0.5, '락 발동 판정이 표시 재정의로 뒤집히면 안 된다');
    }
}
