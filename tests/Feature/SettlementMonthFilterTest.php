<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

        // 전체 = 3건. ⚠️ 2026-08-28 부터 월 필터에 **이번 달 기본값**이 붙었으므로
        //   「전 기간」을 보려면 명시적으로 비운다(그 동작이 남아 있는지도 함께 검증한다).
        $component->set('monthFilter', '');
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

    private function makeSettlementOnDate(string $date, Salesman $salesman, ?string $confirmedAt = null): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'MD-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => substr($date, 0, 7).'-01',
            'purchase_date' => '2026-01-01',
            'salesman_id' => $salesman->id,
        ]);

        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $salesman->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => $confirmedAt ? 'confirmed' : 'pending',
        ]);

        $upd = ['created_at' => $date.' 10:00:00'];
        if ($confirmedAt) {
            $upd['confirmed_at'] = $confirmedAt.' 10:00:00';
        }
        Settlement::where('id', $s->id)->update($upd);

        return $s->fresh();
    }

    /**
     * 앵커 = confirmed_at (재무확정일). 거래완료(created_at)가 늦어도 확정일 기준 귀속 (jin 2026-07-02).
     * 예: 거래완료 7/20 이지만 완납으로 7/2 확정 → 6월 귀속(7/10 지급), created_at(7월) 아님.
     */
    public function test_confirmed_at_anchors_payroll_month_over_created_at(): void
    {
        $manager = $this->makeManager();
        $sm = Salesman::create(['name' => 'confirmed앵커', 'settlement_type' => 'ratio']);

        // created_at 7/20(거래완료 늦음), confirmed_at 7/2(완납 확정 이름) → 귀속 6월.
        $s = $this->makeSettlementOnDate('2026-07-20', $sm, '2026-07-02');

        $component = Volt::actingAs($manager)->test('erp.settlements.index');

        $this->assertSame(['2026-06'], $component->instance()->availableMonths());

        $component->set('monthFilter', '2026-06');
        $items = $component->instance()->settlements()->items();
        $this->assertCount(1, $items);
        $this->assertSame($s->id, $items[0]->id);

        // created_at 이 속한 2026-07 로는 안 잡힘 (앵커가 confirmed_at 이므로).
        $component->set('monthFilter', '2026-07');
        $this->assertCount(0, $component->instance()->settlements()->items());
    }

    /**
     * 급여 10일 cutoff (jin 2026-07-02, 서버 실사용 발견): 1~9일 마무리분은 전달 귀속(이달 10일 지급).
     * 예: 7/2 거래완료(6월 것 마무리) → 6월 정산 → 7/10 지급. 8/10 아님.
     */
    public function test_early_month_settlement_belongs_to_previous_payroll_month(): void
    {
        $manager = $this->makeManager();
        $sm = Salesman::create(['name' => 'cutoff테스트', 'settlement_type' => 'ratio']);

        $early = $this->makeSettlementOnDate('2026-07-02', $sm);   // day 2 < 10 → 2026-06 귀속
        $onOrAfter = $this->makeSettlementOnDate('2026-07-15', $sm); // day 15 ≥ 10 → 2026-07 귀속
        $boundary = $this->makeSettlementOnDate('2026-07-10', $sm);  // day 10 ≥ 10 → 2026-07 귀속

        $component = Volt::actingAs($manager)->test('erp.settlements.index');

        // 드롭다운 = 귀속월(2026-07, 2026-06) 최신순.
        $this->assertSame(['2026-07', '2026-06'], $component->instance()->availableMonths());

        // 2026-06 귀속(7/10 지급) → 7/2 마무리분 1건만.
        $component->set('monthFilter', '2026-06');
        $items = $component->instance()->settlements()->items();
        $this->assertCount(1, $items);
        $this->assertSame($early->id, $items[0]->id);

        // 2026-07 귀속(8/10 지급) → 7/10·7/15 2건.
        $component->set('monthFilter', '2026-07');
        $this->assertCount(2, $component->instance()->settlements()->items());
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

    /**
     * 월 필터 **기본값 = 이번 달** (jin 2026-08-28).
     *
     * 🚨 성능이 이유다 — 담당자별 합계가 필터에 걸린 정산 전부를 PHP 로 순회한다.
     *    전 기간(ssancarerp 3,815건) 8.1초 → 월 스코프 ~1.3초.
     * 🧭 「전 기간」은 없어지지 않았다. 비우면 그대로 나온다(위 테스트가 그걸 검증한다).
     */
    public function test_month_filter_defaults_to_the_current_month(): void
    {
        $manager = $this->makeManager();

        $component = Volt::actingAs($manager)->test('erp.settlements.index');

        $this->assertSame(now()->format('Y-m'), $component->instance()->monthFilter);
    }

    /** URL 로 온 monthFilter 가 이긴다 — #[Url] 이 mount 보다 먼저 채우므로 기본값이 덮으면 안 된다. */
    public function test_month_in_the_url_wins_over_the_default(): void
    {
        $manager = $this->makeManager();

        Livewire::withQueryParams(['monthFilter' => '2026-04']);
        $component = Volt::actingAs($manager)->test('erp.settlements.index');

        $this->assertSame('2026-04', $component->instance()->monthFilter);
    }

    /**
     * 지급보류는 **달과 무관한 잔액**이라 월 기본값을 걸지 않는다 — 딥링크·화면 토글 양쪽.
     * 걸리면 이번 달에 만든 정산만 보여 «보류가 없다»로 읽히고, 재무 대시보드 건수와 갈린다.
     */
    public function test_held_only_never_gets_the_month_default(): void
    {
        $manager = $this->makeManager();

        // ① 딥링크(?held=1)
        Livewire::withQueryParams(['held' => true]);
        $deeplink = Volt::actingAs($manager)->test('erp.settlements.index');
        Livewire::withQueryParams([]);
        $this->assertTrue($deeplink->instance()->heldOnly);
        $this->assertSame('', $deeplink->instance()->monthFilter);

        // ② 화면 안에서 토글 — 기본값이 걸린 상태에서 켜면 풀려야 한다.
        $component = Volt::actingAs($manager)->test('erp.settlements.index');
        $this->assertSame(now()->format('Y-m'), $component->instance()->monthFilter);
        $component->call('toggleHeld');
        $this->assertTrue($component->instance()->heldOnly);
        $this->assertSame('', $component->instance()->monthFilter);
    }

    /**
     * 월 기본값이 가린 「다른 달 확정 대기」를 화면이 스스로 말해야 한다.
     *
     * 🚨 확정대기 알림톡은 월 스코프가 없어 **전체 pending** 을 센다. 안내가 없으면
     *    재무가 「40건」 카톡을 받고 들어와 31건만 보게 된다 — 이번에 채권관리에서 고친
     *    「숫자는 보이는데 행이 없다」와 같은 형태다.
     *    실측(2026-08-28) heymanerp 2026-06 2건 · 2026-07 7건 — 실재한다.
     */
    public function test_pending_in_other_months_is_surfaced(): void
    {
        $manager = $this->makeManager();
        $sm = Salesman::create(['name' => '타월대기', 'settlement_type' => 'ratio']);

        $this->makeSettlementInMonth('2026-04', $sm);
        $this->makeSettlementInMonth('2026-04', $sm);
        $this->makeSettlementInMonth(now()->format('Y-m'), $sm);

        $component = Volt::actingAs($manager)->test('erp.settlements.index');

        // 이번 달 기본값 → 다른 달(2026-04)에 2건 남아 있다고 말해야 한다.
        $this->assertSame(now()->format('Y-m'), $component->instance()->monthFilter);
        $this->assertSame(2, $component->instance()->pendingOutsideMonth());

        // 눌러서 전 기간으로 풀면 가릴 게 없으니 0.
        $component->call('clearMonthFilter');
        $this->assertSame('', $component->instance()->monthFilter);
        $this->assertSame(0, $component->instance()->pendingOutsideMonth());
    }
}
