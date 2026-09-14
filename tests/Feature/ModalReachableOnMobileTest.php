<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 📱 **모달이 화면에 갇히지 않는다** (jin 2026-09-14 — 모바일 점검).
 *
 * 증상: 폰에서 창을 열면 내용이 화면보다 길어 **아래 [적용]·[확인] 버튼에 손이 안 닿는다.**
 * 배경(`fixed inset-0`)이 스크롤되지 않으므로 본문 스크롤로도 못 내린다 — 창이 통째로 갇힌다.
 *
 * 필요한 짝:
 *   ① 카드에 `max-h-[90vh] overflow-y-auto` — 넘치면 카드 **안에서** 스크롤된다.
 *   ② 배경에 `p-…` 여백 — 없으면 90vh 카드가 화면 위아래 끝에 딱 붙어 잡을 곳이 없다.
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는다** — 렌더도 동작도 정상이고, 데스크탑에선 내용이
 *    90vh 안에 들어가 **보이는 것도 똑같다**. 폰에서만 손이 안 닿는다. 그래서 정적으로 검사한다.
 *
 * 🧭 새 모달을 만들면 이 짝을 같이 붙일 것. 안 붙이면 이 테스트가 잡는다.
 */
class ModalReachableOnMobileTest extends TestCase
{
    /** 가운데 정렬 모달 = `fixed inset-0` + `flex … items-center`. 슬라이드 패널·토스트는 해당 없음. */
    private function modalBackdrops(string $path): array
    {
        $lines = file($path);
        $hits = [];
        foreach ($lines as $i => $l) {
            if (! str_contains($l, 'fixed inset-0')) {
                continue;
            }
            if (! preg_match('/flex[^"]*items-center/', $l)) {
                continue;
            }
            $hits[] = $i;
        }

        return [$lines, $hits];
    }

    public function test_every_centered_modal_can_scroll_inside_itself(): void
    {
        $offenders = [];

        foreach (glob(base_path('resources/views/livewire/erp/*/index.blade.php')) as $path) {
            [$lines, $hits] = $this->modalBackdrops($path);
            $rel = basename(dirname($path)).'/'.basename($path);

            foreach ($hits as $i) {
                // 카드는 배경 바로 다음 몇 줄 안에 있다.
                $card = null;
                for ($j = $i + 1; $j < min($i + 6, count($lines)); $j++) {
                    if (preg_match('/class="[^"]*(?:\bcard\b|rounded-)[^"]*"/', $lines[$j])) {
                        $card = $lines[$j];
                        break;
                    }
                }
                if ($card === null) {
                    continue;   // 카드를 못 찾으면 이 형태가 아니다 — 조용히 넘어간다
                }

                // 손이 닿는 형태는 셋이다 — 어느 하나면 통과.
                //   ① 배경 자체가 스크롤 ② 카드가 스크롤 ③ 카드는 높이만 묶고 **안쪽 칸**이 스크롤
                //   ③ 은 머리·바닥을 고정하고 가운데만 굴리는 형태다(정산 「예외 대상」이 그 모양).
                $backdropScrolls = str_contains($lines[$i], 'overflow-y-auto');
                $cardCapped = (bool) preg_match('/max-h-\[\d+vh\]/', $card);
                $cardScrolls = $cardCapped && str_contains($card, 'overflow-y-auto');

                $innerScrolls = false;
                if ($cardCapped && ! $cardScrolls) {
                    preg_match('/^(\s*)/', $card, $ci);
                    $indent = strlen($ci[1]);
                    for ($k = $j + 1; $k < count($lines); $k++) {
                        if (preg_match('/^(\s*)<\/div>/', $lines[$k], $cm) && strlen($cm[1]) === $indent) {
                            break;
                        }
                        if (str_contains($lines[$k], 'overflow-y-auto')) {
                            $innerScrolls = true;
                            break;
                        }
                    }
                }

                if (! $backdropScrolls && ! $cardScrolls && ! $innerScrolls) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            '모달이 화면에 갇힌다 — 내용이 길면 폰에서 아래 버튼에 손이 안 닿는다.',
            '고치는 법: 카드 class 에 max-h-[90vh] overflow-y-auto 추가.',
            '해당 위치: '.implode(', ', $offenders),
        ]));
    }

    /**
     * 90vh 카드는 배경에 여백이 있어야 화면 끝에 안 붙는다.
     * ⚠️ 여백이 없으면 스크롤은 되는데 **가장자리를 못 잡아** 닫기가 어려워진다.
     */
    public function test_modal_backdrops_keep_a_gutter(): void
    {
        $offenders = [];

        foreach (glob(base_path('resources/views/livewire/erp/*/index.blade.php')) as $path) {
            [$lines, $hits] = $this->modalBackdrops($path);
            $rel = basename(dirname($path)).'/'.basename($path);

            foreach ($hits as $i) {
                $card = null;
                for ($j = $i + 1; $j < min($i + 6, count($lines)); $j++) {
                    if (preg_match('/class="[^"]*(?:\bcard\b|rounded-)[^"]*"/', $lines[$j])) {
                        $card = $lines[$j];
                        break;
                    }
                }
                // 높이를 90vh 로 묶은 카드만 대상 — 짧은 창은 여백이 없어도 끝에 안 붙는다.
                if ($card === null || ! preg_match('/max-h-\[\d+vh\]/', $card)) {
                    continue;
                }
                if (! preg_match('/\bp-\d/', $lines[$i]) && ! preg_match('/\bp[xy]-\d/', $lines[$i])) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            '90vh 로 묶은 모달의 배경에 여백이 없다 — 카드가 화면 끝에 붙어 가장자리를 못 잡는다.',
            '고치는 법: 배경 class 에 p-3 추가.',
            '해당 위치: '.implode(', ', $offenders),
        ]));
    }

    /**
     * 스크롤 칸이 된 카드 **안에** 버튼이 있어야 스크롤로 닿는다.
     * 밖에 있으면 카드는 스크롤되는데 버튼은 여전히 화면 밖이다.
     */
    public function test_action_buttons_live_inside_the_scrollable_card(): void
    {
        $offenders = [];

        foreach (glob(base_path('resources/views/livewire/erp/*/index.blade.php')) as $path) {
            $lines = file($path);
            $rel = basename(dirname($path)).'/'.basename($path);

            foreach ($lines as $i => $l) {
                if (! preg_match('/max-h-\[\d+vh\][^"]*overflow-y-auto|overflow-y-auto[^"]*max-h-\[\d+vh\]/', $l)) {
                    continue;
                }
                preg_match('/^(\s*)/', $l, $m);
                $indent = strlen($m[1]);

                $end = count($lines);
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (preg_match('/^(\s*)<\/div>/', $lines[$j], $m2) && strlen($m2[1]) === $indent) {
                        $end = $j;
                        break;
                    }
                }

                $body = implode('', array_slice($lines, $i, $end - $i));
                if (! str_contains($body, '<button')) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            '스크롤 칸이 된 카드 안에 버튼이 없다 — 카드만 스크롤되고 버튼은 화면 밖에 남는다.',
            '해당 위치: '.implode(', ', $offenders),
        ]));
    }
}
