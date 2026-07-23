<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 재고관리 출고일 트리거 (jin 2026-07-09).
 *   재고 = 매입 완납(입고됨) AND 출고일 없음. 진행상태 무관.
 *   미완납 = 입고 전(제외) / 출고일 찍힘 = 출고(제외) / 선적중이어도 미출고면 재고 잔존.
 */
class InventoryStockTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 매입가 있는 차량 + (완납 여부 제어) 확정 매입잔금. */
    private function purchased(int $price = 5_000_000, bool $fullyPaid = true): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'INV'.++$this->c, 'sales_channel' => 'export',
            'purchase_date' => '2026-05-01', 'purchase_price' => $price,
        ]);
        $v->purchaseBalancePayments()->create([
            'amount' => $fullyPaid ? $price : (int) ($price / 2),
            'payment_date' => '2026-05-10', 'confirmed_at' => now(),
        ]);

        return $v->fresh();
    }

    public function test_paid_and_not_out_is_in_stock(): void
    {
        $v = $this->purchased();
        $this->assertContains($v->id, Vehicle::inStock()->pluck('id')->all(), '완납+미출고 → 재고');
    }

    public function test_unpaid_purchase_not_in_stock(): void
    {
        $v = $this->purchased(5_000_000, false);   // 절반만 지급 = 미완납
        $this->assertNotContains($v->id, Vehicle::inStock()->pluck('id')->all(), '매입 미완납 → 입고 전(제외)');
        $this->assertNull($v->warehouse_in_date, '미완납 → 입고일 없음');
    }

    public function test_out_date_excludes_from_stock(): void
    {
        $v = $this->purchased();
        $v->update(['warehouse_out_date' => '2026-06-01']);
        $this->assertNotContains($v->id, Vehicle::inStock()->pluck('id')->all(), '출고일 찍힘 → 재고 제외');
    }

    public function test_shipped_but_not_out_stays_in_stock(): void
    {
        // jin 핵심: 선적중인데 출고일 없으면 재고에 남아 발견 가능.
        $v = $this->purchased();
        $v->update(['sale_price' => 10_000_000, 'sale_date' => '2026-05-15', 'buyer_id' => Buyer::create(['name' => 'b', 'is_active' => true])->id, 'exchange_rate' => 1, 'bl_loading_location' => '부산항']);
        $v->refreshCaches();
        $this->assertSame('선적중', $v->fresh()->progress_status_cache);
        $this->assertContains($v->id, Vehicle::inStock()->pluck('id')->all(), '선적중+미출고 → 재고 잔존');
    }

    public function test_completed_excluded_from_stock_even_without_out_date(): void
    {
        // jin 2026-07-23: 거래완료(출항)는 출고일 미입력이어도 재고 제외. 확실히 나간 것이므로.
        $v = $this->purchased();
        $v->update(['sale_price' => 10_000_000, 'sale_date' => '2026-05-15', 'buyer_id' => Buyer::create(['name' => 'bc', 'is_active' => true])->id, 'exchange_rate' => 1, 'bl_loading_location' => '부산항', 'bl_document' => 'bl.pdf']);
        $v->finalPayments()->create(['amount' => 10_000_000, 'type' => 'balance', 'payment_date' => '2026-05-20', 'confirmed_at' => now()]);
        $v->refreshCaches();
        $this->assertSame('거래완료', $v->fresh()->progress_status_cache);
        $this->assertNull($v->fresh()->warehouse_out_date, '출고일 미입력 상태');
        $this->assertNotContains($v->id, Vehicle::inStock()->pluck('id')->all(), '거래완료 → 출고일 없어도 재고 제외');
        $this->assertNotContains($v->id, Vehicle::preShippingStock()->pluck('id')->all(), '거래완료 → 선적전 재고에서도 제외');
    }

    public function test_warehouse_in_date_is_purchase_full_payment_date(): void
    {
        $v = $this->purchased();
        $this->assertSame('2026-05-10', $v->warehouse_in_date?->format('Y-m-d'), '입고일 = 매입 완납일');
    }

    public function test_apply_warehouse_out_batch(): void
    {
        // 즉시저장 아님 — draft(warehouseOut) 지정 후 「적용」으로 일괄 저장 (오클릭 방지).
        $this->actingAs($this->admin());
        $v = $this->purchased();

        Volt::test('erp.inventory.index')
            ->set('warehouseOut', [$v->id => '2026-06-15'])
            ->call('applyWarehouseOut');
        $this->assertSame('2026-06-15', $v->fresh()->warehouse_out_date?->format('Y-m-d'), '출고일 적용');

        // 비우면 재고 복귀
        Volt::test('erp.inventory.index')
            ->set('warehouseOut', [$v->id => ''])
            ->call('applyWarehouseOut');
        $this->assertNull($v->fresh()->warehouse_out_date, '출고일 제거 → 재고 복귀');
    }
}
