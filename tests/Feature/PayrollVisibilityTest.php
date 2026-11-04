<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔒 사내직원관리 공개 범위 + 업무관리자 명부 (jin 2026-11-04).
 *
 * - 「내가 선택한 사람만 내수직원, 사내직원·검차직원·관리·(수출통관·재무)·업무관리자 급여 항목을 볼 수 있도록」 — 사내직원관리 한정.
 *   한 번도 저장 안 했으면 종전(급여 입력 권한자 모두). 시스템관리자는 늘 본다.
 * - 「사내직원관리에 업무관리자도」 — 관리→업무관리자로 바꾸면 명부가 비활성이 되던 것(ssancarerp 이다은, 10월 급여 9줄이 월정산에서 빠질 뻔).
 */
class PayrollVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리'): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'phone' => '0108888'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now()]);
    }

    private function matrix(array $map): void
    {
        Setting::query()->updateOrCreate(['key' => 'payroll_viewers'], ['value' => json_encode($map, JSON_UNESCAPED_UNICODE), 'type' => 'string']);
        Setting::flushRiskParamMemo();
    }

    public function test_manager_is_mirrored_as_staff_and_keeps_an_existing_sales_row(): void
    {
        $super = $this->user('super');
        $this->actingAs($super);

        // 관리(일반사용자) → 업무관리자로 바꿔 저장해도 명부 행이 활성이고 「업무관리자」로 보인다
        $u = $this->user('user', '관리');
        $sm = Salesman::create(['user_id' => $u->id, 'name' => $u->name, 'type' => 'staff', 'is_active' => true]);
        Volt::test('admin.users.index')->call('openEdit', $u->id)->set('permission', 'manager')->call('save')->assertHasNoErrors();
        $sm->refresh();
        $this->assertTrue((bool) $sm->is_active, '업무관리자로 바꿔도 비활성이 되지 않는다');
        $this->assertSame('staff', $sm->type);
        $this->assertSame('업무관리자', $sm->type_label);
        $this->assertSame('manager', $sm->payrollCategory());

        // 영업 사내직원 행이 있는 업무관리자(최영숙 형태)는 유형을 지킨다
        $m = $this->user('manager', '관리');
        $emp = Salesman::create(['user_id' => $m->id, 'name' => $m->name, 'type' => 'employee', 'is_active' => true]);
        Volt::test('admin.users.index')->call('openEdit', $m->id)->call('save')->assertHasNoErrors();
        $this->assertSame('employee', $emp->fresh()->type);
        $this->assertTrue((bool) $emp->fresh()->is_active);
    }

    public function test_sync_command_revives_only_inactive_managers_without_a_leave_date(): void
    {
        $mgr = $this->user('manager');
        $dead = Salesman::create(['user_id' => $mgr->id, 'name' => '이다은', 'type' => 'staff', 'is_active' => false]);
        $sales = $this->user('user', '영업');
        $quit = Salesman::create(['user_id' => $sales->id, 'name' => '그만둔영업', 'type' => 'employee', 'is_active' => false]);
        $noRow = $this->user('manager');

        $this->artisan('salesmen:sync-staff')->assertSuccessful();
        $this->assertFalse((bool) $dead->fresh()->is_active, 'dry-run 은 안 바꾼다');

        $this->artisan('salesmen:sync-staff', ['--apply' => true])->assertSuccessful();
        $this->assertTrue((bool) $dead->fresh()->is_active, '업무관리자 비활성 행 → 활성');
        $this->assertFalse((bool) $quit->fresh()->is_active, '그만둔 영업은 그대로');
        $this->assertSame('staff', Salesman::where('user_id', $noRow->id)->value('type'), '행 없는 업무관리자 = 새로');
    }

    public function test_visibility_defaults_to_payroll_editors_then_follows_the_matrix(): void
    {
        $finance = $this->user('user', '재무');
        $manager = $this->user('user', '관리');
        $super = $this->user('super');
        $exportUser = $this->user('user', '수출통관');
        $insp = Salesman::create(['name' => '오검차', 'type' => 'inspector', 'is_active' => true]);
        $emp = Salesman::create(['name' => '김사원', 'type' => 'employee', 'is_active' => true]);

        $this->assertTrue($finance->canSeePayrollOf($insp), '저장 전 = 종전(급여 입력 권한자 모두)');
        $this->assertFalse($exportUser->canSeePayrollOf($insp), '급여 입력 권한 없는 사람은 원래대로 못 본다');

        $this->matrix(['employee' => [$finance->id], 'inspector' => []]);
        $this->assertTrue($finance->canSeePayrollOf($emp));
        $this->assertFalse($finance->canSeePayrollOf($insp), '체크 안 한 칸');
        $this->assertFalse($manager->canSeePayrollOf($emp), '체크 안 된 사람');
        $this->assertTrue($super->canSeePayrollOf($insp), '시스템관리자는 늘');
    }

    public function test_salesmen_page_hides_and_protects_payroll_outside_the_matrix(): void
    {
        $finance = $this->user('user', '재무');
        $insp = Salesman::create(['name' => '오검차', 'type' => 'inspector', 'is_active' => true]);
        $emp = Salesman::create(['name' => '김사원', 'type' => 'employee', 'is_active' => true]);
        PayrollEntry::replaceFor($insp->id, now()->format('Y-m'), [['label' => '기본급', 'amount' => 2_000_000]]);
        $this->matrix(['employee' => [$finance->id]]);
        $this->actingAs($finance);

        $hidden = Volt::test('erp.salesmen.index')->call('openEdit', $insp->id);
        $this->assertStringNotContainsString('data-pay-amount', $hidden->html(), '검차직원 급여 칸이 안 보인다');
        $hidden->set('payrollItems.0', '9999999')->call('save');
        $this->assertSame(2_000_000, (int) PayrollEntry::where('salesman_id', $insp->id)->sum('amount'), '화면을 조작해도 안 바뀐다');

        $shown = Volt::test('erp.salesmen.index')->call('openEdit', $emp->id);
        $this->assertStringContainsString('data-pay-amount', $shown->html(), '사내직원 급여 칸은 보인다');

        // 급여대장 업로드 후보에도 공개 범위 밖 사람은 없다
        $ids = array_column(Volt::test('erp.salesmen.index')->instance()->importCandidates(), 'id');
        $this->assertContains($emp->id, $ids);
        $this->assertNotContains($insp->id, $ids);
    }

    public function test_manager_tab_and_matrix_save(): void
    {
        $super = $this->user('super');
        $mgrUser = $this->user('manager');
        $mgr = Salesman::create(['user_id' => $mgrUser->id, 'name' => '업무김', 'type' => 'staff', 'is_active' => true]);
        $staffUser = $this->user('user', '관리');
        Salesman::create(['user_id' => $staffUser->id, 'name' => '관리박', 'type' => 'staff', 'is_active' => true]);
        $this->actingAs($super);

        $tab = Volt::test('erp.salesmen.index')->call('setTypeFilter', 'manager')->html();
        $this->assertStringContainsString('업무김', $tab);
        $this->assertStringNotContainsString('관리박', $tab);
        $this->assertStringNotContainsString('업무김', Volt::test('erp.salesmen.index')->call('setTypeFilter', 'staff:관리')->html(), '관리 탭엔 업무관리자 빠짐');

        $finance = $this->user('user', '재무');
        Volt::test('admin.settings')->assertSee('data-payroll-viewers', false)
            ->set('payrollViewerMatrix.manager', [(string) $finance->id])
            ->set('payrollViewerMatrix.employee', [])
            ->call('savePayrollViewers');
        $this->assertSame([$finance->id], Setting::payrollViewers()['manager']);
        $this->assertSame([], Setting::payrollViewers()['employee']);
        $this->assertTrue($finance->fresh()->canSeePayrollOf($mgr->fresh()));
        $this->assertTrue(AuditLog::where('action', 'payroll_viewers_changed')->exists());
    }
}
