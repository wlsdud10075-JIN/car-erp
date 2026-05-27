<?php

namespace Tests\Feature;

use App\Models\Salesman;
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
}
