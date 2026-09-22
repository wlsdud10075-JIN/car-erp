<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 📈 관리자 대시보드 그래프 — «처음 들어가면 안 그려지고 새로고침해야 뜬다» (jin 2026-09-22, ssancarerp 상시).
 *
 * 원인: Chart.js 가 컴포넌트 안 `<script src>` 로 오는데, wire:navigate 로 진입하면 Livewire 가 body 의 script 를
 * 복제해 **비동기로** 로드하고 Alpine init 은 그걸 기다리지 않는다 → `renderCharts()` 가 `Chart` 미정의로 조용히 return.
 * F5 는 script 가 동기라 멀쩡 → 「새로고침하면 된다」로 위장된다.
 *
 * ⚠️ 기능 테스트로는 원리상 못 잡는다(렌더는 정상, 그래프만 안 그려짐) → 정적 검사.
 */
class AdminDashboardChartReadyTest extends TestCase
{
    private const BLADE = 'resources/views/livewire/admin/dashboard.blade.php';

    public function test_charts_wait_for_chart_js_before_drawing(): void
    {
        $blade = file_get_contents(base_path(self::BLADE));

        $this->assertTrue(str_contains($blade, 'whenChartReady(cb)'), 'whenChartReady 헬퍼가 없다');
        $this->assertTrue(
            (bool) preg_match('/whenChartReady\(cb\)\s*\{[\s\S]{0,400}typeof Chart/', $blade),
            'whenChartReady 가 Chart 정의 여부를 보지 않는다'
        );

        // 그리기는 전부 헬퍼를 지나야 한다 — 직접 $nextTick 으로 그리면 navigate 진입에서 다시 빈 그래프가 된다
        $direct = preg_match_all('/\$nextTick\(\(\) => this\.renderCharts\(\)\)/', $blade);
        $this->assertSame(0, $direct, 'renderCharts 를 whenChartReady 없이 직접 부르는 곳이 있다');
        $this->assertGreaterThanOrEqual(4, preg_match_all('/whenChartReady\(\(\) => this\.renderCharts\(\)\)/', $blade));
    }
}
