<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * 🏷️ **필터칩공용** — 「누르면 걸리는 버튼」 한 벌 (jin 2026-11-09 기획 · 11-22 착수, 정본 `docs/design/list-screen-commons.md §4-B`).
 *
 * 화면은 **필터축**을 선언만 한다(키 · 동작 종류 · 선택지 · 어디에 거나). 클릭 규칙·URL 보존·쿼리 조립·겉모양은 여기와
 * `<x-erp.filter-chips>` 가 한다. 명칭 = 버튼 하나 「필터칩」 · 독립 묶음 「필터축」(§1-B).
 *
 * ```php
 * protected function filterChips(): array
 * {
 *     return [
 *         'active' => ['type' => 'single', 'column' => 'is_active', 'options' => [
 *             '1' => ['label' => __('common.active'), 'class' => 'badge badge-green'],
 *             '0' => ['label' => __('common.inactive'), 'class' => 'badge badge-gray'],
 *         ]],
 *         'over_cap' => ['type' => 'bool', 'label' => '2억 초과', 'class' => 'badge badge-red',
 *             'apply' => fn ($q) => $q->generalStock()->where('purchase_price', '>', Vehicle::GENERAL_STOCK_PRICE_CAP)],
 *     ];
 * }
 * ```
 * 동작 종류: `single`(하나만 켜짐, 다시 누르면 해제) · `multi`(누를 때마다 켜고 끔) · `bool`(켜기/끄기 하나).
 *   - `column` 만 주면 공용이 `where`/`whereIn` 을 만든다. SQL 이 컬럼 하나가 아니면 `apply` 클로저로 화면이 넘긴다
 *     (single: fn($q, $value) · multi: fn($q, array $values) · bool: fn($q)). 🚫 스코프 조건을 선언에 옮겨 적지 않는다 — 스코프를 부른다(§8 #44).
 *   - 선택지에 `count` 를 주면 칩에 건수가 붙는다. `hide_when_zero` 축은 건수 0 인 칩을 숨긴다(켜져 있는 칩은 남긴다).
 *   - `show` 가 false 인 축은 그리지도 걸지도 않는다(탭에 따라 뜨는 칩).
 *
 * 🏷️ **칩은 행 뱃지와 같은 모양이다**(jin 2026-11-20 «뱃지로 표시되는 건 그 뱃지 그대로 필터가 되었으면 해») — `class` 에 그 뱃지의
 *    클래스를 그대로 준다. 꺼짐 = 흐리게, 켜짐 = 테두리(컴포넌트가 붙인다).
 * 🔒 URL 로 들어온 값은 선언에 있는 축·선택지만 인정한다(밖의 값은 조용히 무시, §8 #26).
 * 🚫 프로퍼티와 같은 이름의 메서드 금지(§8 #32) — 프로퍼티 `chipFilters`, 메서드 `chipClick`/`chipOn`.
 */
trait ListFilters
{
    /** 축 => 값(single·bool 은 문자열, multi 는 배열). 주소창 `?f[active]=1` 로 남는다. */
    #[Url(as: 'f')]
    public array $chipFilters = [];

    /** @return array<string, array<string, mixed>> 필터축 선언 */
    abstract protected function filterChips(): array;

    /** 칩 클릭 — 동작 종류별 상태 전이. 선언에 없는 축·값은 무시한다. */
    public function chipClick(string $axis, string $value = '1'): void
    {
        $def = $this->filterChips()[$axis] ?? null;
        if ($def === null || ! $this->chipValueAllowed($def, $value)) {
            return;
        }
        $type = $def['type'] ?? 'single';
        if ($type === 'multi') {
            $cur = self::chipStrings($this->chipFilters[$axis] ?? []);
            $cur = in_array($value, $cur, true) ? array_values(array_diff($cur, [$value])) : [...$cur, $value];
            if ($cur === []) {
                unset($this->chipFilters[$axis]);
            } else {
                $this->chipFilters[$axis] = $cur;
            }
        } elseif ((string) ($this->chipFilters[$axis] ?? '') === $value) {
            unset($this->chipFilters[$axis]);   // 같은 칩을 다시 누르면 해제
        } else {
            $this->chipFilters[$axis] = $value;
        }
        $this->afterChipChanged();
    }

    /** 그 칩이 켜져 있나. */
    public function chipOn(string $axis, string $value = '1'): bool
    {
        $cur = $this->chipFilters[$axis] ?? null;

        return is_array($cur) ? in_array($value, self::chipStrings($cur), true) : ($cur !== null && is_scalar($cur) && (string) $cur === $value);
    }

    /** 칩이 바뀐 뒤 — 기본 = 1페이지로. computed 캐시를 쓰는 화면은 오버라이드해 unset 한다. */
    protected function afterChipChanged(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /** 걸려 있는 칩을 쿼리에 건다 — 축끼리는 AND. */
    protected function applyChipFilters(Builder $q): Builder
    {
        foreach ($this->filterChips() as $axis => $def) {
            if (($def['show'] ?? true) === false || ! array_key_exists($axis, $this->chipFilters)) {
                continue;
            }
            $type = $def['type'] ?? 'single';
            $raw = $this->chipFilters[$axis];
            if ($type === 'multi') {
                $values = array_values(array_filter(self::chipStrings($raw), fn ($v) => $this->chipValueAllowed($def, $v)));
                if ($values === []) {
                    continue;
                }
                isset($def['apply']) ? $def['apply']($q, $values) : $q->whereIn($def['column'], $values);

                continue;
            }
            $value = is_scalar($raw) ? (string) $raw : '';
            if (! $this->chipValueAllowed($def, $value)) {
                continue;
            }
            if ($type === 'bool') {
                isset($def['apply']) ? $def['apply']($q) : $q->where($def['column'], true);
            } else {
                isset($def['apply']) ? $def['apply']($q, $value) : $q->where($def['column'], $value);
            }
        }

        return $q;
    }

    /**
     * 컴포넌트가 그릴 모양 — 축마다 칩 목록(라벨·클래스·건수·켜짐).
     *
     * @return array<int, array{axis: string, chips: array<int, array<string, mixed>>}>
     */
    public function chipAxes(?array $only = null): array
    {
        $out = [];
        foreach ($this->filterChips() as $axis => $def) {
            if (($def['show'] ?? true) === false || ($only !== null && ! in_array($axis, $only, true))) {
                continue;
            }
            $options = ($def['type'] ?? 'single') === 'bool'
                ? ['1' => ['label' => $def['label'] ?? $axis, 'class' => $def['class'] ?? 'badge badge-gray', 'count' => $def['count'] ?? null, 'title' => $def['title'] ?? null]]
                : ($def['options'] ?? []);
            $chips = [];
            foreach ($options as $value => $opt) {
                $value = (string) $value;
                $opt = is_array($opt) ? $opt : ['label' => (string) $opt];
                $on = $this->chipOn($axis, $value);
                $count = $opt['count'] ?? null;
                if (($def['hide_when_zero'] ?? false) && $count !== null && (int) $count === 0 && ! $on) {
                    continue;
                }
                $chips[] = [
                    'value' => $value, 'label' => $opt['label'] ?? $value, 'class' => $opt['class'] ?? ($def['class'] ?? 'badge badge-gray'),
                    'count' => $count, 'title' => $opt['title'] ?? ($def['title'] ?? null), 'on' => $on,
                ];
            }
            if ($chips !== []) {
                $out[] = ['axis' => $axis, 'chips' => $chips];
            }
        }

        return $out;
    }

    /** 주소창으로 들어온 값은 모양을 믿지 않는다 — 스칼라만 문자열로(중첩 배열·null 은 버린다). */
    private static function chipStrings(mixed $raw): array
    {
        return array_values(array_map('strval', array_filter((array) $raw, 'is_scalar')));
    }

    private function chipValueAllowed(array $def, string $value): bool
    {
        return ($def['type'] ?? 'single') === 'bool' ? $value === '1' : array_key_exists($value, $def['options'] ?? []);
    }
}
