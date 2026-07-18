<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 매입취소 Layer 2 (jin 2026-07-18) — 매입 탭 취소 마커 전환/해제.
 * 위약금은 판매가(sale_price) 재사용, cancel_status 마커로 판매통계·정산 분기. '취소완료'는 미수0 계산.
 */
class PurchaseCancelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '222나4513',
            'sales_channel' => 'export',
            'currency' => 'EUR',
            'exchange_rate' => 1746,
            'purchase_price' => 1_000_000,
        ]);
    }

    public function test_mark_sets_cancelled(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('markPurchaseCancelled')
            ->assertSet('cancelStatus', Vehicle::CANCEL_ACTIVE);

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_ACTIVE, $v->cancel_status);
        $this->assertNotNull($v->cancelled_at);
    }

    public function test_unmark_reverts_to_none(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();
        $v->update(['cancel_status' => Vehicle::CANCEL_ACTIVE, 'cancelled_at' => now()]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('unmarkPurchaseCancelled')
            ->assertSet('cancelStatus', Vehicle::CANCEL_NONE);

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_NONE, $v->cancel_status);
        $this->assertNull($v->cancelled_at);
    }

    public function test_closed_cannot_be_unmarked(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();
        $v->update(['cancel_status' => Vehicle::CANCEL_CLOSED, 'cancelled_at' => now()]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('unmarkPurchaseCancelled')
            ->assertSet('cancelStatus', Vehicle::CANCEL_CLOSED);

        $this->assertSame(Vehicle::CANCEL_CLOSED, $v->refresh()->cancel_status);
    }

    public function test_label_computes_done_from_unpaid(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $v = $this->makeVehicle();
        $v->update([
            'cancel_status' => Vehicle::CANCEL_ACTIVE,
            'sale_price' => 600, 'sale_date' => '2026-05-25', 'buyer_id' => $buyer->id,
        ]);

        // 미수 > 0 → '매입취소'
        $this->assertSame('매입취소', $v->fresh()->cancel_status_label);

        // 위약금 완납 → '취소완료'(계산)
        $v->finalPayments()->create([
            'amount' => 600, 'type' => 'balance', 'payment_date' => '2026-05-27', 'confirmed_at' => now(),
        ]);
        $this->assertSame('취소완료', $v->fresh()->cancel_status_label);
    }
}
