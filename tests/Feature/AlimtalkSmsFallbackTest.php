<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Setting;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 알림톡 실패 시 문자(LMS) 대체발송 (jin 2026-08-20).
 *
 * BizM 이 대신 보낸다 — 우리가 실패를 감지해 재발송하는 게 아니라, 발송 요청에
 * `smsKind`·`msgSms`·`smsSender`·`smsLmsTit` 를 얹어 두면 알림톡이 실패했을 때 자동으로 문자가 나간다
 * (API v2.29.5 §3.2). 추가 API 호출이 없다.
 *
 * 계기: 말소등록증이 특정 딜러에게 `K101:NotAvailableSendMessage` 로 **한 달째** 안 가고 있었다
 *   (실측 heymanerp 30일 sent 50 / failed 7, 전부 같은 번호). 서류를 못 받으면 딜러 업무가 멈춘다.
 *
 * ⚠️ 대상은 `SMS_FALLBACK` 에 넣은 것만 — 여기 넣는 만큼 **문자 요금이 나간다**.
 * ⚠️ 발신번호가 비면 아예 안 얹는다 → BizM 등록 전에 배포해도 무해하다.
 */
class AlimtalkSmsFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $smsSender = '0212345678'): void
    {
        $set = Setting::companyTemplateSet();
        foreach ([
            "alimtalk_enabled_{$set}" => '1',
            "alimtalk_userid_{$set}" => 'testuser',
            "alimtalk_profile_{$set}" => 'testprofile',
            "alimtalk_sms_sender_{$set}" => $smsSender,
            'alimtalk_tmpl_erp_deregistration_notice_'.$set => 'tmpl_dereg',
            'alimtalk_tmpl_erp_purchase_unpaid_'.$set => 'tmpl_unpaid',
        ] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
        Setting::updateOrCreate(['key' => "alimtalk_userkey_{$set}"],
            ['value' => Crypt::encryptString('k'), 'type' => 'string']);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'X1'], 'message' => 'K000']], 200),
        ]);
    }

    /** 🔒 말소등록증에는 대체문자 4개 필드가 실린다. */
    public function test_deregistration_notice_carries_sms_fallback_fields(): void
    {
        $this->configure();
        $this->fakeOk();

        BizmAlimtalkService::active()->send('erp_deregistration_notice', '01012345678', [
            '차량번호' => '12가3456', '링크' => 'https://example.test/x',
        ]);

        Http::assertSent(function ($req) {
            $item = $req->data()[0];
            $this->assertSame('L', $item['smsKind'] ?? null, 'LMS 여야 한다 — SMS(90byte)엔 링크가 든 본문이 안 들어간다');
            $this->assertSame('0212345678', $item['smsSender'] ?? null);
            $this->assertSame($item['msg'], $item['msgSms'] ?? null, '문자 본문은 알림톡 본문과 같아야 한다');
            $this->assertSame('말소등록증 발급 안내', $item['smsLmsTit'] ?? null);
            // 실제 내용이 실렸는지 — 링크가 빠지면 딜러가 서류를 못 받는다.
            $this->assertStringContainsString('https://example.test/x', $item['msgSms']);

            return true;
        });
    }

    /** 대상이 아닌 알림에는 안 붙는다 — 넣는 만큼 문자 요금이 나간다. */
    public function test_other_templates_do_not_get_sms_fallback(): void
    {
        $this->configure();
        $this->fakeOk();

        BizmAlimtalkService::active()->send('erp_purchase_unpaid', '01012345678', ['건수' => '3', '총액' => '1,000']);

        Http::assertSent(function ($req) {
            $item = $req->data()[0];
            $this->assertArrayNotHasKey('smsKind', $item);
            $this->assertArrayNotHasKey('msgSms', $item);
            $this->assertArrayNotHasKey('smsSender', $item);

            return true;
        });
    }

    /** 🔒 발신번호가 없으면 아예 안 붙는다 — BizM 사전등록 전에 배포해도 무해해야 한다. */
    public function test_no_fallback_without_a_registered_sender(): void
    {
        $this->configure('');
        $this->fakeOk();

        BizmAlimtalkService::active()->send('erp_deregistration_notice', '01012345678', [
            '차량번호' => '12가3456', '링크' => 'https://example.test/x',
        ]);

        Http::assertSent(function ($req) {
            $item = $req->data()[0];
            $this->assertArrayNotHasKey('smsKind', $item, '발신번호 없이 대체발송을 요청하면 BizM 이 거부한다');
            $this->assertArrayNotHasKey('smsSender', $item);

            return true;
        });
    }

    /** 하이픈을 넣어도 숫자만 저장·전송된다 — BizM 은 하이픈 없는 형태를 받는다. */
    public function test_sender_number_is_normalised_to_digits(): void
    {
        $this->configure('02-1234-5678');
        $this->assertSame('0212345678', AlimtalkConfig::active()->smsSender);
    }

    /**
     * ⚠️ 아이템리스트형은 대체발송 대상이 되면 안 된다 — 카드가 문자로 안 가서
     * 본문만 남고 **숫자가 통째로 빠진다**(대표 보고류가 여기 해당).
     */
    public function test_itemlist_templates_are_not_sms_fallback_targets(): void
    {
        $bad = array_values(array_intersect(
            AlimtalkTemplates::SMS_FALLBACK,
            array_keys(AlimtalkTemplates::ITEMLIST),
        ));

        $this->assertSame([], $bad,
            "아이템리스트형은 카드가 문자로 안 간다 — 본문만 가면 숫자가 빠진다.\n"
            .'문자로 보내려면 문자 전용 본문을 따로 만들 것: '.implode(', ', $bad));
    }

    /** 대상마다 LMS 제목이 있어야 한다 — 없으면 제목 없는 문자가 나간다. */
    public function test_every_fallback_target_has_an_lms_title(): void
    {
        foreach (AlimtalkTemplates::SMS_FALLBACK as $code) {
            $this->assertArrayHasKey($code, AlimtalkTemplates::TEMPLATES, "{$code} 가 템플릿에 없다");
            $title = AlimtalkTemplates::SMS_TITLE[$code] ?? '';
            $this->assertNotSame('', $title, "{$code} 에 LMS 제목이 없다");
            $this->assertLessThanOrEqual(30, mb_strlen($title), "{$code} LMS 제목이 30자를 넘는다");
        }
    }

    /** 발송 로그는 종전대로 남는다 — 대체발송을 얹어도 기록 경로가 안 바뀐다. */
    public function test_logging_is_unchanged(): void
    {
        $this->configure();
        $this->fakeOk();

        BizmAlimtalkService::active()->send('erp_deregistration_notice', '01012345678', [
            '차량번호' => '12가3456', '링크' => 'https://example.test/x',
        ]);

        $log = AlimtalkLog::where('template_code', 'erp_deregistration_notice')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
    }
}
