<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 차량목록 컬럼 렌더 최적화 가드 (jin 2026-09-07 「100개씩 보면 렉」 제보).
 *
 * 실측(4,288대 복제 DB) — 100행 HTML **908KB**, 행마다 td 36개인데 기본 표시는 10개였다.
 * 안 보이는 칸까지 전부 그려 보내고 CSS 로 가리고 있었다. 서버가 표시 컬럼을 알게 해
 * **아예 안 그리도록** 바꿨다: 908KB → 584KB(−36%), 행 1개 8.5KB → 3.5KB(−59%).
 *
 * 🚨 이 파일은 **정적 검사**다. 기능 테스트로는 원리상 못 잡는다 —
 *    되돌아가도 화면은 정상으로 보이고 응답만 커진다.
 */
class VehicleListColumnRenderTest extends TestCase
{
    private const VIEW = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private function source(): string
    {
        return file_get_contents(base_path(self::VIEW));
    }

    public function test_hidden_columns_are_skipped_on_the_server_not_only_hidden_by_css(): void
    {
        $src = $this->source();

        // x-show 만 있고 서버 조건이 없는 열이 있으면 그 열은 100행 내내 그려져 전송된다.
        preg_match_all("/<t([dh])\b[^>]*x-show=\"visible\['([a-z_]+)'\]\"/", $src, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, '컬럼 토글 구조가 통째로 바뀌었다면 이 테스트부터 다시 쓸 것');

        foreach ($m as [$tag, $kind, $key]) {
            $this->assertStringContainsString(
                "\$this->colOn('{$key}')",
                $src,
                "컬럼 '{$key}' 이 서버 조건 없이 x-show 로만 가려진다 — 100행이면 안 보이는 칸이 그대로 전송된다"
            );
        }
    }

    public function test_header_and_body_use_the_same_condition(): void
    {
        // th 와 td 가 다른 조건이면 열이 밀려 표가 깨진다.
        $src = $this->source();
        $th = [];
        $td = [];
        preg_match_all("/colOn\('([a-z_]+)'\)\): @endphp<th/", $src, $mth);
        preg_match_all("/colOn\('([a-z_]+)'\)\): @endphp<td/", $src, $mtd);
        sort($mth[1]);
        sort($mtd[1]);
        // ⚠️ 양쪽이 «둘 다 비어도» assertSame 은 통과한다 — 그러면 아무것도 검사하지 않은 채 초록이다.
        $this->assertGreaterThan(20, count($mtd[1]), '조건부 컬럼이 거의 없다 — 정규식이 안 맞거나 최적화가 되돌아갔다');
        $this->assertSame($mth[1], $mtd[1], 'th 와 td 의 조건부 컬럼 목록이 어긋난다 — 열이 밀린다');
    }

    public function test_condition_uses_php_block_so_livewire_adds_no_block_markers(): void
    {
        // 🔑 @if 를 쓰면 Livewire 가 조건마다 <!--[if BLOCK]--> 마커를 넣는다.
        //    31개 열 × 2개 × ~27B = 행마다 1.6KB — 실측에서 100행 HTML 이 908→1,084KB 로
        //    **오히려 커졌다**. @php 안의 조건문은 마커를 만들지 않는다.
        $src = $this->source();
        $this->assertStringNotContainsString('@if($this->colOn(', $src,
            'colOn 조건은 @php if(): 형태여야 한다 — @if 는 행마다 Livewire 블록 마커를 늘린다');
        $this->assertStringContainsString('@php if ($this->colOn(', $src);
    }

    public function test_server_falls_back_to_rendering_everything_when_it_does_not_know(): void
    {
        // 화면이 아직 안 알려 준 첫 렌더에서 컬럼이 사라지면 안 된다 — 빈 배열 = 전부 그린다.
        $component = new class
        {
            public array $visibleColumns = [];

            public function colOn(string $key): bool
            {
                return $this->visibleColumns === [] || in_array($key, $this->visibleColumns, true);
            }
        };
        $this->assertTrue($component->colOn('sale_total'), '모르면 그려야 한다');
        $component->visibleColumns = ['brand_model'];
        $this->assertTrue($component->colOn('brand_model'));
        $this->assertFalse($component->colOn('sale_total'));

        // 실제 컴포넌트도 같은 규칙인지 — 소스에서 확인(정적)
        $this->assertStringContainsString('$this->visibleColumns === [] || in_array($key, $this->visibleColumns, true)',
            $this->source(), 'colOn 의 폴백 규칙이 바뀌면 첫 렌더에서 컬럼이 사라질 수 있다');
    }
}
