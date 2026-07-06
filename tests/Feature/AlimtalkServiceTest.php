<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 알림톡 전송 파운데이션 — 게이트/실패격리/로그/렌더.
 * 실발송(BizM)은 크리덴셜 대기라 Http::fake 로 계약만 검증.
 */
class AlimtalkServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 현재 회사(set) 기준으로 발송 가능한 최소 설정을 채운다. */
    private function configure(string $code = 'erp_daily_summary'): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'heyman', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'PROFILE_KEY', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$code}_{$set}"], ['value' => 'TMPL_'.$code, 'type' => 'string']);
    }

    public function test_render_substitutes_variables(): void
    {
        $msg = AlimtalkTemplates::render('erp_sale_unpaid', [
            '차량번호' => '19더9065', '바이어' => 'ABC', '미수금액' => 'USD 4,200',
        ]);

        $this->assertStringContainsString('19더9065', $msg);
        $this->assertStringContainsString('바이어: ABC', $msg);
        $this->assertStringNotContainsString('#{', $msg);   // 미치환 자리표시자 없음
    }

    public function test_configured_send_hits_bizm_and_logs_msgid(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response([['msgid' => 'BIZM-123', 'code' => 'success']], 200)]);

        $log = BizmAlimtalkService::active()->send('erp_daily_summary', '010-1234-5678', [
            '날짜' => '2026-07-06', '판매건수' => '12', '매출액' => '4억 2,000만원',
            '선적전건수' => '5', '선적전금액' => '3,200만원', '선적후건수' => '3', '선적후금액' => '8,500만원',
            '미수합계' => '1억 1,700만원',
        ]);

        $this->assertSame('sent', $log->status);
        $this->assertSame('BIZM-123', $log->msgid);
        $this->assertSame('01012345678', $log->phone);   // 정규화
        Http::assertSent(fn ($req) => $req->hasHeader('userid', 'heyman')
            && $req->data()[0]['tmplId'] === 'TMPL_erp_daily_summary'
            && $req->data()[0]['profile'] === 'PROFILE_KEY');
    }

    public function test_master_gate_off_skips_without_http(): void
    {
        $this->configure();
        Setting::updateOrCreate(['key' => 'alimtalk_enabled_'.Setting::companyTemplateSet()], ['value' => '0', 'type' => 'boolean']);
        Http::fake();

        $log = BizmAlimtalkService::active()->send('erp_daily_summary', '01011112222');

        $this->assertSame('skipped', $log->status);
        Http::assertNothingSent();
    }

    public function test_unconfigured_skips(): void
    {
        Http::fake();

        $log = BizmAlimtalkService::active()->send('erp_daily_summary', '01011112222');

        $this->assertSame('skipped', $log->status);
        Http::assertNothingSent();
    }

    public function test_network_exception_is_swallowed_and_logged_failed(): void
    {
        $this->configure();
        Http::fake(fn () => throw new \RuntimeException('boom'));

        // 예외를 호출측으로 던지면 안 됨(fire-and-forget) — 여기서 잡히면 테스트 실패.
        $log = BizmAlimtalkService::active()->send('erp_daily_summary', '01011112222');

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('boom', (string) $log->error);
    }

    public function test_missing_tmplid_cannot_send(): void
    {
        $this->configure('erp_daily_summary');   // vehicle_new tmplId 는 안 채움
        $cfg = AlimtalkConfig::active();

        $this->assertTrue($cfg->canSend('erp_daily_summary'));
        $this->assertFalse($cfg->canSend('erp_vehicle_new'));
        $this->assertTrue($cfg->isConfigured());   // userkey 없어도 발송 계정은 설정됨
    }

    public function test_test_send_bypasses_master_gate(): void
    {
        $this->configure();
        Setting::updateOrCreate(['key' => 'alimtalk_enabled_'.Setting::companyTemplateSet()], ['value' => '0', 'type' => 'boolean']);
        Http::fake(['*' => Http::response([['msgid' => 'BIZM-TEST']], 200)]);

        $log = BizmAlimtalkService::active()->sendTest('010-9999-8888');

        $this->assertSame('sent', $log->status);
        Http::assertSentCount(1);
    }

    public function test_settings_component_saves_config_and_encrypts_userkey(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        $set = Setting::companyTemplateSet();

        Volt::test('admin.settings')
            ->assertOk()
            ->set('alimtalkEnabled', true)
            ->set('alimtalkUserid', 'heyman')
            ->set('alimtalkProfile', 'PROFILE_KEY')
            ->set('alimtalkUserkey', 'SECRET_KEY')
            ->set('alimtalkTmplIds.erp_vehicle_new', 'TMPL_NEW')
            ->set('alimtalkToggles.erp_sale_unpaid', false)
            ->call('saveAlimtalk')
            ->assertHasNoErrors();

        $this->assertTrue((bool) Setting::get("alimtalk_enabled_{$set}"));
        $this->assertSame('heyman', Setting::get("alimtalk_userid_{$set}"));
        $this->assertSame('TMPL_NEW', Setting::get("alimtalk_tmpl_erp_vehicle_new_{$set}"));
        $this->assertFalse((bool) Setting::get("alimtalk_toggle_erp_sale_unpaid_{$set}"));
        $this->assertSame('SECRET_KEY', Crypt::decryptString(Setting::get("alimtalk_userkey_{$set}")));
    }
}
