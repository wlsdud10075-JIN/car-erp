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
 * item 1 (jin 2026-07-07) — 업무관리자(permission='manager') 권한 등급.
 *
 * manager = admin 등가 권한에서 [기능설정·단계강제·super/admin 계정관리]만 제외.
 * super > admin(대표) > manager(업무관리자) > user.
 */
class ManagerPermissionTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function user(string $perm, ?string $role = null): User
    {
        return User::factory()->create([
            'permission' => $perm,
            'role' => $role ?? '관리',
            'email_verified_at' => now(),
        ]);
    }

    public function test_manager_capability_flags(): void
    {
        $m = $this->user('manager');

        $this->assertTrue($m->isManager());
        $this->assertFalse($m->isAdmin(), 'manager 는 isAdmin(super|admin) 아님');
        // 얻는 권한 (admin 등가)
        $this->assertTrue($m->canViewAdminDashboard());
        $this->assertTrue($m->canAccessAdmin());
        $this->assertTrue($m->canManageUsers());
        $this->assertTrue($m->canApprove());
        $this->assertTrue($m->canAccessErp());
        $this->assertTrue($m->canApproveUnpaidExport());
        $this->assertTrue($m->canEditVehicleFinancialFields());
        $this->assertTrue($m->canViewReceivables());
        // 제외 2종 (super 전용)
        $this->assertFalse($m->canToggleFeatures(), '기능설정은 super 전용');
        $this->assertFalse($m->canForceStageJump(), '단계 강제는 super 전용');
    }

    public function test_manager_scope_vehicle_full_access(): void
    {
        $m = $this->user('manager');
        // canScopeVehicle 은 manager 분기에서 즉시 true (팀 스코프 아님).
        $this->assertTrue($m->canScopeVehicle(new Vehicle(['salesman_id' => 999])));
    }

    public function test_manager_can_manage_users_except_super_admin(): void
    {
        $m = $this->user('manager');

        $this->assertTrue($m->canManageUserAccount($this->user('user', '영업')), 'user 관리 가능');
        $this->assertTrue($m->canManageUserAccount($this->user('manager')), '다른 manager 관리 가능 (jin 명세)');
        $this->assertFalse($m->canManageUserAccount($this->user('admin')), 'admin 계정 못 건드림');
        $this->assertFalse($m->canManageUserAccount($this->user('super')), 'super 계정 못 건드림');
    }

    public function test_manager_route_access(): void
    {
        $this->actingAs($this->user('manager'));

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.audit-logs.index'))->assertOk();
        $this->get(route('admin.document-access-logs.index'))->assertOk();
        $this->get(route('admin.users.index'))->assertOk();
        // 기능설정은 super 전용 → 403
        $this->get(route('admin.settings'))->assertForbidden();
    }

    public function test_manager_cannot_grant_admin_via_component(): void
    {
        $this->actingAs($this->user('manager'));

        Volt::test('admin.users.index')
            ->call('openCreate')
            ->set('name', 'ADMIN TRY')
            ->set('email', 'admintry@test.com')
            ->set('password', 'password123')
            ->set('permission', 'admin')
            ->set('role', '관리')
            ->call('save')
            ->assertHasErrors('permission');

        $this->assertDatabaseMissing('users', ['email' => 'admintry@test.com']);
    }

    /**
     * 핵심 회귀 (advisor) — 리스트 스코핑은 role 기준인데 manager 의 role 값에 상관없이 전 차량이 보여야 한다.
     * UI 로 만든 manager 는 role 드롭다운이 숨겨져 기본값 '영업'으로 저장됨 → 스코핑이 role 기반이면
     * restrictToOwnSalesman 로 빈 목록이 될 뻔. isManager 예외로 admin 등가 전체 노출 보장.
     */
    public function test_manager_sees_all_vehicles_regardless_of_role(): void
    {
        $otherSalesman = Salesman::create(['name' => '타영업', 'is_active' => true, 'type' => 'freelance']);
        Vehicle::create([
            'vehicle_number' => 'SCOPE-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false, 'purchase_date' => '2026-05-10', 'purchase_price' => 1000,
            'salesman_id' => $otherSalesman->id,
        ]);

        // role 이 '영업'(UI 기본값)이든 '관리'든 manager 는 타 영업 차량을 본다.
        foreach (['영업', '관리'] as $role) {
            $this->actingAs($this->user('manager', $role));
            Volt::test('erp.vehicles.index')->assertSee('SCOPE-1');
        }
    }

    public function test_manager_can_create_regular_user(): void
    {
        $this->actingAs($this->user('manager'));

        Volt::test('admin.users.index')
            ->call('openCreate')
            ->set('name', 'NEW SALES')
            ->set('email', 'newsales@test.com')
            ->set('password', 'password123')
            ->set('permission', 'user')
            ->set('role', '영업')
            ->set('type', 'freelance')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newsales@test.com', 'permission' => 'user']);
    }
}
