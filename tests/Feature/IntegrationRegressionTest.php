<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * /loop plan: docs/loop-plans/2026-05-23-integration-regression.md
 *
 * 운영 통합 회귀 박제 — 회의확장씬 + 새회의 + 한글화 + 재고관리 + KPI 분리까지
 * 누적된 기능들이 함께 작동하는지 회귀 모드로 검증. 모두 초록 통과가 정상.
 */
class IntegrationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    private function makeManager(?User $manager = null): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '관리',
            'manager_user_id' => $manager?->id,
            'email_verified_at' => now(),
        ]);
    }

    private function makeSalesUser(?User $manager = null): array
    {
        $u = User::factory()->create([
            'permission' => 'user', 'role' => '영업',
            'manager_user_id' => $manager?->id,
            'email_verified_at' => now(),
        ]);
        $s = Salesman::create([
            'name' => 'S-'.++$this->counter,
            'user_id' => $u->id,
            'is_active' => true,
            'type' => 'freelance',
        ]);

        return [$u, $s];
    }

    // ─── A. 전체 워크플로우 (3건) ─────────────────────────────────────

    public function test_case01_full_workflow_v4_cascade(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        // 매입중
        $v = Vehicle::create([
            'vehicle_number' => 'IR-WF-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_date' => '2026-04-01',
            'purchase_price' => 1_000_000,
        ]);
        $this->assertSame('매입중', $v->fresh()->progress_status);

        // 매입 잔금 confirmed → 매입완료
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-04-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();
        $this->assertSame('매입완료', $v->fresh()->progress_status);

        // 말소완료
        $v->update(['is_deregistered' => true, 'deregistration_document' => 'fake/dereg.pdf']);
        $v->refreshCaches();
        $this->assertSame('말소완료', $v->fresh()->progress_status);

        // 판매중
        $v->update(['sale_price' => 2_000_000, 'sale_date' => '2026-05-01']);
        $v->refreshCaches();
        $this->assertSame('판매중', $v->fresh()->progress_status);

        // 판매 잔금 confirmed → 판매완료
        $v->finalPayments()->create([
            'amount' => 2_000_000, 'exchange_rate' => 1,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();
        $this->assertSame('판매완료', $v->fresh()->progress_status);

        // 선적중
        $v->update(['bl_loading_location' => 'PUSAN PORT']);
        $v->refreshCaches();
        $this->assertSame('선적중', $v->fresh()->progress_status);

        // 거래완료 (v4: bl_document 우선)
        $v->update(['bl_document' => 'fake/bl.pdf']);
        $v->refreshCaches();
        $this->assertSame('거래완료', $v->fresh()->progress_status);
    }

    public function test_case02_settlement_auto_created_on_completion(): void
    {
        // 거래완료 시 Vehicle::saved 훅이 자동 Settlement 생성 (프리랜서 ratio default 50%)
        $admin = $this->makeAdmin();
        [, $sm] = $this->makeSalesUser();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-AUTO-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'salesman_id' => $sm->id,
            'purchase_date' => '2026-04-01',
            'purchase_price' => 1_000_000,
            'sale_price' => 2_000_000, 'sale_date' => '2026-05-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 2_000_000, 'exchange_rate' => 1,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        // case01 패턴과 동일 — relation cache stale 회피 위해 단계 분리
        $v = $v->fresh();
        $v->update(['bl_loading_location' => 'PUSAN']);
        $v = $v->fresh();
        $v->update(['bl_document' => 'fake/bl.pdf']);
        $v->refreshCaches();

        $this->assertSame('거래완료', $v->fresh()->progress_status);
        $settlement = $v->settlements()->first();
        $this->assertNotNull($settlement, '거래완료 시 Settlement 자동 생성');
        // Salesman.type='freelance' → settlement_type='ratio'
        $this->assertSame('ratio', $settlement->settlement_type);
        // ratio default 50 — decimal cast 형식 차이 가능. 50.0 검증 (float).
        $this->assertEqualsWithDelta(50.0, (float) $settlement->settlement_ratio, 0.01);
    }

    public function test_case03_paid_auto_secondary_pending(): void
    {
        // 회의확장씬 #8 — settlement_status='paid' 진입 시 secondary_status='pending' 자동
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-PAID-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_date' => '2026-04-01',
            'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        $this->assertNull($s->fresh()->secondary_status);

        // paid 전환
        $s->update(['settlement_status' => 'paid', 'paid_at' => now()]);

        $this->assertSame('pending', $s->fresh()->secondary_status);
    }

    // ─── B. 2차 정산 + 환차 (3건) ────────────────────────────────────

    public function test_case04_exchange_diff_profit_scenario(): void
    {
        // 회의확장씬 #7 — 입금 시점 환율 1300, close 시점 1380 → 환차익 양수
        Cache::put('exchange_rates', ['USD' => 1380.0], 60);
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-DIFF-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1300,
            'dhl_request' => false,
            'sale_price' => 500, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 500, 'exchange_rate' => 1300,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $fresh = $s->fresh();
        $this->assertSame('closed', $fresh->secondary_status);
        // 500 × 1380 - 500 × 1300 = 690,000 - 650,000 = 40,000
        $this->assertSame(40000.0, (float) $fresh->exchange_difference_krw);
    }

    public function test_case05_freelance_payout_adds_exchange_diff(): void
    {
        // 회의확장씬 #6+7 보강 — closed + ratio + 환차 양수 → actual_payout += diff
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-PAY-FL-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 10_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000, 'selling_fee' => 100_000,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 15000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
        $base = $s->settlement_amount - $s->document_fee;

        $this->assertSame($base + 15000, $s->actual_payout);
    }

    public function test_case06_per_unit_payout_ignores_exchange_diff(): void
    {
        // 회의확장씬 #6+7 — per_unit (사내직원) 은 환차 미반영 (회사 부담)
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-PAY-EMP-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 10_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 15000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
        $base = $s->settlement_amount;

        $this->assertSame($base, $s->actual_payout, 'per_unit 은 환차 미반영');
    }

    // ─── C. KPI 정합 (2건) ─────────────────────────────────────────────

    public function test_case07_admin_dashboard_revenue_split_integrity(): void
    {
        // 새회의 #7 + 3-B — 발생·회수·미수 KPI 정합
        $admin = $this->makeAdmin();

        $v = Vehicle::create([
            'vehicle_number' => 'IR-KPI-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 10_000_000, 'sale_date' => now()->format('Y-m-d'),
            'purchase_date' => now()->subMonth()->format('Y-m-d'),
            'purchase_price' => 5_000_000,
        ]);
        $v->finalPayments()->create([
            'amount' => 6_000_000, 'exchange_rate' => 1,
            'payment_date' => now()->format('Y-m-d'),
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();

        $this->actingAs($admin);
        $kpis = Volt::test('admin.dashboard')
            ->set('dateFrom', now()->subMonths(2)->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->call('applyFilters')
            ->instance()->kpis;

        $this->assertSame(10_000_000, $kpis['sale_total_krw'], '발생 매출');
        $this->assertSame(6_000_000, $kpis['cash_received_krw'], '현금 회수');
        $this->assertSame(4_000_000, $kpis['unpaid_krw'], '미수금 = 발생 - 회수');
    }

    public function test_case08_skills_section13_single_source_unpaid_ratio(): void
    {
        // SKILLS §13 — sale_unpaid_amount_krw_cache 자동 갱신 + 입금률 = (분모 - 분자) / 분모
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-S13-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 10_000_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 3_000_000, 'exchange_rate' => 1,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();
        $v = $v->fresh();

        $this->assertSame(10_000_000, (int) $v->sale_total_amount, '분모: sale_total_amount');
        $this->assertSame(7_000_000, (int) $v->sale_unpaid_amount, '분자: 10M - 3M');
        $this->assertSame(7_000_000, (int) $v->sale_unpaid_amount_krw_cache, 'KRW 캐시 동기');
        $this->assertSame(0.7, (float) $v->unpaid_ratio, '미납률 70%');
    }

    // ─── D. 권한 scoping (2건) ────────────────────────────────────────

    public function test_case09_manager_sees_only_subordinate_vehicles(): void
    {
        // 회의확장씬 #11 — 관리 role 본인 부하 영업의 차량만 조회
        $manager = $this->makeManager();
        [, $sub] = $this->makeSalesUser($manager);
        [, $other] = $this->makeSalesUser();   // 다른 영업 (manager_user_id 없음)

        $mine = Vehicle::create([
            'vehicle_number' => 'IR-MS-MY-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $sub->id,
            'purchase_date' => '2026-04-01',
        ]);
        $others = Vehicle::create([
            'vehicle_number' => 'IR-MS-OT-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $other->id,
            'purchase_date' => '2026-04-01',
        ]);

        $this->actingAs($manager);
        $list = Volt::test('erp.vehicles.index')->instance()->vehicles;
        $ids = $list->pluck('id')->toArray();

        $this->assertContains($mine->id, $ids, '본인 부하 영업의 차량');
        $this->assertNotContains($others->id, $ids, '다른 영업의 차량은 안 보임');
    }

    public function test_case10_sales_role_sees_only_own_vehicles(): void
    {
        // 영업 role 본인 차량만 + 다른 영업 차량 비노출
        [$salesUser, $sm] = $this->makeSalesUser();
        [, $otherSm] = $this->makeSalesUser();

        $mine = Vehicle::create([
            'vehicle_number' => 'IR-OWN-MY-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $sm->id,
            'purchase_date' => '2026-04-01',
        ]);
        $others = Vehicle::create([
            'vehicle_number' => 'IR-OWN-OT-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $otherSm->id,
            'purchase_date' => '2026-04-01',
        ]);

        $this->actingAs($salesUser);
        $list = Volt::test('erp.vehicles.index')->instance()->vehicles;
        $ids = $list->pluck('id')->toArray();

        $this->assertContains($mine->id, $ids, '본인 차량 노출');
        $this->assertNotContains($others->id, $ids, '다른 영업 차량 비노출');
    }
}
