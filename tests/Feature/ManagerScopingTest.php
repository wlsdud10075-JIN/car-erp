<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 #11 + #2 회귀 — [관리]별 영업담당자 배정 (1:N) scoping.
 *
 * - users.manager_user_id FK self-ref (nullable, nullOnDelete)
 * - User::manager() / subordinates() / getSubordinateSalesmanIds()
 * - vehicles/index — [관리] 본인 담당 영업의 차량만 노출
 * - buyers/index — [관리] 본인 담당 영업의 바이어만 노출 (vehicles 통한 간접)
 * - admin/super: 전체 노출 (분기 X)
 * - 영업: 8711e7d restrictToOwnSalesman 우선 (본 테스트 범위 외)
 */
class ManagerScopingTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeManager(string $name = '관리자A'): User
    {
        return User::factory()->create([
            'name' => $name,
            'permission' => 'user',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function makeSales(?User $manager, string $name): User
    {
        $sales = User::factory()->create([
            'name' => $name,
            'permission' => 'user',
            'role' => '영업',
            'type' => 'employee',
            'manager_user_id' => $manager?->id,
            'email_verified_at' => now(),
        ]);
        Salesman::create([
            'user_id' => $sales->id,
            'name' => $sales->name,
            'is_active' => true,
            'type' => 'employee',
        ]);

        return $sales;
    }

    private function makeVehicle(int $salesmanId, ?int $buyerId = null): Vehicle
    {
        $this->counter++;

        return Vehicle::create([
            'vehicle_number' => 'MST-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'salesman_id' => $salesmanId,
            'buyer_id' => $buyerId,
        ]);
    }

    public function test_users_table_has_manager_user_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'manager_user_id'));
    }

    public function test_user_manager_and_subordinates_relationship(): void
    {
        $mgr = $this->makeManager();
        $s1 = $this->makeSales($mgr, '영업1');
        $s2 = $this->makeSales($mgr, '영업2');
        $orphan = $this->makeSales(null, '미배정');

        $this->assertSame(2, $mgr->subordinates()->count(), '[관리] 의 subordinates = 2명');
        $this->assertSame($mgr->id, $s1->manager?->id, '영업 → manager belongsTo');
        $this->assertNull($orphan->manager, '미배정 영업은 manager NULL');
    }

    public function test_get_subordinate_salesman_ids_returns_salesman_ids(): void
    {
        $mgr = $this->makeManager();
        $s1 = $this->makeSales($mgr, '영업1');
        $s2 = $this->makeSales($mgr, '영업2');
        $this->makeSales(null, '미배정');

        $ids = $mgr->getSubordinateSalesmanIds();
        sort($ids);
        $expected = [$s1->salesman->id, $s2->salesman->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_empty_subordinates_returns_empty_array(): void
    {
        $mgr = $this->makeManager('미배정관리');
        $this->makeSales(null, '독립영업');

        $this->assertSame([], $mgr->getSubordinateSalesmanIds(), 'subordinates 0명 → 빈 배열');
    }

    public function test_vehicles_index_restricts_manager_to_subordinate_salesman(): void
    {
        $mgr = $this->makeManager();
        $myS = $this->makeSales($mgr, '내영업');
        $otherS = $this->makeSales(null, '외부영업');

        $mine = $this->makeVehicle($myS->salesman->id);
        $other = $this->makeVehicle($otherS->salesman->id);

        $this->actingAs($mgr);
        // dateFrom/dateTo 비움 — mount default(now-2m ~ now)가 purchase_date NULL 차량 제외하는 영향 회피.
        $list = Volt::test('erp.vehicles.index')
            ->set('dateFrom', '')
            ->set('dateTo', '')
            ->get('vehicles');
        $ids = collect($list->items())->pluck('id')->all();

        $this->assertContains($mine->id, $ids, '[관리] 는 본인 담당 영업의 차량 조회 가능');
        $this->assertNotContains($other->id, $ids, '[관리] 는 외부 영업의 차량 조회 차단');
    }

    public function test_buyers_index_restricts_manager_to_subordinates_buyers(): void
    {
        $mgr = $this->makeManager();
        $myS = $this->makeSales($mgr, '내영업');
        $otherS = $this->makeSales(null, '외부영업');

        $myBuyer = Buyer::create(['name' => 'MY BUYER', 'is_active' => true]);
        $otherBuyer = Buyer::create(['name' => 'OTHER BUYER', 'is_active' => true]);
        $this->makeVehicle($myS->salesman->id, $myBuyer->id);
        $this->makeVehicle($otherS->salesman->id, $otherBuyer->id);

        $this->actingAs($mgr);
        $list = Volt::test('erp.buyers.index')->get('buyers');
        $ids = collect($list->items())->pluck('id')->all();

        $this->assertContains($myBuyer->id, $ids, '[관리] 는 본인 영업의 바이어 조회 가능');
        $this->assertNotContains($otherBuyer->id, $ids, '[관리] 는 외부 영업의 바이어 조회 차단');
    }

    public function test_buyers_table_has_salesman_id_column(): void
    {
        // E-1 (2026-05-22) — buyers.salesman_id FK 마이그 확인
        $this->assertTrue(Schema::hasColumn('buyers', 'salesman_id'));
    }

    public function test_buyer_with_direct_salesman_id_visible_to_manager(): void
    {
        // E-2 (2026-05-22) — buyer.salesman_id 직접 관계만으로 [관리] 솔팅 (vehicles 없음)
        $mgr = $this->makeManager();
        $myS = $this->makeSales($mgr, '내영업');
        $otherS = $this->makeSales(null, '외부영업');

        $myBuyer = Buyer::create(['name' => 'DIRECT MY', 'is_active' => true, 'salesman_id' => $myS->salesman->id]);
        $otherBuyer = Buyer::create(['name' => 'DIRECT OTHER', 'is_active' => true, 'salesman_id' => $otherS->salesman->id]);

        $this->actingAs($mgr);
        $list = Volt::test('erp.buyers.index')->get('buyers');
        $ids = collect($list->items())->pluck('id')->all();

        $this->assertContains($myBuyer->id, $ids, '[관리] 는 buyer.salesman_id 직접 관계로도 솔팅');
        $this->assertNotContains($otherBuyer->id, $ids);
    }

    public function test_admin_sees_all_vehicles_and_buyers(): void
    {
        $admin = User::factory()->create([
            'name' => '관리자',
            'permission' => 'admin',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);
        $mgr = $this->makeManager();
        $myS = $this->makeSales($mgr, '내영업');
        $otherS = $this->makeSales(null, '외부영업');

        $buyerA = Buyer::create(['name' => 'A', 'is_active' => true]);
        $buyerB = Buyer::create(['name' => 'B', 'is_active' => true]);
        $vA = $this->makeVehicle($myS->salesman->id, $buyerA->id);
        $vB = $this->makeVehicle($otherS->salesman->id, $buyerB->id);

        $this->actingAs($admin);

        $vehicles = collect(Volt::test('erp.vehicles.index')
            ->set('dateFrom', '')
            ->set('dateTo', '')
            ->get('vehicles')->items())->pluck('id')->all();
        $buyers = collect(Volt::test('erp.buyers.index')->get('buyers')->items())->pluck('id')->all();

        $this->assertContains($vA->id, $vehicles);
        $this->assertContains($vB->id, $vehicles);
        $this->assertContains($buyerA->id, $buyers);
        $this->assertContains($buyerB->id, $buyers);
    }
}
