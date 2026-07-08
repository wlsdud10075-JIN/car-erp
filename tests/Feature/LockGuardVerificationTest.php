<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 락/가드 검증 (2026-07-08 jin) — 안 되는 방향을 실제 구동해 락이 걸리는지 + 에러메시지 검증.
 *   (a) 외화 환율 필수 — 판매가 있는 외화 차량에 환율 미입력 시 차단.
 *   (b) 비용/금액 검증 에러메시지 한글화 — raw "cost deregistration str" 누출 방지.
 *   (c) 회계 연관 차량 삭제 사유 모달 + AuditLog.
 */
class LockGuardVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        app()->setLocale('ko');
    }

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function party(): array
    {
        return [
            Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']),
            Buyer::create(['name' => 'LOCK BUYER '.uniqid(), 'is_active' => true]),
        ];
    }

    // ── (a) 외화 환율 필수 ────────────────────────────────────────────
    public function test_foreign_currency_sale_without_rate_is_blocked(): void
    {
        [$sm, $buyer] = $this->party();
        $this->actingAs($this->manager());

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'FX-1')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('currency', 'USD')
            ->set('sale_price_str', '5,000')
            ->set('sale_date', '2026-05-01')
            ->set('exchange_rate_str', '')   // 환율 미입력
            ->call('save')
            ->assertHasErrors('exchange_rate_str');

        $this->assertDatabaseMissing('vehicles', ['vehicle_number' => 'FX-1']);
    }

    public function test_foreign_currency_sale_with_rate_passes(): void
    {
        [$sm, $buyer] = $this->party();
        $this->actingAs($this->manager());

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'FX-2')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('currency', 'USD')
            ->set('sale_price_str', '5,000')
            ->set('sale_date', '2026-05-01')
            ->set('exchange_rate_str', '1300')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vehicles', ['vehicle_number' => 'FX-2']);
    }

    public function test_krw_needs_no_rate(): void
    {
        [$sm, $buyer] = $this->party();
        $this->actingAs($this->manager());

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'FX-KRW')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('currency', 'KRW')
            ->set('sale_price_str', '5,000,000')
            ->set('sale_date', '2026-05-01')
            ->set('exchange_rate_str', '')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_edit_sale_price_without_date_is_friendly_not_500(): void
    {
        // 편집: 판매가 넣고 판매일 비우면 chk_sale_required(DB) 500 대신 "판매일 필수 — 판매 탭".
        [$sm, $buyer] = $this->party();
        $car = Vehicle::create(['vehicle_number' => 'SALE-D', 'sales_channel' => 'heyman', 'salesman_id' => $sm->id]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $car->id)
            ->set('currency', 'KRW')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('sale_price_str', '5,000,000')
            ->set('sale_date', '')
            ->call('save')
            ->assertHasErrors('sale_date');
    }

    public function test_edit_sale_price_without_buyer_is_friendly(): void
    {
        [$sm] = $this->party();
        $car = Vehicle::create(['vehicle_number' => 'SALE-B', 'sales_channel' => 'heyman', 'salesman_id' => $sm->id]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $car->id)
            ->set('currency', 'KRW')
            ->set('buyer_id_str', '')
            ->set('sale_price_str', '5,000,000')
            ->set('sale_date', '2026-05-01')
            ->call('save')
            ->assertHasErrors('buyer_id_str');
    }

    // ── (b) 비용 검증 에러메시지 한글화 ───────────────────────────────
    public function test_negative_cost_error_message_is_korean_not_raw(): void
    {
        [$sm, $buyer] = $this->party();
        $this->actingAs($this->manager());

        $component = Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'COST-1')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('currency', 'KRW')
            ->set('cost_deregistration_str', '-5000')   // 음수 비용
            ->call('save')
            ->assertHasErrors('cost_deregistration_str');

        $errors = $component->errors()->get('cost_deregistration_str');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('말소비', $errors[0], '한글 필드명(말소비)로 안내');
        $this->assertStringNotContainsString('cost_deregistration', $errors[0], 'raw 필드명 노출 금지');
    }

    // ── (c) 회계 연관 차량 삭제 사유 모달 ────────────────────────────
    private function vehicleWithConfirmedPayment(User $actor): Vehicle
    {
        $v = Vehicle::create(['vehicle_number' => 'DEL-'.uniqid(), 'sales_channel' => 'heyman']);
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-04-02',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $actor->id,
        ]);

        return $v->fresh();
    }

    public function test_finance_linked_vehicle_delete_opens_reason_modal_then_logs(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $v = $this->vehicleWithConfirmedPayment($admin);

        Volt::test('erp.vehicles.index')
            ->call('delete', $v->id)
            ->assertSet('showDeleteGate', true);   // 즉시 삭제 안 됨 — 모달

        $this->assertNotNull(Vehicle::find($v->id), '사유 입력 전엔 삭제 안 됨');

        Volt::test('erp.vehicles.index')
            ->call('delete', $v->id)
            ->set('deleteReason', '중복 등록 오류로 삭제')
            ->call('confirmDeleteWithReason')
            ->assertHasNoErrors()
            ->assertSet('showDeleteGate', false);

        $this->assertNull(Vehicle::find($v->id), '사유 입력 후 삭제됨');
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Vehicle::class,
            'auditable_id' => $v->id,
            'action' => 'vehicle_deleted_with_reason',
        ]);
    }

    public function test_plain_vehicle_deletes_without_modal(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $v = Vehicle::create(['vehicle_number' => 'DEL-PLAIN', 'sales_channel' => 'heyman']);

        Volt::test('erp.vehicles.index')
            ->call('delete', $v->id)
            ->assertSet('showDeleteGate', false);

        $this->assertNull(Vehicle::find($v->id), '회계 무관 차량은 즉시 삭제');
    }

    public function test_non_admin_role_blocked_from_confirmed_lock_delete_with_toast(): void
    {
        // 재무 role(canScopeVehicle=true, but canAccessAdmin=false) — 확정 잔금 차량 삭제 시 모달 대신 admin_only 토스트.
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $admin = $this->admin();
        $v = $this->vehicleWithConfirmedPayment($admin);
        $this->actingAs($finance);

        Volt::test('erp.vehicles.index')
            ->call('delete', $v->id)
            ->assertSet('showDeleteGate', false)   // 모달 안 뜸 — 권한 먼저 차단
            ->assertDispatched('notify');

        $this->assertNotNull(Vehicle::find($v->id), '비-관리자 role은 확정 잔금 차량 삭제 불가');
    }

    public function test_business_manager_can_delete_finance_vehicle_via_modal(): void
    {
        // 업무관리자(permission='manager') = admin 등가 → 회계 차량도 모달+사유로 삭제 가능(차단 아님, jin 의도).
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);
        $v = $this->vehicleWithConfirmedPayment($manager);
        $this->actingAs($manager);

        Volt::test('erp.vehicles.index')
            ->call('delete', $v->id)
            ->assertSet('showDeleteGate', true)   // 차단 아님 — 사유 모달
            ->set('deleteReason', '중복 등록 삭제')
            ->call('confirmDeleteWithReason')
            ->assertHasNoErrors();

        $this->assertNull(Vehicle::find($v->id), '업무관리자는 사유 입력 후 삭제 가능');
    }

    // ── Phase 2 — 채권 500 방어 + 잔차 처리 ──────────────────────────
    public function test_receivable_delete_of_confirmed_mirror_is_friendly_not_500(): void
    {
        // 확정 FP에 연결된 채권 deposit 삭제 → DomainException. deleteHistory try/catch 없으면 500(jin 실측).
        $buyer = Buyer::create(['name' => 'RCV BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'RCV-1', 'sales_channel' => 'heyman', 'buyer_id' => $buyer->id,
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
        ]);
        $admin = $this->admin();
        $rh = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-05-02', 'collector_id' => $admin->id,
            'method' => 'deposit', 'amount' => 500_000,
        ]);
        $fp = FinalPayment::find($rh->fresh()->final_payment_id);
        $fp->confirmed_at = now();
        $fp->saveQuietly();

        $this->actingAs($admin);
        Volt::test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->call('deleteHistory', $rh->id)
            ->assertHasNoErrors();   // 500 대신 정상 처리

        $this->assertNotNull(ReceivableHistory::find($rh->id), '확정 연결 이력은 삭제 안 됨(친절 차단)');
    }

    public function test_small_remainder_cleared_by_other_method(): void
    {
        // 998 입금 + 2 남은 잔차 → 채권 「기타(other)」 2 입력 → 미수 0(완납).
        $buyer = Buyer::create(['name' => 'REM BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'REM-1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'currency' => 'USD', 'exchange_rate' => 1300, 'sale_price' => 1000, 'sale_date' => '2026-05-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 998, 'exchange_rate' => 1300, 'payment_date' => '2026-05-02',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $this->admin()->id,
        ]);
        $v->refreshCaches();
        $this->assertSame(2, (int) $v->fresh()->sale_unpaid_amount, '잔차 2 남음');

        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-05-03', 'collector_id' => $this->admin()->id,
            'method' => 'other', 'amount' => 2,
        ]);
        $v->refreshCaches();
        $this->assertSame(0, (int) $v->fresh()->sale_unpaid_amount, '기타 처리로 완납');
    }
}
