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
 * 관리↔영업 다대다 배정 (2026-06-30 jin) — 영업 1명을 [관리] 여러 명이 함께 담당.
 *   manager_salesman pivot. getSubordinateSalesmanIds = pivot ∪ 레거시 manager_user_id.
 *   사용자관리 UI = 영업 편집 시 관리자 다중 체크.
 */
class ManagerMultiAssignTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function manager(string $name): User
    {
        return User::factory()->create([
            'name' => $name, 'permission' => 'user', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    /** @return array{0:User,1:Salesman} */
    private function salesmanUser(string $name): array
    {
        $u = User::factory()->create([
            'name' => $name, 'permission' => 'user', 'role' => '영업', 'type' => 'employee', 'email_verified_at' => now(),
        ]);
        $s = Salesman::create(['user_id' => $u->id, 'name' => $name, 'is_active' => true, 'type' => 'employee']);

        return [$u, $s];
    }

    private function vehicle(int $salesmanId): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'MMA-'.++$this->counter, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'salesman_id' => $salesmanId,
            'purchase_price' => 1_000_000, 'purchase_date' => '2026-05-01', 'dhl_request' => false,
        ]);
    }

    public function test_both_managers_scope_the_shared_salesman(): void
    {
        $m1 = $this->manager('관리1');
        $m2 = $this->manager('관리2');
        [$su, $s] = $this->salesmanUser('영업1');
        $v = $this->vehicle($s->id);

        $su->managers()->sync([$m1->id, $m2->id]);

        $this->assertContains($s->id, $m1->getSubordinateSalesmanIds(), '관리1이 공유 영업 스코프');
        $this->assertContains($s->id, $m2->getSubordinateSalesmanIds(), '관리2도 공유 영업 스코프');
        $this->assertTrue($m1->canScopeVehicle($v));
        $this->assertTrue($m2->canScopeVehicle($v));
    }

    public function test_legacy_manager_user_id_still_scopes(): void
    {
        // pivot 없이 구 단일 manager_user_id 만 — 합집합으로 여전히 스코프(구 데이터·테스트 호환).
        $m = $this->manager('관리L');
        [$su, $s] = $this->salesmanUser('영업L');
        $su->update(['manager_user_id' => $m->id]);
        $v = $this->vehicle($s->id);

        $this->assertContains($s->id, $m->getSubordinateSalesmanIds());
        $this->assertTrue($m->canScopeVehicle($v));
    }

    public function test_user_management_ui_saves_multiple_managers(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $m1 = $this->manager('관리X');
        $m2 = $this->manager('관리Y');
        [$su] = $this->salesmanUser('영업Z');
        $this->actingAs($admin);

        Volt::test('admin.users.index')
            ->call('openEdit', $su->id)
            ->set('manager_user_ids', [(string) $m1->id, (string) $m2->id])
            ->call('save')
            ->assertHasNoErrors();

        $ids = $su->fresh()->managers()->pluck('users.id')->all();
        $this->assertContains($m1->id, $ids);
        $this->assertContains($m2->id, $ids);
        $this->assertSame($m1->id, $su->fresh()->manager_user_id, 'manager_user_id = primary(첫 선택)');
    }

    public function test_unchecking_removes_manager_scope(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $m1 = $this->manager('관리P');
        $m2 = $this->manager('관리Q');
        [$su, $s] = $this->salesmanUser('영업R');
        $su->managers()->sync([$m1->id, $m2->id]);
        $su->update(['manager_user_id' => $m1->id]);
        $this->actingAs($admin);

        // m1 해제, m2 만 남김
        Volt::test('admin.users.index')
            ->call('openEdit', $su->id)
            ->set('manager_user_ids', [(string) $m2->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($m1->fresh()->canScopeVehicle($this->vehicle($s->id)), '해제된 관리1 스코프 제거');
        $this->assertTrue($m2->fresh()->canScopeVehicle($this->vehicle($s->id)));
    }
}
