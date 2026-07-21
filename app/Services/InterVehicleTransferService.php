<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\PurchaseBalancePayment;
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
 * 큐 19-K — 재무 정방향 거부 (approved_awaiting_finance 진입):
 *   ↓ rejectByFinance() — 재무가 송금 불가 사유로 거부 (final_payment 미생성, ledger 영향 0)
 *   finance_rejected (영업이 사유 확인 후 새 transfer 요청 가능)
 *
 * 큐 19-L — void 흐름 재무 거부 (voided_awaiting_finance 진입):
 *   ↓ rejectVoidByFinance() — 재무가 환불 불가 사유로 void 거부 (final_payment 그대로 유지)
 *   executed (이체 살아있음, 영업이 다시 void 요청 가능 — void 시도 무산)
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
     * 보증금 적용 (jin 2026-07-20) — [관리]/업무관리자 기안. status=pending(결제 대기중) + ApprovalRequest(deposit_apply).
     *   기존 request()와 방향 동일(source=끌어올 차, target=신규 차)이나, 승인=최고관리자 → 즉시 적용(executeDepositApply).
     *   $paymentType: 타겟 입금 유형 — deposit_down(계약금) / balance(잔금).
     *
     * @throws DomainException 가드 위반·유형 오류 시
     */
    public function applyDeposit(
        Vehicle $source,
        Vehicle $target,
        float $amount,
        User $drafter,
        string $paymentType,
        ?string $reason = null,
    ): InterVehicleTransfer {
        if (! in_array($paymentType, ['deposit_down', 'balance'], true)) {
            throw new DomainException('입금 유형은 계약금(deposit_down) 또는 잔금(balance) 이어야 합니다.');
        }
        $this->assertGuards($source, $target, $amount);

        // 중복 차단 — 소스 기준 미처리 이체(활성 ApprovalRequest) 있으면 차단 (request() 동일 정책).
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

        return DB::transaction(function () use ($source, $target, $amount, $drafter, $paymentType, $reason) {
            $approvalReq = ApprovalRequest::create([
                'requester_id' => $drafter->id,
                'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_DEPOSIT_APPLY,
                'target_type' => Vehicle::class,
                'target_id' => $target->id,   // 타겟(신규 차) 기준
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
                    'payment_type' => $paymentType,
                ],
            ]);

            return InterVehicleTransfer::create([
                'source_vehicle_id' => $source->id,
                'target_vehicle_id' => $target->id,
                'buyer_id' => $source->buyer_id,
                'amount' => $amount,
                'currency' => $source->currency,
                'kind' => InterVehicleTransfer::KIND_DEPOSIT_APPLY,
                'target_payment_type' => $paymentType,
                'approval_request_id' => $approvalReq->id,
                'status' => InterVehicleTransfer::STATUS_PENDING,
                'requester_id' => $drafter->id,
            ]);
        });
    }

    /**
     * 보증금 적용 승인 = 즉시 적용 (jin 2026-07-20). 최고관리자(admin) 승인 시 ApprovalRequest.execute() 경유 호출.
     *   pending → executed. FinalPayment 페어 즉시 생성(source −amount / target +amount, 유형=target_payment_type).
     *   재무 확정 단계 없음(기존 standard 이체와 분리).
     *
     * 가드: 최고관리자(isAdmin) 승인 + SoD(승인자 ≠ 기안자) + assertGuards 재검증 + atomic 상태전이.
     *
     * @throws DomainException 위 가드 위반 시
     */
    public function executeDepositApply(InterVehicleTransfer $transfer, User $approver): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->kind !== InterVehicleTransfer::KIND_DEPOSIT_APPLY) {
            throw new DomainException('보증금 적용 이체가 아닙니다.');
        }
        if (! $approver->isAdmin()) {
            throw new DomainException('보증금 적용은 최고관리자만 승인할 수 있습니다.');
        }
        // SoD — 기안자 ≠ 승인자.
        if ($transfer->requester_id !== null && $transfer->requester_id === $approver->id) {
            throw new DomainException('기안자와 승인자는 다른 사용자여야 합니다 (SoD).');
        }

        $this->assertGuards($transfer->sourceVehicle, $transfer->targetVehicle, (float) $transfer->amount);

        DB::transaction(function () use ($transfer, $approver) {
            $confirmedAt = now();

            // atomic 상태전이 — pending 1건만 executed 로. 동시/중복 승인 시 결제쌍 중복 방지.
            $affected = InterVehicleTransfer::whereKey($transfer->id)
                ->where('status', InterVehicleTransfer::STATUS_PENDING)
                ->update([
                    'status' => InterVehicleTransfer::STATUS_EXECUTED,
                    'executed_at' => $confirmedAt,
                    'approver_id' => $approver->id,
                    'confirmed_by_user_id' => $approver->id,
                    'confirmed_at' => $confirmedAt,
                ]);
            if ($affected !== 1) {
                throw new DomainException('이미 처리됐거나 상태가 변경된 이체입니다 (동시 승인 방지).');
            }

            $today = now()->toDateString();
            $amount = (float) $transfer->amount;
            $type = $transfer->target_payment_type ?: 'balance';
            $marker = "보증금 적용 #{$transfer->id} (최고관리자 승인 #{$approver->id})";

            // source (음수, 잔금 성격) — 그 차 보증금을 뺌
            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$amount,
                'type' => 'balance',
                'payment_date' => $today,
                'note' => "→ 차량 #{$transfer->target_vehicle_id} 보증금 적용 ({$marker})",
                'confirmed_by_user_id' => $approver->id,
                'confirmed_at' => $confirmedAt,
            ]);
            // target (양수, 선택 유형=계약금/잔금)
            FinalPayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'type' => $type,
                'payment_date' => $today,
                'note' => "← 차량 #{$transfer->source_vehicle_id} 보증금 적용 ({$marker})",
                'confirmed_by_user_id' => $approver->id,
                'confirmed_at' => $confirmedAt,
            ]);
        });

        $transfer->refresh();
    }

    /**
     * 보증금 매입 funding (C2, jin 2026-07-21) — [관리]/업무관리자 기안. status=pending + ApprovalRequest.
     *   소스 차(그 바이어 선적전, 여유) 보증금(외화)으로 대상 차 매입대금(원화 $amountKrw)을 funding.
     *   승인 흐름 = standard 3단(기안→관리승인→재무 실물확정). 재무확정 시 소스 −FinalPayment(외화, 미수↑) +
     *   대상 매입 PBP(원화, confirmed, marker) → 매입 GREEN. amount=소스 차감 외화(=amount_krw÷소스환율), 스냅샷 보존.
     *
     * @param  float  $amountKrw  대상 매입 funding 원화액
     *
     * @throws DomainException 가드 위반 시
     */
    public function applyPurchaseFunding(
        Vehicle $source,
        Vehicle $target,
        float $amountKrw,
        User $drafter,
        ?string $reason = null,
    ): InterVehicleTransfer {
        $this->assertPurchaseFundingGuards($source, $target, $amountKrw);
        $this->assertNoInProgressTransfer($source);

        $sourceRate = (float) $source->exchange_rate;
        $sourceForeign = round($amountKrw / $sourceRate, 2);

        return DB::transaction(function () use ($source, $target, $amountKrw, $sourceForeign, $sourceRate, $drafter, $reason) {
            $approvalReq = ApprovalRequest::create([
                'requester_id' => $drafter->id,
                'action_type' => ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING,
                'target_type' => Vehicle::class,
                'target_id' => $target->id,
                'status' => ApprovalRequest::STATUS_PENDING,
                'reason' => $reason,
                'payload' => [
                    'source_vehicle_id' => $source->id,
                    'source_vehicle_number' => $source->vehicle_number,
                    'target_vehicle_id' => $target->id,
                    'target_vehicle_number' => $target->vehicle_number,
                    'buyer_id' => $source->buyer_id,
                    'amount' => $sourceForeign,
                    'amount_krw' => $amountKrw,
                    'currency' => $source->currency,
                    'source_exchange_rate' => $sourceRate,
                ],
            ]);

            return InterVehicleTransfer::create([
                'source_vehicle_id' => $source->id,
                'target_vehicle_id' => $target->id,
                'buyer_id' => $source->buyer_id,
                'amount' => $sourceForeign,
                'amount_krw' => $amountKrw,
                'currency' => $source->currency,
                'kind' => InterVehicleTransfer::KIND_PURCHASE_FUNDING,
                'source_exchange_rate' => $sourceRate,
                'approval_request_id' => $approvalReq->id,
                'status' => InterVehicleTransfer::STATUS_PENDING,
                'requester_id' => $drafter->id,
            ]);
        });
    }

    /**
     * 매입 funding 관리 승인 (의사결정만, ledger 미생성). pending → approved_awaiting_finance.
     * ApprovalRequest.execute() 경유. 재무 실물확정(confirmPurchaseFundingByFinance)으로 이연.
     */
    public function approvePurchaseFunding(InterVehicleTransfer $transfer, User $approver): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->kind !== InterVehicleTransfer::KIND_PURCHASE_FUNDING) {
            throw new DomainException('보증금 매입 funding 이체가 아닙니다.');
        }
        if ($transfer->status !== InterVehicleTransfer::STATUS_PENDING) {
            throw new DomainException("이미 처리된 이체입니다 (현재 상태: {$transfer->status}).");
        }
        // SoD — 기안자 ≠ 관리 승인자 (기안≠관리≠재무 3자 분리, 보안팀 요구).
        if ($transfer->requester_id !== null && $transfer->requester_id === $approver->id) {
            throw new DomainException('기안자와 승인자는 다른 사용자여야 합니다 (SoD).');
        }

        $this->assertPurchaseFundingGuards($transfer->sourceVehicle, $transfer->targetVehicle, (float) $transfer->amount_krw);

        $transfer->update([
            'status' => InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            'approver_id' => $approver->id,
        ]);
    }

    /**
     * 매입 funding 재무 실물확정 (은행이체 후). approved_awaiting_finance → executed.
     *   소스 차: FinalPayment −(amount 외화) [소스 판매 미수↑, 소스환율 스냅샷 = 회수 환차 baseline]
     *   대상 차: PurchaseBalancePayment +(amount_krw 원화, confirmed, "바이어 funding" marker) → 매입 GREEN
     *
     * 가드: kind·SoD(관리승인자≠재무확정자)·paid 정산 없음. atomic 상태전이(동시 확정 시 ledger 이중생성 차단).
     * PBP creating 가드(canConfirmFinance)가 재무 권한 자연 강제.
     */
    public function confirmPurchaseFundingByFinance(InterVehicleTransfer $transfer, User $financeUser, ?string $note = null): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->kind !== InterVehicleTransfer::KIND_PURCHASE_FUNDING) {
            throw new DomainException('보증금 매입 funding 이체가 아닙니다.');
        }
        if ($transfer->approver_id !== null && $transfer->approver_id === $financeUser->id) {
            throw new DomainException('관리 승인자와 재무 확정자는 다른 사용자여야 합니다 (SoD).');
        }
        $this->assertPaidSettlementGuard($transfer->sourceVehicle, $transfer->targetVehicle);

        DB::transaction(function () use ($transfer, $financeUser, $note) {
            $confirmedAt = now();

            $affected = InterVehicleTransfer::whereKey($transfer->id)
                ->where('status', InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE)
                ->update([
                    'status' => InterVehicleTransfer::STATUS_EXECUTED,
                    'executed_at' => $confirmedAt,
                    'confirmed_by_user_id' => $financeUser->id,
                    'confirmed_at' => $confirmedAt,
                    'finance_note' => $note,
                ]);
            if ($affected !== 1) {
                throw new DomainException('이미 처리됐거나 상태가 변경된 이체입니다 (동시 확정 방지).');
            }

            $today = now()->toDateString();
            $sourceForeign = (float) $transfer->amount;
            $amountKrw = (float) $transfer->amount_krw;
            $sourceRate = (float) $transfer->source_exchange_rate;
            $marker = "보증금 매입 선지급 #{$transfer->id} (관리 승인 #{$transfer->approval_request_id}, 재무 확정 #{$financeUser->id})";

            // 소스 차 — 음수 FinalPayment (외화, 소스 판매 미수↑). 소스환율 스냅샷 보존.
            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$sourceForeign,
                'type' => 'balance',
                'payment_date' => $today,
                'exchange_rate' => $sourceRate,
                'note' => "→ 차량 #{$transfer->target_vehicle_id} 매입 선지급 ({$marker})",
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => $confirmedAt,
                'finance_note' => $note,
            ]);

            // 대상 차 — 매입 PBP (원화, confirmed, 바이어 funding marker) → 매입 GREEN.
            $pbp = PurchaseBalancePayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'amount' => $amountKrw,
                'type' => 'balance',
                'payment_date' => $today,
                'note' => "바이어 보증금 선지급 ← 차량 #{$transfer->source_vehicle_id} ({$marker})",
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => $confirmedAt,
                'finance_note' => $note,
            ]);

            InterVehicleTransfer::whereKey($transfer->id)->update(['purchase_balance_payment_id' => $pbp->id]);
        });

        $transfer->refresh();
    }

    /**
     * 매입 funding 전용 가드 (C2). standard assertGuards 와 별개 — amount 의미가 원화라 재사용 불가.
     *   같은 바이어 · source 환율>0 · source 미수율 ≤ 0.5 · source 차감 외화 ≤ available(source) ·
     *   바이어 보증금 여력(computeReceivableGauge available_krw) ≥ amountKrw · source/target paid 정산 없음.
     */
    private function assertPurchaseFundingGuards(Vehicle $source, Vehicle $target, float $amountKrw): void
    {
        if ($source->id === $target->id) {
            throw new DomainException('funding 출처와 대상 차량이 동일할 수 없습니다.');
        }
        if ($source->buyer_id === null || $target->buyer_id === null || $source->buyer_id !== $target->buyer_id) {
            throw new DomainException('funding 출처/대상 차량의 바이어가 동일해야 합니다.');
        }
        if ($amountKrw <= 0) {
            throw new DomainException('funding 금액은 0보다 커야 합니다.');
        }
        $sourceRate = (float) ($source->exchange_rate ?? 0);
        if ($sourceRate <= 0) {
            throw new DomainException('출처 차량의 판매 환율이 입력되어야 합니다 (원화↔외화 환산).');
        }

        $ratio = $source->unpaid_ratio;
        if ($ratio === null) {
            throw new DomainException('출처 차량의 미수율을 계산할 수 없습니다 (판매가 미입력).');
        }
        if ($ratio > 0.5) {
            $pct = round($ratio * 100, 1);
            throw new DomainException("출처 차량에 50% 이상 입금되어야 funding 가능합니다 (현재 미수율 {$pct}%).");
        }

        $sourceForeign = $amountKrw / $sourceRate;
        $limit = $this->available($source);
        if ($sourceForeign > $limit + 0.005) {
            throw new DomainException('출처 차량 한도를 초과했습니다 (받은 금액의 50%까지, 한도: '.number_format($limit, 2).' '.$source->currency.').');
        }

        // 바이어 aggregate 보증금 여력 캡 (단일출처 Buyer::computeReceivableGauge).
        $available = Buyer::find($source->buyer_id)?->receivableGauge()['available_krw'] ?? null;
        if ($available === null) {
            throw new DomainException('바이어 보증금 여력을 계산할 수 없습니다 (진행중 판매 이력 없음).');
        }
        if ($amountKrw > $available + 1) {
            throw new DomainException('바이어 보증금 여력을 초과했습니다 (여력: '.number_format($available).'원).');
        }

        $this->assertPaidSettlementGuard($source, $target);
    }

    /** 소스 기준 미처리 이체 중복 차단 (request()/applyDeposit 동일 정책). */
    private function assertNoInProgressTransfer(Vehicle $source): void
    {
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

        // SoD — 관리 승인자 ≠ 재무 확정자 (비-race 검증, 빠른 실패용 유지).
        if ($transfer->approver_id !== null && $transfer->approver_id === $financeUser->id) {
            throw new DomainException('관리 승인자와 재무 확정자는 다른 사용자여야 합니다 (SoD).');
        }

        // paid settlement guard — read-only 검증 (race 무관).
        $this->assertPaidSettlementGuard($transfer->sourceVehicle, $transfer->targetVehicle);

        DB::transaction(function () use ($transfer, $financeUser, $note) {
            $confirmedAt = now();

            // claudefinalreview 3-1 — 상태전이를 atomic conditional update 로 (이중기입 방지).
            // AWAITING→EXECUTED 를 단일 쿼리로 전이하고, 1건 전이한 호출만 FinalPayment 를 생성한다.
            // (구: 상태확인을 트랜잭션 밖에서 → 동시/중복 호출 시 ±결제쌍 중복 생성 위험.
            //  이제 두 번째 호출은 0건 전이 → throw → 결제 미생성.) 상태확인은 이 update 가 단일 출처.
            $affected = InterVehicleTransfer::whereKey($transfer->id)
                ->where('status', InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE)
                ->update([
                    'status' => InterVehicleTransfer::STATUS_EXECUTED,
                    'executed_at' => $confirmedAt,
                    'confirmed_by_user_id' => $financeUser->id,
                    'confirmed_at' => $confirmedAt,
                    'finance_note' => $note,
                ]);

            if ($affected !== 1) {
                // 이미 executed/voided 등으로 전이됐거나 동시 확정이 선행됨 → 결제 생성 안 함(롤백).
                throw new DomainException('이미 처리됐거나 상태가 변경된 이체입니다 (동시 확정 방지). 현재 상태를 확인하세요.');
            }

            $today = now()->toDateString();
            $amount = (float) $transfer->amount;
            $marker = "차량 간 자금 이체 #{$transfer->id} (관리 승인 #{$transfer->approval_request_id}, 재무 확정 #{$financeUser->id})";

            // 큐 20-B — transfer 잔금은 본질적으로 재무 확정된 ledger 페어. 생성 시점에 confirmed_at SET.
            // (분자 A안 필터 finalPayments()->whereNotNull('confirmed_at') 에 즉시 반영)
            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$amount,
                'payment_date' => $today,
                'note' => "→ 차량 #{$transfer->target_vehicle_id} 이체 ({$marker})",
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => $confirmedAt,
                'finance_note' => $note,
            ]);

            FinalPayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'payment_date' => $today,
                'note' => "← 차량 #{$transfer->source_vehicle_id} 에서 이체 ({$marker})",
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => $confirmedAt,
                'finance_note' => $note,
            ]);
        });

        // 조건부 update 는 in-memory 모델을 갱신하지 않으므로 호출자 일관성 위해 동기화.
        $transfer->refresh();
    }

    /**
     * 큐 19-K — 재무 정방향 거부 (실물 송금 불가 시).
     *
     * approved_awaiting_finance → finance_rejected.
     * final_payment 미생성 — ledger 영향 0. 영업이 사유 확인 후 새 transfer 요청 가능 (한도 그대로).
     *
     * 가드:
     *   - 상태가 approved_awaiting_finance 아니면 차단 (executed/voided 등 거부 불가)
     *   - approver_id === financeUser->id 면 self-reject 차단 (SoD)
     *   - reason 필수 5자 이상 — UI 모달에서 1차 검증, Service 레벨 2차 보장
     *
     * @throws DomainException 위 가드 위반 시
     */
    public function rejectByFinance(InterVehicleTransfer $transfer, User $financeUser, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('재무 거부 사유를 5자 이상 입력하세요.');
        }

        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE) {
            throw new DomainException("관리 승인 대기 상태의 이체만 재무 거부할 수 있습니다 (현재 상태: {$transfer->status}).");
        }
        if ($transfer->approver_id !== null && $transfer->approver_id === $financeUser->id) {
            throw new DomainException('관리 승인자와 재무 거부자는 다른 사용자여야 합니다 (SoD).');
        }

        $transfer->update([
            'status' => InterVehicleTransfer::STATUS_FINANCE_REJECTED,
            'finance_rejected_by_user_id' => $financeUser->id,
            'finance_rejected_at' => now(),
            'finance_reject_reason' => $reason,
        ]);
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
        // 매입 funding 은 target 이 매입 PBP(FinalPayment 아님)라 void 역거래(−FinalPayment on target)가 ledger 오염.
        // v1 은 void 미지원 — 정정은 별도 처리(개별 잠금해제/반대 PBP). standard/deposit_apply 는 정상.
        if ($transfer->kind === InterVehicleTransfer::KIND_PURCHASE_FUNDING) {
            throw new DomainException('보증금 매입 funding 은 이체 취소(void)를 지원하지 않습니다. 정정은 재무에 문의하세요.');
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
            $confirmedAt = now();

            // 큐 20-B — void 페어 잔금도 confirmed_at SET (정합성 유지)
            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'payment_date' => $today,
                'note' => "이체 취소 (#{$transfer->id}): {$reason}",
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => $confirmedAt,
                'finance_note' => $note,
            ]);

            FinalPayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$amount,
                'payment_date' => $today,
                'note' => "이체 취소 (#{$transfer->id}): {$reason}",
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => $confirmedAt,
                'finance_note' => $note,
            ]);

            $transfer->update([
                'status' => InterVehicleTransfer::STATUS_VOIDED,
                'voided_at' => $confirmedAt,
                'confirmed_by_user_id' => $financeUser->id,
                'confirmed_at' => $confirmedAt,
                'finance_note' => $note,
            ]);
        });
    }

    /**
     * 큐 19-L — void 재무 거부 (환불·역송금 불가 시).
     *
     * voided_awaiting_finance → executed (원래 이체 완료 상태로 복귀).
     * final_payment 페어는 그대로 유지 — 이체 자체가 살아있음. 영업이 다시 void 요청 가능.
     * void_finance_rejected_* 컬럼에 거부 메타 기록 (이력 추적).
     *
     * 가드:
     *   - 상태가 voided_awaiting_finance 아니면 차단
     *   - approver_id === financeUser->id 면 self-reject 차단 (SoD)
     *   - reason 필수 5자 이상
     *
     * @throws DomainException 위 가드 위반 시
     */
    public function rejectVoidByFinance(InterVehicleTransfer $transfer, User $financeUser, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('재무 거부 사유를 5자 이상 입력하세요.');
        }

        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE) {
            throw new DomainException("취소 승인 대기 상태의 이체만 재무 거부할 수 있습니다 (현재 상태: {$transfer->status}).");
        }
        if ($transfer->approver_id !== null && $transfer->approver_id === $financeUser->id) {
            throw new DomainException('관리 승인자와 재무 거부자는 다른 사용자여야 합니다 (SoD).');
        }

        $transfer->update([
            'status' => InterVehicleTransfer::STATUS_EXECUTED,
            'void_finance_rejected_by_user_id' => $financeUser->id,
            'void_finance_rejected_at' => now(),
            'void_finance_reject_reason' => $reason,
        ]);
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
