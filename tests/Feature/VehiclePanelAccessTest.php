<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * claudereview B — openEdit 관리 role 스코핑.
 * 관리는 본인 팀(부하 영업담당) 차량만 편집 가능 — 목록 스코프(managerScopeSalesmanIds)와 동일 기준.
 * (목록은 본인 팀만 보여주는데 편집은 임의 ID 가능하던 불일치 보정.)
 */
class VehiclePanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagerWithTeamVehicle(): array
    {
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $sub = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id]);
        $teamSalesman = Salesman::create(['name' => '팀원영업', 'user_id' => $sub->id, 'is_active' => true, 'type' => 'employee']);
        $teamVehicle = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'salesman_id' => $teamSalesman->id]);

        $otherSalesman = Salesman::create(['name' => '외부영업', 'is_active' => true, 'type' => 'employee']);
        $otherVehicle = Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export', 'salesman_id' => $otherSalesman->id]);

        return compact('manager', 'teamVehicle', 'otherVehicle');
    }

    public function test_manager_can_edit_own_team_vehicle(): void
    {
        $c = $this->makeManagerWithTeamVehicle();
        $this->actingAs($c['manager']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['teamVehicle']->id)
            ->assertSet('editingId', $c['teamVehicle']->id);
    }

    public function test_manager_cannot_edit_out_of_team_vehicle(): void
    {
        $c = $this->makeManagerWithTeamVehicle();
        $this->actingAs($c['manager']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['otherVehicle']->id)
            ->assertForbidden();
    }

    /**
     * B/L 이중가드 (선적·B/L 묶음 v2) — 영업 요청(shipping_requests.bl_type) ≠
     * 관리 확인(vehicles.bl_type) 시 B/L탭에 경고 표시 + bl_type 패널 로드.
     */
    public function test_bl_double_guard_warns_when_vehicle_type_differs_from_bundle(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $v = Vehicle::create(['vehicle_number' => '77사7777', 'sales_channel' => 'export', 'bl_type' => 'original']);
        ShippingRequest::create([
            'batch_id' => 'bg', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO',
            'bl_type' => 'surrender', 'bl_status' => 'requested',
            'requested_by_email' => 's@a.com', 'status' => 'in_progress', 'requested_at' => now(),
        ]);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('bl_type', 'original')   // 차량 확인값 로드
            ->assertSee('이중가드');              // 영업(써랜더) ≠ 관리(오리지널) → 경고
    }

    /**
     * [관리] 가 재무확정 잔금 있는 차량 삭제 시도 → 500 Ignition 페이지(코드 노출) 대신 토스트.
     * (사용자 지적 2026-05-27 — 스크린샷의 DomainException 500 을 작은 팝업으로.)
     */
    public function test_manager_delete_locked_vehicle_shows_toast_not_500(): void
    {
        // Review.md #4 (2026-06-09) — delete 스코프 가드 추가 후, 토스트 경로는
        // "관리가 접근 가능한 본인 팀 차량이 잠긴 경우"여야 한다 (팀 밖 차량은 403 선차단).
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $sub = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id]);
        $salesman = Salesman::create(['name' => '팀원영업', 'user_id' => $sub->id, 'is_active' => true, 'type' => 'employee']);
        $v = Vehicle::create(['vehicle_number' => '55마5555', 'sales_channel' => 'export', 'sale_price' => 1_000_000, 'salesman_id' => $salesman->id]);
        $v->finalPayments()->create(['amount' => 500_000, 'type' => 'balance', 'confirmed_at' => now()]); // 확정 잔금 → lock
        $this->actingAs($manager);

        Volt::test('erp.vehicles.index')
            ->call('delete', $v->id)
            ->assertHasNoErrors()
            ->assertDispatched('notify');   // 큰 에러 대신 토스트

        $this->assertNotNull(Vehicle::find($v->id), '권한 부족이라 삭제 차단(차량 존속)돼야');
    }

    /** ?openVehicle=ID 진입 → 해당 차량 편집 패널 자동 오픈 + 매입 탭 전환 이벤트. */
    public function test_open_vehicle_param_auto_opens_edit_panel(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = Vehicle::create(['vehicle_number' => '66바6666', 'sales_channel' => 'export']);
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index', ['openVehicle' => $v->id])
            ->assertSet('editingId', $v->id)
            ->assertDispatched('switch-tab');
    }
}
