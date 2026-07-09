<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * jin 2026-07-09 — 등록 피드백(토스트+창닫힘) / 로그 한글화·차량번호·검색 / 정산 인라인·일괄 확정.
 */
class RegistrationFeedbackAndLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function vehicle(string $number): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $number,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-06-01',
        ]);
    }

    // ── 항목 1: 등록 피드백 ────────────────────────────────────────
    public function test_buyer_save_dispatches_notify_and_closes_panel(): void
    {
        $this->actingAs($this->admin());

        Volt::test('erp.buyers.index')
            ->call('openCreate')
            ->set('name', '테스트바이어')
            ->call('save')
            ->assertDispatched('notify')
            ->assertSet('showPanel', false);

        $this->assertDatabaseHas('buyers', ['name' => '테스트바이어']);
    }

    // ── 항목 2: 로그 한글화 / 차량번호 / 검색 ──────────────────────
    public function test_column_any_resolves_korean_across_tables(): void
    {
        $this->assertSame('판매가', ColumnLabel::columnAny('sale_price'));       // vehicles
        $this->assertSame('정산 상태', ColumnLabel::columnAny('settlement_status')); // settlements
        $this->assertSame('unknown_col', ColumnLabel::columnAny('unknown_col'));   // fallback
    }

    public function test_change_values_are_koreanized(): void
    {
        // enum 값 (테이블 인지)
        $this->assertSame('확정', ColumnLabel::value(Settlement::class, 'settlement_status', 'confirmed'));
        $this->assertSame('건당(사내직원)', ColumnLabel::value(Settlement::class, 'settlement_type', 'per_unit'));
        // boolean
        $this->assertSame('예', ColumnLabel::value(Vehicle::class, 'is_deregistered', '1'));
        $this->assertSame('아니오', ColumnLabel::value(Vehicle::class, 'is_deregistered', '0'));
        // 매핑 없는 값(금액 등)은 원문 그대로
        $this->assertSame('1500000', ColumnLabel::value(Vehicle::class, 'sale_price', '1500000'));
        // null 보존
        $this->assertNull(ColumnLabel::value(Settlement::class, 'settlement_status', null));
    }

    public function test_custom_actions_are_koreanized(): void
    {
        $this->assertSame('정산 지급 승인(카톡)', ColumnLabel::action('payout_approved_via_link'));
        $this->assertSame('확정금액 수정 허용', ColumnLabel::action('ledger_field_unlocked'));
    }

    public function test_audit_log_resolves_vehicle_number_and_search(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $v1 = $this->vehicle('11가1111');
        $v2 = $this->vehicle('22나2222');

        AuditLog::create([
            'user_id' => $admin->id, 'auditable_type' => Vehicle::class, 'auditable_id' => $v1->id,
            'action' => 'updated', 'column_name' => 'sale_price', 'old_value' => '0', 'new_value' => '100',
        ]);
        AuditLog::create([
            'user_id' => $admin->id, 'auditable_type' => Vehicle::class, 'auditable_id' => $v2->id,
            'action' => 'updated', 'column_name' => 'sale_price', 'old_value' => '0', 'new_value' => '200',
        ]);

        // 검색 = 차량번호 → 해당 차량 로그만
        $component = Volt::test('admin.audit-logs.index')->set('search', '11가1111');
        $logs = $component->instance()->logs;
        foreach ($logs as $log) {
            $this->assertSame($v1->id, $log->auditable_id);
        }
        $this->assertGreaterThanOrEqual(1, $logs->count());

        // 차량번호 해석 맵
        $map = $component->instance()->vehicleNumbers;
        foreach ($logs as $log) {
            $this->assertSame('11가1111', $map[$log->id]);
        }
    }

    public function test_audit_search_no_match_returns_zero(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $v = $this->vehicle('33다3333');
        AuditLog::create([
            'user_id' => $admin->id, 'auditable_type' => Vehicle::class, 'auditable_id' => $v->id,
            'action' => 'created', 'column_name' => null,
        ]);

        $logs = Volt::test('admin.audit-logs.index')->set('search', '존재안함9999')->instance()->logs;
        $this->assertSame(0, $logs->count());
    }

    // ── 항목 3: 정산 인라인 / 일괄 확정 ────────────────────────────
    private function pendingSettlement(Vehicle $v, Salesman $sm, string $month): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit',
            'per_unit_amount' => 100000,
            'settlement_status' => 'pending',
            'attributed_month' => $month.'-01',
        ]);
    }

    public function test_confirm_settlement_inline(): void
    {
        $this->actingAs($this->admin());
        $sm = Salesman::create(['name' => '김직원', 'type' => 'employee', 'is_active' => true]);
        $v = $this->vehicle('44라4444');
        $s = $this->pendingSettlement($v, $sm, '2026-07');

        Volt::test('erp.settlements.index')
            ->call('confirmSettlement', $s->id)
            ->assertDispatched('notify');

        $s->refresh();
        $this->assertSame('confirmed', $s->settlement_status);
        $this->assertNotNull($s->confirmed_at);
    }

    public function test_confirm_pins_null_attributed_month_to_current_bucket(): void
    {
        $this->actingAs($this->admin());
        $sm = Salesman::create(['name' => '박직원', 'type' => 'employee', 'is_active' => true]);
        $s = $this->pendingSettlement($this->vehicle('88아8888'), $sm, '2026-07');
        // 레거시(백필 전) 상황 재현: attributed_month=null, created_at 을 7/15(귀속월 7월)로 고정
        Settlement::where('id', $s->id)->update(['attributed_month' => null, 'created_at' => '2026-07-15 10:00:00']);

        Volt::test('erp.settlements.index')->call('confirmSettlement', $s->id);

        $s->refresh();
        $this->assertSame('confirmed', $s->settlement_status);
        // 확정 시점(now)이 아니라 원래 버킷(7월)으로 고정 — 드리프트 방지
        $this->assertSame('2026-07', $s->attributed_month?->format('Y-m'));
    }

    public function test_savings_neg_balance_blocks_without_false_success(): void
    {
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => '잔액테스트', 'is_active' => true]);

        Volt::test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('txn_type', 'USED')
            ->set('txn_amount', 500000)   // 잔액 0 인데 USED → 음수
            ->call('addSavingsTransaction')
            ->assertHasErrors('txn_amount');

        // 실패 → 적립금 USED 행 생성 안 됨 (거짓 성공 없음)
        $this->assertDatabaseMissing('savings_statuses', [
            'buyer_id' => $buyer->id,
            'transaction_type' => 'USED',
        ]);
    }

    public function test_confirm_month_bulk(): void
    {
        $this->actingAs($this->admin());
        $sm = Salesman::create(['name' => '이직원', 'type' => 'employee', 'is_active' => true]);
        $a = $this->pendingSettlement($this->vehicle('55마5555'), $sm, '2026-07');
        $b = $this->pendingSettlement($this->vehicle('66바6666'), $sm, '2026-07');
        $other = $this->pendingSettlement($this->vehicle('77사7777'), $sm, '2026-08');

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->call('confirmMonth')
            ->assertDispatched('notify');

        $this->assertSame('confirmed', $a->refresh()->settlement_status);
        $this->assertSame('confirmed', $b->refresh()->settlement_status);
        // 다른 월은 그대로
        $this->assertSame('pending', $other->refresh()->settlement_status);
    }
}
