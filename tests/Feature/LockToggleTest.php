<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Phase 0 — Lock spot 토글 인프라 (2026-07-10).
 *   돈 흐름 락 4종을 기능설정 "락 관제"에서 super 가 토글. 게이트는 Setting::lockEnabled 단일 출처.
 */
class LockToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function super(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    private function setLock(string $lock, bool $on): void
    {
        Setting::updateOrCreate(
            ['key' => 'lock_'.$lock.'_'.Setting::companyTemplateSet()],
            ['value' => $on ? '1' : '0', 'type' => 'boolean'],
        );
    }

    /** 말소완료 + 판매 <50% 입금 + 선적 컨사이니 지정된 export 차량 (C5 평가 도달용). */
    private function underpaidShippable(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $sm = Salesman::create(['name' => 'S', 'is_active' => true, 'type' => 'freelance']);
        $consignee = Consignee::create(['name' => 'CONS', 'buyer_id' => $buyer->id, 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'SHIP-1',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'salesman_id' => $sm->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => 10_000_000,
            'sale_date' => '2026-05-01',
            'is_deregistered' => true,
            'deregistration_document' => 'derg.pdf',
            'bl_consignee_id' => $consignee->id,   // C4/컨사이니 통과, C5(미수)만 평가
        ]);
        $v->refreshCaches();

        return $v;
    }

    public function test_lock_enabled_returns_defaults_when_unset(): void
    {
        // 기본값: 매입등록·선적진입·B/L = ON, 매입지급 = OFF (미설정 시 LOCK_DEFAULTS).
        $this->assertTrue(Setting::lockEnabled('purchase_registration'));
        $this->assertTrue(Setting::lockEnabled('shipping_entry'));
        $this->assertTrue(Setting::lockEnabled('bl_issue'));
        $this->assertFalse(Setting::lockEnabled('purchase_payment'));
    }

    public function test_super_toggle_persists_per_company_and_writes_audit(): void
    {
        $this->actingAs($this->super());

        Volt::test('admin.settings')
            ->set('lockToggles.purchase_payment', true)
            ->assertHasNoErrors();

        $set = Setting::companyTemplateSet();
        $this->assertTrue(Setting::lockEnabled('purchase_payment'));
        $this->assertDatabaseHas('settings', ['key' => 'lock_purchase_payment_'.$set, 'value' => '1']);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Setting::class,
            'action' => 'lock_toggle_changed',
            'column_name' => 'lock_purchase_payment',
            'new_value' => '1',
        ]);
    }

    public function test_super_can_turn_existing_lock_off(): void
    {
        $this->actingAs($this->super());

        Volt::test('admin.settings')
            ->set('lockToggles.bl_issue', false)
            ->assertHasNoErrors();

        $this->assertFalse(Setting::lockEnabled('bl_issue'));
    }

    public function test_non_super_cannot_open_settings(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        Volt::test('admin.settings')->assertStatus(403);
    }

    // ── Phase 1 — 기존 락 3개 토글 래핑 (OFF 시 게이트 skip) ──────────────

    public function test_shipping_entry_lock_off_bypasses_c5(): void
    {
        $v = $this->underpaidShippable();
        $v->bl_loading_location = 'BUSAN';   // 선적 진입 입력

        // 락 ON(기본) → C5 차단
        $this->setLock('shipping_entry', true);
        try {
            $v->guardStageOrderForExport();
            $this->fail('락 ON 이면 C5(미수 50%)가 차단해야 함');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('export_buyer_id', $e->errors());
        }

        // 락 OFF → 통과
        $this->setLock('shipping_entry', false);
        $v->guardStageOrderForExport();
        $this->assertTrue(true, '락 OFF 면 미수 무관 통과');
    }

    public function test_bl_issue_lock_off_bypasses_g1(): void
    {
        $this->actingAs($this->super());   // G1 은 auth()->check() 필요
        $v = $this->underpaidShippable();
        $v->bl_document = 'bl.pdf';         // 신규 B/L 첨부 (original 없음)

        // 락 ON(기본) → G1 차단 (미완납)
        $this->setLock('bl_issue', true);
        try {
            $v->guardBlFiftyPercentRuleOnSaving();
            $this->fail('락 ON 이면 G1(100% 미완납)이 차단해야 함');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bl_document', $e->errors());
        }

        // 락 OFF → 통과
        $this->setLock('bl_issue', false);
        $v->guardBlFiftyPercentRuleOnSaving();
        $this->assertTrue(true, '락 OFF 면 미완납이어도 발행 통과');
    }

    public function test_purchase_registration_lock_off_allows_over_threshold_buyer(): void
    {
        // 미수 100% 바이어 (판매 1000만, 입금 0) — 락 ON 이면 등록 게이트 발동.
        $buyer = Buyer::create(['name' => '미수바이어', 'is_active' => true]);
        $sm = Salesman::create(['name' => '기존', 'is_active' => true, 'type' => 'freelance']);
        $owed = Vehicle::create([
            'vehicle_number' => 'OWED-1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'salesman_id' => $sm->id, 'currency' => 'KRW', 'exchange_rate' => 1,
            'sale_price' => 10_000_000, 'sale_date' => '2026-05-01',
        ]);
        $owed->refreshCaches();

        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]));
        $this->setLock('purchase_registration', false);

        // 락 OFF → 게이트 미발동, 바로 등록 성공
        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'FREE-1')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('purchase_price_str', '5,000,000')
            ->call('save')
            ->assertSet('showPurchaseGate', false)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vehicles', ['vehicle_number' => 'FREE-1']);
    }
}
