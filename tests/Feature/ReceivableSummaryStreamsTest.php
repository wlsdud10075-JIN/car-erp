<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 채권관리 KPI(summary) 는 판매 차량 **전량**을 합산한다(날짜 기본값 없음 — ssancarerp 5,000대+).
 * `get()->sum(접근자)` 로 한 번에 올리면 fpm 128MB 가 터진다(2026-10-26 ssancarerp 실사고, 사용자 3명 8회 500).
 * 접근자는 단일 출처라 SQL 로 옮기지 않고(SKILLS §13 — 직접 SQL 합산 금지) 묶음으로 흘려 더한다.
 */
class ReceivableSummaryStreamsTest extends TestCase
{
    public function test_summary_streams_instead_of_loading_every_vehicle(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/receivables/index.blade.php'));
        $start = strpos($src, 'public function summary(): array');
        $end = strpos($src, '#[Computed]', $start + 1);
        $body = substr($src, $start, $end - $start);

        $this->assertStringNotContainsString('->get()->sum(', $body, 'summary() 가 차량 전량을 메모리에 올린다 — lazyById 로 흘릴 것');
        $this->assertGreaterThanOrEqual(5, substr_count($body, '->lazyById(500)->sum('));
    }
}
