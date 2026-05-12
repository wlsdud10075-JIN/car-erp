<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardActionCountsTest extends TestCase
{
    use RefreshDatabase;

    private int $vehicleCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite 테스트에서 export_buyer_id 등 가짜 ID 사용 가능하도록 FK 비활성화.
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->vehicleCounter++;

        return Vehicle::create(array_merge([
            'vehicle_number' => 'TEST-'.$this->vehicleCounter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'is_disposed' => false,
            'dhl_request' => false,
        ], $overrides));
    }

    // ── 14 액션 케이스 (영업 5 / 통관 7 / 정산 5) ──────────────────────

    public function test_purchase_unpaid_counts_only_outstanding_purchase_balance(): void
    {
        $this->makeVehicle(['purchase_price' => 1000, 'down_payment' => 500]);
        $this->makeVehicle(['purchase_price' => 1000, 'down_payment' => 1000]);

        $this->assertSame(1, Vehicle::action('purchase_unpaid')->count());
    }

    public function test_sale_unpaid_includes_null_krw_cache_for_fx_missing(): void
    {
        // KRW 완납
        $this->makeVehicle(['sale_price' => 1000, 'deposit_down_payment' => 1000]);
        // KRW 미입금
        $this->makeVehicle(['sale_price' => 1000, 'deposit_down_payment' => 500]);
        // 외화 환율 0 → KRW 캐시 NULL → sale_unpaid 카운트에 포함 (orWhereNull 분기)
        $this->makeVehicle(['sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 0]);

        $this->assertSame(2, Vehicle::action('sale_unpaid')->count());
    }

    public function test_clearance_needed_isolates_export_channel_only(): void
    {
        $this->makeVehicle(['sales_channel' => 'heyman', 'sale_price' => 1000, 'deposit_down_payment' => 1000]);
        $this->makeVehicle(['sales_channel' => 'export', 'sale_price' => 1000, 'deposit_down_payment' => 1000]);

        $this->assertSame(1, Vehicle::action('clearance_needed')->count());
    }

    public function test_shipping_needed_requires_export_declaration_without_bl(): void
    {
        $this->makeVehicle(['export_declaration_document' => 'edoc.pdf']);
        $this->makeVehicle(['export_declaration_document' => 'edoc.pdf', 'bl_document' => 'bl.pdf']);

        $this->assertSame(1, Vehicle::action('shipping_needed')->count());
    }

    public function test_dhl_needed_requires_bl_with_dhl_request_false(): void
    {
        $this->makeVehicle(['bl_document' => 'bl.pdf', 'dhl_request' => false]);
        $this->makeVehicle(['bl_document' => 'bl.pdf', 'dhl_request' => true]);

        $this->assertSame(1, Vehicle::action('dhl_needed')->count());
    }

    public function test_clearance_info_missing_for_export_buyer_or_shipping_date_null(): void
    {
        $buyer = Buyer::create(['name' => 'TEST BUYER', 'is_active' => true]);
        $this->makeVehicle(['sale_price' => 1000, 'export_buyer_id' => null, 'shipping_date' => null]);
        $this->makeVehicle(['sale_price' => 1000, 'export_buyer_id' => $buyer->id, 'shipping_date' => '2026-05-01']);

        $this->assertSame(1, Vehicle::action('clearance_info_missing')->count());
    }

    public function test_forwarding_missing_when_export_buyer_set_but_forwarding_null(): void
    {
        $buyer = Buyer::create(['name' => 'TEST BUYER', 'is_active' => true]);
        $fwd = ForwardingCompany::create(['name' => 'TEST FWD', 'is_active' => true]);

        $this->makeVehicle(['export_buyer_id' => $buyer->id, 'shipping_date' => '2026-05-01', 'forwarding_company_id' => null]);
        $this->makeVehicle(['export_buyer_id' => $buyer->id, 'shipping_date' => '2026-05-01', 'forwarding_company_id' => $fwd->id]);

        $this->assertSame(1, Vehicle::action('forwarding_missing')->count());
    }

    public function test_shipping_process_needed_when_declaration_set_but_bl_loading_null(): void
    {
        $this->makeVehicle(['export_declaration_document' => 'edoc.pdf', 'bl_loading_location' => null]);
        $this->makeVehicle(['export_declaration_document' => 'edoc.pdf', 'bl_loading_location' => '부산항']);

        $this->assertSame(1, Vehicle::action('shipping_process_needed')->count());
    }

    public function test_bl_upload_needed_when_loading_location_set_but_bl_document_null(): void
    {
        $this->makeVehicle(['bl_loading_location' => '부산항', 'bl_document' => null]);
        $this->makeVehicle(['bl_loading_location' => '부산항', 'bl_document' => 'bl.pdf']);

        $this->assertSame(1, Vehicle::action('bl_upload_needed')->count());
    }

    public function test_exchange_rate_missing_for_foreign_currency_without_rate(): void
    {
        $this->makeVehicle(['currency' => 'USD', 'exchange_rate' => 0, 'sale_price' => 1000]);
        $this->makeVehicle(['currency' => 'USD', 'exchange_rate' => 1350, 'sale_price' => 1000]);
        $this->makeVehicle(['currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1000]);

        $this->assertSame(1, Vehicle::action('exchange_rate_missing')->count());
    }

    public function test_receivable_risk_counts_only_danger_or_critical(): void
    {
        // BL 발행 + 미입금 → critical
        $this->makeVehicle(['sale_price' => 1000, 'deposit_down_payment' => 0, 'bl_document' => 'bl.pdf', 'dhl_request' => false]);
        // 50% 미입금 → caution (제외)
        $this->makeVehicle(['sale_price' => 1000, 'deposit_down_payment' => 500]);
        // 완납 → safe (제외)
        $this->makeVehicle(['sale_price' => 1000, 'deposit_down_payment' => 1000]);

        $this->assertSame(1, Vehicle::action('receivable_risk')->count());
    }

    // ── M2 보안: selectedSalesmanId 권한 우회 차단 ──────────────────────

    public function test_non_admin_cannot_change_selected_salesman_id(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $this->actingAs($user);

        Volt::test('erp.dashboard')
            ->set('selectedSalesmanId', 9999)
            ->assertSet('selectedSalesmanId', 0);
    }

    public function test_admin_can_change_selected_salesman_id_freely(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        Volt::test('erp.dashboard')
            ->set('selectedSalesmanId', 5)
            ->assertSet('selectedSalesmanId', 5);
    }

    public function test_non_admin_with_salesman_snaps_selected_salesman_to_own_id(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $salesman = Salesman::create(['name' => 'TEST 영업', 'is_active' => true, 'user_id' => $user->id]);
        $this->actingAs($user);

        Volt::test('erp.dashboard')
            ->set('selectedSalesmanId', 9999)
            ->assertSet('selectedSalesmanId', $salesman->id);
    }

    // ── advisor 발견 버그 회귀: admin이 영업 뷰에서 담당자 N 선택 후 통관/정산 뷰 전환 시 N 무시 ──

    public function test_admin_salesman_filter_ignored_in_clearance_view(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $salesman = Salesman::create(['name' => 'TEST 영업', 'is_active' => true]);
        $this->actingAs($admin);

        // 김영업 차량 1대, 다른 담당자 차량 1대 → 통관 뷰에선 둘 다 active 카운트
        $this->makeVehicle(['salesman_id' => $salesman->id]);
        $this->makeVehicle(['salesman_id' => null]);

        $component = Volt::test('erp.dashboard')
            ->set('selectedSalesmanId', $salesman->id)
            ->set('roleView', '통관');

        // 통관 뷰의 activeVehicles는 selectedSalesmanId 무시 → 2대 모두 보여야
        $this->assertSame(2, $component->get('activeVehicles')->count());
    }

    // ── M3 가드: roleView 변경 권한 ───────────────────────────────────

    public function test_non_toggle_eligible_user_cannot_change_role_view(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $this->actingAs($user);

        Volt::test('erp.dashboard')
            ->set('roleView', '정산')
            ->assertSet('roleView', '영업');
    }

    public function test_role_jeonche_user_can_toggle_role_view_freely(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '전체']);
        $this->actingAs($user);

        Volt::test('erp.dashboard')
            ->set('roleView', '정산')
            ->assertSet('roleView', '정산');
    }

    public function test_admin_can_toggle_role_view_freely(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '전체']);
        $this->actingAs($admin);

        Volt::test('erp.dashboard')
            ->set('roleView', '통관')
            ->assertSet('roleView', '통관');
    }
}
