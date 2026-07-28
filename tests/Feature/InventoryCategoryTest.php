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

    /**
     * 출고완료 탭 (jin 2026-07-28) — 출고일이 찍히면 재고에서 빠져 어디서도 못 보던 차량 조회용.
     * inStock() 은 whereNull(warehouse_out_date) 라 이 탭만 스코프가 배타적으로 분기한다.
     */
    public function test_shipped_out_tab_lists_released_vehicles_only(): void
    {
        $inStock = $this->stockVehicle(true, '88아8888');
        $released = $this->stockVehicle(true, '99자9999', shippedOut: true);

        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        $ids = Volt::test('erp.inventory.index')->set('category', 'shipped_out')
            ->instance()->inventoryVehicles->pluck('id')->all();

        $this->assertContains($released->id, $ids, '출고된 차량이 출고완료 탭에 없다');
        $this->assertNotContains($inStock->id, $ids, '재고 중인 차량이 출고완료 탭에 섞였다');
    }

    /** 출고완료 카운트는 재고 합계(cat_all)와 별개로 집계된다. */
    public function test_shipped_out_count_is_separate_from_stock_counts(): void
    {
        $this->stockVehicle(true, '10가1010');
        $this->stockVehicle(true, '20나2020', shippedOut: true);

        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        $counts = Volt::test('erp.inventory.index')->instance()->categoryCounts;
        $this->assertSame(1, $counts['pre_ship'], '출고분은 재고 카운트에서 빠져야 한다');
        $this->assertSame(1, $counts['shipped_out']);
    }

    /** 출고일 변경이 감사로그에 남아야 한다 (누가 언제 출고 처리했는지 추적). */
    public function test_warehouse_out_date_is_audited(): void
    {
        $this->assertContains('warehouse_out_date', Vehicle::AUDITED_COLUMNS);

        $v = $this->stockVehicle(true, '30다3030');
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        $v->update(['warehouse_out_date' => now()->toDateString()]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Vehicle::class,
            'auditable_id' => $v->id,
            'column_name' => 'warehouse_out_date',
        ]);
    }
}
