<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 매입취소 Layer 2 (jin 2026-07-18) — 매입 탭 취소 마커 전환/해제.
 * 위약금은 판매가(sale_price) 재사용, cancel_status 마커로 판매통계·정산 분기. '취소완료'는 미수0 계산.
 */
class PurchaseCancelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '222나4513',
            'sales_channel' => 'export',
            'currency' => 'EUR',
            'exchange_rate' => 1746,
            'purchase_price' => 1_000_000,
        ]);
    }

    public function test_mark_sets_cancelled(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('markPurchaseCancelled')
            ->assertSet('cancelStatus', Vehicle::CANCEL_ACTIVE);

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_ACTIVE, $v->cancel_status);
        $this->assertNotNull($v->cancelled_at);
    }

    public function test_unmark_reverts_to_none(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();
        $v->update(['cancel_status' => Vehicle::CANCEL_ACTIVE, 'cancelled_at' => now()]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('unmarkPurchaseCancelled')
            ->assertSet('cancelStatus', Vehicle::CANCEL_NONE);

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_NONE, $v->cancel_status);
        $this->assertNull($v->cancelled_at);
    }

    public function test_closed_cannot_be_unmarked(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle();
        $v->update(['cancel_status' => Vehicle::CANCEL_CLOSED, 'cancelled_at' => now()]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('unmarkPurchaseCancelled')
            ->assertSet('cancelStatus', Vehicle::CANCEL_CLOSED);

        $this->assertSame(Vehicle::CANCEL_CLOSED, $v->refresh()->cancel_status);
    }

    public function test_receivable_cancel_filter_where_clause(): void
    {
        $buyer = Buyer::create(['name' => 'RB', 'is_active' => true]);
        $mk = fn (string $plate, string $cancel) => Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1_000_000,
            'sale_date' => now()->format('Y-m-d'), 'purchase_date' => now()->format('Y-m-d'),
            'buyer_id' => $buyer->id, 'cancel_status' => $cancel,
        ]);
        $mk('NORMALCAR', Vehicle::CANCEL_NONE);
        $mk('CXLCAR', Vehicle::CANCEL_ACTIVE);

        // buildQuery 의 cancelFilter 절과 동일한 WHERE — 취소만 / 정상만 분리.
        $base = fn () => Vehicle::query()->where('sale_price', '>', 0);
        $cancelledOnly = $base()->where('cancel_status', '!=', Vehicle::CANCEL_NONE)->pluck('vehicle_number');
        $normalOnly = $base()->where('cancel_status', Vehicle::CANCEL_NONE)->pluck('vehicle_number');

        $this->assertTrue($cancelledOnly->contains('CXLCAR'));
        $this->assertFalse($cancelledOnly->contains('NORMALCAR'));
        $this->assertTrue($normalOnly->contains('NORMALCAR'));
        $this->assertFalse($normalOnly->contains('CXLCAR'));
    }

    public function test_admin_dashboard_receivable_kpis_count_cancels(): void
    {
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $mk = fn (string $plate, string $cancel) => Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1_000_000,
            'sale_date' => now()->format('Y-m-d'), 'purchase_date' => now()->format('Y-m-d'),
            'buyer_id' => $buyer->id, 'cancel_status' => $cancel,
        ]);
        $mk('AC1', Vehicle::CANCEL_ACTIVE);
        $mk('AC2', Vehicle::CANCEL_CLOSED);
        $mk('AC3', Vehicle::CANCEL_NONE);

        $kpis = Volt::test('admin.dashboard')
            ->set('dateFrom', '2000-01-01')->set('dateTo', '2100-01-01')
            ->instance()->receivableKpis();

        $this->assertSame(1, $kpis['cancel_active']);
        $this->assertSame(1, $kpis['cancel_closed']);
    }

    private function cancelledUnpaidVehicle(string $plate, string $smType): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.$plate, 'is_active' => true]);
        $sm = Salesman::create(['name' => 'S'.$plate, 'is_active' => true, 'type' => $smType]);

        return Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1746, 'sale_price' => 600,
            'sale_date' => '2026-05-25', 'buyer_id' => $buyer->id, 'salesman_id' => $sm->id,
            'cancel_status' => Vehicle::CANCEL_ACTIVE,
        ]);
    }

    public function test_close_freezes_shortfall_freelancer_half(): void
    {
        $this->actingAs($this->admin());
        $v = $this->cancelledUnpaidVehicle('CL1', 'freelance');   // 600 EUR 미수 × 1746 = 1,047,600

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('closePurchaseCancelUnpaid')
            ->assertSet('cancelStatus', Vehicle::CANCEL_CLOSED);

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_CLOSED, $v->cancel_status);
        $this->assertSame(1_047_600, (int) $v->cancel_shortfall_krw);
        $this->assertSame(523_800, $v->cancel_freelancer_loss_krw);   // 프리랜서 절반
    }

    public function test_close_employee_bears_no_loss(): void
    {
        $this->actingAs($this->admin());
        $v = $this->cancelledUnpaidVehicle('CL2', 'employee');

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call('closePurchaseCancelUnpaid');

        $v->refresh();
        $this->assertSame(1_047_600, (int) $v->cancel_shortfall_krw);
        $this->assertSame(0, $v->cancel_freelancer_loss_krw);   // 사내직원 = 회사 전액 부담
    }

    public function test_close_blocked_when_fully_paid(): void
    {
        $this->actingAs($this->admin());
        $v = $this->cancelledUnpaidVehicle('CL3', 'freelance');
        $v->finalPayments()->create([
            'amount' => 600, 'type' => 'balance', 'payment_date' => '2026-05-27', 'confirmed_at' => now(),
        ]);   // 완납

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call('closePurchaseCancelUnpaid');

        $v->refresh();
        $this->assertSame(Vehicle::CANCEL_ACTIVE, $v->cancel_status);   // 마감 안 됨
        $this->assertNull($v->cancel_shortfall_krw);
    }

    /**
     * 미반영 매입취소 손실 집계 — 프리랜서만, 부족분의 절반.
     *
     * 🔀 2026-08-06 (jin) — 집계 위치가 월배치 화면 computed 에서 `Vehicle::unsettledCancelLossBySalesman()`
     *   단일 출처로 옮겨졌다(정산관리 카드 + 제출 모달 공용). 「반영 표시」 수동 버튼은 사라지고
     *   배치 최종 승인 시 자동으로 찍힌다 — 상세 = SettlementBatchSubmitModalTest.
     */
    public function test_unsettled_cancel_loss_by_salesman(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]));
        $fm = Salesman::create(['name' => 'Free', 'is_active' => true, 'type' => 'freelance']);
        $em = Salesman::create(['name' => 'Emp', 'is_active' => true, 'type' => 'employee']);
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $mkClosed = fn (string $plate, Salesman $sm, int $shortfall) => Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'sale_price' => $shortfall, 'sale_date' => '2026-07-05', 'buyer_id' => $buyer->id, 'salesman_id' => $sm->id,
            'cancel_status' => Vehicle::CANCEL_CLOSED, 'cancel_shortfall_krw' => $shortfall, 'cancelled_at' => '2026-07-10',
        ]);
        $f1 = $mkClosed('F1', $fm, 1_000_000);   // 몫 500,000
        $mkClosed('F2', $fm, 600_000);           // 몫 300,000
        $mkClosed('E1', $em, 800_000);           // 사내직원 → 회사 전액 부담, 제외

        $map = Vehicle::unsettledCancelLossBySalesman();

        $this->assertCount(1, $map, '프리랜서 1명만');
        $this->assertSame(800_000, $map[$fm->id]['sum']);          // 500k + 300k
        $this->assertArrayNotHasKey($em->id, $map);
        // 차량번호가 아니라 id 로 준다 — 같은 차량번호가 여러 행일 수 있다.
        $this->assertContains($f1->id, $map[$fm->id]['vehicle_ids']);

        // 반영 도장이 찍히면 집계에서 빠진다 (이중청구 방지).
        Vehicle::where('salesman_id', $fm->id)->update(['cancel_loss_settled_at' => now()]);
        $this->assertSame([], Vehicle::unsettledCancelLossBySalesman());
    }

    public function test_sales_stats_exclude_cancels_receivable_includes(): void
    {
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $mk = fn (string $plate, string $cancel) => Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'sale_price' => 1_000_000, 'sale_date' => now()->subDays(30)->format('Y-m-d'),
            'purchase_date' => now()->subDays(30)->format('Y-m-d'), 'buyer_id' => $buyer->id, 'cancel_status' => $cancel,
        ]);
        $mk('SR1', Vehicle::CANCEL_NONE);      // 실판매
        $mk('SR2', Vehicle::CANCEL_ACTIVE);    // 매입취소(위약금)

        $kpis = Volt::test('admin.dashboard')
            ->set('dateFrom', '2000-01-01')->set('dateTo', '2100-01-01')
            ->instance()->kpis();

        // 판매실적 = 취소차 제외
        $this->assertSame(1, $kpis['sale_count'], '판매량은 취소차 제외');
        $this->assertSame(1_000_000, $kpis['sale_total_krw'], '매출은 취소차 제외');
        $this->assertSame(1, $kpis['by_progress']['판매중'] ?? 0, '파이프라인은 취소차 제외');
        // 미수(채권) = 취소차 포함 (위약금도 실제 미수)
        $this->assertSame(2_000_000, $kpis['unpaid_krw'], '미수금은 취소차 포함');
    }

    public function test_panel_shows_done_label_when_paid(): void
    {
        // 222나4513 케이스 — 취소 + 위약금 완납 → 매입 탭 패널에 '취소완료' 표시.
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'PD1', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'sale_price' => 500, 'sale_date' => '2026-05-01', 'buyer_id' => $buyer->id, 'cancel_status' => Vehicle::CANCEL_ACTIVE,
        ]);
        $v->finalPayments()->create(['amount' => 500, 'type' => 'balance', 'payment_date' => '2026-05-02', 'confirmed_at' => now()]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('cancelStatusLabel', '취소완료');
    }

    public function test_vehicle_list_cancel_filter_splits_active_done_closed(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $base = fn (string $plate, string $cancel) => Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'sale_price' => 500_000, 'sale_date' => '2026-05-01', 'buyer_id' => $buyer->id, 'cancel_status' => $cancel,
        ]);
        $base('CA1', Vehicle::CANCEL_ACTIVE);                        // 미납 → cache>0 (매입취소 미수)
        $done = $base('CD1', Vehicle::CANCEL_ACTIVE);
        $done->finalPayments()->create(['amount' => 500_000, 'type' => 'balance', 'payment_date' => '2026-05-02', 'confirmed_at' => now()]);  // 완납 → cache 0 (취소완료)
        $base('CC1', Vehicle::CANCEL_CLOSED);
        $base('CN1', Vehicle::CANCEL_NONE);

        // 차량목록 필터 WHERE 재현
        $activeQ = Vehicle::query()->where('cancel_status', Vehicle::CANCEL_ACTIVE)->where('sale_unpaid_amount_krw_cache', '>', 0)->pluck('vehicle_number');
        $doneQ = Vehicle::query()->where('cancel_status', Vehicle::CANCEL_ACTIVE)
            ->where(fn ($q) => $q->where('sale_unpaid_amount_krw_cache', '<=', 0)->orWhereNull('sale_unpaid_amount_krw_cache'))->pluck('vehicle_number');
        $closedQ = Vehicle::query()->where('cancel_status', Vehicle::CANCEL_CLOSED)->pluck('vehicle_number');

        $this->assertTrue($activeQ->contains('CA1') && ! $activeQ->contains('CD1'), '매입취소(미수)=CA1만');
        $this->assertTrue($doneQ->contains('CD1') && ! $doneQ->contains('CA1'), '취소완료=CD1만');
        $this->assertTrue($closedQ->contains('CC1'), '미수마감=CC1');
    }

    public function test_label_computes_done_from_unpaid(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $v = $this->makeVehicle();
        $v->update([
            'cancel_status' => Vehicle::CANCEL_ACTIVE,
            'sale_price' => 600, 'sale_date' => '2026-05-25', 'buyer_id' => $buyer->id,
        ]);

        // 미수 > 0 → '매입취소'
        $this->assertSame('매입취소', $v->fresh()->cancel_status_label);

        // 위약금 완납 → '취소완료'(계산)
        $v->finalPayments()->create([
            'amount' => 600, 'type' => 'balance', 'payment_date' => '2026-05-27', 'confirmed_at' => now(),
        ]);
        $this->assertSame('취소완료', $v->fresh()->cancel_status_label);
    }

    // ── 🚫 매입취소 차는 단계 큐에 안 들어간다 (jin 2026-09-09) ────────────────────
    //
    // 🚨 **실사고**: heymanerp `222나4513`(취소 · 위약금 완납 · 라벨 「취소완료」)이 **6개 큐**에 걸려
    //    매입 완납일부터 **107일째** 말소 재촉을 받고 있었다. 위약금을 `sale_price` 로 추적하는 구조
    //    때문에 진행상태가 「판매완료」로 계산되고, 그 아래 단계 큐가 전부 «수출해야 한다» 로 읽었다.

    /** 그 사고를 그대로 재현한 차 — 매입 완납 + 위약금 완납 + 말소 미처리. */
    private function cancelledFullyPaid(): Vehicle
    {
        $sm = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'EASY DRIVE', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => '222나4513',
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1746,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'purchase_price' => 1_000_000, 'purchase_date' => now()->subDays(107)->toDateString(),
            // 위약금 — 매입취소는 이 칸을 재사용한다(2026-07-18 설계)
            'sale_price' => 600, 'sale_date' => now()->subDays(100)->toDateString(),
            'cancel_status' => Vehicle::CANCEL_ACTIVE, 'cancelled_at' => now()->subDays(107),
        ]);
        // 매입 완납 — 이게 없으면 애초에 말소 큐에 안 들어가 테스트가 아무것도 검사하지 않는다.
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => 1_000_000,
            'payment_date' => now()->subDays(107)->toDateString(), 'confirmed_at' => now()->subDays(107),
        ]);
        // 위약금 완납 → 라벨이 「취소완료」가 된다
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 600,
            'payment_date' => now()->subDays(90)->toDateString(), 'confirmed_at' => now()->subDays(90),
        ]);
        $v->refresh()->refreshProgressCache();

        return $v->refresh();
    }

    /** 전제 — 이 차가 「취소완료」 · 매입 완납 · 말소 미처리라는 것부터 확인한다. */
    public function test_the_fixture_reproduces_the_reported_state(): void
    {
        $v = $this->cancelledFullyPaid();

        $this->assertSame('취소완료', $v->cancel_status_label);
        $this->assertSame(0, $v->purchase_unpaid_amount, '매입 완납이어야 말소 큐에 들어간다');
        $this->assertSame(0.0, (float) $v->sale_unpaid_amount, '위약금 완납이어야 「취소완료」다');
        $this->assertFalse((bool) $v->is_deregistered);
    }

    /** 🚫 단계 큐 16개 전부에서 빠진다 — 매입을 취소했으면 말소·통관·선적·B/L·DHL 이 영영 없다. */
    public function test_cancelled_vehicle_is_out_of_every_workflow_queue(): void
    {
        $v = $this->cancelledFullyPaid();

        $workflow = [
            'deregistration_needed',
            'clearance_needed', 'clearance_request_needed', 'clearance_info_missing',
            'clearance_stuck', 'clearance_candidates', 'forwarding_missing',
            'export_declaration_upload_needed',
            'shipping_needed', 'shipping_process_needed', 'bl_upload_needed',
            'dhl_needed', 'dhl_dispatch_needed',
            'eta_clearance_reminder', 'eta_missing',
            'document_deadline_reminder',
        ];
        foreach ($workflow as $action) {
            $this->assertFalse(
                Vehicle::query()->action($action)->where('id', $v->id)->exists(),
                "매입취소 차가 {$action} 큐에 남아 있다"
            );
        }
    }

    /**
     * ✅ **돈 큐는 그대로** — 위약금 채권 추적이 매입취소 기능의 본체다. 여기까지 빼면
     *    받을 돈이 화면에서 사라진다(jin 2026-09-09 확인: 매입 미지급도 재무가 봐야 할 돈).
     */
    public function test_cancelled_vehicle_stays_in_the_money_queues(): void
    {
        $sm = Salesman::create(['name' => '무사백2', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'EASY DRIVE 2', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => '222나4514',
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1746,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'purchase_price' => 1_000_000, 'purchase_date' => now()->subDays(107)->toDateString(),
            'sale_price' => 600, 'sale_date' => now()->subDays(100)->toDateString(),
            'cancel_status' => Vehicle::CANCEL_ACTIVE, 'cancelled_at' => now()->subDays(107),
        ]);
        $v->refresh()->refreshProgressCache();

        // 위약금 미수 — 계속 받아야 한다
        $this->assertTrue(Vehicle::query()->action('sale_unpaid')->where('id', $v->id)->exists(),
            '위약금 미수가 채권 큐에서 사라졌다');
        // 딜러 대금 미지급 — 재무가 봐야 할 돈
        $this->assertTrue(Vehicle::query()->action('purchase_unpaid')->where('id', $v->id)->exists(),
            '매입 미지급이 큐에서 사라졌다');
    }

    /**
     * 🔒 **대시보드 카운트와 목록 SQL 이 같아야 한다**(§8 #44). 둘이 갈리면 「카드엔 1대인데 눌러도
     *    아무것도 없는」 화면이 된다 — 대시보드가 `scopeAction` 을 그대로 쓰는지 확인한다.
     */
    public function test_dashboard_count_and_list_agree_after_the_exclusion(): void
    {
        $cancelled = $this->cancelledFullyPaid();

        // 대조군 — 취소가 아닌 같은 상태의 차는 그대로 잡혀야 한다(조건을 통째로 깨뜨린 게 아님을 증명)
        $normal = Vehicle::create([
            'vehicle_number' => '333다1111',
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1746,
            'dhl_request' => false, 'salesman_id' => $cancelled->salesman_id, 'buyer_id' => $cancelled->buyer_id,
            'purchase_price' => 1_000_000, 'purchase_date' => now()->subDays(107)->toDateString(),
        ]);
        PurchaseBalancePayment::create([
            'vehicle_id' => $normal->id, 'amount' => 1_000_000,
            'payment_date' => now()->subDays(107)->toDateString(), 'confirmed_at' => now()->subDays(107),
        ]);
        $normal->refresh()->refreshProgressCache();

        $ids = Vehicle::query()->action('deregistration_needed')->pluck('id')->all();

        $this->assertContains($normal->id, $ids, '정상 차량이 말소 큐에서 빠졌다 — 조건을 과하게 좁혔다');
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertSame(count($ids), Vehicle::query()->action('deregistration_needed')->count(),
            '카운트와 목록이 갈렸다');
    }

    /** 말소 재촉 알림톡도 같은 출처를 쓰므로 자동으로 빠진다 — 조건을 두 곳에 적지 않는다. */
    public function test_deregistration_reminder_does_not_target_a_cancelled_vehicle(): void
    {
        $v = $this->cancelledFullyPaid();

        $hit = Vehicle::query()->action('deregistration_needed')
            ->where('is_deregistered', false)
            ->where(fn ($q) => $q->whereNull('container_number')->orWhere('container_number', ''))
            ->where(fn ($q) => $q->whereNull('export_declaration_number')->orWhere('export_declaration_number', ''))
            ->whereNotNull('buyer_id')
            ->where('id', $v->id)->exists();

        $this->assertFalse($hit, '매입취소 차가 말소 재촉 알림톡 대상에 남아 있다');
    }
}
