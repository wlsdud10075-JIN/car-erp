<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량관리 목록 검색에 구입처(purchase_from) 포함 (2026-07-13).
 */
class VehiclePurchaseFromSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_purchase_from(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        $match = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'purchase_price' => 1000000, 'dhl_request' => false,
            'purchase_from' => '서울오토경매장',
        ]);
        $other = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'purchase_price' => 1000000, 'dhl_request' => false,
            'purchase_from' => '부산딜러',
        ]);

        Volt::test('erp.vehicles.index')
            ->set('search', '오토경매')
            ->assertSee($match->vehicle_number)
            ->assertDontSee($other->vehicle_number);
    }
}
