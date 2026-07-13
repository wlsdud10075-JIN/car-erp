<?php

namespace App\Console\Commands;

use App\Models\AlimtalkLog;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkConfig;
use Illuminate\Console\Command;

/**
 * BizM 전송결과 폴링 — 발송된 알림톡(status=sent, msgid 보유)의 실제 도달/미도달을 조회해 기록한다.
 *   BizM 은 결과 push 웹훅이 없어 GET /v2/sender/report 폴링이 유일한 방법(msgid 발급 후 90일 유효).
 *   미확정(report 미준비·조회실패)은 report_status=null 로 두어 다음 실행에 재조회된다.
 *   게이트 미설정 시 내부 skip(배포 ≠ 작동).
 */
class AlimtalkPollReport extends Command
{
    protected $signature = 'alimtalk:poll-report {--days=85 : 조회 대상 최근 일수(BizM 90일 제한 여유)}';

    protected $description = 'BizM 전송결과 조회 — 발송된 알림톡(msgid)의 실제 도달/미도달을 폴링해 기록';

    public function handle(): int
    {
        $config = AlimtalkConfig::active();
        if (! $config->isConfigured()) {
            $this->info('alimtalk not configured — skip report poll');

            return self::SUCCESS;
        }

        $service = new BizmAlimtalkService($config);
        $days = max(1, (int) $this->option('days'));
        $delivered = $undelivered = $pending = 0;

        AlimtalkLog::query()
            ->where('status', 'sent')
            ->whereNotNull('msgid')
            ->whereNull('report_status')
            ->where('created_at', '>=', now()->subDays($days))
            ->chunkById(200, function ($logs) use ($service, &$delivered, &$undelivered, &$pending) {
                foreach ($logs as $log) {
                    $body = $service->fetchReport((string) $log->msgid);
                    $status = AlimtalkLog::classifyReport($body);

                    $log->report_checked_at = now();
                    $log->report_raw = $body;
                    $log->report_status = $status;   // null = 미확정 → 다음 실행에 재조회
                    $log->save();

                    match ($status) {
                        'delivered' => $delivered++,
                        'undelivered' => $undelivered++,
                        default => $pending++,
                    };
                }
            });

        $this->info("report poll done — delivered={$delivered} undelivered={$undelivered} pending={$pending}");

        return self::SUCCESS;
    }
}
