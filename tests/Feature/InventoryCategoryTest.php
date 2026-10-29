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

    // ── 🚚 2026-10-29 (jin (가)) — 선적일이 지난 차는 출고일 없이도 재고에서 빠져 출고완료로 간다 ──

    public function test_a_shipped_car_leaves_stock_without_an_out_date(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $gone = $this->stockVehicle(sold: true, num: '81가8101');
        $gone->update(['shipping_date' => now()->subDays(3)->toDateString()]);   // 통관완료까지 갔는데 B/L 파일이 없는 흔한 모양
        $booked = $this->stockVehicle(sold: true, num: '82나8202');
        $booked->update(['shipping_date' => now()->addDays(5)->toDateString()]);   // 배 예약 — 아직 안 나감
        $today = $this->stockVehicle(sold: false, num: '83다8303');
        $today->update(['shipping_date' => now()->toDateString()]);   // 오늘 선적 = 떠난 것(경계 포함)
        $this->assertNull($gone->fresh()->warehouse_out_date, '출고일 데이터는 안 건드린다');

        $this->assertSame(['82나8202'], Vehicle::query()->inStock()->pluck('vehicle_number')->all());
        $this->assertSame(['82나8202'], Vehicle::query()->preShippingStock()->pluck('vehicle_number')->all());
        $this->assertSame([], Vehicle::query()->generalStock()->pluck('vehicle_number')->all());
        $left = Vehicle::query()->leftStock()->pluck('vehicle_number')->all();
        sort($left);
        $this->assertSame(['81가8101', '83다8303'], $left);
        $this->assertSame(['81가8101', '83다8303'], Vehicle::query()->inventoryShippedOut()->orderBy('vehicle_number')->pluck('vehicle_number')->all(), 'board /inventory shipped_out 도 같은 경계');

        // 화면 — 출고완료 탭에 두 대, 재고 카운트엔 한 대
        $c = Volt::test('erp.inventory.index');
        $c->call('setCategory', 'shipped_out')->assertSee('81가8101')->assertSee('83다8303')->assertDontSee('82나8202');
        $counts = $c->instance()->categoryCounts();
        $this->assertSame(2, $counts['shipped_out']);
        $this->assertSame(1, $counts['pre_ship']);
    }

    /** 출고일을 먼저 찍어도 종전대로 즉시 빠진다 — 두 신호의 합집합. */
    public function test_still_in_yard_and_left_stock_are_complements(): void
    {
        $a = $this->stockVehicle(sold: false, num: '84라8404', shippedOut: true);
        $b = $this->stockVehicle(sold: false, num: '85마8505');
        $this->assertSame(['84라8404'], Vehicle::query()->leftStock()->pluck('vehicle_number')->all());
        $this->assertSame(['85마8505'], Vehicle::query()->stillInYard()->pluck('vehicle_number')->all());
        $this->assertSame(Vehicle::count(), Vehicle::query()->leftStock()->count() + Vehicle::query()->stillInYard()->count());
        $this->assertNotNull($a);
        $this->assertNotNull($b);
    }
}
