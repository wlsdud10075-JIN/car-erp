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
 * 실행은 InterVehicleTransferService (큐 19-B):
 *   source 차량에 음수 final_payment + target 차량에 양수 final_payment 트랜잭션,
 *   두 FinalPayment는 transfer_id로 묶여 추적.
 *
 * 상태 머신 (append-only):
 *   pending  → approved → executed → voided (별도 voided 거래로만)
 *   pending  → rejected (ApprovalRequest 측 결정 반영)
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
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'executed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_PENDING => '대기',
        self::STATUS_APPROVED => '승인',
        self::STATUS_EXECUTED => '실행 완료',
        self::STATUS_VOIDED => '취소',
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
            self::STATUS_APPROVED => 'badge-blue',
            self::STATUS_EXECUTED => 'badge-green',
            self::STATUS_VOIDED => 'badge-gray',
            default => 'badge-gray',
        };
    }
}
