<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 예약 작업 실행 기록 (jin 11-12 관제센터 「루틴」 칸 1단계) — 시작에 한 줄, 끝에 성공/실패.
 * 실패 순서는 실제 스케줄러와 같게: Finished(exitCode≠0) 가 먼저, 그 뒤 Failed(§8 #90).
 */
class ScheduledTaskRunRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(string $command)
    {
        return app(Schedule::class)->command($command)->dailyAt('03:00');
    }

    public function test_a_successful_run_is_one_ok_row(): void
    {
        $t = $this->task('db:backup');
        event(new ScheduledTaskStarting($t));
        $t->exitCode = 0;
        event(new ScheduledTaskFinished($t, 1.234));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('db:backup', $run->command);
        $this->assertSame('ok', $run->status);
        $this->assertSame(1234, $run->duration_ms);
        $this->assertSame('0 3 * * *', $run->expression);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_failed_run_is_never_recorded_as_ok_and_keeps_the_reason(): void
    {
        $t = $this->task('alimtalk:pickup');
        event(new ScheduledTaskStarting($t));
        $t->exitCode = 1;
        event(new ScheduledTaskFinished($t, 0.5));   // 스케줄러는 실패해도 Finished 를 먼저 낸다
        $this->assertSame('failed', ScheduledTaskRun::sole()->status);
        event(new ScheduledTaskFailed($t, new \RuntimeException('BizM 401')));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->exit_code);
        $this->assertSame('BizM 401', $run->error);
    }

    public function test_an_exception_before_finish_is_recorded_too(): void
    {
        $t = $this->task('vehicles:rebuild-caches');
        event(new ScheduledTaskStarting($t));
        event(new ScheduledTaskFailed($t, new \RuntimeException('DB 끊김')));

        $this->assertSame(['failed', 'DB 끊김'], [ScheduledTaskRun::sole()->status, ScheduledTaskRun::sole()->error]);
    }

    public function test_latest_by_command_gives_last_run_and_last_success(): void
    {
        Carbon::setTestNow('2026-11-11 03:00:00');
        $a = $this->task('db:backup');
        event(new ScheduledTaskStarting($a));
        $a->exitCode = 0;
        event(new ScheduledTaskFinished($a, 1));

        Carbon::setTestNow('2026-11-12 03:00:00');
        $b = $this->task('db:backup');
        event(new ScheduledTaskStarting($b));
        event(new ScheduledTaskFailed($b, new \RuntimeException('디스크 가득')));

        $x = ScheduledTaskRun::latestByCommand()['db:backup'];
        $this->assertSame('failed', $x['last']->status);
        $this->assertSame('2026-11-11 03:00:00', $x['last_ok_at']->format('Y-m-d H:i:s'));
    }

    public function test_rows_older_than_90_days_are_pruned(): void
    {
        ScheduledTaskRun::create(['command' => 'old', 'status' => 'ok', 'started_at' => now()->subDays(91)]);
        ScheduledTaskRun::create(['command' => 'new', 'status' => 'ok', 'started_at' => now()->subDays(1)]);

        $this->artisan('model:prune', ['--model' => [ScheduledTaskRun::class]])->assertSuccessful();
        $this->assertSame(['new'], ScheduledTaskRun::pluck('command')->all());
    }

    public function test_the_real_schedule_runner_records_a_run(): void
    {
        // 운영 cron 이 부르는 schedule:run 경로 그대로(schedule:test 는 이벤트를 안 낸다 — 손으로 돌린 건 안 남는다).
        //   10:01 = 등록된 다른 작업이 하나도 안 도는 시각(매시 :00 · 5분마다 :00/:05) → 이 프로세스 안 클로저만 돈다.
        Carbon::setTestNow('2026-11-12 10:01:00');
        app(Schedule::class)->call(fn () => null)->everyMinute()->name('probe-ok');
        app(Schedule::class)->call(fn () => throw new \RuntimeException('일부러 실패'))->everyMinute()->name('probe-fail');

        $this->artisan('schedule:run');

        $this->assertSame('ok', ScheduledTaskRun::where('command', 'probe-ok')->sole()->status);
        $fail = ScheduledTaskRun::where('command', 'probe-fail')->sole();
        $this->assertSame('failed', $fail->status);
        $this->assertSame('일부러 실패', $fail->error);
        $this->assertSame(2, ScheduledTaskRun::count(), '그 시각엔 다른 작업이 안 돌아야 한다(테스트 전제)');
    }
}
