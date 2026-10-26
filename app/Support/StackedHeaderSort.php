<?php

namespace App\Support;

/**
 * 🔢 헤더 겹침 정렬 — 차량관리(jin 2026-10-19)와 같은 동작을 재고관리·채권관리가 공유한다
 *   (jin 2026-10-26 «차량관리에 적용된 내용을 보면 좋을 것 같아 · 채권관리탭에도»).
 *
 *   헤더 클릭 = 기본 정렬이면 그 칸 ▲ 로 시작, 이미 정렬 중이면 뒤에 ▲ 로 이어 붙는다(Shift 없음).
 *   같은 칸은 ▲ → ▼ → 빠짐. 첫 칸이 빠지면 다음 칸이 첫 정렬, 다 빠지면 기본 정렬(= 화면이 원래 쓰던 순서).
 *   `sortColumn === ''` 가 「기본 정렬」이다 — 쿼리는 그때 종전 orderBy 를 그대로 쓴다.
 *
 *   쓰는 컴포넌트가 정하는 것:
 *     - `sortableKeys()`  허용 키 목록 — 밖의 값은 조용히 무시(클라이언트가 보내는 값이라 §8 #26).
 *     - `applySortKey($q, $col, $dir)`  키 하나를 ORDER BY 로(관계 칸은 이름 서브쿼리, 계산 칸은 식).
 *     - `afterSortChanged()`  computed 캐시 unset + resetPage.
 *   ⚠️ 차량관리 `vehicles/index` 는 자체 구현(같은 규칙, 기본 = created_at desc) — 규칙을 바꾸면 둘 다 고칠 것.
 *   🚫 프로퍼티와 같은 이름의 메서드 금지(§8 #32) — 그래서 메서드는 clickSort/addSort/resetSort/setSort 다.
 */
trait StackedHeaderSort
{
    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    /** 2·3번째 정렬 키 — 각 원소 = ['col' => 키, 'dir' => asc|desc]. 첫 정렬 포함 최대 5단계. */
    public array $sortExtra = [];

    /** @return string[] 허용 키 */
    abstract protected function sortableKeys(): array;

    abstract protected function applySortKey($q, string $col, string $dir);

    protected function afterSortChanged(): void {}

    public function sortable(string $col): bool
    {
        return $col !== '' && in_array($col, $this->sortableKeys(), true);
    }

    public function isDefaultSort(): bool
    {
        return $this->sortColumn === '' && $this->sortExtra === [];
    }

    /** 헤더 클릭 — 기본이면 그 칸 ▲, 정렬 중인 칸이면 ▲ → ▼ → 빠짐, 새 칸이면 뒤에 ▲. */
    public function clickSort(string $col): void
    {
        if (! $this->sortable($col)) {
            return;
        }
        if ($this->isDefaultSort()) {
            $this->setSort($col);

            return;
        }
        if ($this->sortColumn === $col) {
            if ($this->sortDirection === 'asc') {
                $this->sortDirection = 'desc';
            } else {
                $next = array_shift($this->sortExtra);
                [$this->sortColumn, $this->sortDirection] = $next
                    ? [$next['col'], ($next['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc']
                    : ['', 'asc'];
            }
            $this->afterSortChanged();

            return;
        }
        $this->addSort($col);
    }

    public function resetSort(): void
    {
        [$this->sortColumn, $this->sortDirection, $this->sortExtra] = ['', 'asc', []];
        $this->afterSortChanged();
    }

    /** 겹친 키 이어 붙이기 — 이미 있으면 ▲ → ▼ → 빠짐, 처음이면 ▲ 로 맨 뒤에. */
    public function addSort(string $col): void
    {
        if (! $this->sortable($col) || $this->sortColumn === $col) {
            return;
        }
        foreach ($this->sortExtra as $i => $k) {
            if (($k['col'] ?? null) === $col) {
                if (($k['dir'] ?? 'asc') === 'asc') {
                    $this->sortExtra[$i]['dir'] = 'desc';
                } else {
                    unset($this->sortExtra[$i]);
                    $this->sortExtra = array_values($this->sortExtra);
                }
                $this->afterSortChanged();

                return;
            }
        }
        if (count($this->sortExtra) >= 4) {
            return;
        }
        $this->sortExtra[] = ['col' => $col, 'dir' => 'asc'];
        $this->afterSortChanged();
    }

    /** 개별 정렬 — 겹친 키를 비우고 그 칸만. 같은 칸이면 방향 뒤집기. */
    public function setSort(string $col): void
    {
        if (! $this->sortable($col)) {
            return;
        }
        $this->sortExtra = [];
        if ($this->sortColumn === $col) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            [$this->sortColumn, $this->sortDirection] = [$col, 'asc'];
        }
        $this->afterSortChanged();
    }

    /** 헤더 표시용 — 첫 정렬 + 겹친 키. 기본 정렬이면 []. */
    public function sortKeysForHeader(): array
    {
        if ($this->sortColumn === '') {
            return [];
        }

        return array_merge([['col' => $this->sortColumn, 'dir' => $this->sortDirection]], $this->sortExtra);
    }

    /** 헤더 칸에 붙일 표식 — '▲' / '▼' / 겹침이면 '2▲' 처럼 순번. 정렬 안 된 칸은 ''. */
    public function sortIndicator(string $col): string
    {
        $keys = $this->sortKeysForHeader();
        foreach ($keys as $i => $k) {
            if (($k['col'] ?? null) === $col) {
                $ind = (($k['dir'] ?? 'asc') === 'asc') ? '▲' : '▼';

                return count($keys) > 1 ? ($i + 1).$ind : $ind;
            }
        }

        return '';
    }

    /**
     * 쿼리에 정렬 적용 — 기본 정렬이면 손대지 않고 그대로 돌려준다(호출측이 종전 orderBy 를 쓴다).
     * 겹친 키는 화이트리스트를 다시 본다(클라이언트가 보낼 수 있는 값). 끝에 id 로 동점을 고정(§8 #92).
     */
    protected function applyStackedSort($q, string $idColumn = 'id')
    {
        if ($this->sortColumn === '' || ! $this->sortable($this->sortColumn)) {
            return $q;
        }
        $dir = $this->sortDirection === 'desc' ? 'desc' : 'asc';
        $q = $this->applySortKey($q, $this->sortColumn, $dir);
        foreach ($this->sortExtra as $k) {
            if (is_array($k) && is_string($k['col'] ?? null) && $k['col'] !== $this->sortColumn && $this->sortable($k['col'])) {
                $q = $this->applySortKey($q, $k['col'], (($k['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc');
            }
        }

        return $q->orderBy($idColumn, $dir);
    }

    /** 빈 값(NULL·'')을 방향과 무관하게 뒤로 보내는 ORDER BY 조각 — 날짜 칸은 `= ''` 비교를 넣지 말 것(MySQL 이 거부). */
    protected function emptyLastExpression(string $col, bool $text): string
    {
        return $text ? "({$col} IS NULL OR {$col} = '') ASC" : "{$col} IS NULL ASC";
    }
}
