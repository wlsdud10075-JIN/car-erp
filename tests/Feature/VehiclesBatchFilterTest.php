<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 선적요청 배치 딥링크 — vehicles/index ?ids=1,2 로 그 차량만 조회.
 * 기본 날짜필터(최근 2개월)에 묻히지 않게 mount 가 ids 진입 시 날짜기본 스킵.
 */
class VehiclesBatchFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_ids_param_filters_to_batch_vehicles_only(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
        $a = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export']);
        $b = Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export']);
        Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export']);

        $this->actingAs($admin);

        Volt::test('erp.vehicles.index', ['ids' => $a->id.','.$b->id])
            ->assertSee('11가1111')
            ->assertSee('22나2222')
            ->assertDontSee('33다3333');   // 배치 외 차량은 안 보임
    }
}
