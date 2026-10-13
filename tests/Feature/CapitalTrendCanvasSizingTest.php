<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 📈 자금추이 스파크라인 — «모바일에서 관리자 대시보드 그래프가 깨진다» (jin 2026-10-13).
 *
 * 원인: app.js `drawCapitalTrend` 가 캔버스 **자신의** clientWidth 를 재서 `cv.width = w × dpr` 로 백킹을 키우는데
 * CSS 크기를 안 잡아 그 백킹 크기가 곧 화면 크기가 됐다. 다음 측정이 그 값을 읽어 폴링(30s)·resize 마다 배로 커진다
 * (폰 DPR 3 실측 900 → 2700 → 8100px). 데스크탑 DPR 1 은 ×1 이라 안 보였다. SKILLS §8 #116.
 *
 * ⚠️ 기능 테스트로는 원리상 못 잡는다(그리기는 브라우저 JS) → 정적 검사. 깨뜨려 확인: 아래 셋 중 하나를 되돌리면 빨개진다.
 */
class CapitalTrendCanvasSizingTest extends TestCase
{
    private const JS = 'resources/js/app.js';

    private const BLADE = 'resources/views/livewire/admin/dashboard.blade.php';

    public function test_sparkline_measures_parent_and_pins_css_size(): void
    {
        $js = file_get_contents(base_path(self::JS));
        $this->assertSame(1, preg_match('/function drawCapitalTrend\(id\)\s*\{([\s\S]*?)\n\}/', $js, $m), 'drawCapitalTrend 가 없다');
        $fn = $m[1];

        // ① 폭은 부모에서 — 캔버스 자신의 clientWidth 를 읽으면 자기 백킹 크기를 다시 읽어 커진다
        $this->assertStringNotContainsString('cv.clientWidth', $fn, '캔버스 자신의 clientWidth 를 읽는다(자기 증식)');
        $this->assertStringContainsString('cv.parentElement.clientWidth', $fn, '부모 폭을 재지 않는다');

        // ② CSS 크기를 화면 px 로 못 박아야 백킹(dpr 배)이 화면 크기가 되지 않는다
        $this->assertStringContainsString("cv.style.width = w + 'px'", $fn, 'CSS 폭을 고정하지 않는다');
        $this->assertStringContainsString("cv.style.height = h + 'px'", $fn, 'CSS 높이를 고정하지 않는다');
        $this->assertMatchesRegularExpression('/cv\.style\.width[\s\S]*cv\.width = w \* dpr/', $fn, 'CSS 크기 고정이 백킹 설정보다 뒤에 있다');
    }

    public function test_sparkline_canvas_is_block_full_width_in_blade(): void
    {
        $blade = file_get_contents(base_path(self::BLADE));
        $this->assertSame(1, preg_match('/<canvas id="capitalTrendChart"[^>]*>/', $blade, $m));
        $this->assertStringContainsString('class="block w-full"', $m[0], '캔버스가 카드 폭을 따르지 않는다');
    }
}
