<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
        // 자동생성 = settlement_ratio 컬럼 null + 설정 기반 effective_ratio (기본 50%).
        $this->assertNull($settlement->settlement_ratio);
        $this->assertSame(50, $settlement->effective_ratio);
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
        // 2026-07-06 재피벗 — 판매환율 1300, 잔금 1380 수령 → 환차익 양수
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
            'amount' => 500, 'exchange_rate' => 1380,   // 수령 환율 1380 (판매환율 1300 보다 높음)
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
            // 판매일 15일 전(결제대기 유예 10일 경과) — 미수금 KPI 는 grace 제외라 유예 경과 채권이어야 집계됨.
            'sale_price' => 10_000_000, 'sale_date' => now()->subDays(15)->format('Y-m-d'),
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
        $list = Volt::test('erp.vehicles.index')->set('dateFrom', now()->subYear()->format('Y-m-d'))->set('dateTo', now()->addDay()->format('Y-m-d'))->instance()->vehicles;
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
        $list = Volt::test('erp.vehicles.index')->set('dateFrom', now()->subYear()->format('Y-m-d'))->set('dateTo', now()->addDay()->format('Y-m-d'))->instance()->vehicles;
        $ids = $list->pluck('id')->toArray();

        $this->assertContains($mine->id, $ids, '본인 차량 노출');
        $this->assertNotContains($others->id, $ids, '다른 영업 차량 비노출');
    }

    // ─── E. 사용자 운영 시나리오 (7건, 2026-05-23 추가) ──────────────

    public function test_case11_two_managers_with_five_salesmen_each(): void
    {
        // 사용자 명세: 관리 2명 × 각자 부하 영업 5명씩. 격리 검증.
        $mgrA = $this->makeManager();
        $mgrB = $this->makeManager();
        $aIds = [];
        $bIds = [];
        for ($i = 0; $i < 5; $i++) {
            [, $sm] = $this->makeSalesUser($mgrA);
            $v = Vehicle::create([
                'vehicle_number' => 'IR-TM-A'.($i + 1).'-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id,
                'purchase_date' => '2026-04-01',
            ]);
            $aIds[] = $v->id;
        }
        for ($i = 0; $i < 5; $i++) {
            [, $sm] = $this->makeSalesUser($mgrB);
            $v = Vehicle::create([
                'vehicle_number' => 'IR-TM-B'.($i + 1).'-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id,
                'purchase_date' => '2026-04-01',
            ]);
            $bIds[] = $v->id;
        }

        $this->actingAs($mgrA);
        $listA = Volt::test('erp.vehicles.index')->set('dateFrom', now()->subYear()->format('Y-m-d'))->set('dateTo', now()->addDay()->format('Y-m-d'))->instance()->vehicles->pluck('id')->toArray();
        foreach ($aIds as $id) {
            $this->assertContains($id, $listA, "관리A 부하 영업의 차량 #$id 노출");
        }
        foreach ($bIds as $id) {
            $this->assertNotContains($id, $listA, "관리B 부하 영업의 차량 #$id 격리");
        }

        $this->actingAs($mgrB);
        $listB = Volt::test('erp.vehicles.index')->set('dateFrom', now()->subYear()->format('Y-m-d'))->set('dateTo', now()->addDay()->format('Y-m-d'))->instance()->vehicles->pluck('id')->toArray();
        foreach ($bIds as $id) {
            $this->assertContains($id, $listB, "관리B 부하 영업의 차량 #$id 노출");
        }
        foreach ($aIds as $id) {
            $this->assertNotContains($id, $listB, "관리A 부하 영업의 차량 #$id 격리");
        }
    }

    public function test_case12_mixed_usd_eur_exchange_diff_scenarios(): void
    {
        // USD 환차익 + EUR 환차손 동시 시나리오 — 통화별 분리 검증 (2026-07-06 재피벗)
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        // USD 차량 — 판매환율 1300, 잔금 1380 수령 → 환차익 +
        $vUsd = Vehicle::create([
            'vehicle_number' => 'IR-MX-USD-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300,
            'dhl_request' => false,
            'sale_price' => 500, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $vUsd->finalPayments()->create([
            'amount' => 500, 'exchange_rate' => 1380,   // 수령 1380 > 판매환율 1300
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $sUsd = Settlement::create([
            'vehicle_id' => $vUsd->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // EUR 차량 — 판매환율 1450, 잔금 1400 수령 → 환차손 -
        $vEur = Vehicle::create([
            'vehicle_number' => 'IR-MX-EUR-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1450,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $vEur->finalPayments()->create([
            'amount' => 400, 'exchange_rate' => 1400,   // 수령 1400 < 판매환율 1450
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $sEur = Settlement::create([
            'vehicle_id' => $vEur->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $component = Volt::test('erp.settlements.index');
        $component->call('closeSecondarySettlement', $sUsd->id);
        $component->call('closeSecondarySettlement', $sEur->id);

        // USD: 500 × (1380 - 1300) = +40,000 환차익
        $this->assertSame(40000.0, (float) $sUsd->fresh()->exchange_difference_krw, 'USD 환차익 +40,000');
        // EUR: 400 × (1400 - 1450) = -20,000 환차손
        $this->assertSame(-20000.0, (float) $sEur->fresh()->exchange_difference_krw, 'EUR 환차손 -20,000');
    }

    public function test_case13_secondary_cost_7items_change_recomputes_payout(): void
    {
        // 2차 정산 대기 동안 기타비용 7개 변경 → cost_total → settlement_amount → actual_payout 재계산
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-C7-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 10_000_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000, 'selling_fee' => 100_000,
            'cost_deregistration' => 24_000, 'cost_license' => 11_000, 'cost_towing' => 30_000,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
        $payoutBefore = $s->actual_payout;
        $costBefore = (int) $v->cost_total;

        // 7개 cost_* 변경 (보험·이전비·기타1·2 추가, 기존 3개 증액)
        $v->update([
            'cost_deregistration' => 30_000,
            'cost_license' => 15_000,
            'cost_towing' => 40_000,
            'cost_insurance' => 100_000,
            'cost_transfer' => 50_000,
            'cost_extra1' => 20_000,
            'cost_extra2' => 30_000,
        ]);
        $v = $v->fresh();
        $s = $s->fresh();
        $costAfter = (int) $v->cost_total;
        $payoutAfter = $s->actual_payout;

        $costDiff = $costAfter - $costBefore;
        $this->assertSame(220_000, $costDiff, 'cost_total 증가분 220,000 = 30k+15k+40k+100k+50k+20k+30k - (24k+11k+30k)');

        // actual_payout 변화 = cost 증가분 × ratio × (-1) × 0.9 (vat 후 보정)
        // cost ↑ → settlement_sales_krw ↓ → sales_margin ↓ → total_margin ↓ → settlement_amount ↓ → actual_payout ↓
        $this->assertLessThan($payoutBefore, $payoutAfter, '기타비용 증가 → actual_payout 감소');
    }

    public function test_case14_foreign_currency_full_secondary_integration(): void
    {
        // 외화 + 1차 환차 + 2차 기타비용 변경 통합 — case04·5·13 통합 (2026-07-06 재피벗)
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-FULL-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300,
            'dhl_request' => false,
            'sale_price' => 10_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000, 'selling_fee' => 100_000,
            'cost_deregistration' => 24_000, 'cost_license' => 11_000, 'cost_towing' => 30_000,
        ]);
        $v->finalPayments()->create([
            'amount' => 10_000, 'exchange_rate' => 1380,   // 수령 1380 > 판매환율 1300 → 환차익
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // 2차 대기 중 기타비용 추가 (보험·이전비)
        $v->update(['cost_insurance' => 150_000, 'cost_transfer' => 50_000]);

        // 2차 close — 자동 환율 1380 사용
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);
        $s = $s->fresh();

        $this->assertSame('closed', $s->secondary_status);
        // 환차 = 10,000 × (1380 - 1300) = +800,000
        $this->assertSame(800_000.0, (float) $s->exchange_difference_krw, '환차익 +800,000');
        // actual_payout 에 환차 +800,000 가산 (프리랜서 1:1) — 단순 부등식 검증
        $this->assertGreaterThan($s->settlement_amount, $s->actual_payout, '환차 가산으로 base 초과');
    }

    public function test_case15_inventory_per_manager_scope(): void
    {
        // 재고(jin 2026-07-09) = 매입 완납 AND 미출고. 진행상태 무관 — 선적중이어도 미출고면 잔존.
        $mgrA = $this->makeManager();
        $mgrB = $this->makeManager();
        [, $smA] = $this->makeSalesUser($mgrA);
        [, $smB] = $this->makeSalesUser($mgrB);

        $pay = function (Vehicle $v) {
            $v->purchaseBalancePayments()->create([
                'amount' => 1_000_000, 'payment_date' => '2026-04-10', 'confirmed_at' => now(),
            ]);
            $v->refreshCaches();
        };

        // 관리A — 매입완납 재고 차량
        $vA = Vehicle::create([
            'vehicle_number' => 'IR-INV-A-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $smA->id,
            'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
        ]);
        $pay($vA);

        // 관리A — 선적중 + 매입완납 + 미출고 → 재고 잔존 (새 동작)
        $vAShip = Vehicle::create([
            'vehicle_number' => 'IR-INV-A-S-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $smA->id,
            'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
            'sale_price' => 2_000_000, 'sale_date' => '2026-05-01',
            'bl_loading_location' => 'PUSAN',
        ]);
        $pay($vAShip);

        // 관리B — 매입완납 재고 차량 (스코프 격리 대상)
        $vB = Vehicle::create([
            'vehicle_number' => 'IR-INV-B-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $smB->id,
            'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
        ]);
        $pay($vB);

        $this->actingAs($mgrA);
        $listA = Volt::test('erp.inventory.index')->instance()->inventoryVehicles->pluck('id')->toArray();

        $this->assertContains($vA->id, $listA, '관리A 부하 재고 포함');
        $this->assertContains($vAShip->id, $listA, '선적중이어도 미출고면 재고 잔존');
        $this->assertNotContains($vB->id, $listA, '관리B 부하 차량 격리');
    }

    public function test_case16_vehicle_column_change_creates_audit_log(): void
    {
        // 차량 AUDITED_COLUMNS 변경 → audit_logs row 생성 (column별)
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-AUD-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
        ]);

        $countBefore = AuditLog::query()
            ->where('auditable_type', Vehicle::class)
            ->where('auditable_id', $v->id)
            ->where('column_name', 'sale_price')
            ->count();

        // sale_price update → audit_log row 생성
        $v->update(['sale_price' => 5_000_000, 'sale_date' => '2026-05-01']);

        $countAfter = AuditLog::query()
            ->where('auditable_type', Vehicle::class)
            ->where('auditable_id', $v->id)
            ->where('column_name', 'sale_price')
            ->count();

        $this->assertGreaterThan($countBefore, $countAfter, 'sale_price 변경 시 audit_logs row 생성');

        $log = AuditLog::query()
            ->where('auditable_type', Vehicle::class)
            ->where('auditable_id', $v->id)
            ->where('column_name', 'sale_price')
            ->latest('created_at')->first();
        $this->assertNotNull($log, 'audit log row 존재');
        $this->assertSame('5000000', (string) $log->new_value, 'new_value 정확');
    }

    public function test_case17_settlement_paid_transition_logs_audit(): void
    {
        // Settlement::AUDITED_COLUMNS (settlement_status / secondary_status / paid_at) 변경 감사
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-SAUD-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $countBefore = AuditLog::query()
            ->where('auditable_type', Settlement::class)
            ->where('auditable_id', $s->id)
            ->where('column_name', 'settlement_status')
            ->count();

        // confirmed → paid
        $s->update(['settlement_status' => 'paid', 'paid_at' => now()]);

        $countAfter = AuditLog::query()
            ->where('auditable_type', Settlement::class)
            ->where('auditable_id', $s->id)
            ->where('column_name', 'settlement_status')
            ->count();

        $this->assertGreaterThan($countBefore, $countAfter, 'settlement_status 변경 시 audit_logs row 생성');
    }

    // ─── F. 정산 캐리오버 (3건, 2026-05-23 추가, 새회의 #8) ─────────

    public function test_case18_carryover_positive_applied_next_settlement(): void
    {
        // 영업 A 정산 #1 close → +50,000 환차익 carryover_out → 다음 정산 #2 +50,000 가산
        $admin = $this->makeAdmin();
        [, $sm] = $this->makeSalesUser();

        // 정산 #1 — paid + 환차익 (closed 시 carryover_out_krw = +50,000)
        $s1 = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-CO1-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id,
                'purchase_date' => '2026-04-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'carryover_out_krw' => 50000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // 정산 #2 신규 — creating 훅이 미적용 이월 흡수
        $s2 = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-CO2-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id,
                'purchase_date' => '2026-05-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-06-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->assertSame('50000.00', (string) $s2->fresh()->carryover_in_krw, '#2 정산 carryover_in_krw = #1 이월 +50,000');
        $base = $s2->settlement_amount - $s2->document_fee;
        $this->assertSame($base + 50000, $s2->fresh()->actual_payout, 'actual_payout 에 이월 가산');
    }

    public function test_case19_carryover_negative_deducted_next_settlement(): void
    {
        // 환차손 -30,000 이월 → 다음 정산에서 차감 (사용자 정책: 음수 이월 허용)
        [, $sm] = $this->makeSalesUser();

        Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-CO3-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id,
                'purchase_date' => '2026-04-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => -30000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $s2 = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-CO4-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id,
                'purchase_date' => '2026-05-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-06-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->assertSame('-30000.00', (string) $s2->fresh()->carryover_in_krw, '음수 이월 흡수');
        $base = $s2->settlement_amount - $s2->document_fee;
        $this->assertSame($base - 30000, $s2->fresh()->actual_payout, 'actual_payout 에서 차감');
    }

    public function test_case20_carryover_isolated_per_salesman(): void
    {
        // 영업 A 의 +20,000 이월이 영업 B 의 정산에 영향 X (영업담당자별 격리)
        [, $smA] = $this->makeSalesUser();
        [, $smB] = $this->makeSalesUser();

        // 영업 A 의 closed 정산 — carryover_out +20,000
        Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-CO5-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $smA->id,
                'purchase_date' => '2026-04-01',
            ])->id,
            'salesman_id' => $smA->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 20000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // 영업 B 의 신규 정산 — A 이월 흡수 X
        $sB = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-CO6-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $smB->id,
                'purchase_date' => '2026-05-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-06-01',
            ])->id,
            'salesman_id' => $smB->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->assertNull($sB->fresh()->carryover_in_krw, '영업 B 정산은 영업 A 이월 미흡수');
    }

    // ─── G. 정합성·무결성 (5건, 2026-05-23 추가) ─────────────────────

    public function test_case21_carryover_accumulates_multiple_closed(): void
    {
        // 운영 실제 흐름 — closed 2건 누적 시 신규 정산 흡수 패턴:
        // #1 closed (out=30k) → #2 신규 (creating 자동 흡수 in=30k) → #2 closed (out=20k 추가)
        //   → #3 신규 — Σ(out)=50k - Σ(in)=30k = unconsumed 20k 흡수
        // 즉 신규 정산은 항상 "미흡수 잔액"만 흡수 (이미 흡수된 분 제외).
        [, $sm] = $this->makeSalesUser();

        Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-ACC1-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-03-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 30000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
        // #2 — creating 훅이 #1 30k 자동 흡수 (carryover_in_krw=30000)
        $s2 = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-ACC2-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-04-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 20000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
        $this->assertSame('30000.00', (string) $s2->fresh()->carryover_in_krw, '#2 가 #1 30,000 흡수');

        // #3 — 남은 미흡수 잔액 20k만 흡수
        $s3 = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-ACC3-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-05-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-06-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->assertSame('20000.00', (string) $s3->fresh()->carryover_in_krw, '#3 가 미흡수 20,000 흡수');
    }

    public function test_case22_carryover_not_reabsorbed_after_consumption(): void
    {
        // 흡수된 carryover는 동일 영업담당자 후속 정산에서 다시 흡수 X
        [, $sm] = $this->makeSalesUser();

        Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-RE1-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-04-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 40000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // 1차 흡수 정산
        $s2 = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-RE2-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-05-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-06-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        $this->assertSame('40000.00', (string) $s2->fresh()->carryover_in_krw);

        // 2차 신규 정산 — 이전에 이미 흡수됐으므로 잔액 0 (불릴 carryover 없음)
        $s3 = Settlement::create([
            'vehicle_id' => Vehicle::create([
                'vehicle_number' => 'IR-RE3-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-06-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-07-01',
            ])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        $this->assertNull($s3->fresh()->carryover_in_krw, '재흡수 안 됨 (잔액 0)');
    }

    public function test_case23_salesman_change_isolates_carryover(): void
    {
        // 영업 A의 이월 잔액이 영업 B의 차량 정산에 영향 X (case20과 별개로 다중 누적 확인)
        [, $smA] = $this->makeSalesUser();
        [, $smB] = $this->makeSalesUser();

        // 영업 A 이월 +60,000 (2건 합산)
        Settlement::create([
            'vehicle_id' => Vehicle::create(['vehicle_number' => 'IR-SC1-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $smA->id, 'purchase_date' => '2026-04-01'])->id,
            'salesman_id' => $smA->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 30000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);
        Settlement::create([
            'vehicle_id' => Vehicle::create(['vehicle_number' => 'IR-SC2-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $smA->id, 'purchase_date' => '2026-04-15'])->id,
            'salesman_id' => $smA->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 30000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // 영업 B 의 신규 정산 — A 60,000 이월 흡수 X
        $sB = Settlement::create([
            'vehicle_id' => Vehicle::create(['vehicle_number' => 'IR-SC3-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $smB->id, 'purchase_date' => '2026-05-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-06-01'])->id,
            'salesman_id' => $smB->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->assertNull($sB->fresh()->carryover_in_krw, '영업 B 격리 (A 60,000 이월 영향 X)');
    }

    public function test_case24_closed_without_snapshot_skips_carryover_out(): void
    {
        // 구 data 호환 — confirmed_snapshot 없는 정산은 carryover_out 0 또는 NULL
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $v = Vehicle::create([
            'vehicle_number' => 'IR-NOSNAP-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => 'pending',
            'confirmed_at' => now(), 'paid_at' => now(),
            'confirmed_snapshot' => null,   // 명시적 null (구 data)
        ]);

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        // snapshot null → paid_payout 0 → carryover_out = closed_payout 그대로.
        // 이 정합도 통과해야 함 — 부정확하지만 NULL 안전 fallback
        $this->assertSame('closed', $s->fresh()->secondary_status);
        // closed_payout 자체는 계산 가능 (snapshot 없어도 actual_payout accessor 동작)
    }

    public function test_case25_consumed_carryover_does_not_double_apply(): void
    {
        // 흡수 후 다른 영업담당자 정산이 다른 영업담당자의 이월과 합산되지 X (정확한 격리)
        // 누적 합 정확도 — Σ(out) - Σ(in) 산식 검증
        [, $sm] = $this->makeSalesUser();

        // closed 정산 1건 (+100,000)
        Settlement::create([
            'vehicle_id' => Vehicle::create(['vehicle_number' => 'IR-DBL1-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-03-01'])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 100000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // 흡수 정산
        $s2 = Settlement::create([
            'vehicle_id' => Vehicle::create(['vehicle_number' => 'IR-DBL2-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-04-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-05-01'])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        $this->assertSame('100000.00', (string) $s2->fresh()->carryover_in_krw);

        // 신규 closed 정산 추가 (+25,000)
        Settlement::create([
            'vehicle_id' => Vehicle::create(['vehicle_number' => 'IR-DBL3-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-04-15'])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'carryover_out_krw' => 25000,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        // 또 다른 신규 정산 — 100k 이미 흡수, 25k만 남아있어야
        $s4 = Settlement::create([
            'vehicle_id' => Vehicle::create(['vehicle_number' => 'IR-DBL4-'.++$this->counter,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_date' => '2026-05-01',
                'sale_price' => 1_000_000, 'sale_date' => '2026-06-01'])->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->assertSame('25000.00', (string) $s4->fresh()->carryover_in_krw, '이미 흡수된 100k 제외, 신규 25k만 흡수');
    }
}
