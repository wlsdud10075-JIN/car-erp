<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\ForwardingCompany;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 큐 4 — 관리자 대시보드 차트 보강.
 * 8-1: 기간 필터 보강 (기준일 컬럼 전환 + 빠른 범위 선택).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리']);
    }

    // ── 8-1: 기준일 컬럼 전환 ──────────────────────────────────────────

    public function test_kpis_filters_by_purchase_date_when_date_type_is_purchase(): void
    {
        $this->actingAs($this->admin());

        // 매입일 in-range 1대, sale_date in-range지만 purchase_date out-of-range 1대
        Vehicle::create([
            'vehicle_number' => 'IN-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2026-05-10', 'purchase_price' => 1000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'OUT-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2025-01-01',  // out
            'sale_date' => '2026-05-10',       // in
            'sale_price' => 2000,
        ]);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateType', 'purchase')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('kpis');

        $this->assertSame(1, $kpis['vehicles']);  // 매입 in-range만
        $this->assertSame(1000, $kpis['purchase_total']);
    }

    public function test_kpis_filters_by_sale_date_when_date_type_is_sale(): void
    {
        $this->actingAs($this->admin());

        Vehicle::create([
            'vehicle_number' => 'IN-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2025-01-01',  // out of purchase range
            'sale_date' => '2026-05-10',       // in of sale range
            'sale_price' => 2000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'OUT-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2026-05-10',  // in purchase
            'sale_date' => '2025-01-01',       // out of sale
            'sale_price' => 1000,
        ]);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateType', 'sale')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('kpis');

        $this->assertSame(1, $kpis['vehicles']);
        $this->assertSame(2000, $kpis['sale_total_krw']);
    }

    public function test_kpis_completed_date_type_maps_to_bl_issue_date(): void
    {
        $this->actingAs($this->admin());

        Vehicle::create([
            'vehicle_number' => 'IN-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2025-01-01',
            'bl_issue_date' => '2026-05-10',
        ]);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateType', 'completed')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('kpis');

        $this->assertSame(1, $kpis['vehicles']);
    }

    // ── 8-1: 빠른 범위 선택 ────────────────────────────────────────────

    public function test_set_quick_range_this_month_sets_first_to_last_day(): void
    {
        $this->actingAs($this->admin());

        $component = Volt::test('admin.dashboard')->call('setQuickRange', 'this_month');

        $from = now()->copy()->startOfMonth()->format('Y-m-d');
        $to = now()->copy()->endOfMonth()->format('Y-m-d');

        $this->assertSame($from, $component->get('dateFrom'));
        $this->assertSame($to, $component->get('dateTo'));
    }

    public function test_set_quick_range_last_year_sets_jan_1_to_dec_31(): void
    {
        $this->actingAs($this->admin());

        $component = Volt::test('admin.dashboard')->call('setQuickRange', 'last_year');

        $lastYear = now()->year - 1;
        $this->assertSame("{$lastYear}-01-01", $component->get('dateFrom'));
        $this->assertSame("{$lastYear}-12-31", $component->get('dateTo'));
    }

    public function test_vehicles_url_maps_completed_date_type_to_bl(): void
    {
        $this->actingAs($this->admin());

        $url = Volt::test('admin.dashboard')
            ->set('dateType', 'completed')
            ->set('dateFrom', '2026-01-01')
            ->set('dateTo', '2026-12-31')
            ->instance()
            ->vehiclesUrl();

        $this->assertStringContainsString('dateType=bl', $url);
    }

    // ── 8-2: 월별 차트 데이터 ──────────────────────────────────────────

    public function test_monthly_chart_data_buckets_purchase_count_by_month(): void
    {
        $this->actingAs($this->admin());

        // 2026년 3월 × 2, 5월 × 1
        Vehicle::create([
            'vehicle_number' => 'M-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2026-03-10', 'purchase_price' => 1000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'M-2', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2026-03-25', 'purchase_price' => 1000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'M-3', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2026-05-15', 'purchase_price' => 1000,
        ]);
        // 2025년 — 다른 해
        Vehicle::create([
            'vehicle_number' => 'M-PREV', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false,
            'purchase_date' => '2025-03-10', 'purchase_price' => 1000,
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-01-01')
            ->set('dateTo', '2026-12-31')
            ->call('applyFilters')
            ->get('monthlyChartData');

        $this->assertSame(2026, $data['year']);
        $this->assertCount(12, $data['counts']['purchase']);
        $this->assertSame(2, $data['counts']['purchase'][2]);  // 3월 (0-indexed = 2)
        $this->assertSame(1, $data['counts']['purchase'][4]);  // 5월
        $this->assertSame(0, $data['counts']['purchase'][0]);  // 1월
    }

    public function test_monthly_chart_data_sums_sales_krw_with_exchange_rate(): void
    {
        $this->actingAs($this->admin());

        // 5월 — KRW 1000원 + USD 100 × 1300 = 130000원 → 131000원
        Vehicle::create([
            'vehicle_number' => 'S-1', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'dhl_request' => false,
            'sale_date' => '2026-05-10', 'sale_price' => 1000, 'exchange_rate' => 1,
        ]);
        Vehicle::create([
            'vehicle_number' => 'S-2', 'sales_channel' => 'export', 'currency' => 'USD',
            'dhl_request' => false,
            'sale_date' => '2026-05-20', 'sale_price' => 100, 'exchange_rate' => 1300,
        ]);
        // 환율 0 — KRW 환산 불가 → 제외
        Vehicle::create([
            'vehicle_number' => 'S-EX', 'sales_channel' => 'export', 'currency' => 'USD',
            'dhl_request' => false,
            'sale_date' => '2026-05-25', 'sale_price' => 999, 'exchange_rate' => 0,
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-01-01')
            ->call('applyFilters')
            ->get('monthlyChartData');

        $this->assertSame(131000, $data['sales_krw'][4]);  // 5월
        $this->assertSame(0, $data['sales_krw'][0]);
    }

    public function test_monthly_chart_data_year_derives_from_date_from(): void
    {
        $this->actingAs($this->admin());

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2024-07-01')
            ->call('applyFilters')
            ->get('monthlyChartData');

        $this->assertSame(2024, $data['year']);
    }

    // ── 8-3: 담당자별 성과 차트 ──────────────────────────────────────

    public function test_salesman_performance_aggregates_by_salesman(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);
        $b = Salesman::create(['name' => '최매입', 'is_active' => true]);

        // 김영업: 2건, 합계 KRW 3000
        Vehicle::create([
            'vehicle_number' => 'P-A1', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'dhl_request' => false, 'exchange_rate' => 1,
            'purchase_date' => '2026-05-10', 'salesman_id' => $a->id,
            'sale_date' => '2026-05-10', 'sale_price' => 1000,
        ]);
        Vehicle::create([
            'vehicle_number' => 'P-A2', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'dhl_request' => false, 'exchange_rate' => 1,
            'purchase_date' => '2026-05-15', 'salesman_id' => $a->id,
            'sale_date' => '2026-05-15', 'sale_price' => 2000,
        ]);
        // 최매입: 1건, 합계 KRW 5000 → 상위 1위
        Vehicle::create([
            'vehicle_number' => 'P-B1', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'dhl_request' => false, 'exchange_rate' => 1,
            'purchase_date' => '2026-05-20', 'salesman_id' => $b->id,
            'sale_date' => '2026-05-20', 'sale_price' => 5000,
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-01-01')
            ->set('dateTo', '2026-12-31')
            ->call('applyFilters')
            ->get('salesmanPerformance');

        // 판매 금액 기준 내림차순 → 최매입 1위, 김영업 2위
        $this->assertSame(['최매입', '김영업'], $data['labels']);
        $this->assertSame([1, 2], $data['sale_count']);
        $this->assertSame([5000, 3000], $data['sale_total_krw']);
        $this->assertSame([5000, 1500], $data['avg_per_vehicle']);
    }

    public function test_salesman_performance_excludes_null_salesman(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);
        Vehicle::create([
            'vehicle_number' => 'NS-1', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'dhl_request' => false, 'exchange_rate' => 1,
            'purchase_date' => '2026-05-10', 'salesman_id' => null,
            'sale_date' => '2026-05-10', 'sale_price' => 9999,
        ]);
        Vehicle::create([
            'vehicle_number' => 'NS-2', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'dhl_request' => false, 'exchange_rate' => 1,
            'purchase_date' => '2026-05-10', 'salesman_id' => $a->id,
            'sale_date' => '2026-05-10', 'sale_price' => 1000,
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-01-01')
            ->call('applyFilters')
            ->get('salesmanPerformance');

        // salesman_id null인 차량은 제외 → 김영업만
        $this->assertSame(['김영업'], $data['labels']);
        $this->assertSame([1000], $data['sale_total_krw']);
    }

    public function test_salesman_performance_respects_date_type(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);

        // dateType=purchase에선 매입일 in-range만, dateType=sale에선 판매일 in-range만
        Vehicle::create([
            'vehicle_number' => 'DT-1', 'sales_channel' => 'heyman', 'currency' => 'KRW',
            'dhl_request' => false, 'exchange_rate' => 1,
            'purchase_date' => '2026-05-10',   // in for purchase
            'sale_date' => '2025-01-01',        // out for sale
            'salesman_id' => $a->id, 'sale_price' => 1000,
        ]);

        $dataPurchase = Volt::test('admin.dashboard')
            ->set('dateType', 'purchase')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('salesmanPerformance');

        $dataSale = Volt::test('admin.dashboard')
            ->set('dateType', 'sale')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('salesmanPerformance');

        $this->assertSame(['김영업'], $dataPurchase['labels']);
        $this->assertSame([], $dataSale['labels']);
    }

    // ── 8-5: 정산 탭 KPI ──────────────────────────────────────────────

    private function makeVehicle(array $overrides = []): Vehicle
    {
        static $i = 0;
        $i++;

        $defaults = [
            'vehicle_number' => 'SK-'.$i, 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => false, 'exchange_rate' => 1,
        ];

        // 2026-05-19 풀회의 안건 E — sale_price > 0 시 sale_date·buyer_id 자동 채움.
        if (($overrides['sale_price'] ?? 0) > 0) {
            if (! array_key_exists('buyer_id', $overrides)) {
                $defaults['buyer_id'] = Buyer::firstOrCreate(['name' => 'TEST BUYER'], ['is_active' => true])->id;
            }
            if (! array_key_exists('sale_date', $overrides)) {
                $defaults['sale_date'] = '2026-05-01';
            }
        }

        // 큐 22-A-3 (2026-05-20) — vehicles 4컬럼 DROP. override 키가 있으면 confirmed FP 자동 생성.
        $sale4Map = [
            'deposit_down_payment' => 'deposit_down',
            'interim_payment' => 'interim',
            'advance_payment1' => 'advance_1',
            'advance_payment2' => 'fee',
        ];
        $sale4Inserts = [];
        foreach ($sale4Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $sale4Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
                }
                unset($overrides[$col]);
            }
        }

        // 큐 22-C-E (2026-05-20) — vehicles 2컬럼 DROP. override 키가 있으면 confirmed PBP 자동 생성.
        $purchase2Map = [
            'down_payment' => 'down',
            'selling_fee_payment' => 'selling_fee',
        ];
        $purchase2Inserts = [];
        foreach ($purchase2Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $purchase2Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
                }
                unset($overrides[$col]);
            }
        }

        $v = Vehicle::create(array_merge($defaults, $overrides));

        foreach ($sale4Inserts as $row) {
            $v->finalPayments()->create([
                'amount' => $row['amount'],
                'type' => $row['type'],
                'confirmed_at' => now(),
            ]);
        }
        if (! empty($purchase2Inserts)) {
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                foreach ($purchase2Inserts as $row) {
                    $v->purchaseBalancePayments()->create([
                        'amount' => $row['amount'],
                        'type' => $row['type'],
                        'payment_date' => now()->subDay()->toDateString(),
                        'confirmed_at' => now(),
                    ]);
                }
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }
        }
        if (! empty($sale4Inserts) || ! empty($purchase2Inserts)) {
            $v->refresh();
        }

        return $v;
    }

    public function test_settlement_kpis_monthly_aggregates_paid_by_salesman_and_month(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);
        $v = $this->makeVehicle();

        // 2026년 5월 김영업 paid 정산 actual_payout=500
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 500,
            'other_deduction' => 0, 'settlement_status' => 'paid',
            'confirmed_at' => '2026-05-10 10:00:00', 'paid_at' => '2026-05-10 11:00:00',
            'confirmed_snapshot' => ['actual_payout' => 500, 'total_margin' => 1000, 'sales_amount_krw' => 5000],
        ]);
        // 2026년 7월 김영업 paid 정산 actual_payout=300
        $v2 = $this->makeVehicle();
        Settlement::create([
            'vehicle_id' => $v2->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 300,
            'other_deduction' => 0, 'settlement_status' => 'paid',
            'confirmed_at' => '2026-07-15 10:00:00', 'paid_at' => '2026-07-15 11:00:00',
            'confirmed_snapshot' => ['actual_payout' => 300, 'total_margin' => 600, 'sales_amount_krw' => 3000],
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-01-01')
            ->set('dateTo', '2026-12-31')
            ->call('applyFilters')
            ->get('settlementKpis');

        $this->assertSame(2026, $data['year']);
        $this->assertCount(1, $data['monthly']['datasets']);
        $this->assertSame('김영업', $data['monthly']['datasets'][0]['label']);
        $this->assertSame(500, $data['monthly']['datasets'][0]['data'][4]);  // 5월
        $this->assertSame(300, $data['monthly']['datasets'][0]['data'][6]);  // 7월
        $this->assertSame(0, $data['monthly']['datasets'][0]['data'][0]);     // 1월 0
    }

    public function test_settlement_kpis_payout_pending_sums_confirmed_only(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);
        $v1 = $this->makeVehicle();
        $v2 = $this->makeVehicle();

        // confirmed → 대기 합계 포함
        Settlement::create([
            'vehicle_id' => $v1->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 1000,
            'other_deduction' => 0, 'settlement_status' => 'confirmed',
            'confirmed_at' => '2026-05-10 10:00:00',
        ]);
        // paid → 대기 합계 제외
        Settlement::create([
            'vehicle_id' => $v2->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 9999,
            'other_deduction' => 0, 'settlement_status' => 'paid',
            'confirmed_at' => '2026-05-10 10:00:00', 'paid_at' => '2026-05-10 11:00:00',
            'confirmed_snapshot' => ['actual_payout' => 9999],
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('settlementKpis');

        $this->assertSame(1000, $data['payout_pending']);
    }

    public function test_settlement_kpis_avg_margin_rate_from_paid_snapshots(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);
        $v1 = $this->makeVehicle();
        $v2 = $this->makeVehicle();

        // 마진율 20% + 마진율 10% = 평균 15%
        Settlement::create([
            'vehicle_id' => $v1->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 1,
            'other_deduction' => 0, 'settlement_status' => 'paid',
            'paid_at' => '2026-05-10', 'confirmed_at' => '2026-05-10',
            'confirmed_snapshot' => ['total_margin' => 2000, 'sales_amount_krw' => 10000],
        ]);
        Settlement::create([
            'vehicle_id' => $v2->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 1,
            'other_deduction' => 0, 'settlement_status' => 'paid',
            'paid_at' => '2026-05-15', 'confirmed_at' => '2026-05-15',
            'confirmed_snapshot' => ['total_margin' => 1000, 'sales_amount_krw' => 10000],
        ]);

        $data = Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->call('applyFilters')
            ->get('settlementKpis');

        $this->assertEqualsWithDelta(0.15, $data['avg_margin_rate'], 0.001);
    }

    // 큐 16 — test_settlement_kpis_channel_avg_margin_groups_by_vehicle_channel 삭제
    // (채널 단일화로 channel_avg_margin 기능 자체 제거)

    // ── 8-6: 채권 탭 미수금 TOP ───────────────────────────────────────

    /**
     * Vehicle::saving 이벤트가 receivable_risk·sale_unpaid_amount_krw_cache를
     * 자동 재계산 → 테스트 fixture는 raw DB update로 캐시 컬럼 직접 set.
     */
    private function setReceivableCache(Vehicle $v, ?int $unpaid, ?string $risk): void
    {
        DB::table('vehicles')->where('id', $v->id)->update([
            'sale_unpaid_amount_krw_cache' => $unpaid,
            'receivable_risk' => $risk,
        ]);
    }

    public function test_receivable_kpis_aggregates_top_salesman_by_unpaid(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);
        $b = Salesman::create(['name' => '최매입', 'is_active' => true]);

        // 김영업 미수금 5000 (sale 10000 → 미납률 50%)
        $v1 = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 10000]);
        $this->setReceivableCache($v1, 5000, 'danger');
        // 최매입 미수금 1000 (sale 10000 → 미납률 10%)
        $v2 = $this->makeVehicle(['salesman_id' => $b->id, 'sale_price' => 10000]);
        $this->setReceivableCache($v2, 1000, 'caution');
        // 미수금 0 차량 — 제외
        $v3 = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 5000]);
        $this->setReceivableCache($v3, 0, 'safe');

        // dateFrom 비움 — 시드 차량 purchase_date NULL이라 default 2개월 필터에서 제외되는 회귀 방지
        $data = Volt::test('admin.dashboard')->set('dateFrom', '')->set('dateTo', '')->call('applyFilters')->get('receivableKpis');

        // 미수금 내림차순 → 김영업 1위
        $this->assertSame('김영업', $data['salesman_top'][0]['name']);
        $this->assertSame(5000, $data['salesman_top'][0]['unpaid']);
        $this->assertSame(50.0, $data['salesman_top'][0]['unpaid_rate']);
        $this->assertSame('최매입', $data['salesman_top'][1]['name']);
        $this->assertSame(10.0, $data['salesman_top'][1]['unpaid_rate']);
    }

    public function test_receivable_kpis_aggregates_top_buyer_by_unpaid(): void
    {
        $this->actingAs($this->admin());

        $b1 = Buyer::create(['name' => 'ABC Trading', 'is_active' => true, 'country_id' => null]);
        $b2 = Buyer::create(['name' => 'XYZ Auto', 'is_active' => true, 'country_id' => null]);

        $v1 = $this->makeVehicle(['buyer_id' => $b1->id, 'sale_price' => 20000]);
        $this->setReceivableCache($v1, 10000, 'critical');
        $v2 = $this->makeVehicle(['buyer_id' => $b2->id, 'sale_price' => 10000]);
        $this->setReceivableCache($v2, 2000, 'caution');

        // dateFrom 비움 — 시드 차량 purchase_date NULL이라 default 2개월 필터에서 제외되는 회귀 방지
        $data = Volt::test('admin.dashboard')->set('dateFrom', '')->set('dateTo', '')->call('applyFilters')->get('receivableKpis');

        $this->assertSame('ABC Trading', $data['buyer_top'][0]['name']);
        $this->assertSame(10000, $data['buyer_top'][0]['unpaid']);
        $this->assertSame(50.0, $data['buyer_top'][0]['unpaid_rate']);
    }

    public function test_receivable_kpis_excludes_null_unpaid_cache(): void
    {
        // 환율 미입력 외화 차량은 sale_unpaid_amount_krw_cache=NULL → 통계 제외
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);

        $vNull = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 1000, 'currency' => 'USD']);
        $this->setReceivableCache($vNull, null, null);  // 환율 0 → 캐시 NULL
        $v = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 5000]);
        $this->setReceivableCache($v, 2000, 'caution');

        // dateFrom 비움 — 시드 차량 purchase_date NULL이라 default 2개월 필터에서 제외되는 회귀 방지
        $data = Volt::test('admin.dashboard')->set('dateFrom', '')->set('dateTo', '')->call('applyFilters')->get('receivableKpis');

        // NULL 캐시 1대 제외 → vehicle_count 1
        $this->assertSame(1, $data['salesman_top'][0]['vehicle_count']);
        $this->assertSame(2000, $data['salesman_top'][0]['unpaid']);
    }

    public function test_receivable_kpis_risk_counts_groups_by_receivable_risk(): void
    {
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);

        foreach ([['safe', 1], ['caution', 2], ['critical', 1]] as [$risk, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $v = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 1000]);
                $this->setReceivableCache($v, 500, $risk);
            }
        }

        // dateFrom 비움 — 시드 차량 purchase_date NULL이라 default 2개월 필터에서 제외되는 회귀 방지
        $data = Volt::test('admin.dashboard')->set('dateFrom', '')->set('dateTo', '')->call('applyFilters')->get('receivableKpis');

        $this->assertSame(1, $data['risk_counts']['safe']);
        $this->assertSame(2, $data['risk_counts']['caution']);
        $this->assertSame(0, $data['risk_counts']['danger']);
        $this->assertSame(1, $data['risk_counts']['critical']);
    }

    public function test_receivable_scope_action_matches_dashboard_count(): void
    {
        // 점검 — 채권 카드 클릭 시 vehicles 목록과 카운트 100% 일치.
        $this->actingAs($this->admin());

        $a = Salesman::create(['name' => '김영업', 'is_active' => true]);

        // caution × 3
        for ($i = 0; $i < 3; $i++) {
            $v = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 1000]);
            $this->setReceivableCache($v, 500, 'caution');
        }
        // safe × 1 (sale_unpaid_amount_krw_cache > 0)
        $v1 = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 1000]);
        $this->setReceivableCache($v1, 100, 'safe');
        // caution이지만 미수금 0 → 카운트·action 모두 제외
        $v2 = $this->makeVehicle(['salesman_id' => $a->id, 'sale_price' => 1000]);
        $this->setReceivableCache($v2, 0, 'caution');

        $cautionCount = Vehicle::action('receivable_caution')->count();
        $safeCount = Vehicle::action('receivable_safe')->count();

        $this->assertSame(3, $cautionCount);
        $this->assertSame(1, $safeCount);
    }

    // ── 8-7: 통관 탭 KPI ──────────────────────────────────────────────

    public function test_clearance_kpis_counts_stuck_vehicles_after_30_days(): void
    {
        $this->actingAs($this->admin());

        $oldSaleDate = now()->subDays(35)->format('Y-m-d');
        $recentSaleDate = now()->subDays(10)->format('Y-m-d');

        // 정체 — 30일 경과 + 수출신고서 NULL + 완납
        $v1 = $this->makeVehicle([
            'sales_channel' => 'export', 'sale_price' => 1000,
            'sale_date' => $oldSaleDate, 'export_declaration_document' => null,
        ]);
        $this->setReceivableCache($v1, 0, 'safe');
        // 정체 아님 — 10일밖에 안 됐음
        $v2 = $this->makeVehicle([
            'sales_channel' => 'export', 'sale_price' => 1000,
            'sale_date' => $recentSaleDate, 'export_declaration_document' => null,
        ]);
        $this->setReceivableCache($v2, 0, 'safe');
        // 정체 아님 — 수출신고서 이미 있음
        $v3 = $this->makeVehicle([
            'sales_channel' => 'export', 'sale_price' => 1000,
            'sale_date' => $oldSaleDate, 'export_declaration_document' => 'edoc.pdf',
        ]);
        $this->setReceivableCache($v3, 0, 'safe');
        // 정체 아님 — 미입금
        $v4 = $this->makeVehicle([
            'sales_channel' => 'export', 'sale_price' => 1000,
            'sale_date' => $oldSaleDate, 'export_declaration_document' => null,
        ]);
        $this->setReceivableCache($v4, 500, 'caution');
        // 큐 16 — v5 (heyman 채널) 픽스처 제거 (단일 채널화).

        // dateFrom 비움 — 시드 차량 purchase_date NULL/과거라 default 필터에서 제외되는 회귀 방지
        $data = Volt::test('admin.dashboard')->set('dateFrom', '')->set('dateTo', '')->call('applyFilters')->get('clearanceKpis');

        $this->assertSame(1, $data['stuck_count']);
        $this->assertSame(30, $data['stuck_threshold_days']);
    }

    public function test_clearance_kpis_counts_unfiled_in_clearance_stage(): void
    {
        $this->actingAs($this->admin());

        $buyer = Buyer::create(['name' => '바이어', 'is_active' => true, 'country_id' => null]);

        // 수출통관중 단계 — buyer + shipping_date + doc NULL
        $this->makeVehicle([
            'sales_channel' => 'export',
            'export_buyer_id' => $buyer->id,
            'shipping_date' => '2026-05-20',
            'export_declaration_document' => null,
        ]);
        // 미카운트 — 문서 이미 있음
        $this->makeVehicle([
            'sales_channel' => 'export',
            'export_buyer_id' => $buyer->id,
            'shipping_date' => '2026-05-20',
            'export_declaration_document' => 'edoc.pdf',
        ]);
        // 미카운트 — shipping_date NULL
        $this->makeVehicle([
            'sales_channel' => 'export',
            'export_buyer_id' => $buyer->id,
            'shipping_date' => null,
        ]);

        // dateFrom 비움 — 시드 차량 purchase_date NULL/과거라 default 필터에서 제외되는 회귀 방지
        $data = Volt::test('admin.dashboard')->set('dateFrom', '')->set('dateTo', '')->call('applyFilters')->get('clearanceKpis');

        $this->assertSame(1, $data['unfiled_count']);
    }

    public function test_clearance_kpis_forwarder_top_groups_by_progress_stage(): void
    {
        $this->actingAs($this->admin());

        $f1 = ForwardingCompany::create(['name' => 'A포워딩', 'is_active' => true]);
        $f2 = ForwardingCompany::create(['name' => 'B포워딩', 'is_active' => true]);

        // A포워딩 — 수출통관중 2 + 선적완료 1 = 3
        for ($i = 0; $i < 2; $i++) {
            DB::table('vehicles')->insert([
                'vehicle_number' => 'AF-'.$i, 'sales_channel' => 'export', 'currency' => 'KRW',
                'dhl_request' => 0,
                'forwarding_company_id' => $f1->id, 'progress_status_cache' => '수출통관중',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('vehicles')->insert([
            'vehicle_number' => 'AF-S', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => 0,
            'forwarding_company_id' => $f1->id, 'progress_status_cache' => '선적완료',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // B포워딩 — 선적중 1
        DB::table('vehicles')->insert([
            'vehicle_number' => 'BF-1', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => 0,
            'forwarding_company_id' => $f2->id, 'progress_status_cache' => '선적중',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 미카운트 — 매입중 단계 (통관·선적 아님)
        DB::table('vehicles')->insert([
            'vehicle_number' => 'BF-X', 'sales_channel' => 'export', 'currency' => 'KRW',
            'dhl_request' => 0,
            'forwarding_company_id' => $f2->id, 'progress_status_cache' => '매입중',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // dateFrom 비움 — 시드 차량 purchase_date NULL/과거라 default 필터에서 제외되는 회귀 방지
        $data = Volt::test('admin.dashboard')->set('dateFrom', '')->set('dateTo', '')->call('applyFilters')->get('clearanceKpis');

        $this->assertSame('A포워딩', $data['forwarder_top'][0]['name']);
        $this->assertSame(3, $data['forwarder_top'][0]['count']);
        $this->assertSame('B포워딩', $data['forwarder_top'][1]['name']);
        $this->assertSame(1, $data['forwarder_top'][1]['count']);
    }
}
