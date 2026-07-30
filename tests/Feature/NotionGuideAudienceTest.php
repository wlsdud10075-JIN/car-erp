<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🚨 Notion 업무가이드 ↔ 챗봇 색인 무결성 가드 (2026-07-30 신설)
 *
 * 챗봇 색인은 매일 03:00 Notion 을 읽어 만든다. 각 청크의 audience 마커로 권한을 가른다.
 * 마커가 하나라도 없으면 색인이 fail-closed 로 통째 멈추고,
 * 전량 미표기면 하위호환으로 전 청크가 staff 취급되어 권한 격리가 풀린다
 * (영업이 대표·시스템 자료를 검색하게 됨).
 *
 * ⚠️ 이 부류는 실행 테스트로 못 잡는다 — Notion API 가 필요하고 CI 에서 못 돈다.
 *    그래서 repo 소스를 정적으로 스캔한다. 라이브와의 대조는 각 스크립트의 --verify 담당.
 *
 * 관련: SKILLS.md §8 #40 · 메모리 project_chatbot_cards_single_source
 */
class NotionGuideAudienceTest extends TestCase
{
    private const AUDIENCES = ['staff', 'finance', 'executive', 'system'];

    private const CARDS_JSON = 'scripts/notion-cards/cards.json';

    private const BUILDERS = [
        'scripts/notion-guide-publish.php',
        'scripts/notion-workflow-lock-guide.php',
    ];

    public function test_every_chatbot_card_has_a_valid_audience(): void
    {
        $cards = json_decode(file_get_contents(base_path(self::CARDS_JSON)), true);
        $this->assertIsArray($cards, 'cards.json 파싱 실패 — 문법이 깨졌습니다.');

        $bad = [];
        $count = 0;
        foreach ($cards as $group) {
            foreach ($group['cards'] as $card) {
                $count++;
                if (! in_array($card['audience'] ?? null, self::AUDIENCES, true)) {
                    $bad[] = "{$group['group']} / {$card['title']} → ".var_export($card['audience'] ?? null, true);
                }
            }
        }

        $this->assertSame([], $bad, "audience 가 없거나 잘못된 카드가 있습니다.\n".
            "이 상태로 발행하면 다음 03:00 색인이 fail-closed 로 멈춥니다.\n".
            '허용값: '.implode('|', self::AUDIENCES)."\n  · ".implode("\n  · ", $bad));
        $this->assertGreaterThan(0, $count, 'cards.json 에 카드가 하나도 없습니다.');
    }

    public function test_chatbot_card_titles_are_unique(): void
    {
        // --card "제목" 이 제목으로 카드를 찾으므로, 중복되면 엉뚱한 카드를 덮어쓴다.
        $cards = json_decode(file_get_contents(base_path(self::CARDS_JSON)), true);
        $titles = [];
        foreach ($cards as $group) {
            foreach ($group['cards'] as $card) {
                $titles[] = $card['title'];
            }
        }
        $dupes = array_keys(array_filter(array_count_values($titles), fn ($n) => $n > 1));

        $this->assertSame([], $dupes, '카드 제목이 중복됩니다(--card 가 오작동): '.implode(', ', $dupes));
    }

    public function test_guide_builders_declare_valid_page_audiences(): void
    {
        foreach (self::BUILDERS as $file) {
            $src = file_get_contents(base_path($file));
            $this->assertMatchesRegularExpression('/\$PAGE_AUDIENCE\s*=/', $src,
                "$file 에 \$PAGE_AUDIENCE 선언이 없습니다 — 발행 시 마커를 못 찍습니다.");

            // $PAGE_AUDIENCE 블록 안의 값들만 검사
            preg_match('/\$PAGE_AUDIENCE\s*=\s*\[(.*?)\];/s', $src, $m);
            $this->assertNotEmpty($m[1] ?? '', "$file 의 \$PAGE_AUDIENCE 를 파싱하지 못했습니다.");

            preg_match_all("/=>\s*'([^']+)'/", $m[1], $vals);
            $this->assertNotEmpty($vals[1], "$file 의 \$PAGE_AUDIENCE 에 값이 없습니다.");
            foreach ($vals[1] as $v) {
                $this->assertContains($v, self::AUDIENCES,
                    "$file 의 \$PAGE_AUDIENCE 에 잘못된 등급 '$v' — 허용값: ".implode('|', self::AUDIENCES));
            }
        }
    }

    public function test_guide_builders_actually_emit_the_marker(): void
    {
        /*
         * 값이 선언돼 있어도 "발행 함수" 가 marker() 를 안 부르면 소용없다.
         * 2026-07-30 이전이 정확히 그 상태였다 — 마커를 만들지 않는 빌더가 페이지를 통째 교체해서,
         * --apply 하는 순간 6페이지 마커가 전부 사라지는 구조였다.
         *
         * ⚠️ 파일 전체에서 marker( 를 찾으면 --verify 쪽 호출에 걸려 통과해버린다(실측).
         *    반드시 발행 함수 본문 안에서만 확인한다.
         */
        $publishFn = [
            'scripts/notion-guide-publish.php' => 'publishPage',
            'scripts/notion-workflow-lock-guide.php' => 'upsertPage',
        ];
        foreach ($publishFn as $file => $fn) {
            $src = file_get_contents(base_path($file));
            $this->assertMatchesRegularExpression('/function marker\s*\(/', $src,
                "$file 에 marker() 정의가 없습니다.");
            $this->assertStringContainsString('ASSISTANT_AUDIENCE=', $src,
                "$file 이 마커 문자열을 만들지 않습니다.");

            $body = $this->functionBody($src, $fn);
            $this->assertNotSame('', $body, "$file 에서 발행 함수 {$fn}() 를 찾지 못했습니다.");
            $this->assertStringContainsString('marker(', $body,
                "$file 의 {$fn}() 가 marker() 를 블록에 붙이지 않습니다 — 발행하면 페이지 마커가 사라져 색인이 멈춥니다.");
        }
    }

    /** 함수 정의부터 중괄호 균형이 맞는 지점까지 잘라낸다(정적 스캔용, 근사치로 충분). */
    private function functionBody(string $src, string $name): string
    {
        $pos = strpos($src, "function {$name}(");
        if ($pos === false) {
            return '';
        }
        $open = strpos($src, '{', $pos);
        if ($open === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $pos, $i - $pos + 1);
                }
            }
        }

        return '';
    }

    public function test_publish_tools_expose_a_verify_mode(): void
    {
        // --verify 가 드리프트를 잡는 유일한 수단이다. 사라지면 정합이 조용히 무너진다.
        $tools = array_merge(self::BUILDERS, ['scripts/notion-cards/publish.php']);
        foreach ($tools as $file) {
            $this->assertStringContainsString("'--verify'", file_get_contents(base_path($file)),
                "$file 에 --verify 모드가 없습니다.");
        }
    }
}
