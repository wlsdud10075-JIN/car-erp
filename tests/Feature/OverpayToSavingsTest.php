<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\SavingsStatus;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 과입금 → 적립금 전환 (jin 2026-07-09).
 *
 * 과입금(음수 미수)된 차량의 초과분을 바이어 적립금(EARNED)으로 옮기고 미수를 0으로 만든다.
 * 정산 paid(회계 잠금) 상태여도 시스템 우회로 확정 잔금을 감액 — 159부9334 케이스가 계기.
 */
class OverpayToSavingsTest extends TestCase
{
    use RefreshDatabase;

    /** 과입금 3,000 + 정산 paid 차량 (auth 미존재 시점에 생성 → creating 가드 우회). */
    private function overpaidPaidVehicle(): array
    {
        $buyer = Buyer::create(['name' => 'OVERPAY BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'OVP-1', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1400, 'dhl_request' => false,
            'buyer_id' => $buyer->id, 'sale_date' => '2026-05-01',
            'sale_price' => 10000, 'transport_fee' => 0,   // 총판매 10,000
        ]);
        // 확정 잔금 13,000 → received 13,000 → 미수 -3,000 (과입금)
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 13000,
            'payment_date' => '2026-05-02', 'confirmed_at' => now(),
        ]);
        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        return [$v, $buyer];
    }

    public function test_convert_overpay_zeroes_unpaid_and_earns_savings_despite_paid(): void
    {
        [$v, $buyer] = $this->overpaidPaidVehicle();
        $this->assertSame(-3000, (int) $v->fresh()->sale_unpaid_amount, '초기 과입금 -3,000 이 아님');

        $user = User::factory()->create(['role' => '재무']);   // canConfirmFinance
        $this->actingAs($user);

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToSavings');

        // 미수 0
        $this->assertSame(0, (int) $v->fresh()->sale_unpaid_amount, '전환 후 미수가 0 이 아님');
        // 확정 잔금 13,000 → 10,000 감액
        $this->assertSame(10000.0, (float) FinalPayment::where('vehicle_id', $v->id)->value('amount'));
        // 바이어 적립금 EARNED +3,000
        $earned = SavingsStatus::where('buyer_id', $buyer->id)->where('transaction_type', 'EARNED')->first();
        $this->assertNotNull($earned, '적립금 EARNED 거래 없음');
        $this->assertSame(3000.0, (float) $earned->savings);
        $this->assertSame('USD', $earned->currency);
        // 정산 paid 그대로 (전환은 정산금 무영향)
        $this->assertSame('paid', Settlement::where('vehicle_id', $v->id)->value('settlement_status'));
    }

    public function test_convert_blocked_when_secondary_settlement_closed(): void
    {
        [$v, $buyer] = $this->overpaidPaidVehicle();
        // 2차 정산 마감 — 환차·이월 산정 완료 → 소급 변경 금지 (SKILLS §28)
        Settlement::where('vehicle_id', $v->id)->update(['secondary_status' => 'closed']);

        $this->actingAs(User::factory()->create(['role' => '재무']));

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToSavings');

        // 아무 변화 없음 — 확정 잔금·미수·적립금 그대로
        $this->assertSame(13000.0, (float) FinalPayment::where('vehicle_id', $v->id)->value('amount'));
        $this->assertSame(-3000, (int) $v->fresh()->sale_unpaid_amount);
        $this->assertSame(0, SavingsStatus::where('buyer_id', $buyer->id)->where('transaction_type', 'EARNED')->count());
    }

    public function test_convert_blocked_when_not_overpaid(): void
    {
        $buyer = Buyer::create(['name' => 'FINE BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'OVP-2', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1400, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 10000, 'transport_fee' => 0,
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5000,
            'payment_date' => '2026-05-02', 'confirmed_at' => now(),
        ]);   // 미수 +5,000 (과입금 아님)

        $this->actingAs(User::factory()->create(['role' => '재무']));

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToSavings');

        // 아무 변화 없음
        $this->assertSame(5000.0, (float) FinalPayment::where('vehicle_id', $v->id)->value('amount'));
        $this->assertSame(0, SavingsStatus::where('buyer_id', $buyer->id)->count());
    }
}
