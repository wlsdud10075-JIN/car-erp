<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 영업담당자별 재고관리 검증.
 *
 * 재고 정의 (jin 2026-07-09 재정의): 매입 완납(입고됨) AND 출고일 없음. 진행상태 무관.
 *   미완납 = 입고 전(제외) / 출고일 찍힘 = 출고(제외) / 선적중이어도 미출고면 잔존.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    /** 매입 완납(입고됨) 차량. $paid=false 면 절반만 지급(미완납=입고 전). */
    private function makeVehicle(?int $salesmanId = null, bool $paid = true, array $extra = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'INV-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $salesmanId,
            'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
        ], $extra));
        $v->purchaseBalancePayments()->create([
            'amount' => $paid ? 1_000_000 : 500_000,
            'payment_date' => '2026-04-10', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        return $v->refresh();
    }

    public function test_inventory_is_paid_and_not_shipped_out(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $paid = $this->makeVehicle();                          // 매입완납 → 재고 O
        $unpaid = $this->makeVehicle(null, false);             // 매입 미완납 → 입고 전(제외)

        // 선적중이지만 완납 + 미출고 → 재고 잔존 (jin 핵심: "선적인데 재고면 발견")
        $shipped = $this->makeVehicle(null, true, [
            'sale_price' => 2_000_000, 'sale_date' => '2026-05-01', 'bl_loading_location' => 'PUSAN PORT',
        ]);

        // 출고일 찍힌 차량 → 재고 제외
        $out = $this->makeVehicle();
        $out->update(['warehouse_out_date' => '2026-06-01']);

        $this->actingAs($admin);
        $ids = Volt::test('erp.inventory.index')->instance()->inventoryVehicles->pluck('id')->toArray();

        $this->assertContains($paid->id, $ids, '매입완납+미출고 → 재고');
        $this->assertNotContains($unpaid->id, $ids, '매입 미완납 → 입고 전(제외)');
        $this->assertContains($shipped->id, $ids, '선적중이어도 미출고면 재고 잔존');
        $this->assertNotContains($out->id, $ids, '출고일 찍힘 → 재고 제외');
    }

    public function test_salesman_filter_limits_results(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sm1 = Salesman::create(['name' => 'SM1-'.++$this->counter, 'is_active' => true, 'type' => 'employee']);
        $sm2 = Salesman::create(['name' => 'SM2-'.++$this->counter, 'is_active' => true, 'type' => 'employee']);
        $v1 = $this->makeVehicle($sm1->id);
        $v2 = $this->makeVehicle($sm2->id);

        $this->actingAs($admin);
        $ids = Volt::test('erp.inventory.index')
            ->set('salesmanFilter', (string) $sm1->id)
            ->instance()->inventoryVehicles->pluck('id')->toArray();

        $this->assertContains($v1->id, $ids);
        $this->assertNotContains($v2->id, $ids);
    }

    public function test_manager_sees_only_subordinate_inventory(): void
    {
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $subUser = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id, 'email_verified_at' => now(),
        ]);
        $sub = Salesman::create(['name' => 'Sub-'.++$this->counter, 'user_id' => $subUser->id, 'is_active' => true, 'type' => 'employee']);
        $otherUser = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $other = Salesman::create(['name' => 'Other-'.++$this->counter, 'user_id' => $otherUser->id, 'is_active' => true, 'type' => 'employee']);

        $mine = $this->makeVehicle($sub->id);
        $others = $this->makeVehicle($other->id);

        $this->actingAs($manager);
        $ids = Volt::test('erp.inventory.index')->instance()->inventoryVehicles->pluck('id')->toArray();

        $this->assertContains($mine->id, $ids, '본인 부하 영업의 재고 노출');
        $this->assertNotContains($others->id, $ids, '다른 영업의 재고 미노출');
    }
}
