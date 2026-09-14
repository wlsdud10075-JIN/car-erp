<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 📱 **잔금 행이 폰에서 옆으로 안 밀린다** (jin 2026-09-14 모바일 점검).
 *
 * 차량 편집 패널의 잔금 줄은 칸 너비가 **데스크탑 패널(700px) 기준 픽셀**로 박혀 있다
 * (`style="width: 112px; flex: none"` 류). 390px 폰에서는 다 못 들어가는데 줄바꿈이 없어서
 *   ① `flex-1` 인 **비고칸이 0px 로 찌그러지고**
 *   ② 그래도 넘쳐서, 부모가 `overflow-y-auto` 라 `overflow-x` 도 auto 가 되어
 *      **판매 탭이 통째로 옆으로 밀린다.**
 *
 * 고침 = 행 묶음에 `flex-wrap`, 비고칸에 `grow basis-full sm:basis-0`.
 * 비고칸을 `flex-1`(= basis 0%) 로 두면 줄바꿈이 생겨도 **남는 틈에 끼어 또 찌그러진다** —
 * 폰에서는 자기 줄을 차지해야 한다. `sm:basis-0` 이라 데스크탑 렌더는 그대로다.
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는다** — 렌더도 저장도 정상이고 데스크탑에선 똑같이 보인다.
 *    폰에서만 밀린다. 그래서 정적으로 클래스를 검사한다.
 *
 * ⚠️ 새 클래스는 **빌드된 CSS 에 있어야 한다**(§8 #50) — 실제로 `basis-full`·`sm:basis-0` 이
 *    처음엔 없어서 `npm run build` 를 돌렸다. 그 확인도 여기서 한다.
 */
class PaymentRowWrapsOnMobileTest extends TestCase
{
    private const PANEL = 'resources/views/livewire/erp/vehicles/index.blade.php';

    /** 고정폭 칸을 담은 잔금 행 묶음은 전부 줄바꿈을 허용해야 한다. */
    public function test_payment_rows_allow_wrapping(): void
    {
        $src = file_get_contents(base_path(self::PANEL));

        // 잔금/이체 행 묶음 = `flex … gap-2 items-center rounded …` 형태.
        preg_match_all('/class="[^"]*\bflex\b[^"]*gap-2 items-center rounded[^"]*"/', $src, $m);

        $this->assertNotEmpty($m[0], '잔금 행 묶음을 못 찾았다 — 구조가 바뀌었으면 이 테스트도 고칠 것');

        $offenders = array_values(array_filter(
            $m[0],
            fn (string $cls) => ! str_contains($cls, 'flex-wrap')
        ));

        $this->assertSame([], $offenders, implode("\n", [
            '잔금 행에 flex-wrap 이 없다 — 폰에서 비고칸이 0px 로 찌그러지고 탭이 옆으로 밀린다.',
            '고치는 법: 그 묶음 class 에 flex-wrap 추가(데스크탑은 폭이 남아 줄이 안 바뀐다).',
            '해당: '.implode(' / ', array_map(fn ($c) => mb_substr($c, 0, 70), $offenders)),
        ]));
    }

    /**
     * 비고칸은 폰에서 **자기 줄**을 차지해야 한다.
     * `flex-1`(basis 0%)로 두면 줄바꿈이 생겨도 남는 틈에 끼어 또 찌그러진다.
     */
    public function test_note_fields_take_their_own_line_on_mobile(): void
    {
        $lines = file(base_path(self::PANEL));
        $offenders = [];

        foreach ($lines as $i => $line) {
            // 잔금 행 안의 비고칸만 — `note` 를 담고 있고 flex 자식인 것.
            $isNote = str_contains($line, "['note']") || str_contains($line, '.note"');
            if (! $isNote) {
                continue;
            }
            if (! preg_match('/class="([^"]*)"/', $line, $m)) {
                continue;
            }
            $cls = $m[1];
            if (! str_contains($cls, 'flex-1') && ! str_contains($cls, 'basis-')) {
                continue;   // 폭을 다투는 칸이 아니다
            }
            if (str_contains($cls, 'basis-full') && str_contains($cls, 'sm:basis-0')) {
                continue;   // 올바른 형태
            }
            $offenders[] = ($i + 1).': '.mb_substr($cls, 0, 60);
        }

        $this->assertSame([], $offenders, implode("\n", [
            '비고칸이 폰에서 자기 줄을 안 차지한다 — 줄바꿈이 생겨도 남는 틈에 끼어 찌그러진다.',
            '고치는 법: flex-1 → grow basis-full sm:basis-0.',
            '해당: '.implode(' / ', $offenders),
        ]));
    }

    /**
     * ⚠️ 새 유틸 클래스는 **빌드된 CSS 에 있어야** 실제로 먹는다(§8 #50).
     * 없으면 화면은 정상 렌더되고 **아무 효과도 없다**.
     */
    public function test_the_classes_actually_exist_in_the_built_css(): void
    {
        $files = glob(public_path('build/assets/*.css'));

        if ($files === []) {
            $this->markTestSkipped('빌드 산출물이 없다(배포 서버가 다시 굽는다) — 로컬에서만 의미 있는 검사');
        }

        $css = '';
        foreach ($files as $f) {
            $css .= file_get_contents($f);
        }

        foreach (['.flex-wrap', '.grow', '.basis-full', '.sm\\:basis-0'] as $needle) {
            $this->assertStringContainsString($needle, $css,
                "{$needle} 가 빌드된 CSS 에 없다 — npm run build 를 돌릴 것. 없으면 화면은 멀쩡한데 아무 효과가 없다");
        }
    }
}
