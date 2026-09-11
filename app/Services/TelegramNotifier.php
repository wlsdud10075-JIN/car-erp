<?php

namespace App\Services;

use App\Support\TelegramConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 시스템 장애 텔레그램 발송 (jin 2026-09-11). 설정 = App\Support\TelegramConfig.
 *
 * 🔴 **fail-open — 발송 실패가 호출자를 절대 안 죽인다.** ssancar.com 이 같은 구조를 2년째
 *    운영하며 얻은 원칙이다(그쪽 주석 원문: *"본체 절대 안 죽임"*). 감시 알림이 감시 대상
 *    작업을 죽이면 본말전도다 — 예컨대 배치 실패 알림이 다음 배치를 죽이면 안 된다.
 *    ⇒ 이 클래스는 **예외를 밖으로 안 던진다.** 실패는 `false` 로만 알린다.
 *
 * 🚫 큐를 쓰지 않는다 — 이 레포에 `ShouldQueue` 구현이 0건이고 워커가 없다. 동기 발송이라
 *    타임아웃을 짧게(10초) 둔다. 알림 한 통 때문에 cron 이 늘어지면 안 된다.
 *
 * ⚠️ **3사가 같은 chat_id 로 보낸다**(봇 재사용, jin 결정) — 본문 첫 줄에 회사 라벨이
 *    없으면 어느 회사 장애인지 모른다. 그래서 `send()` 가 **강제로 머리글을 붙인다**.
 *    호출자가 빼먹을 수 있는 형태로 두지 않는다.
 */
class TelegramNotifier
{
    private const API = 'https://api.telegram.org';

    /** 텔레그램 메시지 상한 4096자. 잘릴 바엔 우리가 자르고 잘렸다고 알린다. */
    private const MAX_LEN = 3900;

    public function __construct(private TelegramConfig $config) {}

    public static function active(): self
    {
        return new self(TelegramConfig::active());
    }

    public function config(): TelegramConfig
    {
        return $this->config;
    }

    /**
     * 본문 발송. 머리글(회사·시각)은 여기서 붙인다 — 호출자는 내용만 준다.
     *
     * @param  string  $title  한 줄 제목(알림 미리보기에 뜨는 부분)
     * @param  string  $body  여러 줄 본문
     * @param  bool  $force  마스터 스위치를 우회한다 — **테스트 발송 버튼 전용**.
     *                       설정을 막 입력한 사람이 도착을 확인해야 하므로.
     */
    public function send(string $title, string $body = '', bool $force = false): bool
    {
        if ($force ? ! $this->config->isConfigured() : ! $this->config->canSend()) {
            return false;
        }

        $text = '['.$this->config->companyLabel().'] '.$title
            ."\n".now()->format('Y-m-d H:i')
            .($body !== '' ? "\n\n".$body : '');

        if (mb_strlen($text) > self::MAX_LEN) {
            $text = mb_substr($text, 0, self::MAX_LEN)."\n… (이하 생략)";
        }

        try {
            $res = Http::timeout(10)->asForm()->post(
                self::API.'/bot'.$this->config->token.'/sendMessage',
                ['chat_id' => $this->config->chatId, 'text' => $text],
            );

            if (! $res->successful()) {
                // 🚫 토큰이 URL 에 있으므로 응답 본문만 남기고 URL 은 남기지 않는다.
                Log::warning('telegram send failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('telegram send error', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
