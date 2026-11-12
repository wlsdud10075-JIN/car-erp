<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
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

        // originMessage 존재(알림톡 실패 → 대체문자 전환): 🔀 2026-10-27 (jin) 문자가 성공(M000)이면 도달, 문자도 실패면 미도달
        $this->assertSame('delivered', AlimtalkLog::classifyReport(['code' => 'success', 'data' => ['msgid' => 'x'], 'message' => 'M000', 'originMessage' => 'K105:NoMatchedTemplate']));
        $this->assertSame('undelivered', AlimtalkLog::classifyReport(['code' => 'success', 'data' => ['msgid' => 'x'], 'message' => 'M103:DeniedByReceiver', 'originMessage' => 'K101:NotAvailableSendMessage']));

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

    /** 🔀 2026-10-27 — 알림톡 실패 → 대체문자 성공은 주의 목록에서 빠지고, 사유 칸엔 회색으로 남는다. */
    public function test_sms_fallback_success_is_delivered_and_not_flagged(): void
    {
        $this->configure();
        $log = $this->makeLog('WEB-SMS');
        Http::fake(['*' => Http::response(['code' => 'success', 'data' => ['msgid' => 'WEB-SMS'], 'message' => 'M000', 'originMessage' => 'K101:NotAvailableSendMessage'], 200)]);

        $this->artisan('alimtalk:poll-report')->assertSuccessful();

        $log->refresh();
        $this->assertSame('delivered', $log->report_status);
        $this->assertSame(0, AlimtalkLog::query()->needsAttention()->count());
        $this->assertNull($log->reasonLabel());
        $this->assertStringContainsString('문자로 도달', (string) $log->smsFallbackLabel());
        $this->assertStringContainsString('K101', (string) $log->smsFallbackLabel());
    }

    /** 옛 규칙으로 미도달이 찍힌 행(ssancarerp 10-27 실제 1건)은 다음 폴링이 저장된 응답으로 다시 분류한다 — API 호출 0. */
    public function test_poll_reclassifies_old_undelivered_rows_with_sms_success(): void
    {
        $this->configure();
        $old = AlimtalkLog::create(['template_code' => 'erp_deregistration_notice', 'phone' => '01000000000', 'status' => 'sent', 'msgid' => 'WEB-OLD',
            'report_status' => 'undelivered', 'report_checked_at' => now()->subDay(),
            'report_raw' => ['code' => 'success', 'data' => ['msgid' => 'WEB-OLD'], 'message' => 'M000', 'originMessage' => 'K101:NotAvailableSendMessage']]);
        $stillBad = AlimtalkLog::create(['template_code' => 'erp_deregistration_notice', 'phone' => '01000000001', 'status' => 'sent', 'msgid' => 'WEB-BAD',
            'report_status' => 'undelivered', 'report_checked_at' => now()->subDay(),
            'report_raw' => ['code' => 'success', 'data' => ['msgid' => 'WEB-BAD'], 'message' => 'M103:DeniedByReceiver', 'originMessage' => 'K101:NotAvailableSendMessage']]);
        Http::fake();

        $this->artisan('alimtalk:poll-report')->expectsOutputToContain('reclassified=1')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('delivered', $old->refresh()->report_status);
        $this->assertSame('undelivered', $stillBad->refresh()->report_status);
        $this->assertSame(1, AlimtalkLog::query()->needsAttention()->count());
    }

    public function test_failed_send_counts_as_attention(): void
    {
        // report 없이도 발송 실패(status=failed)는 주의 대상.
        AlimtalkLog::create(['template_code' => 'erp_daily_summary', 'phone' => '01000000000', 'status' => 'failed', 'error' => 'boom']);

        $this->assertSame(1, AlimtalkLog::query()->needsAttention()->count());
    }

    // ── 로그 화면 + 확인(acknowledge) ──────────────────────────────────
    public function test_super_can_acknowledge_undelivered(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $log = AlimtalkLog::create(['template_code' => 'erp_daily_summary', 'phone' => '01000000000', 'status' => 'sent', 'msgid' => 'M', 'report_status' => 'undelivered']);

        Volt::actingAs($super)->test('admin.alimtalk-logs.index')
            ->assertOk()
            ->call('acknowledge', $log->id);

        $log->refresh();
        $this->assertNotNull($log->acknowledged_at);
        $this->assertSame($super->id, $log->acknowledged_by);
        $this->assertSame(0, AlimtalkLog::query()->needsAttention()->count());
    }

    public function test_acknowledge_all_clears_attention(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        AlimtalkLog::create(['template_code' => 'a', 'phone' => '010', 'status' => 'failed']);
        AlimtalkLog::create(['template_code' => 'b', 'phone' => '011', 'status' => 'sent', 'msgid' => 'M', 'report_status' => 'undelivered']);

        Volt::actingAs($super)->test('admin.alimtalk-logs.index')
            ->call('acknowledgeAll');

        $this->assertSame(0, AlimtalkLog::query()->needsAttention()->count());
    }

    /** jin 11-12 — 머리줄 버튼은 전체 확인이 아니라 「확인 필요」 드로어를 연다. 드로어 = 확인할 것만 + 사유 + 찾기 + 맨 아래 전부 확인. */
    public function test_attention_drawer_lists_only_unacknowledged_with_reason_and_search(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $v = Vehicle::create(['vehicle_number' => '11노3152', 'sales_channel' => 'export', 'currency' => 'USD', 'purchase_price' => 1, 'buyer_undecided' => true]);
        $dead = AlimtalkLog::create(['template_code' => 'erp_purchase_paid_v3', 'phone' => '01023489777', 'vehicle_id' => $v->id, 'status' => 'sent', 'msgid' => 'M1', 'report_status' => 'undelivered',
            'report_raw' => ['code' => 'success', 'message' => 'M505:착신가입자 없음', 'originMessage' => 'K119:MessageNoUserException']]);
        AlimtalkLog::create(['template_code' => 'erp_vehicle_new', 'phone' => '01082167973', 'status' => 'failed', 'error' => 'bizmsg fail — K101:NotAvailableSendMessage']);
        AlimtalkLog::create(['template_code' => 'erp_vehicle_new', 'phone' => '01000000001', 'status' => 'failed', 'error' => 'x', 'acknowledged_at' => now()]);   // 이미 확인
        AlimtalkLog::create(['template_code' => 'erp_vehicle_new', 'phone' => '01000000002', 'status' => 'sent', 'msgid' => 'M2', 'report_status' => 'delivered']);   // 정상

        $c = Volt::actingAs($super)->test('admin.alimtalk-logs.index')->assertOk()
            ->assertSee('확인 필요 (2)')->assertDontSee('data-attention-drawer', false)
            ->call('openAttention')->assertSee('data-attention-drawer', false);
        $html = $c->html();
        $drawer = substr($html, strpos($html, 'data-attention-drawer'));
        $this->assertSame(2, substr_count($drawer, 'data-attention-row'), '확인 안 한 미도달·실패만');
        $this->assertStringContainsString('11노3152', $drawer);
        $this->assertStringContainsString('K119', $drawer, '사유가 같이 보인다');
        $this->assertStringNotContainsString('01000000001', $drawer, '이미 확인한 것은 없다');
        $this->assertStringNotContainsString('01000000002', $drawer, '정상 전달은 없다');

        $c->set('attentionSearch', '11노')->call('searchAttention');
        $html = $c->html();
        $drawer = substr($html, strpos($html, 'data-attention-drawer'));
        $this->assertSame(1, substr_count($drawer, 'data-attention-row'), '드로어 안 찾기');
        $this->assertStringNotContainsString('01082167973', $drawer);

        $c->call('acknowledge', $dead->id);
        $html = $c->html();
        $this->assertSame(0, substr_count(substr($html, strpos($html, 'data-attention-drawer')), 'data-attention-row'), '확인하면 드로어에서 빠진다');
        $this->assertSame(1, AlimtalkLog::query()->needsAttention()->count());

        $c->set('attentionSearch', '')->call('searchAttention')->call('acknowledgeAll');
        $this->assertSame(0, AlimtalkLog::query()->needsAttention()->count());
        $c->call('closeAttention')->assertDontSee('data-attention-drawer', false);
    }
}
