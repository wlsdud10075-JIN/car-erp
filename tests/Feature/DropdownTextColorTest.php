<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🎨 **드롭다운 항목은 글자색을 명시해야 한다** (jin 2026-09-14 제보).
 *
 * 제보: *「바이어 정산현황에서 드롭박스 내용이 안 나왔어. 검색해도 흰색으로 보이고 클릭하면 적용은 되더라.」*
 *
 * 원인 — `resources/css/app.css` 의 강제 규칙이 **`input, textarea, select` 만** 덮는다:
 * ```css
 * input, textarea, select { color: #1f2937; background-color: #ffffff; }
 * ```
 * 드롭다운 항목은 `<button>` 이라 그 규칙 밖이고, Tailwind preflight 가 `color: inherit` 를 준다.
 * 폰의 **「다크 모드 강제」**(크롬 Android·삼성 인터넷)는 상속된 글자색을 밝게 뒤집는데
 * 컨테이너의 `bg-white` 는 그대로 남아 **흰 글자 / 흰 배경**이 된다.
 * 요소는 멀쩡히 있으므로 **누르면 선택은 된다** — 그래서 「안 보이는데 눌리는」 형태가 된다.
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는다** — 렌더도 선택도 정상이고, 데스크탑에선 멀쩡히 보인다.
 *    바뀌는 건 폰에서 사람 눈에 보이느냐뿐이라 **정적으로 클래스를 검사**한다.
 *
 * 🧭 새 드롭다운을 만들면 항목에 `text-gray-800` 같은 **명시 색**을 넣을 것.
 *    🚫 `app.css` 의 강제 규칙에 `button` 을 더하지 말 것 — `.btn-primary`(보라 배경 흰 글자) 같은
 *       색 있는 버튼이 전부 깨진다. 막는 자리는 드롭다운 항목이다.
 */
class DropdownTextColorTest extends TestCase
{
    /** 흰 배경 위에 뜨는 팝업의 항목 버튼들 — 파일 => 그 안에서 항목을 그리는 줄의 표식. */
    private const DROPDOWN_ITEMS = [
        'resources/views/components/erp/combobox.blade.php' => 'x-text="opt.name"',
        'resources/views/components/country-picker.blade.php' => '@click="select(item)"',
        'resources/views/livewire/erp/settlements/index.blade.php' => 'wire:click="selectVehicle(',
    ];

    public function test_every_dropdown_item_declares_its_own_text_color(): void
    {
        $offenders = [];

        foreach (self::DROPDOWN_ITEMS as $rel => $marker) {
            $path = base_path($rel);
            $this->assertFileExists($path, "대상 파일이 사라졌다: {$rel}");

            $lines = file($path);
            $hit = false;

            foreach ($lines as $i => $line) {
                if (! str_contains($line, $marker)) {
                    continue;
                }
                $hit = true;

                // 항목 버튼의 class 는 표식과 같은 줄이거나 바로 앞뒤 몇 줄에 있다.
                $window = implode('', array_slice($lines, max(0, $i - 4), 9));
                if (! preg_match('/class="[^"]*\btext-(gray|slate|zinc|neutral|stone)-[5-9]00\b/', $window)) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }

            $this->assertTrue($hit, "표식을 못 찾았다 — 구조가 바뀌었으면 이 테스트를 같이 고칠 것: {$rel} ({$marker})");
        }

        $this->assertSame([], $offenders, implode("\n", [
            '드롭다운 항목에 글자색이 없다 — 폰 「다크 모드 강제」에서 흰 글자가 되어 안 보인다.',
            '누르면 선택은 되므로 「안 보이는데 눌리는」 형태로 나타난다.',
            '고치는 법: 그 버튼 class 에 text-gray-800 추가.',
            '해당 위치: '.implode(', ', $offenders),
        ]));
    }

    /**
     * 🚫 `app.css` 의 강제 색 규칙에 `button` 을 넣지 말 것 —
     *    색 있는 버튼(.btn-primary 등)이 전부 회색 글자가 된다.
     */
    public function test_the_forced_color_rule_does_not_swallow_buttons(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));

        $this->assertMatchesRegularExpression('/input,\s*\n?\s*textarea,\s*\n?\s*select\s*\{/', $css,
            'input·textarea·select 강제 색 규칙이 사라졌다 — 드롭다운 입력칸이 다시 안 보이게 된다');

        if (preg_match('/\n\s*(button[^{]*,\s*)?input,\s*\n?\s*textarea,\s*\n?\s*select\s*\{/', $css, $m)) {
            $this->assertStringNotContainsString('button', $m[0],
                '강제 색 규칙이 button 까지 덮으면 .btn-primary 같은 색 버튼이 깨진다');
        }
    }
}
