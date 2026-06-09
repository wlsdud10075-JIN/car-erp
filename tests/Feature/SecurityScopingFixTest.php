<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Review.md (2026-06-09) 보안·회계 수정 회귀 테스트.
 *
 *  #3 문서 다운로드 RRN IDOR — 컨트롤러 소유권 스코프 가드
 *  #4 차량 delete/save 스코프 누락 — mutating 엔드포인트 재인가
 *  #2 영업 cashflow IDOR — $salesmanId #[Locked]
 *  #1 paid 정산 무가드 삭제 — Settlement::deleting 가드
 *  공통: User::canScopeVehicle 단일 출처
 */
class SecurityScopingFixTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeManager(string $name = '관리A'): User
    {
        return User::factory()->create([
            'name' => $name, 'permission' => 'user', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function makeSales(?User $manager, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name, 'permission' => 'user', 'role' => '영업', 'type' => 'employee',
            'manager_user_id' => $manager?->id, 'email_verified_at' => now(),
        ]);
        Salesman::create([
            'user_id' => $user->id, 'name' => $name, 'is_active' => true, 'type' => 'employee',
        ]);

        return $user;
    }

    private function makeVehicle(int $salesmanId): Vehicle
    {
        $this->counter++;

        return Vehicle::create([
            'vehicle_number' => 'SEC-'.$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $salesmanId,
        ]);
    }

    // ── #3/#4 공통 — canScopeVehicle 단일 출처 ───────────────────────

    public function test_can_scope_vehicle_for_sales_own_vs_other(): void
    {
        $mgr = $this->makeManager();
        $a = $this->makeSales($mgr, '영업A');
        $b = $this->makeSales(null, '영업B');
        $vA = $this->makeVehicle($a->salesman->id);
        $vB = $this->makeVehicle($b->salesman->id);

        $this->assertTrue($a->canScopeVehicle($vA), '영업은 본인 담당 차량 허용');
        $this->assertFalse($a->canScopeVehicle($vB), '영업은 타 담당 차량 차단');
    }

    public function test_can_scope_vehicle_for_manager_team(): void
    {
        $mgr = $this->makeManager();
        $mine = $this->makeSales($mgr, '내영업');
        $outside = $this->makeSales(null, '외부영업');
        $vMine = $this->makeVehicle($mine->salesman->id);
        $vOut = $this->makeVehicle($outside->salesman->id);

        $this->assertTrue($mgr->canScopeVehicle($vMine), '관리는 본인 팀 차량 허용');
        $this->assertFalse($mgr->canScopeVehicle($vOut), '관리는 외부 팀 차량 차단');
    }

    public function test_can_scope_vehicle_admin_and_clearance_finance_see_all(): void
    {
        $admin = User::factory()->create([
            'name' => 'admin', 'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $clearance = User::factory()->create([
            'name' => '통관', 'permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now(),
        ]);
        $finance = User::factory()->create([
            'name' => '재무', 'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
        $someSales = $this->makeSales(null, '영업X');
        $v = $this->makeVehicle($someSales->salesman->id);

        $this->assertTrue($admin->canScopeVehicle($v), 'admin 전체 허용');
        $this->assertTrue($clearance->canScopeVehicle($v), '수출통관 전체 허용 (전 차량 업무)');
        $this->assertTrue($finance->canScopeVehicle($v), '재무 전체 허용');
    }

    // ── #3 문서 다운로드 IDOR ─────────────────────────────────────

    public function test_document_download_blocked_for_other_salesman_vehicle(): void
    {
        $a = $this->makeSales(null, '영업A');
        $b = $this->makeSales(null, '영업B');
        $vB = $this->makeVehicle($b->salesman->id);

        // 영업A 가 영업B 차량 말소신청서를 직접 URL 호출 → 403 (RRN 노출 차단).
        $this->actingAs($a)
            ->get('/erp/vehicles/'.$vB->id.'/documents/deregistration')
            ->assertStatus(403);
    }

    // ── #4 차량 delete 스코프 ─────────────────────────────────────

    public function test_vehicle_delete_blocked_for_other_salesman(): void
    {
        $a = $this->makeSales(null, '영업A');
        $b = $this->makeSales(null, '영업B');
        $vB = $this->makeVehicle($b->salesman->id);

        $this->actingAs($a);

        // Livewire 는 액션 내 abort(403) 을 예외로 던지지 않고 403 응답으로 변환한다.
        Volt::test('erp.vehicles.index')
            ->call('delete', $vB->id)
            ->assertStatus(403);

        $this->assertNotNull(Vehicle::find($vB->id), '차량이 삭제되지 않아야 함');
    }

    // ── #2 cashflow $salesmanId #[Locked] ────────────────────────

    public function test_cashflow_salesman_id_is_locked(): void
    {
        $a = $this->makeSales(null, '영업A');

        $this->actingAs($a);

        $component = Volt::test('erp.salesmen.cashflow', ['id' => $a->salesman->id]);

        // 클라이언트가 다른 ID 주입 시도 → Locked 라 상태 변경 안 됨.
        try {
            $component->set('salesmanId', 999999);
        } catch (\Throwable $e) {
            // Locked 위반은 예외로 거부됨 — 정상.
        }

        $component->assertSet('salesmanId', $a->salesman->id);
    }

    // ── #1 paid 정산 무가드 삭제 ─────────────────────────────────

    public function test_paid_settlement_cannot_be_deleted(): void
    {
        $sales = $this->makeSales(null, '영업A');
        $vehicle = $this->makeVehicle($sales->salesman->id);

        // 인증 없는 상태로 paid 정산 구성 (승인 가드 우회 — 시드 컨텍스트).
        $settlement = new Settlement;
        $settlement->vehicle_id = $vehicle->id;
        $settlement->salesman_id = $sales->salesman->id;
        $settlement->settlement_type = 'per_unit';
        $settlement->per_unit_amount = 100000;
        $settlement->settlement_status = 'pending';
        $settlement->save();
        $settlement->settlement_status = 'paid';
        $settlement->save();

        // 인증된 사용자 컨텍스트에서 삭제 시도 → DomainException 차단.
        $admin = User::factory()->create([
            'name' => 'admin', 'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        $settlement->delete();
    }

    public function test_pending_settlement_is_deletable(): void
    {
        $sales = $this->makeSales(null, '영업A');
        $vehicle = $this->makeVehicle($sales->salesman->id);

        $settlement = Settlement::create([
            'vehicle_id' => $vehicle->id,
            'salesman_id' => $sales->salesman->id,
            'settlement_type' => 'per_unit',
            'per_unit_amount' => 100000,
            'settlement_status' => 'pending',
        ]);

        $admin = User::factory()->create([
            'name' => 'admin', 'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);

        $settlement->delete();
        $this->assertNull(Settlement::find($settlement->id), 'pending 정산은 삭제 가능해야 함');
    }
}
