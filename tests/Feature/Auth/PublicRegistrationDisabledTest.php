<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 공개 회원가입 차단 (jin 2026-07-30) — 사내 ERP 라 계정 생성은 /admin/users 에서만.
 *
 * 왜 가드가 필요한가: Laravel 스타터 킷이 /register 를 기본 제공하고, 로그인 화면에 링크가
 * 없어도 URL 직접 진입이 된다. 제거 전까지 실제로 막고 있던 건 users.role DB 기본값('전체')이
 * User::ROLES 에 없어서 canAccessErp() 가 false 인 **우연**이었다 — 설계된 방어가 아니었다.
 */
class PublicRegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_route_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_register_route_name_is_not_registered(): void
    {
        $this->assertFalse(
            app('router')->has('register'),
            "route('register') 가 살아 있으면 스타터 킷 가입 화면이 되살아난 것"
        );
    }

    /**
     * 🚩 재발 방지 — role DB 기본값이 유효한 role 로 "정리" 되면 자가가입 계정이 곧바로
     *   ERP 전체를 보게 된다. 기본값은 ROLES 밖에 있어야 한다(이중 안전망).
     */
    public function test_default_role_is_not_a_grantable_role(): void
    {
        $u = User::create([
            'name' => 'x', 'email' => 'x@example.com', 'password' => bcrypt('secret-pass-1234'),
        ]);

        $this->assertNotContains($u->fresh()->role, User::ROLES, 'DB 기본 role 이 유효 role 이면 안 된다');
        $this->assertFalse($u->fresh()->canAccessErp());
    }
}
