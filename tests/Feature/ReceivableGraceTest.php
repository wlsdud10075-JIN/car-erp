<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 결제대기(grace) 10일 유예 규칙 (jin 2026-07-06 A안).
 * 선적 전 미수는 판매일+10일 지나야 채권, 그 전엔 grace. 선적 후는 즉시.
 * pivot=출고일(warehouse_out_date, jin 2026-07-18) — 출고 전이면 선적전(항구 대기), 출고 후면 선적후.
 */
class ReceivableGraceTest extends TestCase
{
    use RefreshDatabase;

    private function sold(int $daysAgo, bool $shipped, string $num): Vehicle
    {
        $buyer = Buyer::create(['name' => '딜러'.$num, 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => $num,
            'sales_channel' => 'export',
            'sale_price' => 10000,
            'sale_date' => now()->subDays($daysAgo)->toDateString(),
            'purchase_date' => now()->subMonth()->toDateString(),   // 대시보드 날짜필터(purchase_date) 통과용
            'buyer_id' => $buyer->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            // 선적 후 = 출고일 있음(출항). pivot=출고일 (jin 2026-07-18).
            'warehouse_out_date' => $shipped ? now()->toDateString() : null,
        ]);
    }

    public function test_pre_shipping_within_grace_is_grace_not_receivable(): void
    {
        $v = $this->sold(5, false, '11가1111');   // 선적 전, 5일 전 판매, 미수

        $this->assertSame('grace', $v->receivable_risk_computed);
        $this->assertSame('grace', $v->fresh()->receivable_risk);   // 캐시 컬럼도
        $this->assertSame('결제대기', $v->receivable_risk_label);

        // 판매미입금 알림/뱃지 대상 아님
        $this->assertNotContains($v->id, Vehicle::query()->action('sale_unpaid')->pluck('id')->all());
    }

    public function test_pre_shipping_past_grace_is_receivable(): void
    {
        $v = $this->sold(15, false, '22나2222');   // 선적 전, 15일 전 판매, 미수

        $this->assertNotSame('grace', $v->receivable_risk_computed);
        $this->assertContains($v->receivable_risk_computed, ['caution', 'danger', 'critical']);
        $this->assertContains($v->id, Vehicle::query()->action('sale_unpaid')->pluck('id')->all());
    }

    public function test_post_shipping_is_receivable_immediately(): void
    {
        $v = $this->sold(0, true, '33다3333');   // 선적 후(출고일 있음), 오늘 판매, 미수

        $this->assertNotSame('grace', $v->receivable_risk_computed);   // 유예 없음
        $this->assertContains($v->receivable_risk_computed, ['caution', 'danger', 'critical']);
        $this->assertContains($v->id, Vehicle::query()->action('sale_unpaid')->pluck('id')->all());
    }

    public function test_fully_paid_is_safe(): void
    {
        $v = $this->sold(5, false, '44라4444');
        $v->finalPayments()->create(['amount' => 10000, 'payment_date' => now()->toDateString(), 'type' => 'balance', 'confirmed_at' => now()]);
        $v->refreshProgressCache();

        $this->assertSame('safe', $v->fresh()->receivable_risk_computed);
    }

    /**
     * 채권 집계 단일 출처 — scopeExcludeReceivableGrace 는 grace(선적전·유예중)만 빼고
     * 선적후(즉시 채권)·유예경과는 남긴다. (jin 2026-07-06 채권금액 grace 제외)
     */
    public function test_exclude_receivable_grace_scope(): void
    {
        $grace = $this->sold(5, false, '55마5555');    // 선적 전, 유예 중 → 제외
        $due = $this->sold(15, false, '66바6666');     // 선적 전, 유예 경과 → 채권
        $shipped = $this->sold(0, true, '77사7777');   // 선적 후, 오늘 판매 → 즉시 채권

        $ids = Vehicle::query()->excludeReceivableGrace()->pluck('id')->all();

        $this->assertNotContains($grace->id, $ids);
        $this->assertContains($due->id, $ids);
        $this->assertContains($shipped->id, $ids);
    }

    public function test_receivable_before_shipping_action_excludes_grace(): void
    {
        $grace = $this->sold(5, false, '88아8888');    // 판매중, 유예 중
        $due = $this->sold(15, false, '99자9999');     // 판매중, 유예 경과

        $ids = Vehicle::query()->action('receivable_before_shipping')->pluck('id')->all();

        $this->assertNotContains($grace->id, $ids, 'grace 는 선적전 채권에서 제외');
        $this->assertContains($due->id, $ids);
    }

    /** 집계 검증 — 관리자 대시보드 미수금 KPI 는 grace 제외분만 합산. */
    public function test_admin_dashboard_unpaid_krw_excludes_grace(): void
    {
        $this->sold(5, false, '11가0001');    // grace 10000 → 제외
        $this->sold(15, false, '22나0002');   // 채권 10000 → 포함

        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateFrom', now()->subMonths(2)->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->call('applyFilters')
            ->instance()->kpis;

        $this->assertSame(10000, $kpis['unpaid_krw'], 'grace 1건 제외, 채권 1건만');
    }

    /** 집계 검증 — 관리자 대시보드 채권 위젯(receivableKpis) 총미수·선적전 미수도 grace 제외. */
    public function test_admin_dashboard_receivable_kpis_exclude_grace(): void
    {
        $sm = Salesman::create(['name' => '김영업', 'is_active' => true]);
        $grace = $this->sold(5, false, '55마0005');
        $grace->update(['salesman_id' => $sm->id]);   // total_unpaid 는 담당자 집계 기반 → salesman 필요
        $due = $this->sold(15, false, '66바0006');
        $due->update(['salesman_id' => $sm->id]);

        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        $rk = Volt::test('admin.dashboard')
            ->set('dateFrom', now()->subMonths(2)->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->call('applyFilters')
            ->instance()->receivableKpis;

        $this->assertSame(10000, $rk['total_unpaid'], 'grace 1건 제외, 채권 1건만');
        $this->assertSame(10000, $rk['classification']['before_shipping']['unpaid'], '선적전 미수 grace 제외');
        $this->assertSame(1, $rk['classification']['before_shipping']['count']);
        // 결제대기 카드 = 제외된 grace 만 따로
        $this->assertSame(10000, $rk['grace_unpaid'], '결제대기 카드 = grace 1건');
        $this->assertSame(1, $rk['grace_count']);
    }

    /** 집계 검증 — 채권관리 페이지 총 미수(summary)는 grace 제외분만 합산. */
    public function test_receivable_page_summary_excludes_grace(): void
    {
        $this->sold(5, false, '33다0003');    // grace → 제외
        $this->sold(15, false, '44라0004');   // 채권 → 포함

        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        $summary = Volt::test('erp.receivables.index')->instance()->summary();

        $this->assertSame(10000, $summary['total_unpaid_krw'], 'grace 1건 제외, 채권 1건만');
        $this->assertSame(10000, $summary['grace_unpaid_krw'], '결제대기 카드 = grace 1건');
        $this->assertSame(1, $summary['grace_count']);
    }
}
