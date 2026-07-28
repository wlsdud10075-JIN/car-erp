<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ComponentAttributeBag;
use Tests\TestCase;

/**
 * combobox 는 드롭다운을 absolute 로 띄우므로 루트에 relative 가 반드시 남아야 한다.
 * 예전엔 {{ $attributes }} 와 class="relative" 를 따로 찍어서, 호출측이 class 를 주면
 * 같은 속성이 두 번 렌더되고 뒤엣것이 무시됐다 → 드롭다운이 화면 밖까지 벌어짐(jin 2026-07-28).
 */
class ComboboxAttributeMergeTest extends TestCase
{
    use RefreshDatabase;

    private function render(string $extraClass = ''): string
    {
        $options = collect([(object) ['id' => 1, 'name' => '바이어A']]);

        return (string) view('components.erp.combobox', [
            'model' => 'buyerFilter',
            'options' => $options,
            'selected' => '',
            'placeholder' => '검색',
            'disabled' => false,
            'required' => false,
            'attributes' => new ComponentAttributeBag(
                $extraClass !== '' ? ['class' => $extraClass] : []
            ),
        ])->render();
    }

    public function test_relative_survives_when_caller_passes_class(): void
    {
        $html = $this->render('w-44');
        $root = substr($html, 0, (int) strpos($html, '>'));

        // 루트 div 에 class 속성이 두 번 찍히면 뒤엣것이 무시된다 — 정확히 1개여야 한다.
        $this->assertSame(1, substr_count($root, 'class='));
        // 그 한 개 안에 둘 다 살아 있어야 한다(순서 무관).
        $this->assertStringContainsString('relative', $root,
            'relative 가 빠지면 드롭다운 absolute 가 엉뚱한 조상 기준이 돼 화면 밖으로 벌어진다');
        $this->assertStringContainsString('w-44', $root);
    }

    public function test_relative_present_when_caller_passes_no_class(): void
    {
        $this->assertStringContainsString('class="relative"', $this->render());
    }
}
