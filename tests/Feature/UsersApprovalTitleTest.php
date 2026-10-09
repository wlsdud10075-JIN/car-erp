<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 사용자관리 — 월정산 v3 (2026-10-09): 최고관리자 결재 직급 + 「업무관리자를 바로 만들 수 없던」 버그.
 *
 * jin 2026-10-08: *「업무관리자를 바로 생성이 안 되고, 꼭 일반사용자(관리)를 놓고, 그다음에 업무관리자로 바꿔야 등록되더라고」*
 * 원인 = 폼 기본값 role='영업'·type='' 인 채로 권한만 바꾸면 역할 칸은 숨겨지는데 `type required_if:role,영업` 검증은
 * 그대로 걸려 멈췄고, 에러는 숨은 칸 안에 그려졌다.
 */
class UsersApprovalTitleTest extends TestCase
{
    use RefreshDatabase;

    private function superUser(): User
    {
        return User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_a_manager_can_be_created_directly_with_the_default_form(): void
    {
        $this->actingAs($this->superUser());

        Volt::test('admin.users.index')->call('openCreate')
            ->set('name', '김업무')->set('email', 'manager@car-erp.test')->set('password', 'password1')
            ->set('permission', 'manager')          // 역할·정산유형은 폼 기본값(영업·빈칸) 그대로 둔 채
            ->call('save')->assertHasNoErrors();

        $u = User::where('email', 'manager@car-erp.test')->firstOrFail();
        $this->assertSame('manager', $u->permission);
        $this->assertSame('관리', $u->role, '권한이 user 가 아니면 역할은 관리로 정리된다');
        $this->assertNull($u->type);
        $this->assertSame(0, Salesman::where('user_id', $u->id)->count(), '업무관리자에게 영업담당자 행이 생기면 안 된다');
    }

    public function test_an_admin_gets_an_approval_title_and_others_do_not(): void
    {
        $this->actingAs($this->superUser());

        Volt::test('admin.users.index')->call('openCreate')
            ->set('name', '박부장')->set('email', 'bu@car-erp.test')->set('password', 'password1')
            ->set('permission', 'admin')->set('approval_title', '부장')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('부장', User::where('email', 'bu@car-erp.test')->value('approval_title'));

        Volt::test('admin.users.index')->call('openCreate')
            ->set('name', '정영업')->set('email', 'sales@car-erp.test')->set('password', 'password1')
            ->set('permission', 'user')->set('role', '재무')->set('approval_title', '대표')   // 폼 변조
            ->call('save')->assertHasNoErrors();
        $this->assertNull(User::where('email', 'sales@car-erp.test')->value('approval_title'), '직급은 최고관리자에게만');

        Volt::test('admin.users.index')->call('openCreate')
            ->set('name', '엉뚱')->set('email', 'x@car-erp.test')->set('password', 'password1')
            ->set('permission', 'admin')->set('approval_title', '회장')
            ->call('save')->assertHasErrors('approval_title');
    }

    public function test_the_title_survives_editing(): void
    {
        $this->actingAs($this->superUser());
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'approval_title' => '전무', 'email_verified_at' => now()]);

        Volt::test('admin.users.index')->call('openEdit', $admin->id)->assertSet('approval_title', '전무')
            ->set('approval_title', '')->call('save')->assertHasNoErrors();
        $this->assertNull($admin->fresh()->approval_title, '비우면 종전(최종 승인자)');
    }
}
