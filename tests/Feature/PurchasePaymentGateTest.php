<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Phase 2 — 매입 지급 락 (#2, 2026-07-10). 기본 OFF(dormant).
 *   락 ON 시: 첫 지급(계약금)은 허용, 2번째 매입 지급~ && 그 차 판매 미수율 > 50% → 차단.
 *   관리/관리자 승인 우회(사유 필수) + AuditLog(purchase_payment_gate_override).
 */
class PurchasePaymentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function setLock(bool $on): void
    {
        Setting::updateOrCreate(
            ['key' => 'lock_purchase_payment_'.Setting::companyTemplateSet()],
            ['value' => $on ? '1' : '0', 'type' => 'boolean'],
        );
    }

    /** 매입가 있고 판매 미수 100% 인 차량. $withFirstPbp=true 면 확정 PBP 1건(계약금) 선적재. */
    private function vehicle(bool $withFirstPbp): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $sm = Salesman::create(['name' => 'S', 'is_active' => true, 'type' => 'freelance']);
        $v = Vehicle::create([
            'vehicle_number' => 'PAY-1',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'salesman_id' => $sm->id,
            'purchase_price' => 5_000_000,
            'purchase_date' => '2026-04-01',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => 10_000_000,
            'sale_date' => '2026-05-01',
        ]);
        if ($withFirstPbp) {
            PurchaseBalancePayment::create([
                'vehicle_id' => $v->id,
                'amount' => 1_000_000,
                'payment_date' => '2026-04-05',
                'confirmed_at' => now(),
            ]);
        }
        $v->refreshCaches();

        return $v;
    }

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_second_payment_under_50_blocks(): void
    {
        $this->setLock(true);
        $v = $this->vehicle(withFirstPbp: true);
        $this->actingAs($this->finance());

        Volt::test('erp.transfers.index')
            ->call('openNewPbpModal')
            ->set('newPbpVehicleId', (string) $v->id)
            ->set('newPbpAmountStr', '2000000')
            ->set('newPbpDate', '2026-05-10')
            ->call('createNewPbp')
            ->assertSet('showPaymentGate', true);

        // 차단 — 2번째 PBP 미생성 (여전히 1건)
        $this->assertSame(1, $v->purchaseBalancePayments()->count());
    }

    public function test_first_payment_allowed_even_under_50(): void
    {
        $this->setLock(true);
        $v = $this->vehicle(withFirstPbp: false);   // 기존 PBP 없음 = 첫 지급
        $this->actingAs($this->finance());

        Volt::test('erp.transfers.index')
            ->call('openNewPbpModal')
            ->set('newPbpVehicleId', (string) $v->id)
            ->set('newPbpAmountStr', '1000000')
            ->set('newPbpDate', '2026-04-05')
            ->call('createNewPbp')
            ->assertSet('showPaymentGate', false);

        $this->assertSame(1, $v->purchaseBalancePayments()->count());
    }

    public function test_lock_off_no_gate(): void
    {
        $this->setLock(false);
        $v = $this->vehicle(withFirstPbp: true);
        $this->actingAs($this->finance());

        Volt::test('erp.transfers.index')
            ->call('openNewPbpModal')
            ->set('newPbpVehicleId', (string) $v->id)
            ->set('newPbpAmountStr', '2000000')
            ->set('newPbpDate', '2026-05-10')
            ->call('createNewPbp')
            ->assertSet('showPaymentGate', false);

        $this->assertSame(2, $v->purchaseBalancePayments()->count());
    }

    public function test_manager_approve_creates_payment_and_audits(): void
    {
        $this->setLock(true);
        $v = $this->vehicle(withFirstPbp: true);
        $this->actingAs($this->manager());

        Volt::test('erp.transfers.index')
            ->call('openNewPbpModal')
            ->set('newPbpVehicleId', (string) $v->id)
            ->set('newPbpAmountStr', '2000000')
            ->set('newPbpDate', '2026-05-10')
            ->call('createNewPbp')
            ->assertSet('showPaymentGate', true)
            ->set('paymentGateReason', 'L/C 확인 완료. 5/20 잔금 입금 예정.')
            ->call('approvePaymentGate')
            ->assertSet('showPaymentGate', false)
            ->assertSet('showNewPbpModal', false);

        $this->assertSame(2, $v->purchaseBalancePayments()->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Vehicle::class,
            'auditable_id' => $v->id,
            'action' => 'purchase_payment_gate_override',
        ]);
    }
}
