<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\User;
use App\Support\BizmResultCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 알림톡 로그 「사유」 칸 = BizM 결과코드 + 뜻 (jin 2026-10-20 «BizM 에 로그인해서 찾기보다 우리 쪽에서 코드와 사유를 같이»).
 *   발송 거부(status=failed)와 미도달(report_status=undelivered, 결과조회 코드) 둘 다.
 */
class AlimtalkResultCodeLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_covers_the_official_list(): void
    {
        $this->assertGreaterThanOrEqual(230, count(BizmResultCodes::CODES), '공식 표 232개(2026-10-20 수집)');
        foreach (['K000', 'K101', 'K104', 'K105', 'K108', 'K140', 'M000', 'M107', 'E104', 'R000'] as $code) {
            $this->assertNotNull(BizmResultCodes::meaning($code), $code);
        }
    }

    public function test_send_rejection_shows_code_and_meaning(): void
    {
        $log = new AlimtalkLog(['status' => 'failed', 'error' => 'bizmsg fail — K104:TemplateNotFound — [{"code":"fail"}]']);

        $this->assertSame('발송 거부 K104 — 템플릿을 찾을 수 없음', $log->reasonLabel());
    }

    public function test_unknown_code_keeps_the_raw_text(): void
    {
        $log = new AlimtalkLog(['status' => 'failed', 'error' => 'bizmsg fail — K777:Whatever']);

        $this->assertSame('bizmsg fail — K777:Whatever', $log->reasonLabel(), '모르는 코드는 원문 그대로');
    }

    public function test_undelivered_shows_report_code_and_fallback_sms(): void
    {
        $plain = new AlimtalkLog(['status' => 'sent', 'report_status' => 'undelivered',
            'report_raw' => ['code' => 'success', 'message' => 'K101:NotAvailableSendMessage']]);
        $this->assertStringStartsWith('미도달 K101 — 수신불가 사용자', $plain->reasonLabel());

        $fallback = new AlimtalkLog(['status' => 'sent', 'report_status' => 'undelivered',
            'report_raw' => ['code' => 'success', 'message' => 'M000', 'originMessage' => 'K105:NoMatchedTemplate']]);
        $this->assertSame('알림톡 실패 K105 — 메시지 내용이 템플릿과 일치하지 않음 · 대체문자 M000 — 문자 발송 성공', $fallback->reasonLabel());

        $delivered = new AlimtalkLog(['status' => 'sent', 'report_status' => 'delivered', 'report_raw' => ['code' => 'success', 'message' => 'K000']]);
        $this->assertNull($delivered->reasonLabel(), '도달한 건은 사유 없음');
    }

    public function test_the_log_screen_renders_the_reason(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        AlimtalkLog::create(['template_code' => 'erp_daily_summary', 'phone' => '01000000000', 'status' => 'failed',
            'error' => 'bizmsg fail — K108:NoMatchedTemplateButtonException — [{}]']);
        AlimtalkLog::create(['template_code' => 'erp_daily_summary', 'phone' => '01000000001', 'status' => 'sent', 'msgid' => 'M',
            'report_status' => 'undelivered', 'report_raw' => ['code' => 'success', 'message' => 'K101:NotAvailableSendMessage']]);

        Volt::actingAs($super)->test('admin.alimtalk-logs.index')
            ->assertOk()
            ->assertSee('발송 거부 K108 — 메시지 버튼/바로연결이 템플릿과 일치하지 않음')
            ->assertSee('미도달 K101 — 수신불가 사용자');
    }
}
