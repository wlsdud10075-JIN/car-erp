<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 로그 화면 접근 권한 매트릭스 (jin 2026-07-28 확정).
 *
 *   운영 로그 3종 (문서접근·감사·메일발송) = **[관리] 이상**
 *      시스템관리자(super) · 최고관리자(admin) · 업무관리자(manager) · role='관리'
 *   알림톡 2종 (알림톡 로그·알림톡 안내)   = **시스템관리자 전용**
 *
 * 라우트 미들웨어(operation-logs / super-admin)와 컴포넌트 mount 가드 이중으로 막는다.
 */
class LogScreensAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_LOG_ROUTES = [
        '/admin/document-access-logs',
        '/admin/audit-logs',
        '/admin/mail-delivery-logs',
    ];

    private const SUPER_ONLY_ROUTES = [
        '/admin/alimtalk-logs',
        '/admin/alimtalk-catalog',
    ];

    private function user(string $permission, ?string $role = null): User
    {
        return User::factory()->create([
            'permission' => $permission,
            'role' => $role ?? '관리',
            'email_verified_at' => now(),
        ]);
    }

    public function test_operation_logs_allowed_for_manage_level_and_above(): void
    {
        $allowed = [
            'super' => $this->user('super'),
            'admin' => $this->user('admin'),
            'manager' => $this->user('manager'),
            'role=관리' => $this->user('user', '관리'),
        ];

        foreach ($allowed as $label => $user) {
            foreach (self::OPERATION_LOG_ROUTES as $route) {
                $this->actingAs($user)->get($route)->assertOk("{$label} 이 {$route} 접근 불가");
            }
        }
    }

    public function test_operation_logs_blocked_for_other_roles(): void
    {
        foreach (['영업', '수출통관', '재무'] as $role) {
            $user = $this->user('user', $role);
            foreach (self::OPERATION_LOG_ROUTES as $route) {
                $this->actingAs($user)->get($route)->assertForbidden("role={$role} 이 {$route} 에 뚫림");
            }
        }
    }

    public function test_alimtalk_screens_are_super_only(): void
    {
        foreach (self::SUPER_ONLY_ROUTES as $route) {
            $this->actingAs($this->user('super'))->get($route)->assertOk("super 가 {$route} 접근 불가");

            // 최고관리자·업무관리자·관리 전부 차단 (jin: 알림톡은 super 만)
            foreach (['admin', 'manager'] as $permission) {
                $this->actingAs($this->user($permission))->get($route)
                    ->assertForbidden("{$permission} 이 {$route} 에 뚫림");
            }
            $this->actingAs($this->user('user', '관리'))->get($route)
                ->assertForbidden("role=관리 가 {$route} 에 뚫림");
        }
    }

    /** 권한 헬퍼 자체도 고정 — 미들웨어·컴포넌트·사이드바가 전부 이걸 본다. */
    public function test_can_view_operation_logs_helper(): void
    {
        $this->assertTrue($this->user('super')->canViewOperationLogs());
        $this->assertTrue($this->user('admin')->canViewOperationLogs());
        $this->assertTrue($this->user('manager')->canViewOperationLogs());
        $this->assertTrue($this->user('user', '관리')->canViewOperationLogs());
        $this->assertFalse($this->user('user', '영업')->canViewOperationLogs());
        $this->assertFalse($this->user('user', '재무')->canViewOperationLogs());
    }
}
