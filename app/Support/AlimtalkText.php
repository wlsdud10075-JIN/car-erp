<?php

namespace App\Support;

/**
 * 알림톡 목록 변수 안에 넣는 짧은 표기 — 폰(390px) 카톡 말풍선은 한 줄이 한글 17자 안팎이라
 * 원 단위 8자리·긴 바이어명·긴 날짜를 그대로 넣으면 한 줄이 3~4줄로 접힌다 (jin 2026-10-27).
 *
 * 🚫 승인된 고정 문장(제목·앞뒤 줄)에는 쓰지 않는다 — 글자 하나만 달라도 발송이 반려된다(§8 #40).
 *    여기 표기는 `#{…목록}` 변수 안쪽 전용이다.
 */
final class AlimtalkText
{
    /** 바이어명 — 앞 두 단어까지, 그래도 길면 자른다. 「ALBANIA MOTORS SH.P.K」 → 「ALBANIA MOTORS」. */
    public static function buyerShort(?string $name, int $max = 16): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '-';
        }
        $words = preg_split('/\s+/u', $name) ?: [$name];
        $short = implode(' ', array_slice($words, 0, 2));

        return mb_strlen($short) > $max ? mb_substr($short, 0, $max) : $short;
    }

    /** 원화를 만 단위로 — 12,340,000 → 「1,234만」, 9,999 → 「1만」 미만은 「0.9만」 대신 「1만」 (반올림). */
    public static function man(int|float $krw): string
    {
        $man = (int) round($krw / 10_000);

        return number_format($man).'만';
    }
}
