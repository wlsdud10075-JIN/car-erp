<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

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
    /**
     * 🧭 **업무 순서** — 상태·단계 칸은 글자순이 아니라 일이 흘러가는 순서로 선다 (jin 2026-11-22 «바꿔»).
     *    글자순이면 정산 「대기」(pending)가 맨 뒤, 진행은 「거래완료」가 맨 앞(가나다)이 된다.
     *    진행 10단계 = v4 cascade 순서(CLAUDE.md 「차량 진행상태」). 단계가 늘면 여기에 넣는다 — 없는 값은 뒤로 간다.
     */
    public const PROGRESS_ORDER = ['매입중', '매입완료', '말소완료', '판매중', '판매완료', '선적중', '선적완료', '통관중', '통관완료', '거래완료'];

    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    /** 2·3번째 정렬 키 — 각 원소 = ['col' => 키, 'dir' => asc|desc]. 첫 정렬 포함 최대 5단계. */
    public array $sortExtra = [];

    /** @return string[] 허용 키 */
    abstract protected function sortableKeys(): array;

    abstract protected function applySortKey($q, string $col, string $dir);

    protected function afterSortChanged(): void {}

    /**
     * 🔽 **첫 클릭에 큰 것부터(▼)** 서는 칸 — 금액 칸 (jin 2026-11-22 «두 번 눌러야 최고 금액이 맨 위로 온다»).
     *    글자·날짜 칸은 ▲ → ▼ → 빠짐, 여기 적힌 칸은 ▼ → ▲ → 빠짐. 겹쳐 붙을 때도 ▼ 로 붙는다(담당자 1▲ → 미수금 2▼).
     *    차량관리(자체 구현)는 `DESC_FIRST` 상수가 같은 일을 한다.
     *
     * @return string[]
     */
    protected function descFirstKeys(): array
    {
        return [];
    }

    private function firstDir(string $col): string
    {
        return in_array($col, $this->descFirstKeys(), true) ? 'desc' : 'asc';
    }

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
            if ($this->sortDirection === $this->firstDir($col)) {
                $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
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
                if (($k['dir'] ?? 'asc') === $this->firstDir($col)) {
                    $this->sortExtra[$i]['dir'] = $this->firstDir($col) === 'asc' ? 'desc' : 'asc';
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
        $this->sortExtra[] = ['col' => $col, 'dir' => $this->firstDir($col)];
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
            [$this->sortColumn, $this->sortDirection] = [$col, $this->firstDir($col)];
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
    /**
     * 🔼🔽 **정렬 헤더 한 칸** — th 안에 `{{ $this->sortTh('name', __('buyer.col_name')) }}` 한 줄 (헤더정렬공용 겉모양, 2026-11-22).
     * 화면마다 `$sortBtn` 클로저로 버튼 HTML 을 다시 쓰던 것을 `<x-erp.sort-th>` 한 곳으로 모았다.
     * 허용 키가 아니면 글자만 찍는다(눌러도 안 되는 버튼을 그리지 않는다 — 08-09 차량관리 정렬 버튼 4개 사고).
     */
    public function sortTh(string $col, string $label, string $align = 'left', string $extra = ''): HtmlString
    {
        if (! $this->sortable($col)) {
            return new HtmlString(e($label));
        }

        return new HtmlString(view('components.erp.sort-th', [
            'col' => $col, 'label' => $label, 'align' => $align, 'extra' => $extra, 'indicator' => $this->headerIndicator($col),
        ])->render());
    }

    /** 헤더에 찍을 표시 — 기본 정렬일 때 종전 기준 칸에 ▼ 를 보여 주는 화면(채권관리)은 오버라이드한다. */
    protected function headerIndicator(string $col): string
    {
        return $this->sortIndicator($col);
    }

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

    /**
     * 선언 한 줄을 ORDER BY 로 — 화면마다 같은 분기를 다시 쓰지 않게 (2026-11-22 정렬 헤더를 다섯 화면에 붙이며).
     *   ['col', 칼럼]            숫자·날짜·불리언 칸
     *   ['text', 칼럼]           글자 칸(빈 문자열도 뒤로)
     *   ['rel', 표, 외래키, 칸?]  다른 표의 값(기본 name) — 예: ['rel', 'buyers', 'consignees.buyer_id']
     *   ['order', 식, 값 목록]    업무 순서 — 목록에 적힌 차례대로(없는 값·빈 값은 뒤). 식은 칼럼 또는 서브쿼리
     *   ['expr', 식]             두 값으로 가르는 식 — 예: ['expr', '(confirmed_at IS NOT NULL)'] = ▲ 대기 먼저 · ▼ 확정 먼저
     *   ['rev', 칼럼]            헤더 뜻과 칼럼 방향이 반대인 칸 — 예: 「경과」(▲ 짧은 것 = 최근 시각부터)
     * 빈 값은 방향과 무관하게 뒤로 보낸다. 🚫 선언은 코드 상수만 — 화면에서 온 값을 넣지 말 것(raw SQL 이다).
     */
    protected function applySortSpec($q, array $spec, string $dir)
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        if ($spec[0] === 'order') {
            return $this->orderByListed($q, $spec[1], $spec[2], $dir);
        }
        if ($spec[0] === 'expr') {
            return $q->orderByRaw("{$spec[1]} {$dir}");
        }
        if ($spec[0] === 'rev') {
            return $q->orderByRaw($this->emptyLastExpression($spec[1], false))->orderBy($spec[1], $dir === 'asc' ? 'desc' : 'asc');
        }
        if ($spec[0] === 'rel') {
            $expr = '(SELECT '.($spec[3] ?? 'name')." FROM {$spec[1]} WHERE {$spec[1]}.id = {$spec[2]} LIMIT 1)";

            return $q->orderByRaw("{$expr} IS NULL ASC")->orderByRaw("{$expr} {$dir}");
        }

        return $q->orderByRaw($this->emptyLastExpression($spec[1], $spec[0] === 'text'))->orderBy($spec[1], $dir);
    }

    /** 목록에 적힌 차례대로 줄 세우기 — 목록에 없는 값·빈 값은 방향과 무관하게 뒤. 값은 바인딩으로 넣는다. */
    protected function orderByListed($q, string $expr, array $values, string $dir)
    {
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $whens = implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys(array_values($values))));
        $case = "(CASE {$expr} {$whens} ELSE NULL END)";

        return $q->orderByRaw("{$case} IS NULL ASC", array_values($values))->orderByRaw("{$case} {$dir}", array_values($values));
    }

    /** 빈 값(NULL·'')을 방향과 무관하게 뒤로 보내는 ORDER BY 조각 — 날짜 칸은 `= ''` 비교를 넣지 말 것(MySQL 이 거부). */
    protected function emptyLastExpression(string $col, bool $text): string
    {
        return $text ? "({$col} IS NULL OR {$col} = '') ASC" : "{$col} IS NULL ASC";
    }
}
