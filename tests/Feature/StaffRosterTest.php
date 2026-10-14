<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Services\SalesmanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 직원 명부 확장 (jin 2026-10-14 «추천으로 하자 · 검차도 사용자등록쪽에 · 사용자관리에서 한번에 관리»).
 *
 * - 사용자 관리에서 저장하면 역할에 따라 Salesman 으로 미러된다: 영업 → employee/freelance · 검차 → inspector ·
 *   일반사용자 관리/수출통관/재무 → staff · 관리자 등급의 비영업 → 미러 없음(기존 행 비활성).
 * - 검차직원은 이메일·비밀번호 없이 등록되고 ERP 에 못 들어간다(role 이 ROLES 밖).
 * - `sales()` 는 employee/freelance 만 — 담당자 드롭다운·board 이메일 매칭에 직원이 섞이지 않는다.
 */
class StaffRosterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function create(array $set): void
    {
        $c = Volt::test('admin.users.index')->call('openCreate');
        foreach ($set as $k => $v) {
            $c->set($k, $v);
        }
        $c->call('save')->assertHasNoErrors();
    }

    public function test_inspector_is_created_without_email_or_password_and_cannot_use_the_erp(): void
    {
        $this->actingAs($this->admin());

        $this->create(['name' => '오검차', 'phone' => '010-1234-5678', 'permission' => 'user', 'role' => User::INSPECTOR_ROLE]);

        $u = User::where('name', '오검차')->firstOrFail();
        $this->assertNull($u->email);
        $this->assertFalse($u->canAccessErp(), '검차는 ROLES 밖이라 ERP 가 열리면 안 된다');
        $sm = Salesman::where('user_id', $u->id)->firstOrFail();
        $this->assertSame('inspector', $sm->type);
        $this->assertTrue($sm->isPayrollOnly());
        $this->assertSame(0, Salesman::query()->sales()->whereKey($sm->id)->count());
    }

    public function test_two_inspectors_without_email_do_not_collide(): void
    {
        $this->actingAs($this->admin());

        $this->create(['name' => '검차1', 'permission' => 'user', 'role' => User::INSPECTOR_ROLE]);
        $this->create(['name' => '검차2', 'permission' => 'user', 'role' => User::INSPECTOR_ROLE]);

        $this->assertSame(2, User::whereNull('email')->count(), 'email unique 는 NULL 을 여러 행 허용해야 한다');
    }

    public function test_non_sales_regular_users_are_mirrored_as_staff_with_their_role_as_label(): void
    {
        $this->actingAs($this->admin());

        $this->create(['name' => '김재무', 'email' => 'fin@x.test', 'password' => 'password1', 'permission' => 'user', 'role' => '재무']);

        $sm = Salesman::with('user')->where('name', '김재무')->firstOrFail();
        $this->assertSame('staff', $sm->type);
        $this->assertSame(__('domain.role.재무'), $sm->type_label);
        $this->assertSame(0, Salesman::query()->sales()->whereKey($sm->id)->count(), '직원은 영업 목록에 섞이지 않는다');
    }

    public function test_sales_user_keeps_the_old_mirror_and_switching_to_staff_keeps_the_row_active(): void
    {
        $this->actingAs($this->admin());
        $this->create(['name' => '박영업', 'email' => 'sales@x.test', 'password' => 'password1', 'permission' => 'user', 'role' => '영업', 'type' => 'freelance']);
        $u = User::where('email', 'sales@x.test')->firstOrFail();
        $this->assertSame('freelance', $u->salesman->type);

        Volt::test('admin.users.index')->call('openEdit', $u->id)->set('role', '관리')->call('save')->assertHasNoErrors();

        $sm = $u->salesman()->firstOrFail();
        $this->assertSame('staff', $sm->type);
        $this->assertTrue($sm->is_active, '영업 → 관리 전환은 이제 비활성화가 아니라 직원(급여만)으로 남는다');
        $this->assertSame(0, Salesman::query()->sales()->whereKey($sm->id)->count());
    }

    public function test_admin_grade_non_sales_accounts_are_not_mirrored(): void
    {
        $this->actingAs($this->admin());

        $this->create(['name' => '최대표', 'email' => 'ceo@x.test', 'password' => 'password1', 'permission' => 'admin', 'role' => '관리']);

        $this->assertNull(Salesman::where('user_id', User::where('email', 'ceo@x.test')->value('id'))->first());
    }

    public function test_resolvers_never_return_a_staff_row(): void
    {
        $u = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email' => 'mgr@x.test']);
        Salesman::create(['user_id' => $u->id, 'name' => $u->name, 'email' => 'mgr@x.test', 'type' => 'staff', 'is_active' => true]);

        $this->assertNull(SalesmanResolver::resolve('mgr@x.test'), 'board 이메일 매칭이 직원 행을 담당자로 꽂으면 안 된다');
    }

    public function test_roster_tabs_filter_by_type_and_by_linked_role(): void
    {
        $fin = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email' => 'f@x.test']);
        $mgr = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email' => 'm@x.test']);
        Salesman::create(['user_id' => $fin->id, 'name' => '재무씨', 'type' => 'staff', 'is_active' => true]);
        Salesman::create(['user_id' => $mgr->id, 'name' => '관리씨', 'type' => 'staff', 'is_active' => true]);
        Salesman::create(['name' => '검차씨', 'type' => 'inspector', 'is_active' => true]);
        Salesman::create(['name' => '영업씨', 'type' => 'employee', 'is_active' => true]);
        $this->actingAs($this->admin());

        Volt::test('erp.salesmen.index')->call('setTypeFilter', 'staff:재무')
            ->assertSee('재무씨')->assertDontSee('관리씨')->assertDontSee('검차씨')->assertDontSee('영업씨');
        Volt::test('erp.salesmen.index')->call('setTypeFilter', 'inspector')
            ->assertSee('검차씨')->assertDontSee('재무씨');
        Volt::test('erp.salesmen.index')->call('setTypeFilter', 'employee')
            ->assertSee('영업씨')->assertDontSee('재무씨');
    }

    public function test_backfill_command_mirrors_existing_regular_users_only_with_apply(): void
    {
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'email' => 'a@x.test', 'name' => '백필관리']);
        User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email' => 'b@x.test', 'name' => '백필대표']);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'type' => 'employee', 'email' => 'c@x.test', 'name' => '이미영업']);
        Salesman::create(['user_id' => $sales->id, 'name' => '이미영업', 'type' => 'employee', 'is_active' => true]);

        $this->artisan('salesmen:sync-staff')->expectsOutputToContain('대상 1명')->assertExitCode(0);
        $this->assertSame(1, Salesman::count(), 'dry-run 은 만들지 않는다');

        $this->artisan('salesmen:sync-staff', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(2, Salesman::count());
        $this->assertSame('staff', Salesman::where('name', '백필관리')->value('type'));
        $this->assertNull(Salesman::where('name', '백필대표')->first(), '관리자 등급은 명부에 안 올린다');
    }
}
