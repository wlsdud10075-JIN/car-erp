<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\InterVehicleTransfer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 승인큐 관리 반려 → 연결 이체 종료 (2026-07-23, jin).
 *   빈틈: 반려 시 ApprovalRequest 만 rejected 되고 이체는 pending orphan → 차량 패널 배너 안 사라짐.
 *   fix: ApprovalRequest::onReject() 가 이체 생성 계열(이체/보증금적용/보증금매입선지급)의
 *        pending 이체를 STATUS_REJECTED 로 종료. void 반려는 이체 executed 유지(대상 아님).
 */
class ApprovalRejectCancelsTransferTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InterVehicleTransferService;
    }

    private function ctx(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);

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

        return compact('buyer', 'drafter', 'manager', 'source', 'target');
    }

    /** 승인큐 컴포넌트 reject 분기와 동일한 처리(상태 rejected + onReject). */
    private function rejectByManager(ApprovalRequest $req, User $manager): void
    {
        $req->update(['status' => 'rejected', 'approver_id' => $manager->id, 'decision_note' => '반려 사유', 'decided_at' => now()]);
        $req->onReject();
    }

    public function test_reject_purchase_funding_cancels_transfer(): void
    {
        ['drafter' => $drafter, 'manager' => $manager, 'source' => $source, 'target' => $target] = $this->ctx();

        $t = $this->service->applyPurchaseFunding($source, $target, 30_000_000, $drafter);
        $this->assertSame(InterVehicleTransfer::STATUS_PENDING, $t->fresh()->status);

        $this->rejectByManager($t->approvalRequest, $manager);

        $this->assertSame(InterVehicleTransfer::STATUS_REJECTED, $t->fresh()->status, '반려 시 이체 종료');
        // 차량 패널 배너 소스 = pending/awaiting_finance/voided_awaiting_finance — rejected 는 제외되어 배너 사라짐
        $this->assertNotContains($t->fresh()->status, [
            InterVehicleTransfer::STATUS_PENDING,
            InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
        ]);
        // ledger 미생성(반려라 실행 안 됨)
        $this->assertSame(0, $target->fresh()->purchaseBalancePayments()->count());
    }

    public function test_reject_of_non_transfer_type_is_noop(): void
    {
        // 이체와 무관한 승인요청 반려 시 onReject() 가 예외 없이 no-op
        $req = ApprovalRequest::create([
            'action_type' => ApprovalRequest::TYPE_UNPAID_EXPORT_OVERRIDE,
            'status' => 'pending',
            'payload' => [],
            'requester_id' => User::factory()->create(['email_verified_at' => now()])->id,
        ]);

        $req->update(['status' => 'rejected']);
        $req->onReject();   // 예외 없어야 함

        $this->assertSame('rejected', $req->fresh()->status);
    }
}
