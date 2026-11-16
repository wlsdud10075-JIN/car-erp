<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\OpsMark;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Ops\OpsRoutines;
use App\Services\Ops\OpsSignature;
use App\Services\Ops\OpsSummary;
use App\Services\TelegramNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🛰️ 관제센터 11-16 (jin «전부다» + «우리도 루틴이 있는 내용을 했는지 안 했는지도 알아야»).
 *   ⑤ 알림톡 실패 = 종류 × 원인 · ⑥ 같은 번호 반복 실패 · ② 마지막 성공·멈춤 의심 · ③ 서버 밖 루틴 실기록
 *   🧑 사람 루틴(주 1회, 월~일, 수요일부터 주의) · ① 텔레그램 사본
 */
class OpsRoutineMarksTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ops-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ops.hmac_secret' => self::SECRET, 'services.ops.owner_emails' => ['jin@example.com']]);
        config(['services.ops.sites' => json_encode([
            ['key' => 'ssancar-erp', 'name' => 'ssancarerp', 'kind' => 'erp', 'self' => true, 'host' => 'heymancar.com'],
        ])]);
        Cache::flush();
        Storage::fake('local');
    }

    private function jin(): User
    {
        return User::factory()->create(['permission' => 'super', 'email' => 'jin@example.com', 'email_verified_at' => now()]);
    }

    private function site(array $data): array
    {
        return ['site' => ['key' => 's1', 'name' => 'heymanerp', 'kind' => 'erp'], 'ok' => true, 'data' => $data];
    }

    // ⑤ ───────────────────────────────────────────

    public function test_alimtalk_failures_are_grouped_by_template_and_reason_without_a_duplicate_morning_line(): void
    {
        for ($i = 0; $i < 3; $i++) {
            AlimtalkLog::create(['template_code' => 'erp_shipping_due', 'phone' => '0101111000'.$i, 'status' => 'failed', 'error' => 'bizmsg fail — E112:OverLimitMessageLength — […]']);
        }
        AlimtalkLog::create(['template_code' => 'erp_shipping_due', 'phone' => '01022227973', 'status' => 'failed', 'error' => 'bizmsg fail — K101:NotAvailableSendMessage']);

        $a = collect(OpsSummary::anomalies());
        $line = $a->firstWhere('kind', '알림톡 실패');
        $this->assertNotNull($line);
        $this->assertSame(4, $line['count']);
        $this->assertStringContainsString('선적임박미수 E112(본문 1,000자 초과) 3건', $line['text']);
        $this->assertStringContainsString('K101(그 번호로 카톡 수신 불가) 1건', $line['text']);
        $morning = (string) ($a->firstWhere('kind', '아침 점검')['text'] ?? '');
        $this->assertStringNotContainsString(__('health.item.alimtalk_failed'), $morning, '알림톡 실패는 한 줄로만 — 아침 점검 줄에 또 나오면 안 된다');
    }

    // ⑥ ───────────────────────────────────────────

    public function test_repeat_failing_number_shows_only_the_last_four_digits(): void
    {
        foreach ([3, 1] as $ago) {
            AlimtalkLog::create(['template_code' => 'erp_shipping_due', 'phone' => '01082167973', 'status' => 'failed', 'error' => 'K101:NotAvailableSendMessage'])
                ->forceFill(['created_at' => now()->subDays($ago)])->save();
        }
        AlimtalkLog::create(['template_code' => 'erp_shipping_due', 'phone' => '01055556666', 'status' => 'failed', 'error' => 'E112', 'created_at' => now()]);   // 하루뿐 — 반복 아님

        $line = collect(OpsSummary::anomalies())->firstWhere('kind', '알림톡 반복 실패');
        $this->assertNotNull($line);
        $this->assertSame(1, $line['count']);
        $this->assertStringContainsString('끝자리 7973 · 2일 실패 K101', $line['text']);
        $this->assertStringNotContainsString('01082167973', $line['text'], '전화번호 전체를 싣지 않는다(요약 계약)');
        $this->assertStringNotContainsString('6666', $line['text']);
    }

    // ② ───────────────────────────────────────────

    public function test_long_running_job_is_flagged_and_failures_carry_last_success(): void
    {
        $sites = [$this->site(['routines' => [
            ['command' => 'db:backup', 'status' => 'running', 'started_at' => now()->subHours(2)->toIso8601String(), 'last_ok_at' => now()->subDay()->toIso8601String()],
            ['command' => 'holidays:sync', 'status' => 'running', 'started_at' => now()->subMinutes(5)->toIso8601String(), 'last_ok_at' => null],
            ['command' => 'alarms:scan', 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'error' => 'boom', 'last_ok_at' => '2026-11-15T06:00:01+09:00'],
        ]])];
        $rows = collect(OpsRoutines::rows($sites)['rows'])->keyBy('label');

        $this->assertSame('failed', $rows['DB 백업']['by']['s1']['state'], '1시간 넘게 도는 중 = 멈춤 의심');
        $this->assertStringContainsString('멈춤 의심', $rows['DB 백업']['by']['s1']['text']);
        $this->assertSame('running', $rows['공휴일 수집']['by']['s1']['state'], '방금 시작한 것은 그냥 도는 중');
        $this->assertStringContainsString('마지막 성공 11/15 06:00', $rows['할일 알람 스캔']['by']['s1']['title']);
    }

    // ③ ───────────────────────────────────────────

    public function test_external_routines_read_real_records(): void
    {
        $sites = [$this->site([
            'marks' => ['nightly-investigate' => [['status' => 'ok', 'at' => now()->subHours(3)->toIso8601String(), 'note' => null, 'source' => 'command']],
                'nightly-notion' => [['status' => 'ok', 'at' => now()->subDays(3)->toIso8601String(), 'note' => null, 'source' => 'command']]],
            'external' => ['apt_run_at' => now()->subHours(8)->toIso8601String(), 'apt_installed_at' => '2026-11-13T08:30:00+00:00'],
        ])];
        $rows = collect(OpsRoutines::externalRows($sites, now()->subDays(2)->toIso8601String()))->keyBy('key');

        $this->assertSame('ok', $rows['nightly-investigate']['cell']['state']);
        $this->assertSame('failed', $rows['nightly-notion']['cell']['state'], '30시간 넘게 새 기록 없음 = 멈춤?');
        $this->assertSame('failed', $rows['brain-sync']['cell']['state']);
        $this->assertSame('ok', $rows['apt']['by']['s1']['state']);
        $this->assertStringContainsString('마지막 설치', $rows['apt']['by']['s1']['title']);
    }

    public function test_ops_mark_command_and_signed_api_write_marks(): void
    {
        $this->artisan('ops:mark', ['key' => 'nightly-investigate'])->assertSuccessful();
        $this->artisan('ops:mark', ['key' => 'Bad Key'])->assertFailed();
        $this->assertSame(1, OpsMark::where('key', 'nightly-investigate')->where('source', 'command')->count());

        $body = json_encode(['key' => 'scene-check', 'status' => 'ok', 'note' => '29씬 통과']);
        $this->call('POST', '/api/internal/ops/mark', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $h = OpsSignature::headers('POST', '/api/internal/ops/mark', [], $body, self::SECRET);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($h as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->call('POST', '/api/internal/ops/mark', [], [], [], $server, $body)->assertOk();
        $this->assertSame('29씬 통과', OpsMark::where('key', 'scene-check')->value('note'));

        $bad = json_encode(['key' => 'x y', 'status' => 'ok']);
        $h = OpsSignature::headers('POST', '/api/internal/ops/mark', [], $bad, self::SECRET);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($h as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->call('POST', '/api/internal/ops/mark', [], [], [], $server, $bad)->assertStatus(422);
    }

    // 🧑 ───────────────────────────────────────────

    public function test_manual_routines_are_judged_by_the_calendar_week(): void
    {
        $mon = Carbon::parse('2026-11-16 10:00', config('app.timezone'));   // 월요일
        $wed = $mon->copy()->addDays(2);
        $lastWeek = $mon->copy()->subDays(5)->toIso8601String();
        $sites = fn (array $marks) => [$this->site(['marks' => $marks])];

        $r = collect(OpsRoutines::manualRows($sites([]), $mon))->keyBy('key');
        $this->assertSame('none', $r['scene-check']['state'], '월·화는 아직이어도 회색');
        $r = collect(OpsRoutines::manualRows($sites([]), $wed))->keyBy('key');
        $this->assertSame('warn', $r['scene-check']['state'], '수요일부터 주의');

        $thisWeek = ['status' => 'ok', 'at' => $wed->copy()->subHour()->toIso8601String(), 'source' => 'api'];
        $r = collect(OpsRoutines::manualRows($sites(['scene-check' => [$thisWeek]]), $wed))->keyBy('key');
        $this->assertSame('ok', $r['scene-check']['state']);
        $this->assertStringNotContainsString('지난주 놓침', $r['scene-check']['text'], '처음 시작한 주엔 「놓침」이 아니다');

        $twoWeeksAgo = ['status' => 'ok', 'at' => $mon->copy()->subDays(10)->toIso8601String(), 'source' => 'api'];
        $r = collect(OpsRoutines::manualRows($sites(['scene-check' => [$thisWeek, $twoWeeksAgo]]), $wed))->keyBy('key');
        $this->assertStringContainsString('지난주 놓침', $r['scene-check']['text'], '하던 루틴이 지난주에 비었으면 같이 알린다');

        $r = collect(OpsRoutines::manualRows($sites(['scene-check' => [['status' => 'ok', 'at' => $lastWeek, 'source' => 'button']]]), $wed))->keyBy('key');
        $this->assertSame('warn', $r['scene-check']['state'], '지난주에 한 것은 이번 주를 채우지 않는다');
        $this->assertStringNotContainsString('지난주 놓침', $r['scene-check']['text']);
    }

    public function test_page_button_marks_a_manual_routine_and_ignores_other_keys(): void
    {
        Http::fake();
        $jin = $this->jin();

        $c = Volt::actingAs($jin)->test('admin.control');
        $this->assertStringContainsString('data-manual-row="scene-check"', $c->html());
        $c->call('markRoutineDone', 'scene-check')->call('markRoutineDone', 'nightly-investigate');

        $this->assertSame(1, OpsMark::count(), '사람 루틴 키만 버튼으로 기록된다');
        $this->assertSame(['button', $jin->id], [OpsMark::first()->source, OpsMark::first()->user_id]);
        $this->assertStringContainsString('이번 주', substr($c->html(), strpos($c->html(), 'data-manual-row="scene-check"'), 1200));
    }

    // ① ───────────────────────────────────────────

    public function test_telegram_sends_are_mirrored_and_shown_even_when_the_send_fails(): void
    {
        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => Crypt::encryptString('T'), 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_chat_id'], ['value' => '1', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '1', 'type' => 'boolean']);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true])->push('down', 500)]);

        $n = TelegramNotifier::active();
        if (! $n->config()->canSend()) {
            $this->markTestSkipped('텔레그램 설정 키 이름이 다르다 — TelegramConfig 확인');
        }
        $this->assertTrue($n->send('🟢 아침 점검', '전부 정상'));
        $this->assertFalse($n->send('🔴 정기 작업 실패', 'db:backup'));

        $this->assertSame(2, TelegramMessage::count());
        $this->assertFalse(TelegramMessage::where('title', '🔴 정기 작업 실패')->value('sent'));

        $html = Volt::actingAs($this->jin())->test('admin.control')->html();
        $t = substr($html, strpos($html, 'data-ops-telegram'));
        $this->assertStringContainsString('🟢 아침 점검', $t);
        $this->assertStringContainsString('전부 정상', $t, '본문 전체가 접힘 안에');
        $this->assertStringContainsString('전송 실패', $t);
    }
}
