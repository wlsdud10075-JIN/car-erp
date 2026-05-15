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
 * 큐 19-B — 차량 간 자금 이체 Service (회의록 v5 §13).
 *
 * 책임:
 *   - available()  : 소스 차량의 이체 한도 계산 (받은 금액 × 0.5)
 *   - request()    : 영업 요청 — InterVehicleTransfer(pending) + ApprovalRequest(pending) 동시 생성
 *   - execute()    : 관리 승인 후 실제 자금 이체 — source 음수 + target 양수 final_payment 페어,
 *                    transfer.status = executed (append-only)
 *
 * void는 별도 흐름 (19-E): voided 거래용 ApprovalRequest 별도 + voidExecute() 메서드.
 *
 * 안전 가드 4종 (요청 시):
 *   1. 양 차량 buyer_id 동일
 *   2. 양 차량 currency 동일 (정합 — amount의 통화 의미 보존)
 *   3. 소스 unpaid_ratio ≤ 0.5 (= 받은 금액 50%↑)
 *   4. amount > 0 AND amount ≤ available()
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
     * 관리 승인 후 실제 자금 이체 실행.
     * 트랜잭션:
     *   1. source 차량에 amount = -X final_payment (transfer_id 연결)
     *   2. target 차량에 amount = +X final_payment (transfer_id 연결)
     *   3. transfer.status = executed, executed_at = now, approver_id 기록
     *
     * append-only — 이미 executed/voided인 거래는 재실행 차단.
     * FinalPayment::created 훅이 양 차량 sale_unpaid_amount_krw_cache 자동 갱신.
     *
     * @throws DomainException 상태가 pending/approved가 아니거나 가드 위반 시
     */
    public function execute(InterVehicleTransfer $transfer, User $approver): void
    {
        $transfer = $transfer->fresh();
        if (! in_array($transfer->status, [InterVehicleTransfer::STATUS_PENDING, InterVehicleTransfer::STATUS_APPROVED], true)) {
            throw new DomainException("이미 처리된 이체입니다 (현재 상태: {$transfer->status}).");
        }

        // 실행 시점에도 한도 재검증 — 요청과 승인 사이 시간차로 source가 추가 입금/환불 받았을 수 있음
        $this->assertGuards($transfer->sourceVehicle, $transfer->targetVehicle, (float) $transfer->amount);

        DB::transaction(function () use ($transfer, $approver) {
            $today = now()->toDateString();
            $amount = (float) $transfer->amount;
            $note = "차량 간 자금 이체 #{$transfer->id} (관리자 승인 #{$transfer->approval_request_id})";

            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$amount,
                'payment_date' => $today,
                'note' => "→ 차량 #{$transfer->target_vehicle_id} 이체 ({$note})",
            ]);

            FinalPayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'payment_date' => $today,
                'note' => "← 차량 #{$transfer->source_vehicle_id} 에서 이체 ({$note})",
            ]);

            $transfer->update([
                'status' => InterVehicleTransfer::STATUS_EXECUTED,
                'executed_at' => now(),
                'approver_id' => $approver->id,
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
        // 이미 대기중인 void 요청 있으면 차단
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
     * 큐 19-E — void 실제 실행 (관리 승인 후 ApprovalRequest::executeInterVehicleTransferVoid에서 호출).
     *
     * 트랜잭션:
     *   1. source 차량에 amount = +X final_payment (원본 음수 → 반대 양수, transfer_id 연결)
     *   2. target 차량에 amount = -X final_payment (원본 양수 → 반대 음수, transfer_id 연결)
     *   3. transfer.status = voided, voided_at = now, void_reason 기록
     *
     * append-only — 기존 final_payment는 절대 삭제·수정 안 함 (회계 흐름 보존).
     * FinalPayment::$allowTransferLinkedMutation는 신규 INSERT엔 영향 없음 (UPDATE/DELETE만 가드).
     *
     * @throws DomainException status가 executed 아니거나 H4 가드 위반 시
     */
    public function void(InterVehicleTransfer $transfer, User $approver, string $reason): void
    {
        $transfer = $transfer->fresh();
        if ($transfer->status !== InterVehicleTransfer::STATUS_EXECUTED) {
            throw new DomainException("실행 완료된 이체만 취소할 수 있습니다 (현재 상태: {$transfer->status}).");
        }

        // H4 정합 — void도 회계 변동이므로 paid Settlement 있으면 차단
        foreach (['출처' => $transfer->sourceVehicle, '대상' => $transfer->targetVehicle] as $label => $v) {
            if ($v && $v->settlements()->where('settlement_status', 'paid')->exists()) {
                throw new DomainException("{$label} 차량에 paid 정산이 있어 이체 취소할 수 없습니다.");
            }
        }

        DB::transaction(function () use ($transfer, $approver, $reason) {
            $today = now()->toDateString();
            $amount = (float) $transfer->amount;

            FinalPayment::create([
                'vehicle_id' => $transfer->source_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,  // 원본 -X → 반대 +X
                'payment_date' => $today,
                'note' => "이체 취소 (#{$transfer->id}): {$reason}",
            ]);

            FinalPayment::create([
                'vehicle_id' => $transfer->target_vehicle_id,
                'transfer_id' => $transfer->id,
                'amount' => -$amount,  // 원본 +X → 반대 -X
                'payment_date' => $today,
                'note' => "이체 취소 (#{$transfer->id}): {$reason}",
            ]);

            $transfer->update([
                'status' => InterVehicleTransfer::STATUS_VOIDED,
                'voided_at' => now(),
                'void_reason' => $reason,
                'approver_id' => $approver->id,
            ]);
        });
    }

    /**
     * 안전 가드 5종 검증. 위반 시 예외.
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
        if ($amount > $limit + 0.005) {  // float 오차 ±0.005
            throw new DomainException('이체 한도를 초과했습니다 (받은 금액의 50%까지 가능, 한도: '.number_format($limit, 2).').');
        }

        // 큐 10 H4 정합 — 양 차량 중 하나라도 paid Settlement 있으면 차단
        foreach (['출처' => $source, '대상' => $target] as $label => $v) {
            if ($v->settlements()->where('settlement_status', 'paid')->exists()) {
                throw new DomainException("{$label} 차량에 paid 정산이 있어 자금 이체할 수 없습니다. 정산 취소 후 재시도하세요.");
            }
        }
    }
}
