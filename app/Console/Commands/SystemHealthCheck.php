<?php

namespace App\Console\Commands;

use App\Services\SystemHealthReport;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * 매일 아침 점검 요약을 시스템관리자에게 텔레그램으로 1통 (jin 2026-09-11, 08:00).
 *
 * 🟢 **이상이 없어도 보낸다** (jin 결정). 안 보내면 조용한 날이 「정상」인지 「감시가 죽은 것」인지
 *    구분할 수 없다 — 2026-09-11 환율 사고가 정확히 그 상태였다(SKILLS §8 #88).
 *    ssancar.com 은 반대로 「보낼 게 없으면 안 보낸다」인데, 그쪽은 cron 이 통째로 멈추면
 *    역시 침묵한다는 구멍이 있다. 하루 1통은 그 구멍을 메우는 dead-man's switch 다.
 *    시끄러우면 기능설정에서 「매일 아침 점검 요약 받기」만 끄면 된다(긴급은 계속 온다).
 *
 * 🚫 회사별로 따로 보낸다(3사 = 3통). 한 대가 나머지를 모아 보내면 **그 한 대가 죽을 때
 *    알림이 통째로 사라진다.** 따로 보내면 **안 온 회사가 그 자체로 신호**가 된다.
 *
 * ⚠️ 발송 실패는 흡수한다 — 알림 한 통 때문에 스케줄러가 실패로 끝나면 안 된다.
 */
class SystemHealthCheck extends Command
{
    protected $signature = 'system:health-check {--dry : 발송하지 않고 본문만 출력}';

    protected $description = '시스템 점검 요약을 텔레그램으로 발송 (매일 08:00)';

    public function handle(SystemHealthReport $report): int
    {
        // 🇰🇷 **받는 사람이 한 명이고 한국어를 쓴다** — 서버 로케일에 맡기지 않는다.
        //    3사 `.env` 의 `APP_LOCALE` 이 제각각일 수 있고(로컬 실측 `en`), 그러면 jin 이
        //    영어 알림을 받는다. 「설정에 뭐라고 적혀 있나」가 아니라 「실제로 뭐가 나가나」로
        //    맞추는 자리다(SKILLS §8 #74). en 번역은 키 대조(LocaleKeyParityTest)용으로만 둔다.
        app()->setLocale('ko');

        $rows = $report->rows();
        $bad = array_values(array_filter($rows, fn ($r) => ! $r['ok']));

        $title = ($bad === [] ? '🟢 ' : '🔴 ').__('health.title');
        $body = $this->body($rows, $bad);

        if ($this->option('dry')) {
            $this->line($title);
            $this->line($body);

            return self::SUCCESS;
        }

        $notifier = TelegramNotifier::active();
        if (! $notifier->config()->canSendDailySummary()) {
            $this->info('텔레그램 요약이 꺼져 있거나 미설정 — skip');

            return self::SUCCESS;
        }

        $notifier->send($title, $body);

        return self::SUCCESS;
    }

    /**
     * O/X 표 + 이상 항목만 아래에 풀어 쓴다.
     * 표는 monospace 정렬 — 텔레그램은 등폭 코드블록을 지원하지만 평문으로도 읽히게 짧게 유지한다.
     */
    private function body(array $rows, array $bad): string
    {
        $lines = [];
        foreach ($rows as $r) {
            $label = __('health.item.'.$r['key']);
            // 🚫 sprintf 자리맞춤을 쓰지 말 것 — PHP 는 바이트로 세서 한글이 어긋난다.
            $lines[] = ($r['ok'] ? 'O' : 'X').'  '.$label.' · '.$r['detail'];
        }

        if ($bad !== []) {
            $lines[] = '';
            foreach ($bad as $r) {
                $lines[] = '❌ '.__('health.item.'.$r['key']).' — '.__('health.why.'.$r['key']);
            }
        }

        return implode("\n", $lines);
    }
}
