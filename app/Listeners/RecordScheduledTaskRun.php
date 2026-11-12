<?php

namespace App\Listeners;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Support\Facades\Log;

/**
 * 예약 작업 실행 기록 (jin 2026-11-12, 관제센터 「루틴」 칸 1단계) — 시작에 한 줄, 끝에 그 줄을 채운다.
 *
 * 🚨 `ScheduledTaskFinished` 는 exit code 검사 「전」에 발행된다(§8 #90) — 실패한 작업도 Finished 를 먼저 낸다.
 *    그래서 Finished 에서 exitCode 로 ok/failed 를 정하고, 뒤이어 오는 Failed 가 같은 줄에 이유를 덧붙인다.
 * 🔗 시작 줄과 끝을 잇는 키 = 스케줄 이벤트 객체(spl_object_id). schedule:run 한 프로세스 안에서 차례로 돈다.
 * ⚠️ 텔레그램 알림(NotifyScheduledTaskOutcome)과 따로 둔다 — 그쪽은 상태 전이에만 보내고, 여기는 매번 남긴다.
 * ⚠️ 절대 예외를 밖으로 내지 않는다 — 기록 때문에 스케줄러가 깨지면 본말전도.
 */
class RecordScheduledTaskRun
{
    /** @var array<int, int> spl_object_id(이벤트) => 기록 id */
    private static array $open = [];

    public function onStarting(ScheduledTaskStarting $event): void
    {
        $this->safely(function () use ($event) {
            $run = ScheduledTaskRun::create([
                'command' => self::name($event->task),
                'expression' => mb_substr((string) $event->task->expression, 0, 60),
                'status' => 'running',
                'started_at' => now(),
            ]);
            self::$open[spl_object_id($event->task)] = $run->id;
        });
    }

    public function onFinished(ScheduledTaskFinished $event): void
    {
        $this->safely(function () use ($event) {
            $run = $this->runFor($event->task);
            if (! $run) {
                return;
            }
            $code = (int) ($event->task->exitCode ?? 0);
            $run->update([
                'status' => $code === 0 ? 'ok' : 'failed',
                'exit_code' => $code,
                'finished_at' => now(),
                'duration_ms' => (int) round($event->runtime * 1000),
            ]);
            if ($code === 0) {
                unset(self::$open[spl_object_id($event->task)]);   // 실패면 남겨 둔다 — 곧 Failed 가 이유를 붙인다
            }
        });
    }

    public function onFailed(ScheduledTaskFailed $event): void
    {
        $this->safely(function () use ($event) {
            $run = $this->runFor($event->task);
            $msg = mb_substr(trim($event->exception->getMessage()), 0, 1000);
            if ($run) {
                $run->update(['status' => 'failed', 'error' => $msg ?: null, 'finished_at' => $run->finished_at ?? now()]);
            } else {   // 시작 줄이 없다(시작 기록이 실패했던 경우) — 실패만이라도 남긴다
                ScheduledTaskRun::create(['command' => self::name($event->task), 'expression' => mb_substr((string) $event->task->expression, 0, 60),
                    'status' => 'failed', 'error' => $msg ?: null, 'started_at' => now(), 'finished_at' => now()]);
            }
            unset(self::$open[spl_object_id($event->task)]);
        });
    }

    private function runFor(ScheduleEvent $task): ?ScheduledTaskRun
    {
        $id = self::$open[spl_object_id($task)] ?? null;

        return $id ? ScheduledTaskRun::find($id) : null;
    }

    /** `'php' artisan alimtalk:pickup` → `alimtalk:pickup` (텔레그램 알림과 같은 규칙) */
    public static function name(ScheduleEvent $task): string
    {
        $command = $task->command ?? null;
        if ($command !== null && preg_match('/artisan[\'"]?\s+([a-z0-9:_\-]+)/i', $command, $m)) {
            return $m[1];
        }

        return mb_substr(trim($command ?: $task->getSummaryForDisplay()), 0, 120);
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('scheduled task run record failed', ['error' => $e->getMessage()]);
        }
    }
}
