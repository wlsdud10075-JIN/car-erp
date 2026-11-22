<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🧩 공용 부품이 「있어도 안 쓰면 그만」이 되지 않게 잡는 정적 가드 (jin 2026-11-09 «새 화면에 기본으로» · 11-22).
 *
 * 왜 정적 검사인가: 화면이 검색칸·정렬 버튼·칩을 손으로 다시 써도 **화면은 멀쩡히 동작한다.** 달라지는 건 「규칙이 한 곳에 있나」뿐이라
 * 기능 테스트로는 못 잡는다. 새 목록 화면을 만들면 여기에 걸리고, 그때 부품을 쓰거나 예외에 사유를 적게 된다.
 */
class ListCommonsGuardTest extends TestCase
{
    /** 검색칸을 손으로 그려도 되는 화면 — 사유와 함께. */
    private const RAW_SEARCH_ALLOWED = [
        'erp/vehicles/index.blade.php' => '검색칸이 셋(차번호·차대번호 끝자리·누적 검색)이고 실행 메서드가 applyFilters — 차량관리 이관은 별도 단계',
        'erp/buyer-account/index.blade.php' => '바이어 정산현황 — 검색 조건이 여러 칸(기간·바이어·통화)이라 검색칸 하나짜리 부품과 모양이 다르다 — 다음 묶음',
        'erp/transfers/index.blade.php' => '탭마다 검색 규칙이 다르고 입력칸 모양이 다르다(input-base) — 다음 묶음',
    ];

    /** @return array<string, string> 상대경로 => 소스 */
    private function erpScreens(): array
    {
        $out = [];
        $base = resource_path('views/livewire');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/erp', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.blade.php')) {
                $out[str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($base) + 1))] = file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    /** 목록 검색칸(`wire:model="search"`)은 `<x-erp.search-bar>` 로 그린다 — 「치는 동안 조회 안 함」·Enter·[검색] 이 한 곳에 있다. */
    public function test_list_search_inputs_go_through_the_search_bar_component(): void
    {
        $offenders = [];
        foreach ($this->erpScreens() as $rel => $src) {
            if (isset(self::RAW_SEARCH_ALLOWED[$rel])) {
                continue;
            }
            if (preg_match('/<input\b[^>]*wire:model(\.[a-z.0-9]+)?="search"/s', $src)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, '검색칸을 손으로 그린 화면 — <x-erp.search-bar> 를 쓰거나 RAW_SEARCH_ALLOWED 에 사유를 적는다');
    }

    /** 검색공용을 쓰는 화면은 검색 사슬을 다시 쓰지 않는다(선언 `searchColumns()` 또는 `applySearch()` 오버라이드 한 곳). */
    public function test_screens_using_list_search_do_not_rewrite_the_like_chain(): void
    {
        $offenders = [];
        foreach ($this->erpScreens() as $rel => $src) {
            if (! str_contains($src, 'use ListSearch;')) {
                continue;
            }
            $this->assertStringContainsString('function searchColumns(): array', $src, "{$rel}: searchColumns() 선언이 없다");
            if (preg_match('/SearchTerm::like\(\$this->search\)/', $src)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, 'ListSearch 를 쓰면서 `like` 사슬을 직접 쓴 화면 — applySearch($q) 를 부른다');
    }

    /** 정렬 헤더는 `sortTh()` 로 — 화면에서 버튼 HTML(`$sortBtn` 클로저)을 다시 쓰지 않는다. 차량관리 자체 구현은 별도 단계. */
    public function test_sort_headers_use_the_shared_component(): void
    {
        foreach ($this->erpScreens() as $rel => $src) {
            if ($rel === 'erp/vehicles/index.blade.php' || ! str_contains($src, 'use StackedHeaderSort;')) {
                continue;
            }
            $this->assertStringNotContainsString('$sortBtn', $src, "{$rel}: 정렬 버튼을 손으로 그렸다 — \$this->sortTh() 를 쓴다");
            $this->assertStringContainsString('sortTh(', $src, "{$rel}: StackedHeaderSort 를 쓰는데 정렬 헤더가 없다");
        }
        $this->assertFileExists(resource_path('views/components/erp/sort-th.blade.php'));
    }

    /** 필터칩공용을 쓰는 화면은 칩을 컴포넌트로 그린다 — 선언(`filterChips()`)만 있고 화면에 안 그리면 주소창으로만 걸리는 숨은 필터가 된다. */
    public function test_screens_declaring_chips_also_render_them(): void
    {
        foreach ($this->erpScreens() as $rel => $src) {
            if (! str_contains($src, 'use ListFilters;')) {
                continue;
            }
            $this->assertStringContainsString('<x-erp.filter-chips', $src, "{$rel}: filterChips() 선언은 있는데 칩을 안 그린다");
            $this->assertStringContainsString('applyChipFilters(', $src, "{$rel}: 칩을 그리는데 쿼리에 안 건다 — 눌러도 아무 일이 없는 칩");
        }
    }

    /**
     * 🔽 드롭박스콤보공용 — **바이어를 고르는 칸**은 `<select>` 가 아니라 `<x-erp.combobox>`(치면서 찾기)로 그린다 (2026-11-22).
     * 바이어는 수백 명이라 select 로는 찾기 어렵다. 담당자(수십 명·「미지정」 같은 특수 선택지)는 select 로 둔다 — 다음 묶음에서 다시 본다.
     */
    public function test_buyer_pickers_use_the_combobox(): void
    {
        $offenders = [];
        foreach ($this->erpScreens() as $rel => $src) {
            if (preg_match_all('/<select\b[^>]*wire:model(?:\.[a-z.0-9]+)?="(buyer_id[a-z_]*|buyerFilter|buyerId)"/s', $src, $m)) {
                $offenders[] = $rel.' ('.implode(', ', $m[1]).')';
            }
        }

        $this->assertSame([], $offenders, '바이어 고르기 칸을 <select> 로 그린 화면 — <x-erp.combobox> 를 쓴다');
    }

    /** 사이드 창(드로어) 폭은 차량 편집 패널과 같다 — 820px(작은 화면 700px). jin 2026-11-22 «전부 820px 통일». */
    public function test_side_panels_share_one_width(): void
    {
        $narrow = [];
        foreach ($this->erpScreens() as $rel => $src) {
            if (preg_match_all('/fixed inset-y-0 right-0[^"]*sm:w-\[(\d+)px\]/', $src, $m)) {
                foreach ($m[1] as $w) {
                    if ((int) $w !== 700) {
                        $narrow[] = "{$rel} ({$w}px)";
                    }
                }
            }
        }

        $this->assertSame([], $narrow, '폭이 다른 사이드 창 — `sm:w-[700px] lg:w-[820px]` 로 맞춘다');
    }
}
