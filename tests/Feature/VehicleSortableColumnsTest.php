<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 정렬 버튼 ↔ 화이트리스트 정적 대조 (2026-08-09).
 *
 * `setSort()` 는 `SORTABLE_COLUMNS` 밖의 컬럼이 오면 **조용히 return** 한다 — 에러도 로그도 없다.
 * 그래서 헤더에 `$sortBtn('x', …)` 을 추가하고 화이트리스트에 안 넣으면 **눌러도 아무 일이 없는 버튼**이 된다.
 * 실제로 2026-08-09 시점에 `eta_date`·`deregistration_date`·`nice_reg_vin`·`purchase_from` 4개가 그 상태였다.
 *
 * ⚠️ 기능 테스트로는 원리상 못 잡는다 — 화면은 정상 렌더되고 클릭도 예외 없이 지나간다(SKILLS §8 #32 부류).
 */
class VehicleSortableColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/livewire/erp/vehicles/index.blade.php';

    public function test_every_sort_button_column_is_whitelisted(): void
    {
        $source = file_get_contents(base_path(self::VIEW));
        $this->assertNotFalse($source, '차량목록 뷰를 읽지 못했다');

        preg_match_all("/\\\$sortBtn\('([a-z_]+)'/", $source, $buttons);
        $buttonColumns = array_values(array_unique($buttons[1]));
        $this->assertNotEmpty($buttonColumns, '정렬 버튼을 하나도 못 찾았다 — 헬퍼 이름이 바뀌었는지 확인');

        preg_match('/private const SORTABLE_COLUMNS = \[(.*?)\];/s', $source, $listMatch);
        $this->assertNotEmpty($listMatch, 'SORTABLE_COLUMNS 를 찾지 못했다');
        preg_match_all("/'([a-z_]+)'/", $listMatch[1], $whitelisted);

        $dead = array_diff($buttonColumns, $whitelisted[1]);

        $this->assertSame([], array_values($dead),
            '정렬 버튼은 있는데 SORTABLE_COLUMNS 에 없다 — 눌러도 아무 일이 안 일어나는 죽은 버튼: '
            .implode(', ', $dead));
    }

    /** 화이트리스트 컬럼이 실제 DB 컬럼인지 — 오타가 있으면 정렬이 SQL 에러를 낸다. */
    public function test_whitelisted_columns_exist_on_the_table(): void
    {
        $source = file_get_contents(base_path(self::VIEW));
        preg_match('/private const SORTABLE_COLUMNS = \[(.*?)\];/s', $source, $listMatch);
        preg_match_all("/'([a-z_]+)'/", $listMatch[1], $whitelisted);

        $columns = Schema::getColumnListing('vehicles');
        $this->assertNotEmpty($columns, 'vehicles 스키마를 읽지 못했다');

        foreach (array_unique($whitelisted[1]) as $col) {
            $this->assertContains($col, $columns, "SORTABLE_COLUMNS 의 '{$col}' 은 vehicles 테이블에 없다");
        }
    }
}
