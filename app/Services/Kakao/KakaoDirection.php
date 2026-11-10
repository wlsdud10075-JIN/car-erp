<?php

namespace App\Services\Kakao;

/**
 * 한 메시지가 「보는 사람」에게 어느 방향인가 — 분류(방 단위 1회)는 그대로 두고 사람마다 계산한다.
 * (docs/design/kakao-automation.md §1 #15 · §10-1. 표본 2 에서 김다정·박수정 재채점으로 실증.)
 *
 *   내일   = 상대가 내 편(또는 @나)에게 한 요청·질문          → 「지금 할 일 / 오늘 안에」
 *   기다림 = 내가 보낸 요청·질문                              → 「답 기다리는 중」
 *   동료   = 같은 편 동료가 보낸 요청·질문                    → 「팀 동료 진행 중」(접힘, jin 11-10 추천대로)
 *   무관   = 나머지(첨부·잡담·대답·정보, 남들끼리의 요청)
 *
 * 규칙은 스크래치 score2.py 의 direction() 과 같다(픽스처 tests/Fixtures/kakao/prom_room_directions.json 으로 고정).
 */
class KakaoDirection
{
    public const MINE = '내일';

    public const WAITING = '기다림';

    public const TEAMMATE = '동료';

    public const NONE = '무관';

    private const ACTIONABLE = ['요청', '질문'];

    /**
     * @param  array{type?: ?string, to?: ?string, mention?: ?string}  $judgment  분류 결과
     * @param  array<string, array<int, string>>  $sides  편 이름 → 그 편 사람들(카톡 표시명)
     * @param  string  $me  보는 사람의 그 방 표시명
     */
    public static function of(string $sender, array $judgment, array $sides, string $me): string
    {
        $type = $judgment['type'] ?? null;
        $to = $judgment['to'] ?? '없음';
        $mention = $judgment['mention'] ?? null;

        if ($type === null || in_array($type, ['첨부', '잡담'], true)) {
            return self::NONE;
        }

        $actionable = in_array($type, self::ACTIONABLE, true);

        if ($sender === $me) {
            return $actionable ? self::WAITING : self::NONE;
        }

        $mySide = self::sideOf($me, $sides);

        if ($actionable) {
            $mentionsMe = self::mentions($mention, $me);
            if ($mentionsMe) {
                return self::MINE;
            }
            if ($mySide !== null && $to === $mySide) {
                // 내 편에게 왔지만 다른 사람을 콕 집었으면 그 사람 일
                return $mention ? self::NONE : self::MINE;
            }
            if ($mySide !== null && self::sideOf($sender, $sides) === $mySide) {
                return self::TEAMMATE;
            }
        }

        return self::NONE;
    }

    public static function sideOf(string $name, array $sides): ?string
    {
        foreach ($sides as $side => $members) {
            foreach ((array) $members as $member) {
                if ($member === $name) {
                    return $side;
                }
            }
        }

        return null;
    }

    /** 「@싼카-수출팀 박수정」처럼 표시명 일부만 적혀도 맞춘다(어느 쪽이 다른 쪽을 포함하면 같은 사람). */
    public static function mentions(?string $mention, string $me): bool
    {
        if ($mention === null || trim($mention) === '') {
            return false;
        }
        foreach (explode(',', $mention) as $token) {
            $t = trim($token);
            if ($t === '') {
                continue;
            }
            if (str_contains($me, $t) || str_contains($t, $me)) {
                return true;
            }
        }

        return false;
    }
}
