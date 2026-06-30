<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * [관리] 팀 영업 한정 사용자 관리 (2026-06-30 jin) — 2026-05-14 "super/admin 전용" 경계 이동.
 *   ⚠️ 핵심: [관리]는 super/admin 계정을 절대 생성·변경 못 함(escalation 차단). canManageUserAccount 단일 가드.
 */
class ManagerUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function user(string $name, string $permission, string $role): User
    {
        return User::factory()->create([
            'name' => $name, 'permission' => $permission, 'role' => $role,
            'type' => $role === '영업' ? 'employee' : null, 'email_verified_at' => now(),
        ]);
    }

    private function teamSalesman(User $manager, string $name): User
    {
        $u = $this->user($name, 'user', '영업');
        Salesman::create(['user_id' => $u->id, 'name' => $name, 'is_active' => true, 'type' => 'employee']);
        $u->managers()->sync([$manager->id]);

        return $u;
    }

    // ── 권한 게이트 ──────────────────────────────────────────
    public function test_manager_can_manage_users_admin_can_too(): void
    {
        $this->assertTrue($this->user('관리', 'user', '관리')->canManageUsers());
        $this->assertTrue($this->user('어드민', 'admin', '관리')->canManageUsers());
        $this->assertFalse($this->user('영업', 'user', '영업')->canManageUsers());
        $this->assertFalse($this->user('재무', 'user', '재무')->canManageUsers());
    }

    public function test_manager_list_scoped_to_team_salesmen_only(): void
    {
        $mgr = $this->user('관리A', 'user', '관리');
        $mine = $this->teamSalesman($mgr, '내영업');
        $other = $this->user('남영업', 'user', '영업');           // 팀 아님
        $admin = $this->user('관리자', 'admin', '관리');

        $list = Volt::actingAs($mgr)->test('admin.users.index')->get('users');
        $ids = collect($list->items())->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids, '타 팀 영업 안 보임');
        $this->assertNotContains($admin->id, $ids, 'admin 안 보임');
        $this->assertNotContains($mgr->id, $ids, '본인(관리)도 목록 X');
    }

    // ── ⚠️ escalation 공격 (가드 핵심) ───────────────────────
    public function test_manager_cannot_open_admin_account(): void
    {
        $mgr = $this->user('관리B', 'user', '관리');
        $admin = $this->user('관리자', 'admin', '관리');

        Volt::actingAs($mgr)->test('admin.users.index')
            ->call('openEdit', $admin->id)
            ->assertStatus(403);
    }

    public function test_manager_cannot_save_injected_admin_id_with_password_reset(): void
    {
        // 비번 리셋 익스플로잇 — 관리가 admin id 주입 + 새 비번 저장 시도.
        $mgr = $this->user('관리C', 'user', '관리');
        $admin = $this->user('관리자', 'admin', '관리');
        $oldHash = $admin->password;

        Volt::actingAs($mgr)->test('admin.users.index')
            ->set('editingId', $admin->id)
            ->set('name', 'hijack')
            ->set('email', $admin->email)
            ->set('phone', '')
            ->set('permission', 'user')
            ->set('role', '영업')
            ->set('password', 'hacked-password-123')
            ->call('save')
            ->assertStatus(403);

        $this->assertSame($oldHash, $admin->fresh()->password, 'admin 비번 불변');
        $this->assertSame('admin', $admin->fresh()->permission, 'admin 권한 불변');
    }

    public function test_manager_cannot_edit_non_team_salesman(): void
    {
        $mgr = $this->user('관리D', 'user', '관리');
        $other = $this->user('남영업', 'user', '영업');   // 팀 배정 안 됨

        Volt::actingAs($mgr)->test('admin.users.index')
            ->call('openEdit', $other->id)
            ->assertStatus(403);
    }

    public function test_manager_save_forces_user_permission_and_sales_role(): void
    {
        // 관리가 본인 팀 영업 편집하며 권한/role 변조 → 서버가 user·영업 강제.
        $mgr = $this->user('관리E', 'user', '관리');
        $sales = $this->teamSalesman($mgr, '팀영업');

        Volt::actingAs($mgr)->test('admin.users.index')
            ->call('openEdit', $sales->id)
            ->set('permission', 'admin')   // 변조 시도
            ->set('role', '관리')          // 변조 시도
            ->set('type', 'employee')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('user', $sales->fresh()->permission, '권한 user 강제');
        $this->assertSame('영업', $sales->fresh()->role, 'role 영업 강제');
    }

    public function test_manager_create_auto_assigns_self_as_manager(): void
    {
        $mgr = $this->user('관리F', 'user', '관리');

        Volt::actingAs($mgr)->test('admin.users.index')
            ->call('openCreate')
            ->set('name', '신규영업')
            ->set('email', 'newsales@car-erp.test')
            ->set('password', 'password123')
            ->set('type', 'employee')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::where('email', 'newsales@car-erp.test')->first();
        $this->assertNotNull($created);
        $this->assertSame('user', $created->permission);
        $this->assertSame('영업', $created->role);
        $this->assertContains($created->id, $mgr->getManagedSalesmanUserIds(), '본인 팀에 자동 배정');
    }

    public function test_manager_cannot_delete(): void
    {
        $mgr = $this->user('관리G', 'user', '관리');
        $sales = $this->teamSalesman($mgr, '팀영업2');

        Volt::actingAs($mgr)->test('admin.users.index')
            ->call('delete', $sales->id)
            ->assertStatus(403);

        $this->assertNotNull($sales->fresh(), '삭제 안 됨');
    }
}
