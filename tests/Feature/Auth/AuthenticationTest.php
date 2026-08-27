<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt as LivewireVolt;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = LivewireVolt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * 로그인 화면은 **번역을 거쳐야 한다** (2026-08-27 — 전역 하드코딩이었다).
     *
     * ⚠️ **지금은 이 화면이 영어로 뜨지 않는다** — `SetLocale` 미들웨어가
     *    `$request->user()?->locale ?: 'ko'` 로 정하는데 로그인 전엓 사용자가 없다.
     *    그래서 **렌더 결과로 영어를 단언하지 않는다** — 거짓 보증이 된다.
     *    대신 **문구가 번역 키를 거치는지**를 정적으로 본다.
     *    비로그인 언어 선택(Accept-Language 등)을 열면 그때 바로 산다.
     */
    public function test_login_screen_goes_through_translation(): void
    {
        $source = file_get_contents(resource_path('views/livewire/auth/login.blade.php'));

        foreach (['계정 로그인', '비밀번호', '로그인 상태 유지', '비밀번호를 잊으셨나요?'] as $korean) {
            $this->assertStringNotContainsString(
                $korean, $source,
                "로그인 화면에 한글이 박혔다: {$korean} — common.auth.* 로 빼야 한다"
            );
        }

        // 키가 양쪽 언어에 실제로 있어야 한다(없으면 키 문자열이 찍힌다).
        foreach (['login_title', 'login_desc', 'password', 'forgot', 'remember', 'submit'] as $key) {
            $this->assertNotSame('common.auth.'.$key, __('common.auth.'.$key), "common.auth.{$key} 번역이 없다");
        }
    }
}
