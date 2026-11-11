<?php

namespace App\Services\Kakao;

/**
 * 모델에 보내기 전에 규칙으로 걸러내는 것 — jin 2026-11-11 «분류를 더 잘하려 하기보다 완료·정보·단순 답변부터 빼서 상황을 낫게».
 * 전부 「할 일이 될 수 없는 메시지」만 다룬다. 애매하면 null(모델에 맡긴다). 예외도 로그도 없이 조용히 사라지는 부류라 정규식은 좁게.
 *
 *   잡담  = 구분선(----, ====, ★★)·같은 글자 반복(ㅁㅁㅁㅁ, ㅋㅋㅋ) — 주의 끌기·줄 긋기
 *   호출  = 「@이름」만 있는 것 — 앞뒤의 같은 사람 메시지에 멘션을 붙이고 자신은 할 일에서 빠진다(KakaoIngestService::attachCalls)
 *   반복  = 같은 사람이 몇 분 안에 같은 본문을 또 보낸 것 — 첫 건만 남긴다(ssancarerp 실측 「1480€ / 1320$」 15연발)
 *
 * 🚫 숫자만 있는 메시지(1730 · 4141)는 여기서 다루지 않는다 — 방마다 뜻이 다르다(무사백 방 = 송금 요청(만원) · 평가사 방 = 차량 번호 끝자리).
 * 🚫 「넵·알겠습니다·감사합니다」도 여기서 안 찍는다 — 모델이 그걸 보고 앞 요청에 resolves 를 단다(11-11 실측 ssancarerp 확인 137건 중 34건·heymanerp 88건 중 15건이 요청을 닫았다).
 *    규칙으로 먼저 찍으면 「내가 보낸 요청에 상대가 넵」이 영영 「답 기다리는 중」에 남는다 — 아낄 토큰보다 그 연결이 비싸다.
 */
class KakaoRules
{
    public const TYPE_CHAT = '잡담';

    public const TYPE_CALL = '호출';

    public const TYPE_REPEAT = '반복';

    /** 같은 본문의 반복으로 치는 간격(분) */
    public const REPEAT_MINUTES = 10;

    /**
     * 본문만 보고 정해지는 유형 — 잡담(구분선·글자 반복)뿐. 모르면 null.
     */
    public static function typeOf(string $body): ?string
    {
        $b = trim($body);
        if ($b === '' || KakaoExportParser::isAttachment($b)) {
            return null;
        }
        if (self::isSeparator($b)) {
            return self::TYPE_CHAT;
        }

        return null;
    }

    /** 구분선·같은 글자 반복(4자 이상) — ----, =====, ★★★, ㅁㅁㅁㅁ, ㅋㅋㅋㅋ */
    public static function isSeparator(string $b): bool
    {
        if (preg_match('/^(\D)\1{3,}$/u', $b) === 1) {   // 숫자 반복(1111)은 번호일 수 있어 제외
            return true;
        }

        return preg_match('/^[-=_*~★☆■□●○◆◇▶▷▪▫·•\s]{4,}$/u', $b) === 1;
    }

    /**
     * 「@이름」만 있는 메시지면 호출된 이름들, 아니면 null.
     * 방 구성원 이름(공백 포함 「Javohir (조하)」「싼카-수출팀 이정아 실장」)을 긴 것부터 떼어내고, 남은 「@토큰」도 떼어낸 뒤 글자가 안 남아야 한다.
     *
     * @param  array<int, string>  $members  방의 모든 구성원 표시명
     * @return array<int, string>|null
     */
    public static function callees(string $body, array $members): ?array
    {
        $b = trim($body);
        if ($b === '' || ! str_starts_with($b, '@')) {
            return null;
        }
        $names = [];
        usort($members, fn ($a, $c) => mb_strlen($c) <=> mb_strlen($a));
        foreach ($members as $m) {
            $m = trim((string) $m);
            if ($m === '') {
                continue;
            }
            $n = 0;
            $b = str_replace('@'.$m, ' ', $b, $n);
            if ($n > 0) {
                $names[] = $m;
            }
        }
        $b = (string) preg_replace_callback('/@([^\s@,]+)/u', function ($m) use (&$names) {
            $names[] = $m[1];

            return ' ';
        }, $b);
        if (preg_match('/[\p{L}\p{N}]/u', $b) === 1) {
            return null;   // 이름 말고 글자가 남았다 — 「@이성호 캐리어 확인 부탁드립니다」는 호출이 아니라 요청
        }

        return $names === [] ? null : array_values(array_unique($names));
    }

    /** 본문 비교용 — 공백·구두점을 지운 꼴 */
    public static function repeatKey(string $body): string
    {
        return mb_strtolower((string) preg_replace('/[\s[:punct:]]+/u', '', trim($body)));
    }
}
