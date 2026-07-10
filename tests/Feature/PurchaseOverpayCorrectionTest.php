<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 매입 과입금 인라인 정정 (jin 2026-07-10) — 초과분만큼 확정 PBP 를 깎아 완납으로 정정 + 사유.
 * 판매(적립금 전환)와 달리 크레딧 아님. secondary-closed 가드 없음(매입 PBP 는 정산 무관).
 */
class PurchaseOverpayCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** 과입금(확정 PBP > 매입총액) 차량 1대 + 편집자(재무권한) 세팅. */
    private function overpaidVehicle(int $purchase = 5_000_000, int $paid = 6_000_000): Vehicle
    {
        $salesman = Salesman::create(['name' => '영업A', 'type' => 'employee', 'is_active' => true]);
        $vehicle = Vehicle::create([
            'vehicle_number' => 'OVERPAY-1', 'sales_channel' => 'export', 'salesman_id' => $salesman->id,
            'currency' => 'KRW', 'exchange_rate' => 1, 'purchase_date' => '2026-06-01',
            'purchase_price' => $purchase, 'selling_fee' => 0,
        ]);
        $vehicle->purchaseBalancePayments()->create([
            'amount' => $paid, 'payment_date' => '2026-06-05', 'confirmed_at' => now(),
        ]);

        return $vehicle->fresh();
    }

    public function test_corrects_overpay_to_fully_paid_with_audit_and_reason(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        $v = $this->overpaidVehicle(5_000_000, 6_000_000);
        $this->assertSame(-1_000_000, (int) $v->purchase_unpaid_amount);   // 과입금 100만

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('purchaseOverpayReason', '중복 지급 오기입')
            ->call('correctPurchaseOverpay');

        $v->refresh();
        $this->assertSame(0, (int) $v->purchase_unpaid_amount);            // 완납으로 정정
        $pbp = $v->purchaseBalancePayments()->first();
        $this->assertSame(5_000_000, (int) $pbp->amount);                 // PBP 초과분만큼 감액
        $this->assertStringContainsString('중복 지급 오기입', (string) $pbp->finance_note);   // 사유 append
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'purchase_overpay_corrected', 'auditable_id' => $v->id,
        ]);
    }

    public function test_reason_required_no_change(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        $v = $this->overpaidVehicle();

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('purchaseOverpayReason', '   ')   // 공백 = 미입력
            ->call('correctPurchaseOverpay')
            ->assertDispatched('notify');

        $this->assertSame(-1_000_000, (int) $v->fresh()->purchase_unpaid_amount);   // 불변
    }

    public function test_not_overpaid_no_change(): void
    {
        // 완납 차량(과입금 아님) → 정정 no-op, 감사로그 없음.
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        $v = $this->overpaidVehicle(5_000_000, 5_000_000);   // 지급=매입 → 완납(0)
        $this->assertSame(0, (int) $v->purchase_unpaid_amount);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('purchaseOverpayReason', '사유')
            ->call('correctPurchaseOverpay')
            ->assertDispatched('notify');

        $this->assertSame(0, (int) $v->fresh()->purchase_unpaid_amount);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'purchase_overpay_corrected', 'auditable_id' => $v->id]);
    }

    public function test_non_finance_role_forbidden(): void
    {
        // setup(PBP 생성)은 재무권한(admin)으로 — 생성 가드 통과.
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        $v = $this->overpaidVehicle();

        // 액션만 수출통관으로 — scope 전체(통과)이나 canConfirmFinance=false → 403.
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
        $this->actingAs($clearance);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('purchaseOverpayReason', '사유')
            ->call('correctPurchaseOverpay')
            ->assertStatus(403);

        $this->assertSame(-1_000_000, (int) $v->fresh()->purchase_unpaid_amount);   // 불변
    }
}
