<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * 시스템관리자 텔레그램으로 임의 제목·본문 1통 (jin 2026-09-28, 야간 배치 실행기 2단계).
 *
 * 회사 GPU PC 의 야간 조사 스크립트가 결과를 보낼 때 쓴다 — 그 PC 는 이미 가진 서버 SSH 키로
 * `php artisan notify:send` 를 부르기만 하면 되므로 **봇 토큰을 서버 밖으로 복사하지 않는다.**
 *
 * - 본문은 STDIN 으로도 받는다(`--body=-`): 여러 줄·따옴표가 섞인 보고서를 셸 인자로 넘기지 않기 위해.
 * - 마스터 스위치가 꺼져 있거나 미설정이면 조용히 exit 0 — 야간 스크립트가 이것 때문에 실패하면 안 된다.
 * - 4,096자 상한은 TelegramNotifier 가 자른다(잘렸다고 표시).
 */
class NotifySend extends Command
{
    protected $signature = 'notify:send {--title= : 한 줄 제목} {--body= : 본문(- 이면 STDIN)}';

    protected $description = '시스템관리자 텔레그램으로 제목·본문 1통 발송 (야간 배치 실행기 전용)';

    public function handle(): int
    {
        $title = trim((string) $this->option('title'));
        if ($title === '') {
            $this->error('--title 이 비었습니다.');

            return self::FAILURE;
        }

        $body = (string) $this->option('body');
        if ($body === '-') {
            $body = (string) stream_get_contents(STDIN);
        }
        $body = trim($body);

        $notifier = TelegramNotifier::active();
        if (! $notifier->config()->canSend()) {
            $this->info('텔레그램이 꺼져 있거나 미설정 — skip');

            return self::SUCCESS;
        }

        $this->info($notifier->send($title, $body) ? '발송' : '발송 실패(흡수)');

        return self::SUCCESS;
    }
}
