<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 정기 작업 실패 즉시 알림 3단계 (jin 2026-09-11).
 *
 * 지키는 것:
 *   ① 실패하면 즉시 1통
 *   ② 🚨 **같은 실패가 반복되면 침묵** — 2주 멈춘 게 매일 울리면 사람이 알림 전체를 무시한다
 *   ③ 복구되면 🟢 1통 — 없으면 「아직 그대로인가」를 사람이 계속 궁금해한다
 *   ④ 🚨 `ScheduledTaskFinished` 는 exit code 검사 **전에** 발행된다 —
 *      성공으로 단정하면 실패할 때마다 거짓 복구 알림이 함께 나간다
 *   ⑤ 리스너가 예외를 밖으로 내면 스케줄러가 깨진다
 */
class ScheduledTaskAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => Crypt::encryptString('123:ABC'), 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_chat_id'], ['value' => '55512345', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    private function task(string $command, int $exitCode = 0): ScheduleEvent
    {
        // EventMutex 는 인터페이스라 컨테이너가 못 만든다 — 구현체를 직접 준다.
        $e = new ScheduleEvent(new CacheEventMutex(app(Factory::class)), "'php' artisan {$command}");
        $e->exitCode = $exitCode;

        return $e;
    }

    private function failTask(string $command, string $reason = '실패 사유'): void
    {
        event(new ScheduledTaskFailed($this->task($command, 1), new \RuntimeException($reason)));
    }

    private function finishTask(string $command, int $exitCode = 0): void
    {
        event(new ScheduledTaskFinished($this->task($command, $exitCode), 1.0));
    }

    // ── ① 실패 = 즉시 1통 ──────────────────────────────────────────────

    public function test_a_failed_job_sends_one_alert_with_its_name_and_reason(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->failTask('alimtalk:pickup', '데이터베이스 연결 실패');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'alimtalk:pickup')
            && str_contains($r['text'], '데이터베이스 연결 실패')
            && str_contains($r['text'], '🔴'));
    }

    // ── ② 반복 실패 = 침묵 ─────────────────────────────────────────────

    public function test_the_same_failure_repeating_stays_silent(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->failTask('db:backup');
        $this->failTask('db:backup');
        $this->failTask('db:backup');

        // 2주 멈춘 게 2주 내내 울리면 사흘째부터 아무도 안 읽는다.
        // 계속 걸려 있는 건 2단계 아침 요약이 대신 말한다.
        Http::assertSentCount(1);
    }

    public function test_a_different_job_failing_is_its_own_alert(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->failTask('db:backup');
        $this->failTask('alarms:scan');

        Http::assertSentCount(2);
    }

    // ── ③ 복구 알림 ────────────────────────────────────────────────────

    public function test_a_recovery_sends_one_green_alert(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->failTask('db:backup');
        $this->finishTask('db:backup', exitCode: 0);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r['text'], '🟢') && str_contains($r['text'], 'db:backup'));
    }

    public function test_a_healthy_job_never_sends_anything(): void
    {
        Http::fake();

        // 정상 → 정상은 아무것도 안 한다(DB 쓰기도 없다). 매분 도는 경로라 조용해야 한다.
        $this->finishTask('alarms:scan', exitCode: 0);
        $this->finishTask('alarms:scan', exitCode: 0);

        Http::assertNothingSent();
    }

    public function test_recovery_is_announced_only_once(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->failTask('db:backup');
        $this->finishTask('db:backup');
        $this->finishTask('db:backup');
        $this->finishTask('db:backup');

        Http::assertSentCount(2);   // 🔴 1 + 🟢 1
    }

    // ── ④ Finished 가 실패에서도 먼저 발행되는 함정 ──────────────────────

    public function test_a_nonzero_exit_on_finished_is_not_a_recovery(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->failTask('db:backup');

        // 실측: ScheduleRunCommand 가 dispatch(Finished) → exitCode 검사 → dispatch(Failed) 순이다.
        // Finished 를 그냥 성공으로 읽으면 실패할 때마다 거짓 「복구」가 함께 나간다.
        $this->finishTask('db:backup', exitCode: 1);

        Http::assertSentCount(1);   // 🔴 하나뿐 — 🟢 가 따라 나오면 안 된다
    }

    public function test_the_real_scheduler_order_sends_exactly_one_alert(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        // 스케줄러가 실제로 내는 순서 그대로 재현한다.
        $this->finishTask('holidays:sync', exitCode: 1);
        $this->failTask('holidays:sync', '수집 실패');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], '🔴'));
    }

    // ── ⑤ 안전 ─────────────────────────────────────────────────────────

    public function test_a_send_failure_does_not_escape_the_listener(): void
    {
        // onFailed 는 스케줄러의 catch 블록 안에서 호출된다 — 여기서 던지면 스케줄러가 깨진다.
        Http::fake(['api.telegram.org/*' => Http::response('nope', 500)]);

        $this->failTask('db:backup');

        $this->assertTrue(true);   // 예외 없이 여기까지 오면 통과
    }

    public function test_nothing_is_sent_when_telegram_is_off(): void
    {
        Http::fake();
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '0', 'type' => 'boolean']);

        $this->failTask('db:backup');

        Http::assertNothingSent();
    }

    public function test_the_daily_summary_toggle_does_not_silence_urgent_alerts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::updateOrCreate(['key' => 'telegram_daily_summary'], ['value' => '0', 'type' => 'boolean']);

        $this->failTask('db:backup');

        // 「평소엔 조용히, 터질 때만」을 고른 사람에게도 이건 가야 한다.
        Http::assertSentCount(1);
    }
}
