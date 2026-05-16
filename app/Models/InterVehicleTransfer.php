<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 큐 19-A — 차량 간 자금 이체 (회의록 v5 §13).
 *
 * 같은 바이어가 1번 차에 50%↑ 입금 후 2번 차도 사면, 관리 승인 하에
 * "받은 금액의 50% 한도" 내에서 1→2 차로 자금 이체.
 *
 * 실행은 InterVehicleTransferService:
 *   source 차량에 음수 final_payment + target 차량에 양수 final_payment 트랜잭션,
 *   두 FinalPayment는 transfer_id로 묶여 추적.
 *
 * 큐 19-F — 5상태 머신 (관리 ≠ 재무 분리, 회의록 2026-05-16):
 *   pending                        — 영업 요청, ApprovalRequest 대기
 *   approved_awaiting_finance      — 관리 승인 (의사결정 통과, final_payment 미생성)
 *   executed                       — 재무 확정 (final_payment 페어 생성, ledger 기록)
 *   voided_awaiting_finance        — 영업 void 요청 + 관리 승인 (의사결정 통과, 반대 부호 final_payment 미생성)
 *   voided                         — 재무 void 확정 (반대 부호 final_payment 페어 생성)
 *   finance_rejected               — 재무 거부 (큐 19-K, approved_awaiting_finance 진입, ledger 미반영)
 *
 * STATUS_APPROVED 는 deprecated — 19-F-A 마이그레이션이 backfill로 approved_awaiting_finance 로 전환.
 * 신규 흐름에선 사용 금지. 19-F-B Service 에서 approve() 가 approved_awaiting_finance 로 직접 set.
 */
class InterVehicleTransfer extends Model
{
    protected $fillable = [
        'source_vehicle_id', 'target_vehicle_id', 'buyer_id',
        'amount', 'currency',
        'approval_request_id',
        'status',
        'executed_at', 'voided_at', 'void_reason',
        'requester_id', 'approver_id',
        'confirmed_by_user_id', 'confirmed_at', 'finance_note',
        'finance_rejected_by_user_id', 'finance_rejected_at', 'finance_reject_reason',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'executed_at' => 'datetime',
        'voided_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'finance_rejected_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    /** @deprecated 큐 19-F — approved_awaiting_finance 로 대체. backfill 후 미사용. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_APPROVED_AWAITING_FINANCE = 'approved_awaiting_finance';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_VOIDED_AWAITING_FINANCE = 'voided_awaiting_finance';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_FINANCE_REJECTED = 'finance_rejected';

    public const STATUSES = [
        self::STATUS_PENDING => '대기',
        self::STATUS_APPROVED => '승인',  // legacy
        self::STATUS_APPROVED_AWAITING_FINANCE => '관리 승인 (재무 처리 대기)',
        self::STATUS_EXECUTED => '실행 완료',
        self::STATUS_VOIDED_AWAITING_FINANCE => '취소 승인 (재무 처리 대기)',
        self::STATUS_VOIDED => '취소',
        self::STATUS_FINANCE_REJECTED => '재무 거부',
    ];

    public function sourceVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'source_vehicle_id');
    }

    public function targetVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'target_vehicle_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function financeConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function financeRejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_rejected_by_user_id');
    }

    public function finalPayments(): HasMany
    {
        return $this->hasMany(FinalPayment::class, 'transfer_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'badge-amber',
            self::STATUS_APPROVED, self::STATUS_APPROVED_AWAITING_FINANCE => 'badge-blue',
            self::STATUS_EXECUTED => 'badge-green',
            self::STATUS_VOIDED_AWAITING_FINANCE => 'badge-amber',
            self::STATUS_VOIDED => 'badge-gray',
            self::STATUS_FINANCE_REJECTED => 'badge-red',
            default => 'badge-gray',
        };
    }
}
