<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 정산관리 「영업담당자별 합계」는 **펼칠 때만 계산**한다 — 정적 가드.
 *
 * 🚨 이 집계는 필터에 걸린 **정산 전부를 순회**하며 행마다 총마진·정산액·실지급액 accessor 를 돈다.
 *    실측(ssancarerp 3,815건) 7.9 초. 그리고 이 화면은 `wire:poll.30s` 라 **30초마다 반복**된다.
 *
 * 🔑 **Alpine 으로 숨기는 것으로는 안 줄어든다** — 서버가 HTML 을 다 만들어 보낸다.
 *    접힘이 **서버 상태**(`$showSummaries`)여야 Blade 가 `$this->salesmanSummaries` 를
 *    아예 안 부르고, 그래야 `#[Computed]` 가 안 돈다.
 *
 * ⚠️ **기능 테스트로는 원리상 못 잡는다** — 어느 쪽이든 화면은 정상 렌더된다.
 *    누가 기본값을 `true` 로 되돌리거나 `@if` 를 걷어내도 눈에 안 띈다. 그래서 정적 검사다.
 */
class SettlementSummaryLazyTest extends TestCase
{
    use RefreshDatabase;

    private function source(): string
    {
        $path = resource_path('views/livewire/erp/settlements/index.blade.php');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_summary_section_is_collapsed_by_default(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+bool\s+\$showSummaries\s*=\s*false\s*;/',
            $this->source(),
            '담당자별 합계는 **기본 접힘**이어야 한다. true 로 되돌리면 첫 화면에서 전 정산을 순회한다.'
        );
    }

    public function test_summary_is_only_computed_when_expanded(): void
    {
        $src = $this->source();

        // `$this->salesmanSummaries` 를 읽는 모든 지점은 `@if($showSummaries)` 안에 있어야 한다.
        // (unset(...) 으로 캐시를 비우는 것은 계산이 아니므로 제외한다.)
        $reads = [];
        foreach (explode("\n", $src) as $i => $line) {
            if (! str_contains($line, '$this->salesmanSummaries')) {
                continue;
            }
            // 주석은 계산이 아니다 — 이 파일은 왜 접는지를 주석으로도 설명한다.
            $t = ltrim($line);
            if (str_contains($line, 'unset(') || str_starts_with($t, '*') || str_starts_with($t, '//')
                || str_starts_with($t, '{{--') || str_starts_with($t, '/**')) {
                continue;
            }
            $reads[] = $i + 1;
        }
        $this->assertNotEmpty($reads, '요약을 읽는 지점이 사라졌다 — 이 가드가 무의미해졌는지 확인할 것.');

        $guardLine = null;
        foreach (explode("\n", $src) as $i => $line) {
            if (str_contains($line, '@if($showSummaries)')) {
                $guardLine = $i + 1;
                break;
            }
        }
        $this->assertNotNull($guardLine, '`@if($showSummaries)` 가드가 없다 — 접혀 있어도 계산이 돈다.');

        foreach ($reads as $line) {
            $this->assertGreaterThan(
                $guardLine,
                $line,
                "{$line}행이 `@if(\$showSummaries)`({$guardLine}행) 밖에서 요약을 읽는다 — 접어도 계산이 돈다."
            );
        }
    }

    public function test_heavy_summary_query_eager_loads_the_relations_its_accessors_read(): void
    {
        $src = $this->source();

        // 담당자별 합계의 `sum('actual_payout')` 은 총마진 → 정산환율 → **미수** 를 타고,
        // 미수가 차량의 잔금·회수이력을 읽는다. 안 실으면 행마다 2쿼리가 붙는다
        // (실측 3,815건 = 7,837쿼리 / 19.3초 → 실으면 209쿼리 / 7.9초).
        $this->assertStringContainsString(
            "->with(['vehicle.finalPayments', 'vehicle.receivableHistories', 'salesman'])",
            $src,
            '담당자별 합계가 잔금·회수이력을 eager load 해야 한다. 빼면 행마다 2쿼리가 되살아난다.'
        );
    }
}
