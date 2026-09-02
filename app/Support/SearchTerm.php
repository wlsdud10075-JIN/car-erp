<?php

namespace App\Support;

/**
 * 검색어 정규화 **단일 출처** (jin 2026-09-02).
 *
 * 사고: 바이어 이메일을 복사해 붙이면 끝에 공백이 딸려와 `LIKE '%…icloud.com %'` 가 되고,
 *      분명히 있는 데이터가 **조용히 0건**으로 나온다. 예외도 안내도 없어서 "데이터가 없나?" 로 읽힌다.
 *
 * 🚫 Livewire 프로퍼티 자체를 trim 하지 말 것 — 검색칸 절반이 `wire:model.live.debounce` 라
 *    두 단어를 칠 때 **사용자가 방금 친 공백을 서버가 지워버린다.** 자르는 곳은 **쿼리에 넣는 지점**이다.
 *
 * 🔧 여기 모아둔 이유 = 나중에 LIKE 와일드카드(`%`·`_`) 이스케이프처럼 전 검색칸에 걸 규칙이
 *    생겼을 때 **한 곳만 고치면 되게**. 지금은 trim 뿐이다(동작 변경 없음).
 */
class SearchTerm
{
    /** 정규화된 검색어. 빈 문자열이면 falsy 라 `when()` 조건으로 그대로 쓸 수 있다. */
    public static function of(?string $raw): string
    {
        return trim((string) $raw);
    }

    /** LIKE 패턴(`%…%`). 호출측은 `of()` 로 빈 검색어를 먼저 걸러낸다. */
    public static function like(?string $raw): string
    {
        return '%'.self::of($raw).'%';
    }
}
