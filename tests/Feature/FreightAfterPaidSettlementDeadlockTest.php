<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 운임비가 1차 지급(paid) 뒤에 수금될 때의 데드락 회귀 (jin 2026-08-26, 실사고 248가4049·29마0712).
 *
 * 두 문이 동시에 닫혀 있었다:
 *   ① FinalPayment::creating 이 'paid 정산 존재'로 신규 판매 잔금을 차단 — 예외구멍 없음(super 도 불가)
 *   ② 그래서 미수(운임비 1,528 EUR)가 남고, closeSecondarySettlement 의 완납 게이트가 2차 마감을 차단
 * ⇒ 돈을 기록할 수도, 마감할 수도 없었다. 매입 쪽은 2026-07-24 에 같은 이유로 이미 완화됨(54가6191).
 *
 * 이 테스트는 «막힌 문 하나만 열면 안 된다»를 박제한다 — 잔금을 넣어도 재무확정이 막히면
 * 미수 분자(confirmed 행만)가 안 줄어 ②가 그대로 남는다.
 */
class FreightAfterPaidSettlementDeadlockTest extends TestCase
{
    use RefreshDatabase;

    /** 운영 248가4049 재현 — EUR 11,105 완납 후 운임비 1,528 이 미수로 남고 정산은 이미 paid. */
    private function stuckVehicle(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'FREIGHT BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'FRT-1', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1729, 'dhl_request' => false,
            'buyer_id' => $buyer->id, 'sale_date' => '2026-07-02',
            'sale_price' => 11105, 'transport_fee' => 1528,
        ]);

        // 차값만 입금된 상태 (운임비 미수)
        FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 11105, 'type' => 'balance',
            'payment_date' => '2026-07-02', 'exchange_rate' => 1729,
            'confirmed_at' => now(),
        ]);

        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'confirmed_at' => now(), 'paid_at' => now(),
            'secondary_status' => 'pending',
        ]);

        return $v->fresh();
    }

    public function test_freight_received_after_paid_can_be_recorded_and_then_closed(): void
    {
        $v = $this->stuckVehicle();
        $this->assertSame(1528, (int) $v->sale_unpaid_amount, '전제 — 운임비가 미수로 남아 있어야 함');

        $finance = User::factory()->create(['role' => '재무']);
        $this->actingAs($finance);

        // ① 잔금 추가가 열려 있어야 한다 (구 가드는 여기서 DomainException)
        FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 1528, 'type' => 'balance',
            'payment_date' => '2026-08-26', 'exchange_rate' => 1729,
            'confirmed_at' => now(), 'confirmed_by_user_id' => $finance->id,
            'note' => '운임비 수금',
        ]);

        $v->refresh();
        $this->assertSame(0, (int) $v->sale_unpaid_amount, '운임비 수금이 미수에서 차감 안 됨');

        // ② 그래야 2차 마감의 완납 게이트를 넘는다
        $settlement = $v->settlements()->first();
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $settlement->id);

        $settlement->refresh();
        $this->assertSame('closed', $settlement->secondary_status, '완납인데 2차 마감이 안 됨');

        // 🔑 판매환율(1729)로 받아 적으면 **이미 지급된 정산의 숫자는 안 움직인다**.
        //   운임비는 정산 base(sale_price + commission + auto_loading − tax_dc) 밖이고,
        //   settlement_exchange_rate 는 실입금KRW ÷ 총판매가인데 전액을 판매환율로 받으면
        //   미완납 폴백이 주던 값(=판매환율)과 같아진다 ⇒ actual_payout 불변 ⇒ 이월 0.
        //   숫자를 움직이는 것은 «잔금을 넣는 행위»가 아니라 «다른 환율로 넣는 것»이다.
        $this->assertSame(0, (int) $settlement->carryover_out_krw, '판매환율로 넣었는데 이월이 생김');
    }

    public function test_a_different_receipt_rate_is_what_moves_the_paid_numbers(): void
    {
        // 위 테스트의 짝 — 실제 입금환율이 다르면 그 차액이 carryover_out 으로 다음 정산에 이월된다.
        //   («그래서 숫자가 바뀝니다»의 정확한 조건. 설계대로 동작하는 것이지만 조건을 박아둔다.)
        $v = $this->stuckVehicle();
        $finance = User::factory()->create(['role' => '재무']);
        $this->actingAs($finance);

        FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 1528, 'type' => 'balance',
            'payment_date' => '2026-08-26', 'exchange_rate' => 1900,   // 판매환율 1729 와 다름
            'confirmed_at' => now(), 'confirmed_by_user_id' => $finance->id,
        ]);

        $settlement = $v->fresh()->settlements()->first();
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $settlement->id);

        $this->assertSame('closed', $settlement->fresh()->secondary_status);
        $this->assertNotSame(0, (int) $settlement->fresh()->carryover_out_krw, '환율 차이가 이월로 안 넘어감');
    }

    public function test_unconfirmed_row_alone_does_not_open_the_second_door(): void
    {
        // 미수 분자는 confirmed 행만 센다 — creating 만 풀고 재무확정 가드를 안 풀면
        //   "잔금은 들어갔는데 마감은 여전히 막힌" 반쪽 수정이 된다. 그 경계를 박제한다.
        $v = $this->stuckVehicle();
        $finance = User::factory()->create(['role' => '재무']);
        $this->actingAs($finance);

        FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 1528, 'type' => 'balance',
            'payment_date' => '2026-08-26', 'exchange_rate' => 1729,
        ]);   // confirmed_at 없음

        $v->refresh();
        $this->assertSame(1528, (int) $v->sale_unpaid_amount, '미확정 잔금이 미수를 줄이면 안 됨');

        $settlement = $v->settlements()->first();
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $settlement->id);

        $this->assertSame('pending', $settlement->fresh()->secondary_status, '미완납인데 2차가 마감됨');
    }

    public function test_after_secondary_close_the_door_shuts_again(): void
    {
        $v = $this->stuckVehicle();
        $v->settlements()->first()->update(['secondary_status' => 'closed']);

        $this->actingAs(User::factory()->create(['role' => '재무']));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('2차 정산 마감');
        FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 1528, 'type' => 'balance',
            'payment_date' => '2026-08-26', 'confirmed_at' => now(),
        ]);
    }
}
