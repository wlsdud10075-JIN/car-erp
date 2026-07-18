<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * item 6 (jin 2026-07-18) — 차량목록 운임비 정확검색 + 운임비 합 총계.
 * 메인 검색과 분리(차번호 숫자 충돌 방지). freightTotals = 필터 결과 운임비 합·건수.
 */
class VehicleFreightSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    public function test_freight_total_and_exact_filter(): void
    {
        $this->actingAs($this->admin());
        Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'transport_fee' => 800000]);
        Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export', 'transport_fee' => 1200000]);
        Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export', 'transport_fee' => 800000]);

        $c = Volt::test('erp.vehicles.index')->set('dateType', 'all');

        // 전체 운임비 합 = 800k + 1.2M + 800k = 2.8M / 3대
        $this->assertSame(2_800_000, $c->instance()->freightTotals['sum']);
        $this->assertSame(3, $c->instance()->freightTotals['count']);
        // 판매총액 합 — sale_price 등 미입력이라 운임비만 = 2.8M (sale_total = sale_price+운임+...−tax_dc)
        $this->assertSame(2_800_000, $c->instance()->freightTotals['sale_total_sum']);

        // 운임비 정확검색 800,000 (콤마 포함 입력) → 2대만
        $c->set('freightExact', '800,000')->call('applyFilters');
        $this->assertSame(1_600_000, $c->instance()->freightTotals['sum']);
        $this->assertSame(2, $c->instance()->freightTotals['count']);

        $ids = $c->instance()->vehicles->pluck('transport_fee')->all();
        $this->assertSame([800000, 800000], array_map('intval', $ids));
    }
}
