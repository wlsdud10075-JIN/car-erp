<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 보증금 매입 funding (C2, jin 2026-07-21) — 소스 보증금(외화)으로 대상 매입대금(원화) funding → 매입 GREEN.
 *   흐름 = 기안(applyPurchaseFunding) → 관리 승인(approvePurchaseFunding) → 재무 실물확정(confirmPurchaseFundingByFinance).
 *   재무확정 시: 소스 −FinalPayment(외화, 미수↑) + 대상 매입 PBP(원화, confirmed) → 매입 GREEN. 여력 자동 감소.
 */
class PurchaseFundingTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InterVehicleTransferService;
    }

    /** 소스 S1($100k 완납, 환율 1300) + 대상 T3(매입 3천만) + 기안/관리/재무 3인. */
    private function ctx(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);
        $finance = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        $source = Vehicle::create([
            'vehicle_number' => 'S1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 100_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);
        $source->finalPayments()->create([
            'amount' => 100_000, 'type' => 'balance', 'payment_date' => '2026-05-02',
            'exchange_rate' => 1300, 'confirmed_at' => now(),
        ]);
        $source->refresh();

        $target = Vehicle::create([
            'vehicle_number' => 'T3', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'purchase_date' => '2026-05-10', 'purchase_price' => 30_000_000,
        ]);

        return compact('buyer', 'drafter', 'manager', 'finance', 'source', 'target');
    }

    public function test_apply_creates_pending_no_ledger(): void
    {
        ['drafter' => $drafter, 'source' => $source, 'target' => $target] = $this->ctx();

        $t = $this->service->applyPurchaseFunding($source, $target, 30_000_000, $drafter, '매입 funding');

        $this->assertSame(InterVehicleTransfer::KIND_PURCHASE_FUNDING, $t->kind);
        $this->assertSame(InterVehicleTransfer::STATUS_PENDING, $t->status);
        $this->assertEqualsWithDelta(23076.92, (float) $t->amount, 0.01, '소스 차감 외화 = 3천만÷1300');
        $this->assertEquals(30_000_000, (int) $t->amount_krw, '매입 funding 원화 정확값');
        $this->assertEquals(1300, (int) $t->source_exchange_rate, '소스 환율 스냅샷');
        $this->assertSame(ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING, $t->approvalRequest->action_type);
        // 재무 확정 전 — ledger 미생성
        $this->assertSame(0, FinalPayment::where('transfer_id', $t->id)->count());
        $this->assertNull($t->purchase_balance_payment_id);
    }

    public function test_full_flow_green_receivable_and_gauge(): void
    {
        ['buyer' => $buyer, 'drafter' => $drafter, 'manager' => $manager, 'finance' => $finance,
            'source' => $source, 'target' => $target] = $this->ctx();

        $before = Buyer::computeReceivableGauge($buyer->vehicles()->get());
        $this->assertSame(65_000_000, (int) $before['available_krw']);

        $t = $this->service->applyPurchaseFunding($source, $target, 30_000_000, $drafter);
        $this->service->approvePurchaseFunding($t, $manager);
        $this->assertSame(InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, $t->fresh()->status);
        // 관리 승인만으론 아직 ledger 없음
        $this->assertSame(0, FinalPayment::where('transfer_id', $t->id)->count());

        $this->service->confirmPurchaseFundingByFinance($t, $finance, '은행이체 완료');
        $t->refresh();
        $this->assertSame(InterVehicleTransfer::STATUS_EXECUTED, $t->status);

        // 소스 −FinalPayment (외화, 미수↑, ratio ≤ 100%)
        $srcFp = FinalPayment::where('transfer_id', $t->id)->where('vehicle_id', $source->id)->first();
        $this->assertEqualsWithDelta(-23076.92, (float) $srcFp->amount, 0.01);
        $source->refresh();
        $this->assertLessThanOrEqual(1.0, $source->unpaid_ratio, '소스 미수율 ≤ 100% (오버플로 없음)');
        $this->assertEqualsWithDelta(0.231, $source->unpaid_ratio, 0.01);

        // 대상 매입 PBP → 매입 GREEN
        $pbp = PurchaseBalancePayment::find($t->purchase_balance_payment_id);
        $this->assertNotNull($pbp);
        $this->assertEquals(30_000_000, (int) $pbp->amount, '매입 원화 정확');
        $this->assertNotNull($pbp->confirmed_at);
        $target->refresh();
        $this->assertSame(0, (int) $target->purchase_unpaid_amount, '대상 매입 미지급 0');
        $this->assertSame('매입완료', $target->progress_status_cache, '매입 GREEN');

        // 여력 자동 감소 = funding KRW 만큼
        $after = Buyer::computeReceivableGauge($buyer->vehicles()->get());
        $this->assertEqualsWithDelta(30_000_000, (int) $before['available_krw'] - (int) $after['available_krw'], 2000,
            '여력이 funding 원화만큼 자동 감소');

        // 대상 판매 미수 불변 (매입 GREEN 만, 판매 안 건드림)
        $this->assertSame(0, FinalPayment::where('vehicle_id', $target->id)->count());
    }

    public function test_approval_execute_routes_to_approve(): void
    {
        ['drafter' => $drafter, 'manager' => $manager, 'source' => $source, 'target' => $target] = $this->ctx();
        $t = $this->service->applyPurchaseFunding($source, $target, 20_000_000, $drafter);

        $this->actingAs($manager);
        $t->approvalRequest->execute();

        $this->assertSame(InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, $t->fresh()->status);
        // execute 는 관리 승인만 — 재무 확정 전이라 ledger 없음
        $this->assertSame(0, FinalPayment::where('transfer_id', $t->id)->count());
    }

    public function test_sod_approver_cannot_confirm(): void
    {
        ['drafter' => $drafter, 'manager' => $manager, 'source' => $source, 'target' => $target] = $this->ctx();
        $t = $this->service->applyPurchaseFunding($source, $target, 20_000_000, $drafter);
        $this->service->approvePurchaseFunding($t, $manager);

        $this->expectException(DomainException::class);
        $this->service->confirmPurchaseFundingByFinance($t, $manager);   // 승인자 = 확정자 차단
    }

    public function test_guard_source_under_50_percent_blocks(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $source = Vehicle::create([
            'vehicle_number' => 'X1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 100_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);
        $source->finalPayments()->create(['amount' => 30_000, 'type' => 'balance', 'payment_date' => '2026-05-02', 'exchange_rate' => 1300, 'confirmed_at' => now()]);  // 30%
        $source->refresh();
        $target = Vehicle::create([
            'vehicle_number' => 'X2', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'purchase_date' => '2026-05-10', 'purchase_price' => 10_000_000,
        ]);

        $this->expectException(DomainException::class);
        $this->service->applyPurchaseFunding($source, $target, 5_000_000, $drafter);
    }

    public function test_guard_exceeds_buyer_available_blocks(): void
    {
        ['drafter' => $drafter, 'source' => $source, 'target' => $target] = $this->ctx();
        // 여력 65M 인데 70M funding 시도 → 차단
        $this->expectException(DomainException::class);
        $this->service->applyPurchaseFunding($source, $target, 70_000_000, $drafter);
    }

    public function test_volt_submit_creates_funding_request(): void
    {
        ['source' => $source, 'target' => $target] = $this->ctx();
        // 기안 권한 + 전체 스코프(담당자 없는 차 openEdit)인 super 로 UI 흐름 검증.
        $drafter = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($drafter);

        \Livewire\Volt\Volt::test('erp.vehicles.index')
            ->call('openEdit', $target->id)
            ->set('pfSourceId', (string) $source->id)
            ->set('pfAmountKrwStr', '30,000,000')
            ->set('pfReason', '바이어 신용으로 매입대금 선지급')
            ->call('submitPurchaseFunding')
            ->assertHasNoErrors();

        $t = InterVehicleTransfer::where('kind', InterVehicleTransfer::KIND_PURCHASE_FUNDING)->first();
        $this->assertNotNull($t);
        $this->assertSame($source->id, $t->source_vehicle_id);
        $this->assertSame($target->id, $t->target_vehicle_id);
        $this->assertSame(InterVehicleTransfer::STATUS_PENDING, $t->status);
        $this->assertEquals(30_000_000, (int) $t->amount_krw);
    }
}
