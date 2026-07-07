<?php

namespace Tests\Feature;

use App\Models\ForwardingCompany;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * item 7 (jin 2026-07-07) — 포워딩사별 선적 현황 (운임비 통화별 합산 + 기간 + 검색).
 */
class ForwardingShipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function shipVehicle(int $fcId, array $attr = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '77가'.rand(1000, 9999),
            'sales_channel' => 'export',
            'forwarding_company_id' => $fcId,
            'shipping_date' => '2026-05-10',
        ], $attr));
    }

    public function test_fees_summed_per_currency(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD ALPHA', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 1000]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 500]);
        $this->shipVehicle($fc->id, ['currency' => 'JPY', 'transport_fee' => 2000]);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')
            ->assertSee('FWD ALPHA')
            ->assertSee('USD 1,500')   // 1000 + 500
            ->assertSee('JPY 2,000');  // 통화별 분리 합산
    }

    public function test_search_by_vessel_filters_shipments(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD BETA', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 1000, 'vessel_name' => 'EVER GIVEN']);
        $this->shipVehicle($fc->id, ['currency' => 'JPY', 'transport_fee' => 9000, 'vessel_name' => 'OTHER SHIP']);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')
            ->set('search', 'EVER GIVEN')
            ->call('searchNow')
            ->assertSee('USD 1,000')
            ->assertDontSee('JPY 9,000');   // 검색 밖 차량은 합산 제외
    }

    public function test_date_range_filters_shipments(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD GAMMA', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 1000, 'shipping_date' => '2026-05-15']);
        $this->shipVehicle($fc->id, ['currency' => 'USD', 'transport_fee' => 7000, 'shipping_date' => '2026-03-01']);

        $this->actingAs($this->admin());

        Volt::test('erp.forwarding-companies.index')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->assertSee('USD 1,000')
            ->assertDontSee('7,000');   // 5월 밖 선적 제외
    }
}
