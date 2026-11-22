<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * 🔎 **검색공용** — 목록 화면의 검색칸 동작 한 벌 (jin 2026-11-09 기획 · 11-22 착수, 정본 `docs/design/list-screen-commons.md §4`).
 *
 * 검색이 화면마다 달라 보이는 건 「어느 칸을 뒤지나」 하나뿐이다. 나머지(프로퍼티·실행 메서드·정규화·사슬 조립)는 여기서 한다.
 *
 *   화면이 선언하는 것 = `searchColumns()` 한 배열.
 *     ['name', 'contact_email', 'buyer.name', 'vehicle.salesman.name']
 *      └ 자기 컬럼 = orWhere          └ 「관계.컬럼」 = orWhereHas(관계, 컬럼 like)  (점이 여럿이면 마지막이 컬럼)
 *   ⚠️ **표시되는 그 관계만** 적는다 — 통관·선적 바이어까지 넣으면 A 로 표시된 행이 B 로 검색된다(재고관리의 교훈).
 *
 *   선언으로 안 되는 화면(탭마다 규칙이 다르다 · 숫자 끝자리 · 누적 검색)은 `applySearch()` 를 오버라이드한다.
 *   검색 뒤에 할 일이 더 있으면(완납 탭으로 건너뛰기 등) `afterSearch()` 를 오버라이드한다.
 *
 * 🚫 치는 동안 조회하지 않는다 — 입력칸은 `wire:model="search"`(deferred) + Enter/[검색] → `searchNow()` (`SearchRequiresButtonTest`).
 * 🚫 프로퍼티와 같은 이름의 메서드 금지(§8 #32) — 그래서 실행 메서드는 `search()` 가 아니라 `searchNow()` 다.
 * 🚫 프로퍼티를 trim 하지 않는다 — 자르는 곳은 쿼리에 넣는 지점(`SearchTerm`).
 */
trait ListSearch
{
    /** 주소창에 남는다 — 새로고침·링크 공유 때 검색이 유지된다. */
    #[Url]
    public string $search = '';

    /** @return string[] 검색 대상 — 자기 컬럼 또는 「관계.컬럼」 */
    abstract protected function searchColumns(): array;

    /** [검색]·Enter — 치는 동안은 조회하지 않는다. */
    public function searchNow(): void
    {
        $this->afterSearch();
    }

    /** [지우기] — 검색어를 비우고 다시 조회한다. */
    public function clearSearch(): void
    {
        $this->search = '';
        $this->afterSearch();
    }

    /** 검색 조건이 바뀐 뒤 — 기본 = 1페이지로. computed 캐시를 쓰는 화면은 오버라이드해 unset 한다. */
    protected function afterSearch(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /** 쿼리에 검색을 건다. 빈 검색어면 그대로 돌려준다. */
    protected function applySearch(Builder $q): Builder
    {
        return self::applySearchColumns($q, $this->search, $this->searchColumns());
    }

    /**
     * 선언 → `where(fn => orWhere … orWhereHas …)` 사슬. 한 덩어리로 감싸므로 앞뒤의 다른 조건(권한 범위·필터)과 AND 로 붙는다.
     *
     * @param  string[]  $columns
     */
    public static function applySearchColumns(Builder $q, ?string $raw, array $columns): Builder
    {
        if (SearchTerm::of($raw) === '' || $columns === []) {
            return $q;
        }
        $like = SearchTerm::like($raw);

        return $q->where(function (Builder $w) use ($columns, $like) {
            foreach ($columns as $col) {
                $dot = strrpos($col, '.');
                if ($dot === false) {
                    $w->orWhere($col, 'like', $like);

                    continue;
                }
                [$relation, $field] = [substr($col, 0, $dot), substr($col, $dot + 1)];
                $w->orWhereHas($relation, fn (Builder $r) => $r->where($field, 'like', $like));
            }
        });
    }
}
