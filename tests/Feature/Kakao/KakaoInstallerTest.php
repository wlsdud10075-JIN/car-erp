<?php

namespace Tests\Feature\Kakao;

use App\Http\Controllers\KakaoInstallerController;
use App\Models\AuditLog;
use App\Models\KakaoCollectorToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §11 「이 PC 연결하기」 — ERP 가 만드는 1회용 설치 .bat (docs/design/kakao-automation.md §11-2·11-3).
 * 토큰·local_secret·회사 주소가 안에 박히고, 받고 설치 안 한 옛 토큰은 폐기, 쓰던 PC 토큰은 그대로. 공개 범위 밖·토글 OFF 는 403.
 * .bat 은 ASCII·CRLF — 한국어 Windows 의 CP949 콘솔에서 깨지지 않게.
 */
class KakaoInstallerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
        config(['app.url' => 'https://heymancar.com']);
    }

    public function test_download_embeds_a_fresh_token_and_secret_and_is_ascii_crlf(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);

        $res = $this->actingAs($user)->post(route('erp.kakao.installer'));

        $res->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $bat = $res->getContent();
        $token = KakaoCollectorToken::sole();
        $this->assertNull($token->device_name, '기기 이름은 에이전트 첫 호출(X-Kakao-Device)이 채운다');
        $this->assertMatchesRegularExpression('/"token": "(kck_[A-Za-z0-9]{48})"/', $bat);
        preg_match('/"token": "(kck_[A-Za-z0-9]{48})"/', $bat, $m);
        $this->assertSame($token->id, KakaoCollectorToken::findActive($m[1])?->id, '박힌 토큰으로 바로 API 가 된다');
        $this->assertStringContainsString('"local_secret": "'.$token->local_secret.'"', $bat);
        $this->assertStringContainsString('set "ERP=https://heymancar.com"', $bat);
        $this->assertStringContainsString('https://heymancar.com/'.KakaoInstallerController::EXE_PUBLIC_PATH, $bat);
        $this->assertStringContainsString('Unblock-File', $bat);
        $this->assertStringContainsString("GetFolderPath('Startup')", $bat, '시작프로그램 바로가기 = PC 로그인 때 자동 시작');
        $this->assertStringContainsString('del "%~f0"', $bat, '자기 삭제(토큰이 평문으로 남지 않게)');
        $this->assertSame(1, preg_match('/^[\x20-\x7E\r\n]+$/', $bat), 'ASCII 만');
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $bat), 'CRLF 만');
        $this->assertDatabaseHas('audit_logs', ['action' => 'kakao_installer_downloaded', 'auditable_id' => $token->id]);
        $this->assertSame(0, AuditLog::where('action', 'kakao_installer_downloaded')->where('new_value', 'like', '%kck_%')->count(), '감사에 토큰 원문을 남기지 않는다');
    }

    public function test_redownload_revokes_only_installers_that_were_never_used(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $this->actingAs($user);
        $this->post(route('erp.kakao.installer'))->assertOk();
        $unused = KakaoCollectorToken::sole();
        $this->post(route('erp.kakao.installer'))->assertOk();
        $this->assertNotNull($unused->fresh()->revoked_at, '받고 설치 안 한 .bat 의 토큰은 폐기');
        $used = KakaoCollectorToken::whereNull('revoked_at')->sole();
        $used->forceFill(['last_used_at' => now(), 'device_name' => 'OFFICE-PC'])->save();   // 설치돼 쓰이는 중

        $this->post(route('erp.kakao.installer'))->assertOk();   // 다른 PC 를 연결하는 중

        $this->assertNull($used->fresh()->revoked_at, '쓰던 PC 의 토큰은 그대로');
        $this->assertSame(2, KakaoCollectorToken::whereNull('revoked_at')->count());
    }

    public function test_agent_first_call_fills_the_device_name(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        [$token, $plain] = KakaoCollectorToken::issue($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'X-Kakao-Device' => 'LAPTOP-1'])->getJson('/api/kakao/rooms')->assertOk();
        $this->assertSame('LAPTOP-1', $token->fresh()->device_name);
        $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'X-Kakao-Device' => 'OTHER'])->getJson('/api/kakao/rooms')->assertOk();
        $this->assertSame('LAPTOP-1', $token->fresh()->device_name, '한 번 채운 이름은 안 바꾼다');
    }

    public function test_outside_visibility_or_toggle_off_is_forbidden_and_issues_nothing(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $other = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        Setting::updateOrCreate(['key' => 'kakao_viewers'], ['value' => json_encode([$other->id]), 'type' => 'string']);
        Setting::flushRiskParamMemo();

        $this->actingAs($user)->post(route('erp.kakao.installer'))->assertForbidden();

        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '0', 'type' => 'boolean']);
        Setting::flushRiskParamMemo();
        $this->actingAs($other)->post(route('erp.kakao.installer'))->assertForbidden();
        $this->assertSame(0, KakaoCollectorToken::count());
    }
}
