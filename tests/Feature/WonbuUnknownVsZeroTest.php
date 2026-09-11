<?php

namespace Tests\Feature;

use App\Services\CarmodooService;
use Tests\TestCase;

/**
 * 원부조회 — 「0건」과 「못 읽음」을 구분한다 (jin 2026-09-11).
 *
 * 🚨 예전엔 요약을 0 으로 초기화하고 정규식이 안 맞으면 0 을 그대로 돌려주며 `success=true`
 *    를 냈다. carmodoo 가 페이지 구조를 바꾸는 날부터 **모든 차가 「압류 0 / 저당 0」= 깨끗**
 *    으로 보이고, 화면은 회색 뱃지로 그려 진짜 깨끗한 차와 구분이 안 된다. 예외도 로그도 없다.
 *    환율 사고(SKILLS §8 #88)는 값이 「안 보이는」 실패였지만 이건 **틀린 답을 자신 있게
 *    보여주는** 실패다 — 매입 판단에 직접 쓰이므로 더 나쁘다.
 *
 * ⚠️ 정적·단위 검사여야 한다 — 기능 테스트로는 원리상 못 잡는다.
 *    서류도 화면도 정상 렌더되고, 숫자만 거짓이다.
 */
class WonbuUnknownVsZeroTest extends TestCase
{
    private function html(string $body): string
    {
        return '<html><body>'.$body.'</body></html>';
    }

    public function test_a_real_zero_is_zero(): void
    {
        $r = (new CarmodooService('https://x', null, 'id', 'pw', 'dno'))->parseHtml(
            $this->html('<p>압류 0건 / 저당 0건 / 구조 0건</p>')
        );

        $this->assertSame(0, $r['summary']['압류']);
        $this->assertSame(0, $r['summary']['저당']);
        $this->assertSame(0, $r['summary']['구조']);
    }

    public function test_a_real_count_is_read(): void
    {
        $r = (new CarmodooService('https://x', null, 'id', 'pw', 'dno'))->parseHtml(
            $this->html('<p>압류 0건 / 저당 2건 / 구조 0건</p>')
        );

        $this->assertSame(2, $r['summary']['저당']);
    }

    public function test_an_unreadable_page_is_null_not_zero(): void
    {
        // carmodoo 가 화면을 바꿔 라벨이 통째로 사라진 경우.
        $r = (new CarmodooService('https://x', null, 'id', 'pw', 'dno'))->parseHtml(
            $this->html('<div class="wonbu_info"><tr><th>자동차번호</th><td>12가3456</td></tr></div>')
        );

        foreach (['압류', '저당', '구조'] as $k) {
            $this->assertNull($r['summary'][$k], "{$k}: 못 읽은 것을 0 으로 돌려주면 「깨끗」으로 위장된다");
        }
    }

    public function test_a_partially_readable_page_flags_only_the_missing_one(): void
    {
        // 압류·저당은 읽히고 구조만 사라진 경우 — 읽힌 것까지 버리지 않는다.
        $r = (new CarmodooService('https://x', null, 'id', 'pw', 'dno'))->parseHtml(
            $this->html('<p>압류 1건 / 저당 0건</p>')
        );

        $this->assertSame(1, $r['summary']['압류']);
        $this->assertSame(0, $r['summary']['저당']);
        $this->assertNull($r['summary']['구조']);
    }

    public function test_the_screen_does_not_flatten_null_into_zero(): void
    {
        // 🚨 `?? 0` 이 돌아오면 서비스 쪽 구분이 통째로 무의미해진다. 정적으로 막는다.
        $src = file_get_contents(base_path('resources/views/livewire/erp/vehicles/index.blade.php'));
        $start = strpos($src, "\$wonbuResult['summary']");
        $this->assertNotFalse($start, '원부 요약 렌더를 찾지 못했다');

        $block = substr($src, $start - 200, 900);
        $this->assertStringNotContainsString("['summary'][\$k] ?? 0", $block,
            'null(못 읽음)을 0(없음)으로 뭉개고 있다 — 화면이 「깨끗」으로 거짓 표시한다');
        $this->assertStringContainsString('wonbu.unknown', $block,
            '못 읽은 경우를 「확인 불가」로 표시해야 한다');
    }
}
