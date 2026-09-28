<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * 배포 실패를 텔레그램으로 1통 (jin 2026-09-28, 야간 배치 실행기 §12-1 A).
 *
 * deploy.yml 의 각 배포 잡이 실패했을 때 `if: failure()` 스텝이 서버에 SSH 로 들어와 이 명령을 부른다.
 * 토큰은 이 서버의 기능설정(DB)에 있으므로 GitHub 에 새 시크릿을 두지 않는다.
 *
 * ⚠️ SSH 자체가 안 되는 실패(호스트 다운)는 이 통로로도 못 알린다 — 그건 GitHub 메일이 남는다.
 * ⚠️ 발송 실패는 흡수한다(exit 0) — 알림 한 통 때문에 실패 스텝이 또 실패하면 로그만 시끄럽다.
 */
class NotifyDeployFailed extends Command
{
    protected $signature = 'notify:deploy-failed {--job= : 실패한 잡 이름(deploy / deploy-ssancar / deploy-karaba)} {--run= : GitHub run id} {--sha= : 배포하려던 커밋}';

    protected $description = '배포 실패를 시스템관리자 텔레그램으로 알린다 (deploy.yml 실패 스텝 전용)';

    public function handle(): int
    {
        app()->setLocale('ko');

        $job = (string) ($this->option('job') ?: '-');
        $run = (string) ($this->option('run') ?: '');
        $sha = substr((string) ($this->option('sha') ?: ''), 0, 7);

        $body = "잡: {$job}"
            .($sha !== '' ? "\n커밋: {$sha}" : '')
            .($run !== '' ? "\n런: https://github.com/wlsdud10075-JIN/car-erp/actions/runs/{$run}" : '')
            ."\n\n".__('health.deploy_failed_why');

        $notifier = TelegramNotifier::active();
        if (! $notifier->config()->canSend()) {
            $this->info('텔레그램이 꺼져 있거나 미설정 — skip');

            return self::SUCCESS;
        }

        $sent = $notifier->send('🔴 '.__('health.deploy_failed'), $body);
        $this->info($sent ? '발송' : '발송 실패(흡수)');

        return self::SUCCESS;
    }
}
