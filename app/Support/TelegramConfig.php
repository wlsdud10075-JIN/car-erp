<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * 시스템 장애 텔레그램 알림 설정 단일 출처 (jin 2026-09-11).
 *
 * 왜 만들었나 — 2026-09-11 네이버 개편으로 환율 스크래핑이 죽었는데 HTTP 는 200 이라
 * 예외도 로그도 없이 null 만 돌아 **이틀간 아무도 몰랐다**(SKILLS §8 #88).
 * 「에러가 났다」가 아니라 **「며칠째 안 돈다」**를 사람에게 알리는 것이 목적이다.
 *
 * 🚫 알림톡(카카오)과 무관하다 — 받는 사람이 다르다. 알림톡 = 직원·바이어·딜러,
 *    이쪽 = **시스템관리자(jin) 개인 DM 1명**. 섞지 말 것.
 *
 * 설정 위치 = 기능설정 화면(super 전용). `.env` 가 아니라 DB(Setting)에 두는 이유 =
 * jin 이 직접 기입하고, **임계값·on/off 를 배포 없이 고치기 위함**(3사 동시배포라 배포가 무겁다).
 * 토큰은 `Crypt` 암호화 — `carmodoo_passwd`·`alimtalk_userkey` 와 같은 패턴.
 *
 * ⚠️ 봇은 **ssancar.com 과 같은 봇을 재사용**한다(jin 2026-09-11 결정). 그쪽 세션이
 *    전용 봇을 권했으나(토큰이 서버 4대로 퍼짐·출처 구분·개별 음소거) jin 이 재확인해 재사용으로 갔다.
 *    ⇒ chat_id 도 같은 jin 개인 DM. 본문에 **회사 라벨을 반드시 넣어** 출처를 가른다.
 *
 * 🔒 **미설정 = 완전 inert.** 토큰이 비면 아무 일도 일어나지 않는다. 이게 없으면
 *    CI·로컬 테스트가 실제로 텔레그램을 친다(`phpunit.xml` 에 관련 env 가 없다).
 */
class TelegramConfig
{
    /** 회사 라벨 — 3사가 같은 DM 으로 보내므로 본문 출처 표기에 쓴다. */
    public const COMPANY_LABELS = ['system' => 'SSANCAR', 'heyman' => 'HEYMAN', 'karaba' => 'KARABA'];

    public function __construct(
        public string $set,
        public ?string $token,
        public string $chatId,
        /** 마스터 스위치 — 끄면 정기·긴급 **전부** 안 나간다. */
        public bool $enabled,
        /** 매일 요약(🟢 포함) on/off — 꺼도 **긴급은 계속 나간다**. */
        public bool $dailySummary,
    ) {}

    public static function active(): self
    {
        $set = Setting::companyTemplateSet();

        $token = null;
        if ($enc = Setting::get('telegram_bot_token')) {
            try {
                $token = Crypt::decryptString($enc);
            } catch (\Throwable $e) {
                // 복호화 실패(APP_KEY 교체 등) = 미설정으로 떨어뜨린다. 잘못된 토큰으로 치는 것보다 낫다.
                $token = null;
            }
        }

        return new self(
            set: $set,
            token: $token,
            chatId: (string) (Setting::get('telegram_chat_id', '') ?: ''),
            enabled: (bool) Setting::get('telegram_enabled', false),
            dailySummary: (bool) Setting::get('telegram_daily_summary', true),
        );
    }

    /** 토큰·chat_id 가 둘 다 있는가. 마스터 스위치와 무관 — 「설정은 됐다」만 본다. */
    public function isConfigured(): bool
    {
        return $this->token !== null && $this->token !== '' && $this->chatId !== '';
    }

    /** 실제로 보낼 수 있는가 = 마스터 on + 설정 완료. */
    public function canSend(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    /** 매일 요약을 보낼 수 있는가 — 마스터에 더해 요약 토글까지. */
    public function canSendDailySummary(): bool
    {
        return $this->canSend() && $this->dailySummary;
    }

    public function companyLabel(): string
    {
        return self::COMPANY_LABELS[$this->set] ?? strtoupper($this->set);
    }
}
