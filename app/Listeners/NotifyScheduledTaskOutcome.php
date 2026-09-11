<?php

namespace App\Listeners;

use App\Models\Setting;
use App\Services\TelegramNotifier;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Log;

/**
 * 정기 작업이 **실패했을 때** 시스템관리자에게 즉시 텔레그램 (jin 2026-09-11, 3단계).
 *
 * 🔑 2단계(아침 요약)와 축이 다르다 — 저쪽은 「며칠째 안 도는가」, 이쪽은 「방금 터졌나」다.
 *    Laravel 이 `ScheduledTaskFailed` 를 **이미 발행하고 있는데 듣는 사람이 0명**이었다.
 *    리스너 하나로 등록된 정기 작업 전부를 코드 수정 없이 덮는다.
 *
 * 🚨 **`ScheduledTaskFinished` 는 exit code 검사 「전」에 발행된다** (실측:
 *    `ScheduleRunCommand::runEvent` — dispatch(Finished) → `if (exitCode != 0) throw` → catch → dispatch(Failed)).
 *    ⇒ Finished 를 그냥 「성공」으로 읽으면 **실패할 때마다 🟢복구 + 🔴실패 두 통**이 가고,
 *      매번 거짓 복구 알림이 된다. 반드시 `exitCode === 0` 을 직접 확인할 것.
 *
 * 🔕 **상태 전이에만 보낸다** — 같은 작업이 계속 실패하면 첫 통만 보내고 침묵한다.
 *    2주 멈춘 것이 2주 내내 매일 울리면 사람은 사흘째부터 안 읽는다(ssancar 운영 원칙).
 *    계속 걸려 있는 건 2단계 아침 요약이 대신 말한다. 복구되면 🟢 한 통 — 그게 있어야
 *    「아직 그대로인지」를 사람이 안 궁금해한다.
 *
 * ⚠️ **이 리스너는 절대 예외를 밖으로 내면 안 된다.** `onFailed` 는 스케줄러의 catch 블록
 *    **안에서** 호출된다 — 여기서 던지면 그 예외가 스케줄러 실행 자체를 깬다.
 */
class NotifyScheduledTaskOutcome
{
    /**
     * 상시 오탐 작업 — 여기 적힌 것은 실패해도 안 알린다.
     *
     * 🚫 **비어 있다고 지우지 말 것.** 「구조적으로 매번 실패하는」 작업이 생기면 여기 적는다.
     *    오탐을 참으면 사람이 알림 전체를 무시하게 되고, 그러면 감시가 있다고 믿으면서
     *    실제로는 없는 상태가 된다 — 없는 것보다 나쁘다.
     *
     * @var array<int, string>
     */
    public const IGNORED = [];

    public function onFailed(ScheduledTaskFailed $event): void
    {
        $this->safely(function () use ($event) {
            $name = $this->name($event->task->command ?? null, $event->task->getSummaryForDisplay());
            if (in_array($name, self::IGNORED, true)) {
                return;
            }
            if ($this->state($name) === 'fail') {
                return;   // 이상 → 이상 : 침묵
            }

            $this->setState($name, 'fail');
            TelegramNotifier::active()->send(
                '🔴 '.__('health.job_failed'),
                $name."\n".$this->reason($event),
            );
        });
    }

    public function onFinished(ScheduledTaskFinished $event): void
    {
        $this->safely(function () use ($event) {
            // 🚨 위 docblock 참조 — Finished 는 실패한 작업에서도 먼저 발행된다.
            if ((int) ($event->task->exitCode ?? 0) !== 0) {
                return;
            }

            $name = $this->name($event->task->command ?? null, $event->task->getSummaryForDisplay());
            if ($this->state($name) !== 'fail') {
                return;   // 정상 → 정상 : 아무것도 안 한다(DB 쓰기도 없다)
            }

            $this->setState($name, 'ok');
            TelegramNotifier::active()->send('🟢 '.__('health.job_recovered'), $name);
        });
    }

    /** `'php' artisan alimtalk:pickup` 같은 전체 명령줄에서 artisan 커맨드 이름만 뽑는다. */
    private function name(?string $command, string $fallback): string
    {
        if ($command !== null && preg_match('/artisan[\'"]?\s+([a-z0-9:_\-]+)/i', $command, $m)) {
            return $m[1];
        }

        return mb_substr(trim($command ?: $fallback), 0, 80);
    }

    private function reason(ScheduledTaskFailed $event): string
    {
        $msg = trim($event->exception?->getMessage() ?? '');

        return $msg === '' ? __('health.job_no_reason') : mb_substr($msg, 0, 400);
    }

    private function stateKey(string $name): string
    {
        // 커맨드 이름을 그대로 키에 쓰면 콜론이 섞인다 — 설정 키 관례에 맞게 눌러 쓴다.
        return 'job_state_'.preg_replace('/[^a-z0-9]+/i', '_', $name);
    }

    private function state(string $name): ?string
    {
        $v = Setting::get($this->stateKey($name));

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function setState(string $name, string $state): void
    {
        Setting::updateOrCreate(
            ['key' => $this->stateKey($name)],
            ['value' => $state, 'type' => 'string', 'description' => "정기 작업 상태 ({$name})"],
        );
    }

    /** 알림 때문에 스케줄러가 깨지면 본말전도다. 무슨 일이 있어도 삼킨다. */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('scheduled task alert failed', ['error' => $e->getMessage()]);
        }
    }
}
