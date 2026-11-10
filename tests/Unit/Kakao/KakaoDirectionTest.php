<?php

namespace Tests\Unit\Kakao;

use App\Services\Kakao\KakaoDirection;
use PHPUnit\Framework\TestCase;

/**
 * 방향 계산 — 표본 2(포워딩사 방 300건, 보낸이는 역할 별칭)에서 두 시점(주담당 아님 수출팀B · 주담당 수출팀A)의
 * 판정을 그대로 재현해야 한다. 픽스처는 본문이 없고 판정 칸만 있다(tests/Fixtures/kakao/prom_room_directions.json).
 * 픽스처의 expect 는 3값(내일/기다림/무관)이라 「동료」는 무관으로 접어 비교하고, 동료는 따로 검사한다.
 */
class KakaoDirectionTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/kakao/prom_room_directions.json'), true);
    }

    public function test_reproduces_both_viewers_on_the_prom_room_sample(): void
    {
        $fx = $this->fixture();
        $this->assertCount(300, $fx['rows']);

        foreach (['수출팀B', '수출팀A'] as $viewer) {
            $counts = [];
            foreach ($fx['rows'] as $row) {
                $got = KakaoDirection::of($row['sender'], $row, $fx['sides'], $viewer);
                $collapsed = $got === KakaoDirection::TEAMMATE ? KakaoDirection::NONE : $got;
                $this->assertSame($row['expect'][$viewer], $collapsed, "id {$row['id']} viewer {$viewer}");
                $counts[$got] = ($counts[$got] ?? 0) + 1;
            }
            // 실측(2026-11-10): 내 일 17 은 두 시점 같고, 기다림은 주담당(A) 19 · 아님(B) 5
            $this->assertSame(17, $counts[KakaoDirection::MINE]);
            $this->assertSame($viewer === '수출팀A' ? 19 : 5, $counts[KakaoDirection::WAITING]);
        }
    }

    public function test_teammate_requests_are_their_own_bucket(): void
    {
        $sides = ['프롬' => ['프롬A'], '수출팀' => ['수출팀A', '수출팀B'], '영업' => ['영업A']];

        // 동료(수출팀A)가 프롬에게 한 요청 → 수출팀B 에게는 「동료」
        $this->assertSame(KakaoDirection::TEAMMATE, KakaoDirection::of('수출팀A', ['type' => '요청', 'to' => '프롬'], $sides, '수출팀B'));
        // 같은 메시지를 수출팀A 본인이 보면 「기다림」
        $this->assertSame(KakaoDirection::WAITING, KakaoDirection::of('수출팀A', ['type' => '요청', 'to' => '프롬'], $sides, '수출팀A'));
        // 동료의 대답·정보는 동료 묶음이 아니다
        $this->assertSame(KakaoDirection::NONE, KakaoDirection::of('수출팀A', ['type' => '대답', 'to' => '프롬'], $sides, '수출팀B'));
        // 다른 편(영업)이 프롬에게 한 요청은 나와 무관
        $this->assertSame(KakaoDirection::NONE, KakaoDirection::of('영업A', ['type' => '요청', 'to' => '프롬'], $sides, '수출팀B'));
    }

    public function test_mention_rules(): void
    {
        $sides = ['프롬' => ['프롬A'], '수출팀' => ['싼카-수출팀 박수정 대리', '싼카-수출팀김다정'], '영업' => ['영업A']];

        // @로 나를 지목하면 내 편이 아닌 쪽에서 와도 내 일
        $this->assertSame(KakaoDirection::MINE, KakaoDirection::of('영업A', ['type' => '요청', 'to' => '영업', 'mention' => '싼카-수출팀 박수정'], $sides, '싼카-수출팀 박수정 대리'));
        // 내 편에게 왔는데 다른 사람을 지목했으면 내 일 아님
        $this->assertSame(KakaoDirection::NONE, KakaoDirection::of('프롬A', ['type' => '요청', 'to' => '수출팀', 'mention' => '싼카-수출팀 박수정'], $sides, '싼카-수출팀김다정'));
        // 내 편에게, 지목 없음 → 내 일
        $this->assertSame(KakaoDirection::MINE, KakaoDirection::of('프롬A', ['type' => '요청', 'to' => '수출팀', 'mention' => null], $sides, '싼카-수출팀김다정'));
        // 표시명 부분 일치(「김다정」 ↔ 「싼카-수출팀김다정」)
        $this->assertTrue(KakaoDirection::mentions('김다정', '싼카-수출팀김다정'));
        $this->assertFalse(KakaoDirection::mentions('박수정', '싼카-수출팀김다정'));
    }

    public function test_unclassified_and_chatter_are_never_todo(): void
    {
        $sides = ['수출팀' => ['나']];
        foreach ([null, '첨부', '잡담', '확인', '정보', '대답'] as $type) {
            $this->assertSame(KakaoDirection::NONE, KakaoDirection::of('프롬A', ['type' => $type, 'to' => '수출팀'], $sides, '나'), (string) $type);
        }
    }
}
