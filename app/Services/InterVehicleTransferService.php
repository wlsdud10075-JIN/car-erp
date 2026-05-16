<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\User;
use App\Models\Vehicle;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * 큐 19-B / 19-E / 19-F — 차량 간 자금 이체 Service.
 *
 * 5상태 머신 (큐 19-F, 회의록 2026-05-16 — 관리 ≠ 재무 분리):
 *   pending                       — 영업 요청, ApprovalRequest 대기
 *   ↓ approve() — 관리 의사결정 (final_payment 미생성)
 *   approved_awaiting_finance
 *   ↓ confirmByFinance() — 재무 실물 확정 (final_payment 페어 생성, ledger)
 *   executed
 *   ↓ approveVoid() — 영업 void 요청 + 관리 의사결정 (반대 부호 final_payment 미생성)
 *   voided_awaiting_finance
 *   ↓ confirmVoidByFinance() — 재무 실물 확정 (반대 부호 final_payment 페어 생성)
 *   voided
 *
 * 안전 가드 5종 (assertGuards):
 *   1. source ≠ target / 2. buyer_id 동일 / 3. currency 동일
 *   4. unpaid_ratio ≤ 0.5 AND amount ≤ available()
 *   5. 큐 10 H4 — source/target 어느 쪽에도 paid Settlement 없음
 *
 * 가드 호출 시점:
 *   - request()           — 안건 1 (요청 단계)
 *   - approve()           — 안건 2 (관리 승인 — pending → approved_awaiting_finance)
 *   - confirmByFinance()  — 안건 3 (재무 확정 — approved_awaiting_finance → executed)
 *   confirm 시점에도 H4 재실행 — approve 후 paid Settlement 발생 케이스 방어 (Specialist F).
 *
 * self-confirm 차단 (회의 결정): approver_id === finance_user_id 시 차단.
 */
class InterVehicleTransferService
{
    /**
     * 소스 차량의 이체 한도 = (현재까지 받은 금액) × 0.5.
     * sale_total_amount ≤ 0 또는 받은 금액 ≤ 0이면 0.
     */
    public function available(Vehicle $source): float
    {
        $received = (float) $source->sale_total_amount - (float) $source->sale_unpaid_amount;
        if ($received <= 0) {
            return 0.0;
        }

        return round($received * 0.5, 2);
    }

