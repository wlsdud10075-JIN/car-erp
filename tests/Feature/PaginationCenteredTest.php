<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 페이지 넘김 버튼 위치 (jin 2026-08-27).
 *
 * 우하단에 [통관서류 알람] 카드가 `fixed bottom-4 right-4` 로 상주해서, 오른쪽 정렬된 페이저와
 * 겹쳐 누르기 어려웠다. 공용 뷰(vendor/pagination/tailwind)를 가운데 정렬로 바꿔 17개 화면이
 * 함께 따라오게 했다.
 *
 * 🔑 그런데 **공용 뷰만 고쳐서는 안 되는 화면이 있었다.** 차량관리는 페이저가
 *   `flex … justify-between` 행 안에 들어 있어서, nav 안에서 아무리 가운데 정렬을 해도
 *   nav 상자 자체가 오른쪽 끝에 있어 아무 일도 일어나지 않았다(jin 실측 — 재고·채권은 되는데
 *   차량관리만 안 됐다). 그래서 nav 를 전체 폭으로 만들고, 차량관리는 페이저를 그 행 밖으로 뺐다.
 *
 * 이 테스트는 **정적 검사**다 — 화면은 어느 쪽이든 정상 렌더되므로 기능 테스트로는 못 잡는다.
 */
class PaginationCenteredTest extends TestCase
{
    use RefreshDatabase;

    private function paginationView(): string
    {
        return file_get_contents(resource_path('views/vendor/pagination/tailwind.blade.php'));
    }

    public function test_shared_pagination_view_centers_and_fills_the_row(): void
    {
        $v = $this->paginationView();

        $this->assertStringContainsString('w-full', $v, 'nav 이 전체 폭을 안 잡으면 가운데 정렬이 안 먹는다');
        $this->assertStringContainsString('justify-center', $v);
        $this->assertStringNotContainsString('sm:justify-between', $v, '오른쪽 정렬이 남아 있다');
    }

    public function test_shared_view_keeps_clear_of_the_floating_alarm_card(): void
    {
        // 좁은 화면에서는 가운데로 옮겨도 우하단 카드가 덮을 수 있어 바닥을 띄운다.
        $this->assertStringContainsString('pb-24', $this->paginationView());
    }

    public function test_no_screen_puts_its_pager_inside_a_justify_between_row(): void
    {
        // 🚨 그 안에 두면 공용 뷰를 아무리 고쳐도 그 화면만 조용히 오른쪽에 남는다.
        //    페이저 바로 앞 120줄 안에서 열린 `justify-between` flex 컨테이너를 찾는다.
        $offenders = [];
        foreach (glob(resource_path('views/livewire/**/**/*.blade.php')) as $file) {
            $lines = file($file);
            foreach ($lines as $i => $line) {
                if (! str_contains($line, '->links()')) {
                    continue;
                }
                $window = implode('', array_slice($lines, max(0, $i - 120), min(120, $i)));
                // 닫힌 것까지 세지 않는 거친 판정이라, 같은 창에서 열림 수가 닫힘 수보다 많을 때만 잡는다.
                $opens = preg_match_all('/<div class="[^"]*flex[^"]*justify-between[^"]*"/', $window);
                if ($opens > 0 && str_contains($window, 'justify-between')) {
                    // 페이저가 그 컨테이너 안에 실제로 있는지 — 창 끝에서 </div> 로 닫혔는지 본다.
                    $tail = implode('', array_slice($lines, max(0, $i - 6), 6));
                    if (! str_contains($tail, '</div>') || substr_count($tail, '</div>') < 2) {
                        $offenders[] = basename(dirname($file)).'/'.basename($file).':'.($i + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            '페이저가 justify-between 행 안에 있다 — 그 화면만 오른쪽에 남는다: '.implode(', ', $offenders));
    }

    public function test_vehicles_pager_is_a_standalone_block(): void
    {
        // 차량관리는 실제로 그 함정을 밟았던 화면이라 직접 못박는다.
        $blade = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<div class="mt-2">\{\{ \$this->vehicles->links\(\) \}\}<\/div>/',
            $blade,
            '차량관리 페이저가 독립 블록이 아니다'
        );
    }
}
