<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 큐 19-B / 19-F — InterVehicleTransferService.
 * 한도·요청·관리 승인(approve)·재무 확정(confirmByFinance)·안전 가드 5종 검증.
 *
 * 5상태 머신:
 *   pending → approved_awaiting_finance → executed
 *           → voided_awaiting_finance   → voided
 */
class InterVehicleTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InterVehicleTransferService;
    }

    /**
     * 회의록 §13 T=0 시나리오 — 1번 차 1억 / 5천만 입금 (ratio 50%).
     * 큐 19-F — finance(정산 role) 사용자 추가 (관리 승인자와 분리해야 SoD 통과).
     */
    private function makeContext(int $sourceReceived = 50_000_000): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);

        $source = Vehicle::create([
            'vehicle_number' => '99가0001',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01',
            'sale_price' => 100_000_000,
            'currency' => 'KRW',
        ]);
        // 큐 22-A-3 — vehicles 4컬럼 DROP. 계약금은 confirmed FP rows 로 표현.
        if ($sourceReceived > 0) {
            $source->finalPayments()->create([
                'amount' => $sourceReceived,
                'type' => 'deposit_down',
                'confirmed_at' => now(),
            ]);
            $source->refresh();
        }
        $target = Vehicle::create([
            'vehicle_number' => '99가0002',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01',
            'sale_price' => 80_000_000,
            'currency' => 'KRW',
        ]);

        return compact('buyer', 'sales', 'manager', 'finance', 'source', 'target');
    }

    public function test_available_returns_received_times_half(): void
    {
        $c = $this->makeContext(50_000_000);
        $this->assertEquals(25_000_000.0, $this->service->available($c['source']));
    }

    public function test_available_zero_when_no_payment_received(): void
    {
        $c = $this->makeContext(0);
        $this->assertEquals(0.0, $this->service->available($c['source']));
    }

    public function test_request_creates_pending_transfer_and_approval(): void
    {
        $c = $this->makeContext();

        $transfer = $this->service->request(
            $c['source'], $c['target'],
            25_000_000,
            $c['sales'],
            reason: '바이어 2번 차 계약금 이체 요청',
        );

        $this->assertEquals(InterVehicleTransfer::STATUS_PENDING, $transfer->status);
        $this->assertEquals(25_000_000, $transfer->amount);
        $this->assertEquals('KRW', $transfer->currency);
        $this->assertEquals($c['buyer']->id, $transfer->buyer_id);
        $this->assertEquals($c['sales']->id, $transfer->requester_id);
        $this->assertNotNull($transfer->approval_request_id);

        $req = ApprovalRequest::find($transfer->approval_request_id);
        $this->assertEquals(ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER, $req->action_type);
        $this->assertEquals('pending', $req->status);
        $this->assertEquals($c['source']->id, $req->payload['source_vehicle_id']);
        $this->assertEquals($c['target']->id, $req->payload['target_vehicle_id']);
    }

    public function test_request_blocks_same_vehicle(): void
    {
        $c = $this->makeContext();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('동일할 수 없습니다');
        $this->service->request($c['source'], $c['source'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_different_buyers(): void
    {
        $c = $this->makeContext();
        $other = Buyer::create(['name' => 'OSAKA MOTORS', 'is_active' => true]);
        $c['target']->update(['buyer_id' => $other->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('바이어가 동일');
        $this->service->request($c['source'], $c['target'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_currency_mismatch(): void
    {
        $c = $this->makeContext();
        $c['target']->update(['currency' => 'USD']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('통화가 일치');
        $this->service->request($c['source'], $c['target'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_when_source_received_below_50pct(): void
    {
        // 1억 중 4천만 받음 → ratio 60% → 50% 미달
        $c = $this->makeContext(40_000_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('50% 이상 입금');
        $this->service->request($c['source'], $c['target'], 1_000_000, $c['sales']);
    }

    public function test_request_blocks_amount_over_limit(): void
    {
        $c = $this->makeContext(50_000_000);  // 한도 = 2500만

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('한도를 초과');
        $this->service->request($c['source'], $c['target'], 30_000_000, $c['sales']);
    }

    public function test_request_blocks_non_positive_amount(): void
    {
        $c = $this->makeContext();

        $this->expectException(DomainException::class);
        $this->service->request($c['source'], $c['target'], 0, $c['sales']);
    }

    /**
     * 큐 19-F — 관리 승인(approve)만 호출하면 final_payment 미생성, status는 대기 상태.
     */
    public function test_approve_does_not_create_final_payments(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, $transfer->status);
        $this->assertEquals($c['manager']->id, $transfer->approver_id);
        $this->assertNull($transfer->executed_at);
        $this->assertNull($transfer->confirmed_by_user_id);
        $this->assertNull($transfer->confirmed_at);

        $this->assertCount(0, FinalPayment::where('transfer_id', $transfer->id)->get());

        // 미수 캐시 — 이체 전 그대로 (5천만 입금 → 5천만 미수)
        $this->assertEquals(50_000_000, (int) $c['source']->fresh()->sale_unpaid_amount);
        $this->assertEquals(80_000_000, (int) $c['target']->fresh()->sale_unpaid_amount);
    }

    /**
     * 회의록 §13 T=2 — 관리 승인 → 재무 확정 → 1번 차 -2500만 + 2번 차 +2500만 트랜잭션.
     */
    public function test_confirm_by_finance_creates_paired_final_payments_and_updates_caches(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance'], '시중은행 거래번호 TEST123');

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_EXECUTED, $transfer->status);
        $this->assertNotNull($transfer->executed_at);
        $this->assertEquals($c['manager']->id, $transfer->approver_id);
        $this->assertEquals($c['finance']->id, $transfer->confirmed_by_user_id);
        $this->assertNotNull($transfer->confirmed_at);
        $this->assertEquals('시중은행 거래번호 TEST123', $transfer->finance_note);

        $payments = FinalPayment::where('transfer_id', $transfer->id)->orderBy('amount')->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(-25_000_000, $payments[0]->amount);
        $this->assertEquals($c['source']->id, $payments[0]->vehicle_id);
        $this->assertEquals(25_000_000, $payments[1]->amount);
        $this->assertEquals($c['target']->id, $payments[1]->vehicle_id);

        $source = $c['source']->fresh();
        $target = $c['target']->fresh();
        $this->assertEquals(75_000_000, (int) $source->sale_unpaid_amount);
        $this->assertEquals(55_000_000, (int) $target->sale_unpaid_amount);
    }

    public function test_approve_blocks_already_approved_transfer(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('이미 처리된');
        $this->service->approve($transfer, $c['manager']);
    }

    public function test_approve_re_validates_guards_at_approval_time(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);

        // 큐 20-B — 분자 A안: ledger 반영하려면 confirmed_at SET 필수. 환불도 재무 확정 가정.
        FinalPayment::create([
            'vehicle_id' => $c['source']->id,
            'amount' => -30_000_000,
            'payment_date' => now()->toDateString(),
            'note' => '환불 (테스트)',
            'confirmed_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('50% 이상 입금');
        $this->service->approve($transfer, $c['manager']);
    }

    /**
     * 큐 19-F — 재무 확정 시점에 H4 paid Settlement 재검증.
     * Specialist F 지적: approve 후 confirmByFinance 사이 paid Settlement 발생 케이스 방어.
     */
    public function test_h4_guard_fires_on_confirm_when_settlement_paid_between_approve_and_confirm(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);

        // approve 후 paid Settlement 발생
        Settlement::create([
            'vehicle_id' => $c['source']->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('paid 정산');
        $this->service->confirmByFinance($transfer, $c['finance']);
    }

    /**
     * 큐 19-F — SoD 차단: approver_id === finance_user_id 인 self-confirm 시도 차단.
     * 사용자 결정 (2026-05-16): 동일 user_id 차단.
     */
    public function test_self_confirm_blocked(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SoD');
        $this->service->confirmByFinance($transfer, $c['manager']);
    }

    /**
     * 큐 10 H4 정합 — paid Settlement가 있는 차량은 자금 이체 불가.
     * 사용자 결정 (2026-05-15): 보수적 차단. 정산 마감 후 회계 무결성 보존.
     */
    public function test_request_blocks_when_source_has_paid_settlement(): void
    {
        $c = $this->makeContext(50_000_000);
        Settlement::create([
            'vehicle_id' => $c['source']->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('paid 정산');
        $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
    }

    public function test_request_blocks_when_target_has_paid_settlement(): void
    {
        $c = $this->makeContext(50_000_000);
        Settlement::create([
            'vehicle_id' => $c['target']->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(),
            'paid_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('paid 정산');
        $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
    }

    /**
     * 큐 19-B / 19-F 통합 — ApprovalRequest::execute() 호출이 service->approve() 트리거.
     * 19-F 후엔 의사결정만 통과: status = approved_awaiting_finance, final_payment 미생성.
     * /erp/approvals 페이지 승인 흐름과 동일 경로 검증.
     */
    public function test_approval_request_execute_triggers_transfer_approval(): void
    {
        $c = $this->makeContext(50_000_000);
        $this->actingAs($c['manager']);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $req = ApprovalRequest::findOrFail($transfer->approval_request_id);

        $req->execute();

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, $transfer->status);
        $this->assertEquals($c['manager']->id, $transfer->approver_id);
        $this->assertCount(0, FinalPayment::where('transfer_id', $transfer->id)->get());
    }

    /**
     * 큐 19-C 보강 (사용자 피드백 2026-05-15) — 같은 source vehicle에
     * pending 상태 transfer가 있으면 새 요청 차단.
     * 큐 19-G — InterVehicleTransfer 기준 가드로 전환, 메시지 변경.
     */
    public function test_request_blocks_when_source_has_pending_transfer(): void
    {
        $c = $this->makeContext(50_000_000);
        $this->service->request($c['source'], $c['target'], 10_000_000, $c['sales']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('미처리 자금 이체');
        $this->service->request($c['source'], $c['target'], 5_000_000, $c['sales']);
    }

    /**
     * 큐 19-G (회의록 부록 A Step 4 발견 버그) — 관리 승인 후
     * approved_awaiting_finance 상태에서 새 요청 차단. 한도 이중 부과 방지.
     */
    public function test_request_blocks_when_source_has_approved_awaiting_finance_transfer(): void
    {
        $c = $this->makeContext(50_000_000);
        $t = $this->service->request($c['source'], $c['target'], 10_000_000, $c['sales']);
        $this->service->approve($t, $c['manager']);

        // approved_awaiting_finance 상태 — pending 아니지만 미처리.
        // 19-G 가드 도입 전엔 여기서 새 요청 통과해 한도 이중 부과 가능했음.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('미처리 자금 이체');
        $this->service->request($c['source'], $c['target'], 5_000_000, $c['sales']);
    }

    /**
     * 큐 19-G — 거부된 ApprovalRequest + stale pending transfer 케이스는 차단 X.
     * /erp/approvals decide() 가 reject 시 ApprovalRequest 만 update 하고 transfer.status 는
     * pending 그대로 둠. 영업이 거부받은 후 새 요청을 할 수 있어야 함.
     */
    public function test_request_allows_new_attempt_after_rejection_with_stale_pending_transfer(): void
    {
        $c = $this->makeContext(50_000_000);
        $t1 = $this->service->request($c['source'], $c['target'], 10_000_000, $c['sales']);

        // 관리가 거부 — ApprovalRequest 만 'rejected' 로 update (실제 /erp/approvals decide 흐름과 동일).
        // transfer.status 는 pending 그대로 stale.
        $t1->approvalRequest()->update([
            'status' => ApprovalRequest::STATUS_REJECTED,
            'approver_id' => $c['manager']->id,
            'decided_at' => now(),
        ]);
        $this->assertEquals(InterVehicleTransfer::STATUS_PENDING, $t1->fresh()->status);

        // 영업이 재요청 — stale pending 차단 안 함, 새 transfer 생성.
        $t2 = $this->service->request($c['source'], $c['target'], 5_000_000, $c['sales']);
        $this->assertEquals(InterVehicleTransfer::STATUS_PENDING, $t2->status);
        $this->assertNotEquals($t1->id, $t2->id);
    }

    /**
     * 큐 19-G — void 관리 승인 후 voided_awaiting_finance 상태에서도 새 요청 차단.
     * 재무가 취소 처리하기 전까지는 회계 상태 미확정.
     */
    public function test_request_blocks_when_source_has_voided_awaiting_finance_transfer(): void
    {
        $c = $this->makeContext(50_000_000);
        $t = $this->service->request($c['source'], $c['target'], 10_000_000, $c['sales']);
        $this->service->approve($t, $c['manager']);
        $this->service->confirmByFinance($t, $c['finance']);
        $this->service->approveVoid($t, $c['manager'], '취소');

        // voided_awaiting_finance 상태 — 재무 처리 전 새 요청 차단.
        // (source unpaid_ratio는 executed로 75% 인데 voided 페어 아직 미생성이라 75% 그대로 —
        //  미수율 가드보다 19-G 가드가 먼저 발화하여 메시지가 '미처리 자금 이체' 임을 검증)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('미처리 자금 이체');
        $this->service->request($c['source'], $c['target'], 5_000_000, $c['sales']);
    }

    /**
     * 큐 19-F-D — 5상태 머신 end-to-end 전이 검증.
     * pending → approved_awaiting_finance → executed → voided_awaiting_finance → voided.
     * 각 단계 메타(approver / finance_confirmer / confirmed_at / finance_note) 보존 +
     * final_payment 4건(정 페어 2 + 역 페어 2) append-only 검증.
     */
    public function test_e2e_5_state_lifecycle_creates_four_final_payments_and_preserves_metadata(): void
    {
        $c = $this->makeContext(50_000_000);

        // 1) pending
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->assertEquals(InterVehicleTransfer::STATUS_PENDING, $transfer->fresh()->status);

        // 2) pending → approved_awaiting_finance
        $this->service->approve($transfer, $c['manager']);
        $this->assertEquals(
            InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            $transfer->fresh()->status
        );
        $this->assertCount(0, FinalPayment::where('transfer_id', $transfer->id)->get());

        // 3) approved_awaiting_finance → executed
        $this->service->confirmByFinance($transfer, $c['finance'], '재무 이체 거래번호 EXEC-001');
        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_EXECUTED, $transfer->status);
        $this->assertEquals($c['manager']->id, $transfer->approver_id);
        $this->assertEquals($c['finance']->id, $transfer->confirmed_by_user_id);
        $this->assertEquals('재무 이체 거래번호 EXEC-001', $transfer->finance_note);
        $execConfirmedAt = $transfer->confirmed_at;
        $this->assertNotNull($execConfirmedAt);
        $this->assertCount(2, FinalPayment::where('transfer_id', $transfer->id)->get());

        // 4) executed → voided_awaiting_finance (approveVoid)
        $this->service->approveVoid($transfer, $c['manager'], '바이어 요청 취소');
        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE, $transfer->status);
        $this->assertEquals('바이어 요청 취소', $transfer->void_reason);
        // 메타 보존 — 정 페어 final_payment는 그대로(append-only)
        $this->assertCount(2, FinalPayment::where('transfer_id', $transfer->id)->get());

        // 5) voided_awaiting_finance → voided (confirmVoidByFinance)
        $this->service->confirmVoidByFinance($transfer, $c['finance'], '재무 환불 거래번호 VOID-001');
        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED, $transfer->status);
        $this->assertNotNull($transfer->voided_at);
        $this->assertEquals('재무 환불 거래번호 VOID-001', $transfer->finance_note);
        // confirmed_at는 voided 시점으로 갱신 (executed 시점 이후)
        $this->assertTrue($transfer->confirmed_at >= $execConfirmedAt);

        // final_payment 총 4건: 원본 페어(-/+ 25M) + 역 페어(+/-25M)
        $payments = FinalPayment::where('transfer_id', $transfer->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(4, $payments);
        // 원본 (executed 시점) — source -, target +
        $this->assertEquals(-25_000_000, $payments[0]->amount);
        $this->assertEquals($c['source']->id, $payments[0]->vehicle_id);
        $this->assertEquals(25_000_000, $payments[1]->amount);
        $this->assertEquals($c['target']->id, $payments[1]->vehicle_id);
        // 역 페어 (voided 시점) — source +, target -
        $this->assertEquals(25_000_000, $payments[2]->amount);
        $this->assertEquals($c['source']->id, $payments[2]->vehicle_id);
        $this->assertEquals(-25_000_000, $payments[3]->amount);
        $this->assertEquals($c['target']->id, $payments[3]->vehicle_id);

        // 미수 캐시 — 이체 전 상태로 복귀 (정 페어와 역 페어 상쇄)
        $this->assertEquals(50_000_000, (int) $c['source']->fresh()->sale_unpaid_amount);
        $this->assertEquals(80_000_000, (int) $c['target']->fresh()->sale_unpaid_amount);
    }

    /**
     * 큐 19-F-D — 상태 가드: executed에서 confirmByFinance 재호출 차단 (이중 확정 방지).
     */
    public function test_confirm_by_finance_blocks_executed_status(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('관리 승인 대기 상태의 이체만');
        $this->service->confirmByFinance($transfer, $c['finance']);
    }

    /**
     * 큐 19-F-D — 상태 가드: voided_awaiting_finance에서 confirmByFinance 호출 차단.
     * void 흐름과 execute 흐름 혼선 방지.
     */
    public function test_confirm_by_finance_blocks_voided_awaiting_finance_status(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);
        $this->service->approveVoid($transfer, $c['manager'], '취소');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('관리 승인 대기 상태의 이체만');
        $this->service->confirmByFinance($transfer, $c['finance']);
    }

    /**
     * 큐 19-F-D — 상태 가드: executed에서 confirmVoidByFinance 우회 호출 차단.
     * approveVoid 의사결정 없이 재무가 직접 void 마킹 시도 차단.
     */
    public function test_confirm_void_by_finance_blocks_executed_status_without_approve_void(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('취소 승인 대기 상태의 이체만');
        $this->service->confirmVoidByFinance($transfer, $c['finance']);
    }

    /**
     * 큐 19-K — rejectByFinance 정상 흐름.
     * approved_awaiting_finance에서 재무 거부 → finance_rejected 전환 + 메타 기록 + final_payment 미생성.
     */
    public function test_reject_by_finance_marks_finance_rejected_without_creating_payments(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);

        $this->service->rejectByFinance($transfer, $c['finance'], '통장 잔액 부족 — 재요청 필요');

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_FINANCE_REJECTED, $transfer->status);
        $this->assertEquals($c['finance']->id, $transfer->finance_rejected_by_user_id);
        $this->assertNotNull($transfer->finance_rejected_at);
        $this->assertEquals('통장 잔액 부족 — 재요청 필요', $transfer->finance_reject_reason);

        // ledger 영향 0 — final_payment 미생성
        $this->assertEquals(0, FinalPayment::where('transfer_id', $transfer->id)->count());

        // executed 메타는 그대로 비어있어야 (confirmedBy / executedAt 미세팅)
        $this->assertNull($transfer->executed_at);
        $this->assertNull($transfer->confirmed_by_user_id);
    }

    /**
     * 큐 19-K — pending 상태에서 rejectByFinance 차단 (관리 승인 전).
     */
    public function test_reject_by_finance_blocks_pending_status(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('관리 승인 대기 상태의 이체만');
        $this->service->rejectByFinance($transfer, $c['finance'], '통장 잔액 부족');
    }

    /**
     * 큐 19-K — executed 상태에서 rejectByFinance 차단 (이미 ledger 기록 후 거부 불가).
     */
    public function test_reject_by_finance_blocks_executed_status(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('관리 승인 대기 상태의 이체만');
        $this->service->rejectByFinance($transfer, $c['finance'], '통장 잔액 부족');
    }

    /**
     * 큐 19-K — SoD: 관리 승인자 == 재무 거부자 차단.
     */
    public function test_reject_by_finance_self_reject_blocked(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SoD');
        $this->service->rejectByFinance($transfer, $c['manager'], '통장 잔액 부족');
    }

    /**
     * 큐 19-K — 사유 5자 미만 차단 (UI 1차, Service 2차 검증).
     */
    public function test_reject_by_finance_requires_reason_min_length(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('5자 이상');
        $this->service->rejectByFinance($transfer, $c['finance'], '부족');
    }

    /**
     * 큐 19-L — rejectVoidByFinance 정상 흐름.
     * voided_awaiting_finance → executed 복귀 + 메타 기록 + final_payment 그대로 유지.
     */
    public function test_reject_void_by_finance_reverts_to_executed_keeping_final_payments(): void
    {
        $c = $this->makeContext(50_000_000);

        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);
        $this->service->voidRequest($transfer->fresh(), $c['sales'], '거래 무산 — 환불 요청');
        $this->service->approveVoid($transfer->fresh(), $c['manager'], '관리 승인');

        // 이 시점 final_payment 페어 2건 존재
        $this->assertEquals(2, FinalPayment::where('transfer_id', $transfer->id)->count());

        $this->service->rejectVoidByFinance($transfer->fresh(), $c['finance'], '환불 거부 — 이미 정상 거래로 확인됨');

        $transfer->refresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_EXECUTED, $transfer->status);
        $this->assertEquals($c['finance']->id, $transfer->void_finance_rejected_by_user_id);
        $this->assertNotNull($transfer->void_finance_rejected_at);
        $this->assertEquals('환불 거부 — 이미 정상 거래로 확인됨', $transfer->void_finance_reject_reason);

        // final_payment 페어 그대로 (역 페어 미생성, 원본 미삭제)
        $this->assertEquals(2, FinalPayment::where('transfer_id', $transfer->id)->count());
    }

    /**
     * 큐 19-L — pending/approved_awaiting_finance/executed 상태에서 rejectVoidByFinance 차단.
     */
    public function test_reject_void_by_finance_blocks_executed_status(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('취소 승인 대기 상태의 이체만');
        $this->service->rejectVoidByFinance($transfer, $c['finance'], '환불 거부 사유');
    }

    /**
     * 큐 19-L — SoD: 관리 승인자(approveVoid 한 사람) == 재무 거부자 차단.
     */
    public function test_reject_void_by_finance_self_reject_blocked(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);
        $this->service->voidRequest($transfer->fresh(), $c['sales'], '거래 무산');
        $this->service->approveVoid($transfer->fresh(), $c['manager'], '관리 승인');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SoD');
        $this->service->rejectVoidByFinance($transfer->fresh(), $c['manager'], '환불 거부 사유');
    }

    /**
     * 큐 19-L — 영업이 거부 후 다시 void 요청 가능 (재시도 동선 보장).
     * 실제 운영에선 ApprovalRequest::execute() 가 AR.status='approved' 마킹 + approveVoid 트리거 한 번에.
     * 본 테스트는 service 만 직접 호출하므로 AR.status 수동 업데이트로 운영 흐름 재현.
     */
    public function test_sales_can_retry_void_after_finance_void_reject(): void
    {
        $c = $this->makeContext(50_000_000);
        $transfer = $this->service->request($c['source'], $c['target'], 25_000_000, $c['sales']);
        $this->service->approve($transfer, $c['manager']);
        $this->service->confirmByFinance($transfer, $c['finance']);

        $firstVoidReq = $this->service->voidRequest($transfer->fresh(), $c['sales'], '1차 취소 시도');
        $this->service->approveVoid($transfer->fresh(), $c['manager'], '관리 승인');
        $firstVoidReq->update(['status' => ApprovalRequest::STATUS_APPROVED, 'approver_id' => $c['manager']->id, 'decided_at' => now()]);

        $this->service->rejectVoidByFinance($transfer->fresh(), $c['finance'], '환불 거부 — 영업 확인 필요');

        // 2차 void 요청 가능 — 차단 가드(중복 pending void)에 막히면 안 됨
        $secondVoidReq = $this->service->voidRequest($transfer->fresh(), $c['sales'], '2차 취소 시도 — 환불 처리 확인됨');

        $this->assertEquals(ApprovalRequest::STATUS_PENDING, $secondVoidReq->status);
        $this->assertEquals(ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID, $secondVoidReq->action_type);
    }
}
