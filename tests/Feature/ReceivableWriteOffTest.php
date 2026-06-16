<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 — 회수방법 'write_off'(손실/셀러부담) (2026-06-16, B-lite).
 *
 * - 손실은 미수를 직접 차감(method != 'deposit' 규칙) + FinalPayment 미생성.
 * - 바이어 패널 누적 카드는 수수료(fee) + 손실(write_off) 합산 표시 (영업 협상 카드).
 */
class ReceivableWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private function saleVehicle(int $buyerId): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'WO-'.$buyerId, 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
            'buyer_id' => $buyerId, 'sale_date' => '2026-05-01',
            'sale_price' => 10000, 'transport_fee' => 0,   // 미납 10000
        ]);
    }

    public function test_write_off_reduces_unpaid_and_creates_no_final_payment(): void
    {
        $buyer = Buyer::create(['name' => 'WO BUYER', 'is_active' => true]);
        $v = $this->saleVehicle($buyer->id);
        $user = User::factory()->create(['role' => '재무']);
        $this->actingAs($user);

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->set('hCollectedAt', '2026-05-11')
            ->set('hCollectorId', (string) $user->id)
            ->set('hMethod', 'write_off')
            ->set('hAmount', '4000')
            ->call('saveHistory')
            ->assertHasNoErrors();

        $this->assertSame(1, ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'write_off')->count());
        // 손실은 미러링 FinalPayment 를 만들지 않음
        $this->assertSame(0, FinalPayment::where('vehicle_id', $v->id)->count());

        $v->refresh();
        $this->assertSame(6000, (int) $v->sale_unpaid_amount, '손실이 미납에서 차감 안 됨');
    }

    public function test_buyer_panel_accumulates_fee_plus_write_off(): void
    {
        $buyer = Buyer::create(['name' => 'WO ACC BUYER', 'is_active' => true]);
        $v = $this->saleVehicle($buyer->id);

        // 손실 2,000 USD × 1438 = 2,876,000 KRW
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-05-11',
            'collector_id' => User::factory()->create()->id,
            'method' => 'write_off', 'amount' => 2000,
        ]);

        // 수수료 1,000 USD × 1438 = 1,438,000 KRW (confirmed)
        $fee = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'fee', 'amount' => 1000,
            'amount_krw' => 1_438_000, 'payment_date' => '2026-05-11',
            'confirmed_at' => now(),
        ]);

        $user = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($user);

        $fees = Volt::test('erp.buyers.index')
            ->set('editingId', $buyer->id)
            ->instance()
            ->buyerFees();

        $this->assertNotNull($fees);
        $this->assertSame(1, $fees['fee_count']);
        $this->assertSame(1, $fees['wo_count']);
        $this->assertSame(2, $fees['count']);
        // 손실 2,876,000 + 수수료 amount_krw 합산
        $this->assertSame(2_876_000 + (int) $fee->fresh()->amount_krw, $fees['total_krw']);
    }

    public function test_buyer_panel_card_renders_combined_total(): void
    {
        $buyer = Buyer::create(['name' => 'WO CARD BUYER', 'is_active' => true]);
        $v = $this->saleVehicle($buyer->id);
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-05-11',
            'collector_id' => User::factory()->create()->id,
            'method' => 'write_off', 'amount' => 2000,   // 2,876,000 KRW
        ]);

        $user = User::factory()->create(['permission' => 'admin']);
        $this->actingAs($user);

        Volt::test('erp.buyers.index')
            ->set('editingId', $buyer->id)
            ->set('showPanel', true)
            ->assertSee('2,876,000');
    }
}
