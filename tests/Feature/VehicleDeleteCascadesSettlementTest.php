<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 차량 삭제 시 정산 동반 처리 + 차량번호 trim (jin 2026-07-27).
 *
 * 실사고(heymanerp 2026-07-23): 차량 3대를 지우고 같은 번호로 재등록했는데 옛 정산이 남아
 * 정산 목록에 "차량번호 없는 행"으로 뜨고 같은 차가 두 번 계상됐다.
 */
class VehicleDeleteCascadesSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function vehicleWithSettlement(string $status = 'pending'): array
    {
        $salesman = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => '239수1388', 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $salesman->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100000,
            'settlement_status' => $status,
        ]);

        return [$v, $s];
    }

    /** 차량을 지우면 pending 정산도 함께 지워진다 — 고아 정산이 남지 않는다. */
    public function test_deleting_vehicle_cascades_pending_settlement(): void
    {
        [$v, $s] = $this->vehicleWithSettlement();
        $this->actingAs($this->admin());

        $v->delete();

        $this->assertNotNull($s->fresh()->deleted_at, '정산이 함께 soft delete 돼야 한다');
        $this->assertSame(0, Settlement::count());
        $this->assertSame(1, Settlement::withTrashed()->count(), '이력은 남는다');
    }

    /** ⚠️ admin 이어도 동반 삭제는 돈다 — 실사고를 낸 것도 admin 이었다. */
    public function test_cascade_runs_even_for_admin(): void
    {
        [$v, $s] = $this->vehicleWithSettlement();
        $this->actingAs($this->admin());

        $v->delete();

        $this->assertNotNull($s->fresh()->deleted_at);
    }

    /** 확정·지급된 정산이 있으면 차량 삭제 자체가 막힌다(회계 보호). */
    public function test_confirmed_settlement_blocks_vehicle_delete(): void
    {
        [$v, $s] = $this->vehicleWithSettlement('confirmed');
        $this->actingAs($this->admin());

        try {
            $v->delete();
            $this->fail('확정 정산이 있으면 삭제가 막혀야 한다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('정산', $e->getMessage());
        }

        $this->assertNull($v->fresh()->deleted_at, '차량이 지워지면 안 된다');
        $this->assertNull($s->fresh()->deleted_at, '정산도 그대로여야 한다');
    }

    /** 잠긴 정산이 섞여 있으면 아무것도 지우지 않는다(부분 삭제 방지). */
    public function test_locked_settlement_prevents_partial_cascade(): void
    {
        [$v, $pending] = $this->vehicleWithSettlement('pending');
        $locked = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $pending->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100000,
            'settlement_status' => 'paid',
        ]);
        $this->actingAs($this->admin());

        try {
            $v->delete();
        } catch (\DomainException $e) {
            // 기대된 차단
        }

        $this->assertNull($pending->fresh()->deleted_at, 'pending 도 안 지워져야 한다(부분 삭제 금지)');
        $this->assertNull($locked->fresh()->deleted_at);
    }

    /** 차량번호 앞뒤 공백은 저장 시 제거된다. */
    public function test_vehicle_number_is_trimmed_on_save(): void
    {
        $v = Vehicle::create(['vehicle_number' => ' 239수1388', 'sales_channel' => 'export']);
        $this->assertSame('239수1388', $v->fresh()->vehicle_number);

        $v->update(['vehicle_number' => '114마1731  ']);
        $this->assertSame('114마1731', $v->fresh()->vehicle_number);
    }
}
