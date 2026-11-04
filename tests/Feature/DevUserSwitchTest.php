<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 로컬 전용 사용자 전환 (jin 2026-11-04, board 088c877 과 같은 구조) — 꺼지면 404 · super 만 · 연속 전환 · 복귀 · 감사 행. */
class DevUserSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $permission, string $role = '관리', ?string $title = null): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'approval_title' => $title, 'email_verified_at' => now()]);
    }

    public function test_dev_user_switch_is_local_super_only(): void
    {
        $root = $this->mk('super');
        $ceo = $this->mk('admin', '관리', '대표');
        $finance = $this->mk('user', '재무');

        config(['app.dev_user_switch' => false]);   // 운영과 같음
        $this->actingAs($root)->post('/dev/switch-user', ['user_id' => $finance->id])->assertNotFound();

        config(['app.dev_user_switch' => true]);
        $this->actingAs($ceo)->post('/dev/switch-user', ['user_id' => $root->id])->assertForbidden();   // 최고관리자도 안 됨(isAdmin 함정)

        $this->actingAs($root)->post('/dev/switch-user', ['user_id' => $finance->id])->assertRedirect();
        $this->assertSame($finance->id, auth()->id());
        $this->get('/erp/dashboard')->assertOk()->assertSee(__('nav.dev_switch.back'));   // 전환된 상태에서도 상자·복귀 버튼

        $this->post('/dev/switch-user', ['user_id' => $ceo->id])->assertRedirect();   // 재무 → 대표로 바로
        $this->assertSame($ceo->id, auth()->id());
        $this->post('/dev/switch-back')->assertRedirect();
        $this->assertSame($root->id, auth()->id());

        $rows = AuditLog::where('action', 'dev_user_switch')->get();
        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->user_id === $root->id), '행위자는 원래 super');
    }

    public function test_no_login_staff_are_not_switch_targets(): void
    {
        config(['app.dev_user_switch' => true]);
        $root = $this->mk('super');
        $inspector = $this->mk('user', User::NO_LOGIN_ROLES[0]);

        $this->actingAs($root)->post('/dev/switch-user', ['user_id' => $inspector->id])->assertNotFound();
        $this->assertSame($root->id, auth()->id());
    }
}
