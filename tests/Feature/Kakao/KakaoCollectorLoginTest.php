<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoCollectorToken;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 수집기 로그인(아이디·비밀번호 → 토큰)과 체크 상태 저장 — 11-10 exe 창 시절 경로. §11 부터 실무자는 ERP 설정 탭 [이 PC 연결하기](.bat)로 연결하고,
 * 이 API 는 남겨 둔다(비밀번호를 치는 다른 경로가 필요할 때·옛 exe 호환).
 */
class KakaoCollectorLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
    }

    public function test_login_issues_a_device_token_and_replaces_the_old_one_for_the_same_device(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'password' => 'secret-pw1']);

        $this->postJson('/api/kakao/login', ['email' => $user->email, 'password' => 'wrong', 'device' => 'PC-1'])->assertStatus(401);
        $this->assertSame(0, KakaoCollectorToken::count());

        $first = $this->postJson('/api/kakao/login', ['email' => $user->email, 'password' => 'secret-pw1', 'device' => 'PC-1']);
        $first->assertOk()->assertJsonPath('name', $user->name);
        $t1 = $first->json('token');
        $this->assertStringStartsWith('kck_', $t1);
        $this->assertNotNull(KakaoCollectorToken::findActive($t1));

        // 같은 기기로 다시 로그인하면 옛 토큰은 폐기
        $second = $this->postJson('/api/kakao/login', ['email' => $user->email, 'password' => 'secret-pw1', 'device' => 'PC-1']);
        $t2 = $second->json('token');
        $this->assertNull(KakaoCollectorToken::findActive($t1));
        $this->assertNotNull(KakaoCollectorToken::findActive($t2));
        $this->assertSame(2, KakaoCollectorToken::count());

        // 토큰으로 바로 방 목록 API 가 된다
        $this->withHeader('Authorization', 'Bearer '.$t2)->getJson('/api/kakao/rooms')->assertOk()->assertJson(['selected' => []]);
    }

    public function test_login_is_blocked_when_the_company_toggle_is_off(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'password' => 'secret-pw1']);
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '0', 'type' => 'boolean']);

        $this->postJson('/api/kakao/login', ['email' => $user->email, 'password' => 'secret-pw1'])->assertStatus(403);
    }

    public function test_select_stores_the_checkbox_state_as_a_whole(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        [, $plain] = KakaoCollectorToken::issue($user, 'PC-1');
        $a = KakaoRoom::create(['name' => '방A']);
        $a->users()->attach($user->id, ['my_name' => '', 'selected' => true]);

        $res = $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/kakao/rooms/select', ['selected' => ['방B', '방C']]);

        $res->assertOk()->assertJson(['selected' => ['방B', '방C']]);
        $this->assertFalse((bool) $user->kakaoRooms()->where('kakao_rooms.id', $a->id)->first()->pivot->selected);   // 목록에 없던 방은 해제
        $this->assertSame(3, KakaoRoom::count());

        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/kakao/rooms/select', ['selected' => []])->assertOk()->assertJson(['selected' => []]);
    }
}
