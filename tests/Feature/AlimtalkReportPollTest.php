<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\BizmAlimtalkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 알림톡 전송결과 폴링(BizM /v2/sender/report) — 도달 분류기 + 폴링 커맨드 + 미도달 확인.
 * inert(실발송 없음)라 분류기 + Http::fake 계약이 유일한 검증 수단(2026-07-13).
 */
class AlimtalkReportPollTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'heyman', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'PROFILE_KEY', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
    }

    private function makeLog(string $msgid = 'WEB-1', string $status = 'sent'): AlimtalkLog
    {
        return AlimtalkLog::create([
            'template_code' => 'erp_daily_summary',
            'phone' => '01000000000',
            'status' => $status,
            'msgid' => $status === 'sent' ? $msgid : null,
            'message' => '테스트 본문',
        ]);
    }

    // ── 분류기 (PDF 명세 예제 실측 payload) ─────────────────────────────
    public function test_classifier_maps_report_payloads(): void
    {
        // 순수 성공코드 → 도달
        $this->assertSame('delivered', AlimtalkLog::classifyReport(['code' => 'success', 'data' => ['msgid' => 'x'], 'message' => 'K000', 'originMessage' => null]));
        $this->assertSame('delivered', AlimtalkLog::classifyReport(['code' => 'success', 'data' => ['msgid' => 'x'], 'message' => 'M000']));

        // 에러코드(':' 포함) → 미도달
        $this->assertSame('undelivered', AlimtalkLog::classifyReport(['code' => 'success', 'data' => ['msgid' => 'x'], 'message' => 'M101:NotAvailableSendMessage']));

        // originMessage 존재(알림톡 실패 → 대체문자 전환) → 미도달 (SMS 성공이어도 알림톡 자체는 미도달)
        $this->assertSame('undelivered', AlimtalkLog::classifyReport(['code' => 'success', 'data' => ['msgid' => 'x'], 'message' => 'M000', 'originMessage' => 'K105:NoMatchedTemplate']));

        // code != success (조회 실패/미준비) → 미확정(null, 재조회)
        $this->assertNull(AlimtalkLog::classifyReport(['code' => 'fail', 'data' => ['msgid' => 'x'], 'message' => 'E110:RequestMsgIdNotFound']));

        // message 없음 → 미확정
        $this->assertNull(AlimtalkLog::classifyReport(['code' => 'success', 'data' => ['msgid' => 'x'], 'message' => '']));
        $this->assertNull(AlimtalkLog::classifyReport(null));
    }

    // ── fetchReport 계약 ────────────────────────────────────────────────
    public function test_fetch_report_hits_bizm_with_msgid_and_profile(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['code' => 'success', 'data' => ['msgid' => 'WEB-1'], 'message' => 'K000'], 200)]);

        $body = BizmAlimtalkService::active()->fetchReport('WEB-1');

        $this->assertSame('success', $body['code']);
        Http::assertSent(fn ($req) => $req->method() === 'GET'
            && $req->hasHeader('userid', 'heyman')
            && str_contains($req->url(), 'sender/report')
            && str_contains($req->url(), 'msgid=WEB-1')
            && str_contains($req->url(), 'profile=PROFILE_KEY'));
    }

    // ── 폴링 커맨드 ─────────────────────────────────────────────────────
    public function test_poll_marks_delivered(): void
    {
        $this->configure();
        $log = $this->makeLog('WEB-DELIV');
        Http::fake(['*' => Http::response(['code' => 'success', 'data' => ['msgid' => 'WEB-DELIV'], 'message' => 'K000'], 200)]);

        $this->artisan('alimtalk:poll-report')->assertSuccessful();

        $log->refresh();
        $this->assertSame('delivered', $log->report_status);
        $this->assertNotNull($log->report_checked_at);
    }

    public function test_poll_marks_undelivered_and_flags_attention(): void
    {
        $this->configure();
        $log = $this->makeLog('WEB-UNDELIV');
        Http::fake(['*' => Http::response(['code' => 'success', 'data' => ['msgid' => 'WEB-UNDELIV'], 'message' => 'M107:DeniedSenderNumber'], 200)]);

        $this->artisan('alimtalk:poll-report')->assertSuccessful();

        $log->refresh();
        $this->assertSame('undelivered', $log->report_status);
        $this->assertSame(1, AlimtalkLog::query()->needsAttention()->count());
    }

    public function test_poll_leaves_pending_on_query_fail_for_retry(): void
    {
        $this->configure();
        $log = $this->makeLog('WEB-PENDING');
        Http::fake(['*' => Http::response(['code' => 'fail', 'data' => ['msgid' => 'WEB-PENDING'], 'message' => 'E110:RequestMsgIdNotFound'], 200)]);

        $this->artisan('alimtalk:poll-report')->assertSuccessful();

        $log->refresh();
        $this->assertNull($log->report_status);            // 미확정 유지 → 다음 실행 재조회
        $this->assertNotNull($log->report_checked_at);     // 조회 시도는 기록
    }

    public function test_poll_skips_when_unconfigured(): void
    {
        $this->makeLog('WEB-X');   // 설정 안 함
        Http::fake();

        $this->artisan('alimtalk:poll-report')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_failed_send_counts_as_attention(): void
    {
        // report 없이도 발송 실패(status=failed)는 주의 대상.
        AlimtalkLog::create(['template_code' => 'erp_daily_summary', 'phone' => '01000000000', 'status' => 'failed', 'error' => 'boom']);

        $this->assertSame(1, AlimtalkLog::query()->needsAttention()->count());
    }

    // ── 로그 화면 + 확인(acknowledge) ──────────────────────────────────
    public function test_admin_can_acknowledge_undelivered(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $log = AlimtalkLog::create(['template_code' => 'erp_daily_summary', 'phone' => '01000000000', 'status' => 'sent', 'msgid' => 'M', 'report_status' => 'undelivered']);

        Volt::actingAs($admin)->test('admin.alimtalk-logs.index')
            ->assertOk()
            ->call('acknowledge', $log->id);

        $log->refresh();
        $this->assertNotNull($log->acknowledged_at);
        $this->assertSame($admin->id, $log->acknowledged_by);
        $this->assertSame(0, AlimtalkLog::query()->needsAttention()->count());
    }

    public function test_acknowledge_all_clears_attention(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        AlimtalkLog::create(['template_code' => 'a', 'phone' => '010', 'status' => 'failed']);
        AlimtalkLog::create(['template_code' => 'b', 'phone' => '011', 'status' => 'sent', 'msgid' => 'M', 'report_status' => 'undelivered']);

        Volt::actingAs($admin)->test('admin.alimtalk-logs.index')
            ->call('acknowledgeAll');

        $this->assertSame(0, AlimtalkLog::query()->needsAttention()->count());
    }
}
