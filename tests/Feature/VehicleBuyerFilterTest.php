<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 #3 Phase 2-4 (2026-05-23) — 차량관리 바이어 select 필터.
 *
 * - 쿼리 필터: buyerId 적용 시 해당 바이어 차량만 노출
 * - 옵션 필터링: admin/통관/재무 전체, [관리] subordinates 의 바이어만, 영업 본인 바이어만
 */
class VehicleBuyerFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeUser(string $role, ?int $managerId = null): User
    {
        return User::factory()->create([
            'permission' => 'user',
            'role' => $role,
            'manager_user_id' => $managerId,
            'email_verified_at' => now(),
        ]);
    }

    public function test_buyer_id_filter_limits_vehicles(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $b1 = Buyer::create(['name' => 'BuyerA-'.++$this->counter, 'country_id' => null]);
        $b2 = Buyer::create(['name' => 'BuyerB-'.++$this->counter, 'country_id' => null]);
        Vehicle::create([
            'vehicle_number' => 'BF-A-'.$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'buyer_id' => $b1->id,
            'sale_price' => 1000000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        Vehicle::create([
            'vehicle_number' => 'BF-B-'.$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'buyer_id' => $b2->id,
            'sale_price' => 1000000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);

        $this->actingAs($admin);
        $component = Volt::test('erp.vehicles.index')
            ->set('dateFrom', now()->subYear()->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->set('buyerId', (string) $b1->id);
        $vehicles = $component->instance()->vehicles;

        $this->assertCount(1, $vehicles);
        $this->assertSame($b1->id, $vehicles->first()->buyer_id);
    }

    public function test_admin_sees_all_buyers_in_filter(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        Buyer::create(['name' => 'A-'.++$this->counter, 'country_id' => null]);
        Buyer::create(['name' => 'B-'.++$this->counter, 'country_id' => null]);
        Buyer::create(['name' => 'C-'.++$this->counter, 'country_id' => null]);

        $this->actingAs($admin);
        $list = Volt::test('erp.vehicles.index')->instance()->buyersForFilter;

        $this->assertGreaterThanOrEqual(3, $list->count());
    }

    public function test_manager_sees_only_subordinate_buyers(): void
    {
        $manager = $this->makeUser('관리');
        $subSales = $this->makeUser('영업', $manager->id);
        $sub = Salesman::create(['name' => 'SubS-'.++$this->counter, 'user_id' => $subSales->id, 'is_active' => true, 'type' => 'employee']);
        $otherSales = $this->makeUser('영업');
        $other = Salesman::create(['name' => 'OtherS-'.++$this->counter, 'user_id' => $otherSales->id, 'is_active' => true, 'type' => 'employee']);

        // 본인 부하 영업의 direct buyer
        $myBuyer = Buyer::create(['name' => 'MyB-'.$this->counter, 'country_id' => null, 'salesman_id' => $sub->id]);
        // 다른 영업의 direct buyer
        $otherBuyer = Buyer::create(['name' => 'OtherB-'.$this->counter, 'country_id' => null, 'salesman_id' => $other->id]);

        $this->actingAs($manager);
        $list = Volt::test('erp.vehicles.index')->instance()->buyersForFilter;
        $ids = $list->pluck('id')->toArray();

        $this->assertContains($myBuyer->id, $ids, '본인 부하 영업의 direct buyer 노출');
        $this->assertNotContains($otherBuyer->id, $ids, '다른 영업의 buyer 미노출');
    }
}
