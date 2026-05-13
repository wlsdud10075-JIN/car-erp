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

    // ── 8-2: 월별 차트 데이터 ──────────────────────────────────────────

    public function test_monthly_chart_data_buckets_purchase_count_by_month(): void
    {
        $this->actingAs($this->admin());

        // 2026년 3월 × 2, 5월 × 1
        Vehicle::create([
            'vehicle_number' => 'M-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2026-03-10', 'purchase_price' => 1000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'M-2', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2026-03-25', 'purchase_price' => 1000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'M-3', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2026-05-15', 'purchase_price' => 1000,
        ]);
        // 2025년 — 다른 해
        Vehicle::create([
            'vehicle_number' => 'M-PREV', 'sales_channel' => 'export', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'purchase_date' => '2025-03-10', 'purchase_price' => 1000,
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-01-01')
            ->set('dateTo', '2026-12-31')
            ->call('applyFilters')
            ->get('monthlyChartData');

        $this->assertSame(2026, $data['year']);
        $this->assertCount(12, $data['counts']['purchase']);
        $this->assertSame(2, $data['counts']['purchase'][2]);  // 3월 (0-indexed = 2)
        $this->assertSame(1, $data['counts']['purchase'][4]);  // 5월
        $this->assertSame(0, $data['counts']['purchase'][0]);  // 1월
    }

    public function test_monthly_chart_data_sums_sales_krw_with_exchange_rate(): void
    {
        $this->actingAs($this->admin());

        // 5월 — KRW 1000원 + USD 100 × 1300 = 130000원 → 131000원
        Vehicle::create([
            'vehicle_number' => 'S-1', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'is_disposed' => false, 'dhl_request' => false,
            'sale_date' => '2026-05-10', 'sale_price' => 1000, 'exchange_rate' => 1,
        ]);
        Vehicle::create([
            'vehicle_number' => 'S-2', 'sales_channel' => 'export', 'currency' => 'USD',
            'is_disposed' => false, 'dhl_request' => false,
            'sale_date' => '2026-05-20', 'sale_price' => 100, 'exchange_rate' => 1300,
        ]);
        // 환율 0 — KRW 환산 불가 → 제외
        Vehicle::create([
            'vehicle_number' => 'S-EX', 'sales_channel' => 'export', 'currency' => 'USD',
            'is_disposed' => false, 'dhl_request' => false,
            'sale_date' => '2026-05-25', 'sale_price' => 999, 'exchange_rate' => 0,
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-01-01')
            ->call('applyFilters')
            ->get('monthlyChartData');

        $this->assertSame(131000, $data['sales_krw'][4]);  // 5월
        $this->assertSame(0, $data['sales_krw'][0]);
    }

    public function test_monthly_chart_data_year_derives_from_date_from(): void
    {
        $this->actingAs($this->admin());

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2024-07-01')
            ->call('applyFilters')
            ->get('monthlyChartData');

        $this->assertSame(2024, $data['year']);
    }
}
