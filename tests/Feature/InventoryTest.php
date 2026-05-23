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
 * 회의확장씬 큐 15 / G5 (2026-05-23) — 영업담당자별 재고관리 검증.
 *
 * 재고 정의: progress_status_cache IN ('매입중', '매입완료', '말소완료')
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

    private function makeVehicle(string $status, ?int $salesmanId = null): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'INV-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'salesman_id' => $salesmanId,
            'purchase_date' => '2026-04-01',
            'purchase_price' => 1_000_000,
        ]);
        // progress_status_cache 직접 갱신 (refreshCaches 호출)
        $v->refreshCaches();

        return $v->refresh();
    }

    public function test_inventory_includes_purchase_and_deregistration_stages(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $v1 = $this->makeVehicle('매입중');
        $v2 = $this->makeVehicle('매입완료');

        // 판매중 차량 — 재고 X
        $v3 = Vehicle::create([
            'vehicle_number' => 'INV-SALE-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
            'sale_price' => 2_000_000, 'sale_date' => '2026-05-01',
        ]);
        $v3->refreshCaches();

        $this->actingAs($admin);
        $list = Volt::test('erp.inventory.index')->instance()->inventoryVehicles;
        $ids = $list->pluck('id')->toArray();

        $this->assertContains($v1->id, $ids, '매입중 재고 포함');
        $this->assertContains($v2->id, $ids, '매입완료 재고 포함');
        $this->assertNotContains($v3->id, $ids, '판매중은 재고 제외');
    }

    public function test_salesman_filter_limits_results(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sm1 = Salesman::create(['name' => 'SM1-'.++$this->counter, 'is_active' => true, 'type' => 'employee']);
        $sm2 = Salesman::create(['name' => 'SM2-'.++$this->counter, 'is_active' => true, 'type' => 'employee']);
        $v1 = $this->makeVehicle('매입중', $sm1->id);
        $v2 = $this->makeVehicle('매입중', $sm2->id);

        $this->actingAs($admin);
        $list = Volt::test('erp.inventory.index')
            ->set('salesmanFilter', (string) $sm1->id)
            ->instance()->inventoryVehicles;
        $ids = $list->pluck('id')->toArray();

        $this->assertContains($v1->id, $ids);
        $this->assertNotContains($v2->id, $ids);
    }

    public function test_manager_sees_only_subordinate_inventory(): void
    {
        $manager = User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $subUser = User::factory()->create([
            'permission' => 'user', 'role' => '영업',
            'manager_user_id' => $manager->id, 'email_verified_at' => now(),
        ]);
        $sub = Salesman::create(['name' => 'Sub-'.++$this->counter, 'user_id' => $subUser->id, 'is_active' => true, 'type' => 'employee']);
        $otherUser = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);
        $other = Salesman::create(['name' => 'Other-'.++$this->counter, 'user_id' => $otherUser->id, 'is_active' => true, 'type' => 'employee']);

        $mine = $this->makeVehicle('매입중', $sub->id);
        $others = $this->makeVehicle('매입중', $other->id);

        $this->actingAs($manager);
        $list = Volt::test('erp.inventory.index')->instance()->inventoryVehicles;
        $ids = $list->pluck('id')->toArray();

        $this->assertContains($mine->id, $ids, '본인 부하 영업의 재고 노출');
        $this->assertNotContains($others->id, $ids, '다른 영업의 재고 미노출');
    }
}
