<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 👆 **터치로도 되는가** (jin 2026-09-14 모바일 점검 4순위).
 *
 * 두 가지를 본다:
 *   ① **hover 전용 정보** — 터치 기기엔 hover 가 없다. `@mouseenter` 만 있는 상세는
 *      폰에서 **눌러도 아무것도 안 나온다**(포워딩사 스케줄 달력이 그랬다 — 선적일·선박·
 *      포워딩사·지급 여부를 볼 길이 0 이었다).
 *   ② **손가락에 너무 작은 칸** — 28px 짜리 입력·버튼. 권장은 44px 다.
 *      특히 [청산]은 되돌리려면 따로 해제해야 하는 **돈 확정 버튼**이라 오탭 비용이 크다.
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는다** — 마우스로는 전부 정상 동작하고 렌더도 정상이다.
 *    터치에서만 안 된다. 그래서 정적으로 검사한다.
 *
 * 🧭 새로 만들 때: 상세를 hover 로만 주지 말 것(탭도 받게), 손가락이 닿는 칸은 폰에서 키울 것.
 */
class TouchAffordanceTest extends TestCase
{
    public function test_hover_only_details_also_open_on_tap(): void
    {
        $offenders = [];

        foreach (glob(base_path('resources/views/livewire/erp/*/index.blade.php')) as $path) {
            $lines = file($path);
            $rel = basename(dirname($path)).'/'.basename($path);

            foreach ($lines as $i => $line) {
                if (! str_contains($line, '@mouseenter')) {
                    continue;
                }

                // 같은 요소(태그가 끝날 때까지)에 탭 경로가 있어야 한다.
                $window = implode('', array_slice($lines, $i, 6));
                $hasTap = str_contains($window, '@click') || str_contains($window, 'wire:click');

                if (! $hasTap) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'hover 로만 열리는 상세가 있다 — 터치 기기엔 hover 가 없어 폰에서는 볼 방법이 없다.',
            '고치는 법: 같은 핸들러를 @click 으로 하나 더 달 것(데스크탑은 mouseenter 가 그대로 산다).',
            '해당 위치: '.implode(', ', $offenders),
        ]));
    }

    /**
     * 28px(`h-7`) 짜리 조작 칸은 폰에서 키운다.
     * `sm:h-7` 로 데스크탑을 되돌리므로 넓은 화면 렌더는 안 바뀐다.
     */
    public function test_small_controls_are_enlarged_on_phones(): void
    {
        $offenders = [];

        foreach (glob(base_path('resources/views/livewire/erp/*/index.blade.php')) as $path) {
            $rel = basename(dirname($path)).'/'.basename($path);
            $lines = file($path);

            foreach ($lines as $i => $line) {
                if (! preg_match('/class="[^"]*\bh-7\b[^"]*"/', $line, $m)) {
                    continue;
                }
                if (str_contains($m[0], 'sm:h-7')) {
                    continue;   // 폰에서 키우고 데스크탑만 28px — 올바른 형태
                }

                // 조작 칸만 — 글자 뱃지 같은 건 대상이 아니다.
                // ⚠️ **태그가 여러 줄에 걸친다** — `<button` 은 class 보다 윗줄에 있는 일이 흔하다.
                //    한 줄만 보면 그런 것을 통째로 놓친다(처음에 실제로 헛돌았다, §8 #73).
                $window = implode('', array_slice($lines, max(0, $i - 3), 4));
                if (! preg_match('/<(input|select|button|textarea)\b/', $window)) {
                    continue;
                }

                $offenders[] = $rel.':'.($i + 1);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            '28px 짜리 조작 칸이 폰에서 그대로다 — 손가락으로 누르기 어렵다(권장 44px).',
            '고치는 법: h-7 → h-9 sm:h-7.',
            '해당 위치: '.implode(', ', $offenders),
        ]));
    }

    /**
     * ⚠️ 폰용으로 새로 쓴 클래스는 **빌드된 CSS 에 있어야** 실제로 먹는다(§8 #50).
     * 없으면 화면은 정상 렌더되고 **아무 효과도 없다**.
     */
    public function test_the_mobile_classes_exist_in_the_built_css(): void
    {
        $files = glob(public_path('build/assets/*.css'));

        if ($files === []) {
            $this->markTestSkipped('빌드 산출물이 없다(배포 서버가 다시 굽는다) — 로컬에서만 의미 있는 검사');
        }

        $css = '';
        foreach ($files as $f) {
            $css .= file_get_contents($f);
        }

        foreach (['.h-9', '.sm\\:h-7', '.min-w-\\[560px\\]'] as $needle) {
            $this->assertStringContainsString($needle, $css,
                "{$needle} 가 빌드된 CSS 에 없다 — npm run build 를 돌릴 것");
        }
    }
}