    /**
     * 영업이 자금 이체 요청.
     *
     * @return InterVehicleTransfer status=pending 상태로 생성된 거래
     *
     * @throws DomainException 안전 가드 위반 시
     */
    public function request(
        Vehicle $source,
        Vehicle $target,
        float $amount,
        User $requester,
        ?string $reason = null,
        ?string $notes = null,
    ): InterVehicleTransfer {
        $this->assertGuards($source, $target, $amount);

        // 큐 19-G — InterVehicleTransfer 기준 중복 차단 (회의록 부록 A Step 4 발견 버그).
        // 기존 ApprovalRequest.status='pending' 검사는 관리 승인 후 통과해 한도 이중 부과 가능.
        // 미처리 상태 3종(pending / approved_awaiting_finance / voided_awaiting_finance)
        // 중 연결된 ApprovalRequest가 활성(pending/approved)인 것만 차단.
        // ApprovalRequest가 rejected/cancelled면 transfer.status가 pending으로 남아도 stale —
        // 영업이 재요청할 수 있어야 함.
        $inProgress = InterVehicleTransfer::where('source_vehicle_id', $source->id)
            ->whereIn('status', [
                InterVehicleTransfer::STATUS_PENDING,
                InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
                InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
            ])
            ->whereHas('approvalRequest', fn ($q) => $q->whereIn('status', [
                ApprovalRequest::STATUS_PENDING,
                ApprovalRequest::STATUS_APPROVED,
            ]))
            ->first();
        if ($inProgress) {
            $label = InterVehicleTransfer::STATUSES[$inProgress->status] ?? $inProgress->status;
            throw new DomainException("이 차량에 미처리 자금 이체가 있습니다 (현재 상태: {$label}). 처리 완료/거부 후 재시도하세요.");
        }

        return DB::transaction(function () use ($source, $target, $amount, $requester, $reason, $notes) {
            $approvalReq = ApprovalRequest::create([
                'requester_id' => $requester->id,
                'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER,
                'target_type' => Vehicle::class,
                'target_id' => $source->id,
                'status' => ApprovalRequest::STATUS_PENDING,
                'reason' => $reason,
                'payload' => [
                    'source_vehicle_id' => $source->id,
                    'source_vehicle_number' => $source->vehicle_number,
                    'target_vehicle_id' => $target->id,
                    'target_vehicle_number' => $target->vehicle_number,
                    'buyer_id' => $source->buyer_id,
                    'amount' => $amount,
                    'currency' => $source->currency,
                ],
            ]);

            return InterVehicleTransfer::create([
                'source_vehicle_id' => $source->id,
                'target_vehicle_id' => $target->id,
                'buyer_id' => $source->buyer_id,
                'amount' => $amount,
                'currency' => $source->currency,
                'approval_request_id' => $approvalReq->id,
                'status' => InterVehicleTransfer::STATUS_PENDING,
                'requester_id' => $requester->id,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * 큐 19-F — 관리 승인 (의사결정만, final_payment 미생성).
     *
     * pending → approved_awaiting_finance.
     * 실행 시점 한도 재검증 — 요청과 승인 사이 source가 추가 입금/환불 받았을 수 있음.
     * approver_id 기록 — confirmByFinance 시 self-confirm 차단 기준.
     *
     * @throws DomainException 상태가 pending 아니거나 가드 위반 시
     */
    public function approve(InterVehicleTransfer $transfer, User $approver): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_PENDING) {
            throw new DomainException("이미 처리된 이체입니다 (현재 상태: {$transfer->status}).");
        }

        $this->assertGuards($transfer->sourceVehicle, $transfer->targetVehicle, (float) $transfer->amount);

        $transfer->update([
            'status' => InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            'approver_id' => $approver->id,
        ]);
    }

    /**
     * 큐 19-F — 재무 확정 (실물 자금 처리 후 시스템 마킹).
     *
     * approved_awaiting_finance → executed.
     * DB::transaction 내부에서:
     *   1. source 차량에 amount = -X final_payment (transfer_id 연결)
     *   2. target 차량에 amount = +X final_payment (transfer_id 연결)
     *   3. transfer.status = executed, executed_at = now, confirmed_by_user_id / confirmed_at / finance_note 기록
     *
     * 가드:
     *   - 상태가 approved_awaiting_finance 아니면 차단
     *   - approver_id === financeUser->id 면 self-confirm 차단 (SoD)
     *   - H4 paid Settlement 재검증 — approve 후 paid 발생 케이스 방어
     *
     * @throws DomainException 위 가드 위반 시
     */
    public function confirmByFinance(InterVehicleTransfer $transfer, User $financeUser, ?string $note = null): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE) {
            throw new DomainException("관리 승인 대기 상태의 이체만 재무 확정할 수 있습니다 (현재 상태: {$transfer->status}).");
        }
        if ($transfer->approver_id !== null && $transfer->approver_id === $financeUser->id) {
            throw new DomainException('관리 승인자와 재무 확정자는 다른 사용자여야 합니다 (SoD).');
        }

        $this->assertPaidSettlementGuard($transfer->sourceVehicle, $transfer->targetVehicle);

        DB::transaction(function () use ($transfer, $financeUser, $note) {
            $today = now()->toDateString();
            $amount = (float) $transfer->amount;
            $marker = "차량 간 자금 이체 #{$transfer->id} (관리 승인 #{$transfer->approval_request_id}, 재무 확정 #{$financeUser->id})";

            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$amount,
                'payment_date' => $today,
                'note' => "→ 차량 #{$transfer->target_vehicle_id} 이체 ({$marker})",
            ]);

            FinalPayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'payment_date' => $today,
                'note' => "← 차량 #{$transfer->source_vehicle_id} 에서 이체 ({$marker})",
            ]);

