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
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 큐 19-E — 이체 취소(void) 흐름 (회의록 v5 §13 안전 가드 4: append-only).
 * Service 단위 + ApprovalRequest 통합 + Volt 모달 E2E.
 */
class InterVehicleTransferVoidTest extends TestCase
{
    use RefreshDatabase;

    private function executeTransferScenario(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '정산']);

        $source = Vehicle::create([
            'vehicle_number' => '99가0001',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 100_000_000,
            'currency' => 'KRW',
            'deposit_down_payment' => 50_000_000,
        ]);
        $target = Vehicle::create([
            'vehicle_number' => '99가0002',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 80_000_000,
            'currency' => 'KRW',
        ]);

        $service = new InterVehicleTransferService;
        $this->actingAs($sales);
        $transfer = $service->request($source, $target, 25_000_000, $sales, reason: '이체 요청');
        $this->actingAs($manager);
        $service->approve($transfer, $manager);
        $service->confirmByFinance($transfer, $finance);
        $transfer->refresh();

        return compact('buyer', 'sales', 'manager', 'finance', 'source', 'target', 'service', 'transfer');
    }

    public function test_void_request_creates_approval_request_pending(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        $req = $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '바이어 2번 차 무산 — 자금 원상복구');

        $this->assertEquals(ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID, $req->action_type);
        $this->assertEquals('pending', $req->status);
        $this->assertEquals($c['transfer']->id, $req->payload['transfer_id']);
    }

    public function test_void_request_blocked_when_transfer_not_executed(): void
    {
        $c = $this->executeTransferScenario();
        $c['transfer']->update(['status' => InterVehicleTransfer::STATUS_VOIDED]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('실행 완료된 이체만');
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '이미 voided');
    }

    public function test_void_request_blocked_when_duplicate_pending(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '첫 요청');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('이미 대기중');
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '중복 요청 시도');
    }

    /**
     * 큐 19-F — void 5단계: approveVoid (의사결정) → confirmVoidByFinance (실물 처리).
     * approveVoid 만 호출하면 final_payment 페어 미생성 — 이체 페어 2건만 잔존.
     */
    public function test_approve_void_does_not_create_reverse_final_payments(): void
    {
        $c = $this->executeTransferScenario();
        $c['service']->approveVoid($c['transfer']->fresh(), $c['manager'], '바이어 2번 차 거래 무산');

        $transfer = $c['transfer']->fresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE, $transfer->status);
        $this->assertNull($transfer->voided_at);
        $this->assertEquals('바이어 2번 차 거래 무산', $transfer->void_reason);

        $this->assertCount(2, FinalPayment::where('transfer_id', $transfer->id)->get());

        // 미수 캐시는 이체 후 상태 그대로 (1번 차 7500만 / 2번 차 5500만)
        $this->assertEquals(75_000_000, (int) $c['source']->fresh()->sale_unpaid_amount);
        $this->assertEquals(55_000_000, (int) $c['target']->fresh()->sale_unpaid_amount);
    }

    /**
     * 회의록 §13 — confirmVoidByFinance 가 양 차량에 반대 부호 final_payment 페어 생성 (append-only).
     */
    public function test_confirm_void_by_finance_creates_reverse_final_payments_and_marks_voided(): void
    {
        $c = $this->executeTransferScenario();
        $c['service']->approveVoid($c['transfer']->fresh(), $c['manager'], '바이어 2번 차 거래 무산');
        $c['service']->confirmVoidByFinance($c['transfer']->fresh(), $c['finance'], '환불 거래번호 RV456');

        $transfer = $c['transfer']->fresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED, $transfer->status);
        $this->assertNotNull($transfer->voided_at);
        $this->assertNotEmpty($transfer->void_reason);
        $this->assertEquals($c['finance']->id, $transfer->confirmed_by_user_id);
        $this->assertEquals('환불 거래번호 RV456', $transfer->finance_note);

        $payments = FinalPayment::where('transfer_id', $transfer->id)->get();
        $this->assertCount(4, $payments);

        $sourceSum = (float) $payments->where('vehicle_id', $c['source']->id)->sum('amount');
        $targetSum = (float) $payments->where('vehicle_id', $c['target']->id)->sum('amount');
        $this->assertEquals(0.0, $sourceSum);
        $this->assertEquals(0.0, $targetSum);

        $source = $c['source']->fresh();
        $target = $c['target']->fresh();
        $this->assertEquals(50_000_000, (int) $source->sale_unpaid_amount);
        $this->assertEquals(80_000_000, (int) $target->sale_unpaid_amount);
    }

    public function test_approve_void_blocked_when_source_has_paid_settlement(): void
    {
        $c = $this->executeTransferScenario();
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
        $c['service']->approveVoid($c['transfer']->fresh(), $c['manager'], 'paid 정산 가드 테스트');
    }

    /**
     * 큐 19-F — void self-confirm 차단 (SoD).
     */
    public function test_void_self_confirm_blocked(): void
    {
        $c = $this->executeTransferScenario();
        $c['service']->approveVoid($c['transfer']->fresh(), $c['manager'], 'SoD 테스트');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SoD');
        $c['service']->confirmVoidByFinance($c['transfer']->fresh(), $c['manager']);
    }

    /**
     * 큐 19-E / 19-F E2E — /erp/approvals 페이지에서 관리 승인 → service.approveVoid() 자동 호출.
     * 19-F 후엔 관리 승인만 통과: status = voided_awaiting_finance, final_payment 페어 미생성.
     */
    public function test_approval_request_execute_triggers_void_approval(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);
        $req = $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '거래 무산 — 원상복구');

        $this->actingAs($c['manager']);
        Volt::test('erp.approvals.index')
            ->call('openApproveModal', $req->id)
            ->set('decisionNote', '바이어 무산 확인. 원상복구 승인.')
            ->call('decide')
            ->assertHasNoErrors();

        $transfer = $c['transfer']->fresh();
        $this->assertEquals(InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE, $transfer->status);
        $this->assertCount(2, FinalPayment::where('transfer_id', $transfer->id)->get());
    }

    public function test_void_modal_submit_creates_approval_request(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferVoidModal', $c['transfer']->id)
            ->assertSet('showTransferVoidModal', true)
            ->set('voidReason', '바이어 2번 차 무산 — 원상복구 요청')
            ->call('submitTransferVoidRequest')
            ->assertHasNoErrors()
            ->assertSet('showTransferVoidModal', false);

        $this->assertEquals(1, ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)->count());
    }

    /**
     * 큐 19-E 보강 (2026-05-15) — submit 직후 finalPayments 메타가 즉시 갱신되어
     * 페이지 새로고침 없이 amber 박스 "취소 요청 중" 시각화.
     */
    public function test_void_submit_immediately_updates_finalpayments_meta(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        $component = Volt::test('erp.vehicles.index')
            ->call('openEdit', $c['source']->id)
            ->call('openTransferVoidModal', $c['transfer']->id)
            ->set('voidReason', '잔금 row 메타 즉시 갱신 테스트')
            ->call('submitTransferVoidRequest');

        // openEdit 재호출 없이 같은 인스턴스에서 finalPayments 확인
        $payments = $component->instance()->finalPayments;
        $transferRow = collect($payments)->first(fn ($r) => ! empty($r['transfer']));

        $this->assertNotNull($transferRow);
        $this->assertTrue($transferRow['transfer']['pending_void']);
        $this->assertFalse($transferRow['transfer']['can_void']);
    }

    /**
     * 큐 19-E 보강 (사용자 피드백 2026-05-15) — pending void가 있으면 잔금 row 메타에
     * pending_void=true + can_void=false 반영. UI에서 amber 박스 + "취소 요청 중" 표시.
     */
    public function test_finalpayments_meta_shows_pending_void_after_request(): void
    {
        $c = $this->executeTransferScenario();
        $this->actingAs($c['sales']);

        // void 요청 보냄
        $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '취소 요청 — 메타 반영 테스트');

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $c['source']->id);
        $payments = $component->instance()->finalPayments;

        $transferRow = collect($payments)->first(fn ($r) => ! empty($r['transfer']));
        $this->assertNotNull($transferRow);
        $this->assertTrue($transferRow['transfer']['pending_void']);
        $this->assertFalse($transferRow['transfer']['can_void']);
    }

    /**
     * 큐 19-H / 19-I (회의록 부록 A Step 7 발견 버그) — void 요청 거부 시 거부 사유가
     * 차량 편집 패널 자금 이체 섹션에 표시되어야 함.
     *
     * 19-I 통합 (사용자 결정 2026-05-16) — transfer/void 결정 박스 1개 통합.
     * 가장 최근(decided_at) 결정이 void rejected 이면 lastDecided.type='void' 로 설정 +
     * emerald "이체 완료" 박스는 가려지고 빨강 "이체 취소 요청 거부됨" 박스만 표시.
     */
    public function test_transfer_context_last_decided_unified_void_rejection(): void
    {
        $c = $this->executeTransferScenario();

        // executeTransferScenario 는 service->approve()/confirmByFinance() 만 호출하므로
        // ApprovalRequest.status 는 pending 그대로. 실제 /erp/approvals decide() 흐름과
        // 일치시키기 위해 transfer ApprovalRequest 를 approved 로 marking.
        $c['transfer']->approvalRequest->update([
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $c['manager']->id,
            'decided_at' => now(),
        ]);

        // 영업이 void 요청
        $this->actingAs($c['sales']);
        $voidReq = $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '바이어 신용 문제로 취소 요청');

        // 관리가 거부 (/erp/approvals decide() 와 동일 흐름).
        // transfer 승인 시점보다 늦은 decided_at → 가장 최근 결정.
        $voidReq->update([
            'status' => ApprovalRequest::STATUS_REJECTED,
            'approver_id' => $c['manager']->id,
            'decision_note' => '회계 확인 필요 — 거부',
            'decided_at' => now()->addMinute(),
        ]);

        // 영업이 source 차량 편집 패널 열기
        $this->actingAs($c['sales']);
        $component = Volt::test('erp.vehicles.index')->call('openEdit', $c['source']->id);
        $ctx = $component->instance()->transferContext;

        $this->assertNotNull($ctx['lastDecided']);
        $this->assertEquals('void', $ctx['lastDecided']['type']);
        $this->assertEquals('rejected', $ctx['lastDecided']['status']);
        $this->assertEquals($c['transfer']->id, $ctx['lastDecided']['transfer_id']);
        $this->assertEquals('회계 확인 필요 — 거부', $ctx['lastDecided']['decision_note']);
        $this->assertEquals('바이어 신용 문제로 취소 요청', $ctx['lastDecided']['reason']);
        $this->assertEquals($c['manager']->name, $ctx['lastDecided']['approver_name']);
    }

    /**
     * 큐 19-J (회의록 부록 A Step 7 후속 발견 버그) — void approved + voided 완료 시
     * 박스가 빨강 "이체 취소 요청 거부됨" 으로 잘못 표시되는 케이스 fix.
     *
     * 시나리오: void 요청 #1 거부 → void 요청 #2 승인 → 재무 처리(voided).
     * 19-I 코드는 voidDecided 검색이 rejected/cancelled 만 잡아 이전 거부된 void 가
     * 살아남아 mostRecent 로 잡혔음. 19-J fix 로 approved 도 포함하고 + void approved
     * 인 경우 transferDecided 로 fallback → 'approved:voided' 회색 박스로 표시.
     */
    public function test_transfer_context_last_decided_shows_voided_after_second_void_approved(): void
    {
        $c = $this->executeTransferScenario();

        // transfer ApprovalRequest 도 approved 로 marking
        $c['transfer']->approvalRequest->update([
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $c['manager']->id,
            'decided_at' => now(),
        ]);

        // 1) 영업 void 요청 #1 → 관리 거부
        $this->actingAs($c['sales']);
        $voidReq1 = $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '첫 번째 취소 요청');
        $voidReq1->update([
            'status' => ApprovalRequest::STATUS_REJECTED,
            'approver_id' => $c['manager']->id,
            'decision_note' => '추가 검토 필요',
            'decided_at' => now()->addMinute(),
        ]);

        // 2) 영업 void 요청 #2 → 관리 승인 + 재무 처리
        $voidReq2 = $c['service']->voidRequest($c['transfer']->fresh(), $c['sales'], '두 번째 취소 요청');
        $voidReq2->update([
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $c['manager']->id,
            'decision_note' => '재검토 후 승인',
            'decided_at' => now()->addMinutes(2),
        ]);
        $c['service']->approveVoid($c['transfer']->fresh(), $c['manager'], '재검토 후 승인');
        $c['service']->confirmVoidByFinance($c['transfer']->fresh(), $c['finance']);

        // 영업이 source 차량 편집 패널 열기
        $this->actingAs($c['sales']);
        $component = Volt::test('erp.vehicles.index')->call('openEdit', $c['source']->id);
        $ctx = $component->instance()->transferContext;

        // 19-J fix: 가장 최근 결정이 void approved 이므로 transferDecided 로 fallback
        // → type='transfer' + transfer.status='voided' → 'approved:voided' 회색 박스 분기
        $this->assertNotNull($ctx['lastDecided']);
        $this->assertEquals('transfer', $ctx['lastDecided']['type']);
        $this->assertEquals('approved', $ctx['lastDecided']['status']);
        $this->assertEquals('voided', $ctx['lastDecided']['transfer_status']);
    }
}
