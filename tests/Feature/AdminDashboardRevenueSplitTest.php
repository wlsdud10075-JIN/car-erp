<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 새회의.txt #7 + 3-B (2026-05-23) — 관리자 대시보드 KPI 발생/현금 분리 검증.
 *
 * 회의 결정: 매출 vs 현금흐름 분리 (회계 표준).
 *   - sale_total_krw: 발생주의 (판매 등록 총액)
 *   - cash_received_krw: 현금주의 (실제 입금액, sale_received_krw_accumulated 합)
 *   - unpaid_krw: 미수금 (sale_unpaid_amount_krw_cache 합)
 */
class AdminDashboardRevenueSplitTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_kpis_include_cash_received_and_unpaid_keys(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        $kpis = Volt::test('admin.dashboard')->instance()->kpis;

        $this->assertArrayHasKey('sale_total_krw', $kpis, '발생 매출 KPI');
        $this->assertArrayHasKey('cash_received_krw', $kpis, '현금 회수 KPI');
        $this->assertArrayHasKey('unpaid_krw', $kpis, '미수금 KPI');
    }

    public function test_kpi_split_reflects_partial_payment(): void
    {
        // 시나리오: 판매 1,000만원, 입금 600만원 → 발생=1,000만 / 회수=600만 / 미수=400만
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);

        $v = Vehicle::create([
            'vehicle_number' => 'RS-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 10_000_000, 'sale_date' => now()->format('Y-m-d'),
            'purchase_date' => now()->subMonth()->format('Y-m-d'),
            'purchase_price' => 5_000_000,
        ]);
        $v->finalPayments()->create([
            'amount' => 6_000_000, 'exchange_rate' => 1,
            'payment_date' => now()->format('Y-m-d'),
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();

        $this->actingAs($admin);
        $kpis = Volt::test('admin.dashboard')
            ->set('dateFrom', now()->subMonths(2)->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->call('applyFilters')
            ->instance()->kpis;

        // 발생: sale_price × rate = 10_000_000
        $this->assertSame(10_000_000, $kpis['sale_total_krw']);
        // 회수: 입금된 final_payment 6_000_000
        $this->assertSame(6_000_000, $kpis['cash_received_krw']);
        // 미수 = sale_total_amount - 받은 것. sale_total_amount = 10_000_000 (다른 비용 0). 받은 6_000_000.
        // sale_unpaid_amount_krw_cache 는 sale_unpaid_amount × rate = 4_000_000.
        $this->assertSame(4_000_000, $kpis['unpaid_krw']);
    }
}
