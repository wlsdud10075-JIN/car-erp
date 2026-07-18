<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
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

    public function test_receivable_cancel_filter_where_clause(): void
    {
        $buyer = Buyer::create(['name' => 'RB', 'is_active' => true]);
        $mk = fn (string $plate, string $cancel) => Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1_000_000,
            'sale_date' => now()->format('Y-m-d'), 'purchase_date' => now()->format('Y-m-d'),
            'buyer_id' => $buyer->id, 'cancel_status' => $cancel,
        ]);
        $mk('NORMALCAR', Vehicle::CANCEL_NONE);
        $mk('CXLCAR', Vehicle::CANCEL_ACTIVE);

        // buildQuery 의 cancelFilter 절과 동일한 WHERE — 취소만 / 정상만 분리.
        $base = fn () => Vehicle::query()->where('sale_price', '>', 0);
        $cancelledOnly = $base()->where('cancel_status', '!=', Vehicle::CANCEL_NONE)->pluck('vehicle_number');
        $normalOnly = $base()->where('cancel_status', Vehicle::CANCEL_NONE)->pluck('vehicle_number');

        $this->assertTrue($cancelledOnly->contains('CXLCAR'));
        $this->assertFalse($cancelledOnly->contains('NORMALCAR'));
        $this->assertTrue($normalOnly->contains('NORMALCAR'));
        $this->assertFalse($normalOnly->contains('CXLCAR'));
    }

    public function test_admin_dashboard_receivable_kpis_count_cancels(): void
    {
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $mk = fn (string $plate, string $cancel) => Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1_000_000,
            'sale_date' => now()->format('Y-m-d'), 'purchase_date' => now()->format('Y-m-d'),
            'buyer_id' => $buyer->id, 'cancel_status' => $cancel,
        ]);
        $mk('AC1', Vehicle::CANCEL_ACTIVE);
        $mk('AC2', Vehicle::CANCEL_CLOSED);
        $mk('AC3', Vehicle::CANCEL_NONE);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateFrom', '2000-01-01')->set('dateTo', '2100-01-01')
            ->instance()->receivableKpis();

        $this->assertSame(1, $kpis['cancel_active']);
        $this->assertSame(1, $kpis['cancel_closed']);
    }

    private function cancelledUnpaidVehicle(string $plate, string $smType): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.$plate, 'is_active' => true]);
        $sm = Salesman::create(['name' => 'S'.$plate, 'is_active' => true, 'type' => $smType]);

        return Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1746, 'sale_price' => 600,
            'sale_date' => '2026-05-25', 'buyer_id' => $buyer->id, 'salesman_id' => $sm->id,
            'cancel_status' => Vehicle::CANCEL_ACTIVE,
        ]);
    }

    public function test_close_freezes_shortfall_freelancer_half(): void
    {
        $this->actingAs($this->admin());
        $v = $this->cancelledUnpaidVehicle('CL1', 'freelance');   // 600 EUR 미수 × 1746 = 1,047,600

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('closePurchaseCancelUnpaid')
            ->assertSet('cancelStatus', Vehicle::CANCEL_CLOSED);

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_CLOSED, $v->cancel_status);
        $this->assertSame(1_047_600, (int) $v->cancel_shortfall_krw);
        $this->assertSame(523_800, $v->cancel_freelancer_loss_krw);   // 프리랜서 절반
    }

    public function test_close_employee_bears_no_loss(): void
    {
        $this->actingAs($this->admin());
        $v = $this->cancelledUnpaidVehicle('CL2', 'employee');

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call('closePurchaseCancelUnpaid');

        $v->refresh();
        $this->assertSame(1_047_600, (int) $v->cancel_shortfall_krw);
        $this->assertSame(0, $v->cancel_freelancer_loss_krw);   // 사내직원 = 회사 전액 부담
    }

    public function test_close_blocked_when_fully_paid(): void
    {
        $this->actingAs($this->admin());
        $v = $this->cancelledUnpaidVehicle('CL3', 'freelance');
        $v->finalPayments()->create([
            'amount' => 600, 'type' => 'balance', 'payment_date' => '2026-05-27', 'confirmed_at' => now(),
        ]);   // 완납

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call('closePurchaseCancelUnpaid');

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_ACTIVE, $v->cancel_status);   // 마감 안 됨
        $this->assertNull($v->cancel_shortfall_krw);
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
