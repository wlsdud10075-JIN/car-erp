<?php

namespace App\Models;

use App\Services\InterVehicleTransferService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'requester_id', 'approver_id',
        'target_type', 'target_id',
        'action_type', 'payload',
        'status', 'reason', 'decision_note', 'decided_at',
        'used_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'decided_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    // 액션 타입 (회의록 v5.1 §9-2 + v5 §13 큐 19)
    public const TYPE_INTER_BUYER_OVERLAP = 'inter_buyer_overlap';

    public const TYPE_SETTLEMENT_PAY = 'settlement_pay';

    public const TYPE_SENSITIVE_ACTION = 'sensitive_action';

    public const TYPE_UNPAID_EXPORT_OVERRIDE = 'unpaid_export_override';

    public const TYPE_INTER_VEHICLE_TRANSFER = 'inter_vehicle_transfer';

    public const TYPES = [
        self::TYPE_INTER_BUYER_OVERLAP => '같은 바이어 미수+신규 거래',
        self::TYPE_SETTLEMENT_PAY => '정산 지급',
        self::TYPE_SENSITIVE_ACTION => '민감 액션 (폐기/RRN/B/L)',
        self::TYPE_UNPAID_EXPORT_OVERRIDE => '50% 룰 예외',
        self::TYPE_INTER_VEHICLE_TRANSFER => '차량 간 자금 이체',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActionLabelAttribute(): string
    {
        return self::TYPES[$this->action_type] ?? $this->action_type;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '대기',
            self::STATUS_APPROVED => '승인',
            self::STATUS_REJECTED => '거부',
            self::STATUS_CANCELLED => '취소',
            default => $this->status,
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'badge-amber',
            self::STATUS_APPROVED => 'badge-green',
            self::STATUS_REJECTED => 'badge-red',
            self::STATUS_CANCELLED => 'badge-gray',
            default => 'badge-gray',
        };
    }

    /**
     * 큐 14-4-2 — 승인 후 실제 액션 실행.
     * /erp/approvals decide() 메서드의 approve 분기에서 호출.
     * AuditLog는 자동으로 approval_request_id 링크 (withApprovalRequest 컨텍스트).
     */
    public function execute(): void
    {
        DB::transaction(function () {
            AuditLog::withApprovalRequest($this->id, function () {
                match ($this->action_type) {
                    self::TYPE_SETTLEMENT_PAY => $this->executeSettlementPay(),
                    self::TYPE_INTER_BUYER_OVERLAP => $this->executeInterBuyerOverlap(),
                    self::TYPE_INTER_VEHICLE_TRANSFER => $this->executeInterVehicleTransfer(),
                    default => throw new \LogicException("Unsupported action_type: {$this->action_type}"),
                };
            });
        });
    }

    /**
     * 큐 14-4-4 — inter_buyer_overlap 승인은 no-op.
     * 실제 액션은 영업이 다음 차량 등록 시도 시 Vehicle::guardSameBuyerOverlap()에서
     * 이 승인을 발견 → used_at 마킹 + 통과. execute()는 호출만 안전.
     */
    private function executeInterBuyerOverlap(): void
    {
        // approve 자체가 영업의 다음 차량 등록을 허용하는 신호. 추가 액션 없음.
    }

    /**
     * 큐 19-B — inter_vehicle_transfer 승인 = InterVehicleTransferService::execute() 호출.
     * service가 source 음수 + target 양수 final_payment 페어 트랜잭션 처리.
     */
    private function executeInterVehicleTransfer(): void
    {
        $transfer = InterVehicleTransfer::where('approval_request_id', $this->id)->firstOrFail();
        $approver = auth()->user() ?? throw new \LogicException('승인자 사용자 컨텍스트가 필요합니다.');
        app(InterVehicleTransferService::class)->execute($transfer, $approver);
    }

    private function executeSettlementPay(): void
    {
        $settlement = Settlement::findOrFail($this->target_id);
        if ($settlement->settlement_status !== 'confirmed') {
            throw new \DomainException('정산 상태가 confirmed가 아닙니다 (현재: '.$settlement->settlement_status.').');
        }
        $settlement->settlement_status = 'paid';
        $settlement->paid_at = now();
        $settlement->save();
    }
}
