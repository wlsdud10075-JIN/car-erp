<?php

namespace Tests\Feature;

use App\Support\AlimtalkTemplates;
use Tests\TestCase;

/**
 * 🟦 아이템리스트 카드의 **길이 규격**을 정적으로 강제한다.
 *
 * ⚠️ 이 부류는 기능 테스트로 못 잡는다 — 규격 검사는 카카오/BizM 쪽에서만 하므로,
 *    로컬·CI 는 전부 초록인데 **운영 발송만 K208 로 반려**된다(SKILLS §8 #36 의 enum 사고와 같은 형태).
 *    2026-07-31 실측: summary title '원금 대비 손익'(8자) → K208 InvalidItemTitleLengthException.
 *
 * description(20자)은 조립 지점(itemListPayload)에서 자동 컷하지만(§8 #35),
 * title 은 ITEMLIST 에 박힌 **리터럴**이라 자동 컷하면 안 된다 — 잘리면 '원금 대비'처럼
 * 뜻이 바뀐 채 나간다. 그래서 컷 대신 여기서 막는다.
 */
class AlimtalkItemListSpecTest extends TestCase
{
    /** 카카오 아이템리스트 규격 (실측 기준). */
    private const HEADER_MAX = 16;

    private const TITLE_MAX = 6;

    public function test_every_item_and_summary_title_is_within_the_kakao_limit(): void
    {
        foreach (AlimtalkTemplates::ITEMLIST as $code => $il) {
            foreach ($il['items'] ?? [] as $i => $item) {
                $t = (string) ($item['title'] ?? '');
                $this->assertLessThanOrEqual(
                    self::TITLE_MAX,
                    mb_strlen($t),
                    "{$code} items[{$i}].title '{$t}' 이 ".self::TITLE_MAX.'자를 넘습니다 — BizM 이 K208 로 반려합니다. '
                    .'띄어쓰기도 1자로 셉니다(공백 제거로 줄이는 게 보통).'
                );
            }

            if (isset($il['summary']['title'])) {
                $t = (string) $il['summary']['title'];
                $this->assertLessThanOrEqual(
                    self::TITLE_MAX,
                    mb_strlen($t),
                    "{$code} summary.title '{$t}' 이 ".self::TITLE_MAX.'자를 넘습니다 — BizM 이 K208 로 반려합니다.'
                );
            }
        }
    }

    public function test_every_header_is_within_the_kakao_limit(): void
    {
        foreach (AlimtalkTemplates::ITEMLIST as $code => $il) {
            $h = (string) ($il['header'] ?? '');
            $this->assertNotSame('', $h, "{$code} 에 header 가 없습니다.");
            $this->assertLessThanOrEqual(
                self::HEADER_MAX,
                mb_strlen($h),
                "{$code} header '{$h}' 이 ".self::HEADER_MAX.'자를 넘습니다.'
            );
        }
    }

    /** 카드에 쓰는 `#{변수}` 는 그 템플릿의 vars 에 실제로 있어야 한다 (오타 시 치환 안 되고 그대로 발송됨). */
    public function test_itemlist_variables_exist_in_the_template_vars(): void
    {
        foreach (AlimtalkTemplates::ITEMLIST as $code => $il) {
            $vars = AlimtalkTemplates::TEMPLATES[$code]['vars'] ?? [];
            $blob = json_encode($il, JSON_UNESCAPED_UNICODE);

            preg_match_all('/#\{([^}]+)\}/u', (string) $blob, $m);
            foreach (array_unique($m[1]) as $used) {
                $this->assertContains(
                    $used,
                    $vars,
                    "{$code} 카드가 쓰는 #{{$used}} 가 템플릿 vars 에 없습니다 — 치환되지 않고 그대로 발송됩니다."
                );
            }
        }
    }
}
