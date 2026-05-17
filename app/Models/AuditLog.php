<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /** Append-only — updated_at 미사용. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'approval_request_id',
        'auditable_type', 'auditable_id', 'action',
        'column_name', 'old_value', 'new_value', 'ip_address',
    ];

    /** RRN 등 마스킹 처리 컬럼 — old/new value를 평문 저장 X. */
    public const MASKED_COLUMNS = [
        'nice_reg_owner_rrn' => '[ENCRYPTED RRN — value not logged]',
        // 큐 20-A — 매입처 계좌번호 (개인정보)
        'purchase_seller_account' => '[ENCRYPTED ACCOUNT — value not logged]',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 큐 14-4-1 — 승인 흐름 변경에 ApprovalRequest 링크 부착.
     * 일반 변경 시 null, 승인 commit 시 해당 ApprovalRequest.id.
     */
    protected static ?int $currentApprovalRequestId = null;

    public static function withApprovalRequest(?int $approvalRequestId, callable $callback): mixed
    {
        $previous = self::$currentApprovalRequestId;
        self::$currentApprovalRequestId = $approvalRequestId;
        try {
            return $callback();
        } finally {
            self::$currentApprovalRequestId = $previous;
        }
    }

    /**
     * 단일 컬럼 변경 기록. RRN 등 마스킹 컬럼은 값을 저장하지 않고
     * "변경 발생" 사실만 기록 (개보법 §29 — 민감정보 로그 평문 금지).
     */
    public static function recordChange(Model $model, string $column, mixed $old, mixed $new): self
    {
        if (array_key_exists($column, self::MASKED_COLUMNS)) {
            $marker = self::MASKED_COLUMNS[$column];
            $oldValue = $old !== null && $old !== '' ? $marker : null;
            $newValue = $new !== null && $new !== '' ? $marker : null;
        } else {
            $oldValue = self::stringify($old);
            $newValue = self::stringify($new);
        }

        return self::create([
            'user_id' => auth()->id(),
            'approval_request_id' => self::$currentApprovalRequestId,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => 'updated',
            'column_name' => $column,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * 라이프사이클 이벤트 기록 (created / deleted / restored / force_deleted).
     */
    public static function recordEvent(Model $model, string $action): self
    {
        return self::create([
            'user_id' => auth()->id(),
            'approval_request_id' => self::$currentApprovalRequestId,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'column_name' => null,
            'old_value' => null,
            'new_value' => null,
            'ip_address' => request()?->ip(),
        ]);
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
