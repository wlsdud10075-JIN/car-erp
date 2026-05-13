<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 큐 4 — 관리자 대시보드 차트 보강.
 * 8-1: 기간 필터 보강 (기준일 컬럼 전환 + 빠른 범위 선택).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '전체']);
    }

    // ── 8-1: 기준일 컬럼 전환 ──────────────────────────────────────────

    public function test_kpis_filters_by_purchase_date_when_date_type_is_purchase(): void
    {
        $this->actingAs($this->admin());

        // 매입일 in-range 1대, sale_date in-range지만 purchase_date out-of-range 1대
        Vehicle::create([
            'vehicle_number' => 'IN-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2026-05-10', 'purchase_price' => 1000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'OUT-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2025-01-01',  // out
            'sale_date' => '2026-05-10',       // in
            'sale_price' => 2000,
        ]);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateType', 'purchase')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('kpis');

        $this->assertSame(1, $kpis['vehicles']);  // 매입 in-range만
        $this->assertSame(1000, $kpis['purchase_total']);
    }

    public function test_kpis_filters_by_sale_date_when_date_type_is_sale(): void
    {
        $this->actingAs($this->admin());

        Vehicle::create([
            'vehicle_number' => 'IN-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2025-01-01',  // out of purchase range
            'sale_date' => '2026-05-10',       // in of sale range
            'sale_price' => 2000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'OUT-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2026-05-10',  // in purchase
            'sale_date' => '2025-01-01',       // out of sale
            'sale_price' => 1000,
        ]);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateType', 'sale')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('kpis');

        $this->assertSame(1, $kpis['vehicles']);
        $this->assertSame(2000, $kpis['sale_total_krw']);
    }

    public function test_kpis_completed_date_type_maps_to_bl_issue_date(): void
    {
        $this->actingAs($this->admin());

        Vehicle::create([
            'vehicle_number' => 'IN-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2025-01-01',
            'bl_issue_date' => '2026-05-10',
        ]);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateType', 'completed')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('kpis');

        $this->assertSame(1, $kpis['vehicles']);
    }

    // ── 8-1: 빠른 범위 선택 ────────────────────────────────────────────

    public function test_set_quick_range_this_month_sets_first_to_last_day(): void
    {
        $this->actingAs($this->admin());

        $component = Volt::test('admin.dashboard')->call('setQuickRange', 'this_month');

        $from = now()->copy()->startOfMonth()->format('Y-m-d');
        $to = now()->copy()->endOfMonth()->format('Y-m-d');

        $this->assertSame($from, $component->get('dateFrom'));
        $this->assertSame($to, $component->get('dateTo'));
    }

    public function test_set_quick_range_last_year_sets_jan_1_to_dec_31(): void
    {
        $this->actingAs($this->admin());

        $component = Volt::test('admin.dashboard')->call('setQuickRange', 'last_year');

        $lastYear = now()->year - 1;
        $this->assertSame("{$lastYear}-01-01", $component->get('dateFrom'));
        $this->assertSame("{$lastYear}-12-31", $component->get('dateTo'));
    }

    public function test_vehicles_url_maps_completed_date_type_to_bl(): void
    {
        $this->actingAs($this->admin());

        $url = Volt::test('admin.dashboard')
            ->set('dateType', 'completed')
            ->set('dateFrom', '2026-01-01')
            ->set('dateTo', '2026-12-31')
            ->instance()
            ->vehiclesUrl();

        $this->assertStringContainsString('dateType=bl', $url);
    }
}