            $transfer->update([
                'status' => InterVehicleTransfer::STATUS_EXECUTED,
                'executed_at' => now(),
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => now(),
                'finance_note' => $note,
            ]);
        });
    }

    /**
     * 큐 19-E — 영업이 이체 취소(void) 요청. executed transfer만 대상.
     * 새 ApprovalRequest (TYPE_INTER_VEHICLE_TRANSFER_VOID) 생성, payload에 transfer_id.
     */
    public function voidRequest(InterVehicleTransfer $transfer, User $requester, string $reason): ApprovalRequest
    {
        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_EXECUTED) {
            throw new DomainException("실행 완료된 이체만 취소할 수 있습니다 (현재 상태: {$transfer->status}).");
        }
        $existing = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->whereJsonContains('payload->transfer_id', $transfer->id)
            ->exists();
        if ($existing) {
            throw new DomainException('이미 대기중인 이체 취소 요청이 있습니다.');
        }

        return ApprovalRequest::create([
            'requester_id' => $requester->id,
            'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID,
            'target_type' => InterVehicleTransfer::class,
            'target_id' => $transfer->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'reason' => $reason,
            'payload' => [
                'transfer_id' => $transfer->id,
                'source_vehicle_id' => $transfer->source_vehicle_id,
                'source_vehicle_number' => $transfer->sourceVehicle?->vehicle_number,
                'target_vehicle_id' => $transfer->target_vehicle_id,
                'target_vehicle_number' => $transfer->targetVehicle?->vehicle_number,
                'amount' => (float) $transfer->amount,
                'currency' => $transfer->currency,
            ],
        ]);
    }

    /**
     * 큐 19-F — void 관리 승인 (의사결정만, 반대 부호 final_payment 미생성).
     *
     * executed → voided_awaiting_finance.
     * void_reason 기록 (영업 요청 사유 또는 관리 결정 사유).
     *
     * @throws DomainException 상태가 executed 아니거나 H4 가드 위반 시
     */
    public function approveVoid(InterVehicleTransfer $transfer, User $approver, string $reason): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_EXECUTED) {
            throw new DomainException("실행 완료된 이체만 취소할 수 있습니다 (현재 상태: {$transfer->status}).");
        }

        $this->assertPaidSettlementGuard($transfer->sourceVehicle, $transfer->targetVehicle);

        $transfer->update([
            'status' => InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
            'void_reason' => $reason,
            'approver_id' => $approver->id,
        ]);
    }

    /**
     * 큐 19-F — void 재무 확정 (반대 부호 final_payment 페어 생성).
     *
     * voided_awaiting_finance → voided.
     * DB::transaction 내부에서:
     *   1. source 차량에 amount = +X final_payment (원본 -X → 반대 +X, transfer_id 연결)
     *   2. target 차량에 amount = -X final_payment (원본 +X → 반대 -X, transfer_id 연결)
     *   3. transfer.status = voided, voided_at = now, confirmed_by_user_id / confirmed_at / finance_note 기록
     *
     * 가드:
     *   - 상태가 voided_awaiting_finance 아니면 차단
     *   - approver_id === financeUser->id 면 self-confirm 차단
     *   - H4 paid Settlement 재검증
     *
     * append-only — 기존 final_payment 는 절대 삭제·수정 안 함 (회계 흐름 보존).
     */
    public function confirmVoidByFinance(InterVehicleTransfer $transfer, User $financeUser, ?string $note = null): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE) {
            throw new DomainException("취소 승인 대기 상태의 이체만 재무 확정할 수 있습니다 (현재 상태: {$transfer->status}).");
        }
        if ($transfer->approver_id !== null && $transfer->approver_id === $financeUser->id) {
            throw new DomainException('관리 승인자와 재무 확정자는 다른 사용자여야 합니다 (SoD).');
        }

        $this->assertPaidSettlementGuard($transfer->sourceVehicle, $transfer->targetVehicle);

        DB::transaction(function () use ($transfer, $financeUser, $note) {
            $today = now()->toDateString();
            $amount = (float) $transfer->amount;
            $reason = $transfer->void_reason ?? '관리 승인으로 이체 취소';

            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'payment_date' => $today,
                'note' => "이체 취소 (#{$transfer->id}): {$reason}",
            ]);

            FinalPayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$amount,
                'payment_date' => $today,
                'note' => "이체 취소 (#{$transfer->id}): {$reason}",
            ]);

            $transfer->update([
                'status' => InterVehicleTransfer::STATUS_VOIDED,
                'voided_at' => now(),
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => now(),
                'finance_note' => $note,
            ]);
        });
    }

    /**
     * 안전 가드 5종 검증 (요청·관리 승인 시점). 위반 시 예외.
     *
     * 5번째 가드 (H4 정합) — 큐 10 H4와 연동: source 또는 target에 paid Settlement가 있으면
     * 차단. 마감된 정산의 snapshot과 vehicle 현황 사이 drift 방지.
     */
    private function assertGuards(Vehicle $source, Vehicle $target, float $amount): void
    {
        if ($source->id === $target->id) {
            throw new DomainException('이체 출처와 대상 차량이 동일할 수 없습니다.');
        }
        if ($source->buyer_id === null || $target->buyer_id === null || $source->buyer_id !== $target->buyer_id) {
            throw new DomainException('이체 출처/대상 차량의 바이어가 동일해야 합니다.');
        }
        if ($source->currency !== $target->currency) {
            throw new DomainException("통화가 일치하지 않습니다 (source: {$source->currency} / target: {$target->currency}).");
        }
        if ($amount <= 0) {
            throw new DomainException('이체 금액은 0보다 커야 합니다.');
        }

        $ratio = $source->unpaid_ratio;
        if ($ratio === null) {
            throw new DomainException('출처 차량의 미수율을 계산할 수 없습니다 (판매가 미입력).');
        }
        if ($ratio > 0.5) {
            $pct = round($ratio * 100, 1);
            throw new DomainException("출처 차량에 50% 이상 입금되어야 이체 가능합니다 (현재 미수율 {$pct}%).");
        }

        $limit = $this->available($source);
        if ($amount > $limit + 0.005) {
            throw new DomainException('이체 한도를 초과했습니다 (받은 금액의 50%까지 가능, 한도: '.number_format($limit, 2).').');
        }

        $this->assertPaidSettlementGuard($source, $target);
    }

    /**
     * 큐 19-F — H4 paid Settlement 단독 가드.
     * confirmByFinance / confirmVoidByFinance / approveVoid 에서 재실행 — approve 이후
     * paid Settlement 발생 케이스 방어 (Specialist F 지적).
     */
    private function assertPaidSettlementGuard(Vehicle $source, Vehicle $target): void
    {
        foreach (['출처' => $source, '대상' => $target] as $label => $v) {
            if ($v && $v->settlements()->where('settlement_status', 'paid')->exists()) {
                throw new DomainException("{$label} 차량에 paid 정산이 있어 자금 이체를 처리할 수 없습니다.");
            }
        }
    }
}
