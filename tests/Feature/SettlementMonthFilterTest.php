<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2026-06-24 — 정산 월(월급 귀속월) 솔팅 검증.
 *
 * jin 결정: 정산 월 기준 = created_at(거래완료/정산 발생월). 월급 주기 = 1일~말일 일한 것 → 다음달 10일 지급.
 * 인원별 카드(salesmanSummaries) + 목록(settlements) 모두 동일 monthFilter 적용 (card SQL == list SQL).
 */
class SettlementMonthFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeManager(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function makeSettlementInMonth(string $ym, Salesman $salesman): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'MF-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => $ym.'-01',
            'purchase_date' => '2026-01-01',
            'salesman_id' => $salesman->id,
        ]);

        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'pending',
        ]);

        // created_at 을 대상 월로 강제 (Eloquent create 는 now() 고정 — SKILLS §8 #11).
        Settlement::where('id', $s->id)->update(['created_at' => $ym.'-15 10:00:00']);

        return $s->fresh();
    }

    public function test_month_filter_scopes_list_and_summary_identically(): void
    {
        $manager = $this->makeManager();
        $sm = Salesman::create(['name' => '월필터테스트', 'settlement_type' => 'ratio']);

        // 2026-04 에 2건, 2026-05 에 1건.
        $this->makeSettlementInMonth('2026-04', $sm);
        $this->makeSettlementInMonth('2026-04', $sm);
        $this->makeSettlementInMonth('2026-05', $sm);

        $component = Volt::actingAs($manager)->test('erp.settlements.index');

        // 전체 = 3건.
        $this->assertCount(3, $component->instance()->settlements()->items());

        // 2026-04 필터 → 목록 2건.
        $component->set('monthFilter', '2026-04');
        $this->assertCount(2, $component->instance()->settlements()->items());

        // 인원별 카드도 동일하게 2건으로 스코프 (card SQL == list SQL).
        $summaries = $component->instance()->salesmanSummaries();
        $this->assertCount(1, $summaries);
        $this->assertSame(2, $summaries[0]['count']);

        // 2026-05 필터 → 1건.
        $component->set('monthFilter', '2026-05');
        $this->assertCount(1, $component->instance()->settlements()->items());
        $this->assertSame(1, $component->instance()->salesmanSummaries()[0]['count']);
    }

    public function test_available_months_lists_distinct_created_at_months_desc(): void
    {
        $manager = $this->makeManager();
        $sm = Salesman::create(['name' => '월목록테스트', 'settlement_type' => 'ratio']);

        $this->makeSettlementInMonth('2026-03', $sm);
        $this->makeSettlementInMonth('2026-05', $sm);
        $this->makeSettlementInMonth('2026-05', $sm);

        $component = Volt::actingAs($manager)->test('erp.settlements.index');

        $this->assertSame(['2026-05', '2026-03'], $component->instance()->availableMonths());
    }
}
