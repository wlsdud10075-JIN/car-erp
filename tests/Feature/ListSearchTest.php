<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Support\ListSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔎 검색공용(`ListSearch`) — 화면은 `searchColumns()` 한 배열만 선언하고 사슬은 부품이 조립한다 (jin 2026-11-09 기획 · 11-22).
 *
 * 지키는 것: ①자기 컬럼 = orWhere ②「관계.컬럼」 = orWhereHas(여러 단계 포함) ③빈 검색어·공백뿐이면 조건을 안 건다
 *            ④앞뒤 공백을 잘라 낸다(복사해 붙인 이메일 끝 공백, SearchTerm) ⑤한 덩어리로 감싸 다른 조건과 AND 로 붙는다
 *            ⑥검색·지우기는 afterSearch() 를 부른다(1페이지로) ⑦훅 오버라이드
 */
class ListSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): array
    {
        $kim = Salesman::create(['name' => '김영업', 'type' => 'employee', 'is_active' => true]);
        $lee = Salesman::create(['name' => '이프리', 'type' => 'freelance', 'is_active' => true]);
        $a = Buyer::create(['name' => 'ADRIA CARS', 'contact_email' => 'adria@example.com', 'is_active' => true, 'salesman_id' => $kim->id]);
        $b = Buyer::create(['name' => 'BALTIC AUTO', 'contact_email' => 'baltic@example.com', 'is_active' => false, 'salesman_id' => $lee->id]);
        $v1 = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'buyer_id' => $a->id, 'salesman_id' => $kim->id, 'dhl_request' => false]);
        $v2 = Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export', 'buyer_id' => $b->id, 'salesman_id' => $lee->id, 'dhl_request' => false]);

        return [$a, $b, $v1, $v2];
    }

    public function test_own_columns_and_relation_columns(): void
    {
        [$a, $b, $v1, $v2] = $this->seedData();

        $names = fn (string $term, array $cols) => ListSearch::applySearchColumns(Buyer::query(), $term, $cols)->orderBy('id')->pluck('name')->all();
        $this->assertSame(['ADRIA CARS'], $names('adria', ['name']));
        $this->assertSame(['BALTIC AUTO'], $names('baltic@', ['name', 'contact_email']));
        $this->assertSame(['BALTIC AUTO'], $names('이프리', ['name', 'salesman.name']), '「관계.컬럼」은 orWhereHas');
        $this->assertSame([], $names('이프리', ['name']), '선언에 없는 관계는 안 뒤진다');

        // 두 단계 관계 — 차량 → 바이어 → 담당자
        $plates = ListSearch::applySearchColumns(Vehicle::query(), '김영업', ['vehicle_number', 'buyer.salesman.name'])->pluck('vehicle_number')->all();
        $this->assertSame(['11가1111'], $plates);
    }

    public function test_blank_term_adds_no_condition_and_spaces_are_trimmed(): void
    {
        $this->seedData();

        foreach (['', '   ', null] as $blank) {
            $this->assertSame(2, ListSearch::applySearchColumns(Buyer::query(), $blank, ['name'])->count());
        }
        $this->assertSame(1, ListSearch::applySearchColumns(Buyer::query(), '  adria@example.com ', ['contact_email'])->count(), '붙여넣기 끝 공백');
        $this->assertSame(2, ListSearch::applySearchColumns(Buyer::query(), 'adria', [])->count(), '선언이 비면 조건 없음');
    }

    /** 검색은 한 덩어리 — 권한 범위·필터 같은 앞 조건을 OR 로 풀어 버리지 않는다. */
    public function test_search_is_one_group_anded_with_other_conditions(): void
    {
        $this->seedData();

        $q = ListSearch::applySearchColumns(Buyer::query()->where('is_active', true), 'a', ['name', 'contact_email']);

        $this->assertSame(['ADRIA CARS'], $q->pluck('name')->all(), '비활성 BALTIC 이 OR 로 새어 들어왔다');
    }

    public function test_component_flow_search_clear_and_hooks(): void
    {
        $this->seedData();
        $screen = new class
        {
            use ListSearch;

            public int $resets = 0;

            public function resetPage(): void
            {
                $this->resets++;
            }

            protected function searchColumns(): array
            {
                return ['name', 'salesman.name'];
            }

            public function rows(): array
            {
                return $this->applySearch(Buyer::query())->orderBy('id')->pluck('name')->all();
            }
        };

        $this->assertCount(2, $screen->rows());
        $screen->search = '김영업 ';
        $screen->searchNow();
        $this->assertSame(['ADRIA CARS'], $screen->rows());
        $this->assertSame(1, $screen->resets, '검색하면 1페이지로');

        $screen->clearSearch();
        $this->assertSame('', $screen->search);
        $this->assertCount(2, $screen->rows());
        $this->assertSame(2, $screen->resets);

        // 선언으로 안 되는 화면은 applySearch 를 덮어쓴다(차량관리 차대번호 끝자리 등)
        $special = new class
        {
            use ListSearch;

            protected function searchColumns(): array
            {
                return [];
            }

            protected function applySearch(Builder $q): Builder
            {
                return $this->search === '' ? $q : $q->where('contact_email', 'like', '%'.$this->search);
            }

            public function rows(): array
            {
                return $this->applySearch(Buyer::query())->pluck('name')->all();
            }
        };
        $special->search = 'baltic@example.com';
        $this->assertSame(['BALTIC AUTO'], $special->rows());
    }
}
