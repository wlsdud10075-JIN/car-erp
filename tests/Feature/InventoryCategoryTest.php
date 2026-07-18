<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 재고 2분류 (jin 2026-07-18):
 *   일반재고(general)   = 재고(매입완납·출고전) 중 미판매(sale_price ≤ 0).
 *   선적전 재고(pre_ship) = 재고 중 판매됨(sale_price > 0).
 */
class InventoryCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function stockVehicle(bool $sold, string $num, bool $shippedOut = false): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => $num, 'sales_channel' => 'export',
            'purchase_price' => 5_000_000, 'selling_fee' => 0,
            'purchase_date' => now()->subMonth()->toDateString(),
            'currency' => 'KRW', 'exchange_rate' => 1,
            'warehouse_out_date' => $shippedOut ? now()->toDateString() : null,
        ]);
        // 매입 완납 (confirmed PBP) → 입고됨
        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            $v->purchaseBalancePayments()->create([
                'amount' => 5_000_000, 'type' => 'down',
                'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now(),
            ]);
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }
        if ($sold) {
            $buyer = Buyer::create(['name' => 'B'.$num, 'is_active' => true]);
            $v->update(['sale_price' => 10_000_000, 'sale_date' => now()->toDateString(), 'buyer_id' => $buyer->id]);
        }

        return $v->fresh();
    }

    public function test_general_stock_is_unsold_in_stock(): void
    {
        $unsold = $this->stockVehicle(false, '11가1111');
        $sold = $this->stockVehicle(true, '22나2222');

        $ids = Vehicle::query()->generalStock()->pluck('id')->all();
        $this->assertContains($unsold->id, $ids);
        $this->assertNotContains($sold->id, $ids, '판매됨은 일반재고 아님');
    }

    public function test_pre_shipping_stock_is_sold_in_stock(): void
    {
        $unsold = $this->stockVehicle(false, '33다3333');
        $sold = $this->stockVehicle(true, '44라4444');

        $ids = Vehicle::query()->preShippingStock()->pluck('id')->all();
        $this->assertContains($sold->id, $ids);
        $this->assertNotContains($unsold->id, $ids, '미판매는 선적전 재고 아님');
    }

    public function test_shipped_out_leaves_both_categories(): void
    {
        // 출고일 찍힌 판매차 = 출고됨 → 재고(양 카테고리) 이탈.
        $out = $this->stockVehicle(true, '55마5555', shippedOut: true);

        $this->assertNotContains($out->id, Vehicle::query()->generalStock()->pluck('id')->all());
        $this->assertNotContains($out->id, Vehicle::query()->preShippingStock()->pluck('id')->all());
    }

    public function test_inventory_screen_category_filter_and_counts(): void
    {
        $unsold = $this->stockVehicle(false, '66바6666');
        $sold = $this->stockVehicle(true, '77사7777');

        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        $counts = Volt::test('erp.inventory.index')->instance()->categoryCounts;
        $this->assertSame(1, $counts['general']);
        $this->assertSame(1, $counts['pre_ship']);

        // 일반재고 탭 → 미판매만
        $generalIds = Volt::test('erp.inventory.index')->set('category', 'general')
            ->instance()->inventoryVehicles->pluck('id')->all();
        $this->assertContains($unsold->id, $generalIds);
        $this->assertNotContains($sold->id, $generalIds);
    }
}
