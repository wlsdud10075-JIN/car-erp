<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 재고관리·채권관리 헤더 정렬 버튼 ↔ SORTABLE 정적 대조 (2026-10-26, `VehicleSortableColumnsTest` 와 같은 뜻).
 * `clickSort()` 는 허용 밖 키를 **조용히 무시**한다 — 버튼을 추가하고 목록에 안 넣으면 눌러도 아무 일이 없는 버튼이 된다(§8 #32 부류).
 */
class HeaderSortButtonsWhitelistedTest extends TestCase
{
    public static function views(): array
    {
        return [
            'inventory' => ['resources/views/livewire/erp/inventory/index.blade.php', '/private const SORTABLE = \[(.*?)\n    \];/s', "/'([a-z_]+)' => /"],
            'receivables' => ['resources/views/livewire/erp/receivables/index.blade.php', '/private const SORTABLE = \[(.*?)\];/s', "/'([a-z_]+)'/"],
        ];
    }

    #[DataProvider('views')]
    public function test_every_sort_button_key_is_in_sortable(string $view, string $listPattern, string $keyPattern): void
    {
        $src = file_get_contents(base_path($view));
        preg_match_all("/\\\$sortBtn\('([a-z_]+)'/", $src, $buttons);
        $this->assertNotEmpty($buttons[1], '정렬 버튼을 하나도 못 찾았다');
        preg_match($listPattern, $src, $list);
        $this->assertNotEmpty($list, 'SORTABLE 을 찾지 못했다');
        preg_match_all($keyPattern, $list[1], $keys);

        $dead = array_diff(array_unique($buttons[1]), $keys[1]);
        $this->assertSame([], array_values($dead), '정렬 버튼은 있는데 SORTABLE 에 없다(죽은 버튼): '.implode(', ', $dead));
        $this->assertStringContainsString('use StackedHeaderSort;', $src);
    }
}
