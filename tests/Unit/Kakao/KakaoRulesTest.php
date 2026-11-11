<?php

namespace Tests\Unit\Kakao;

use App\Services\Kakao\KakaoRules;
use PHPUnit\Framework\TestCase;

/**
 * 모델 앞의 규칙 계층(11-11, jin «완료·정보·단순 답변부터 빼라») — 잡담·호출·반복 판정(확인은 일부러 모델에 — resolves). 전부 운영에서 실제로 본 본문(이름은 가명).
 * 좁게 잡는다: 조금이라도 내용이 있으면 null(모델에 맡긴다).
 */
class KakaoRulesTest extends TestCase
{
    public function test_content_is_left_to_the_model(): void
    {
        // 「넵」도 모델로 — 앞 요청을 닫는 resolves 를 달아야 한다(11-11 실측 확인 137건 중 34건이 요청을 닫음)
        foreach (['넵', '네~', '넵 알겠습니다', '감사합니다^^', '네. 부탁 드리겠습니다.', '넵 두 바이어 모두 오늘 배송시도 예정이라고 합니다.', '확인 부탁드립니다', '확인', '1730', '264거2361', '100만원', '계약금 곧', '전화주세요', '저당 없죠?', '@이성호 캐리어 확인 부탁드립니다.', '1111'] as $b) {
            $this->assertNull(KakaoRules::typeOf($b), $b);
        }
    }

    public function test_separators_and_repeated_characters_are_chat(): void
    {
        foreach (['------------------------------', '==============================', '----', 'ㅁㅁㅁㅁㅁㅁㅁ', 'ㅋㅋㅋㅋㅋ', '★★★★'] as $b) {
            $this->assertSame('잡담', KakaoRules::typeOf($b), $b);
        }
    }

    public function test_attachments_are_not_touched_here(): void
    {
        $this->assertNull(KakaoRules::typeOf('사진 5장'));
        $this->assertNull(KakaoRules::typeOf('.'));
    }

    public function test_mention_only_messages_name_the_callees(): void
    {
        $members = ['이성호', 'Javohir (조하)', '싼카-수출팀 이정아 실장', '박경빈', '최영숙'];

        $this->assertSame(['Javohir (조하)'], KakaoRules::callees('@Javohir (조하)', $members));
        $this->assertSame(['싼카-수출팀 이정아 실장'], KakaoRules::callees('@싼카-수출팀 이정아 실장', $members));
        $this->assertEqualsCanonicalizing(['박경빈', '이성호'], KakaoRules::callees('@박경빈 @이성호', $members));
        $this->assertSame(['최영숙'], KakaoRules::callees('@최영숙 ~', $members));
        $this->assertSame(['ㄱ.임윤태'], KakaoRules::callees('@ㄱ.임윤태', $members));   // 방 구성에 없는 이름도 토큰으로
        $this->assertSame(['Carlos Melendez'], KakaoRules::callees('@Carlos Melendez', ['Carlos Melendez']));
        $this->assertNull(KakaoRules::callees('@Carlos Melendez', []));   // 구성원 목록에 없는 띄어쓴 이름은 뒷 토큰이 본문으로 남는다 — 모델에 맡긴다

        $this->assertNull(KakaoRules::callees('@이성호 캐리어 확인 부탁드립니다.', $members));
        $this->assertNull(KakaoRules::callees('@박병훈 빨리  답변좀 주세요', $members));
        $this->assertNull(KakaoRules::callees('이성호 @박경빈', $members));   // @로 시작하지 않으면 본문
        $this->assertNull(KakaoRules::callees('넵', $members));
    }

    public function test_repeat_key_ignores_spacing_and_punctuation(): void
    {
        $this->assertSame(KakaoRules::repeatKey('1480€ / 1320$'), KakaoRules::repeatKey('1480€/1320$'));
        $this->assertNotSame(KakaoRules::repeatKey('1480€ / 1320$'), KakaoRules::repeatKey('1490€ / 1320$'));
    }
}
