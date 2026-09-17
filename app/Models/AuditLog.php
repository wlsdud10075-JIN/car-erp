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
        // 2026-07-03 — 매도비 계좌번호 (개인정보)
        'purchase_fee_account' => '[ENCRYPTED ACCOUNT — value not logged]',
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
     * 로그인 세션이 없는 경로(HMAC 연동 API)에서 **행위자를 명시**한다 — 2026-08-12.
     *
     * `recordChange`/`recordEvent` 는 `auth()->id()` 를 쓰는데, board 연동은 세션이 없어 늘 null 이다.
     * 그러면 감사 화면에 **「시스템」**으로 찍혀 cron 이 바꾼 것과 구분이 안 된다 — 사람이 고른 값을
     * 사람 이름 없이 남기면 "누가 이렇게 했나" 를 못 따진다(포워딩사 지정이 정확히 그 경우).
     *
     * ⚠️ 연동 사용자를 **로그인시키지 않는다** — 여기서 바꾸는 건 감사 기록의 귀속뿐이다.
     * 연결된 User 가 없는 영업이면 null 로 남는다(그 경우의 추적은 `shipping_requests.requested_by_email`).
     */
    protected static ?int $actorUserId = null;

    public static function actingAs(?int $userId, callable $callback): mixed
    {
        $previous = self::$actorUserId;
        self::$actorUserId = $userId;
        try {
            return $callback();
        } finally {
            self::$actorUserId = $previous;
        }
    }

    private static function actorId(): ?int
    {
        return auth()->id() ?? self::$actorUserId;
    }

    /**
     * 🧹 **이 저장이 이 컬럼을 「실제로」 바꿨나** — 감사 기록 여부의 **단일 판정** (jin 2026-09-17).
     *
     * 🚨 `wasChanged()` 만 믿으면 **안 바꾼 칸이 매번 쌓인다.** Eloquent 는 마지막에 `strcmp` 로 비교하는데,
     *    DB `decimal(15,2)` 는 `"12100.00"` **문자열**로 읽히고 화면은 `12100`(숫자)을 넣는다 ⇒ 다르다고 본다.
     *    이 레포는 같은 함정을 이미 겪어 `Vehicle::guardLedgerLockOnSaving()` 에 정밀 비교를 넣어 뒀는데,
     *    **감사 훅만 안 따라왔다.**
     *
     * 📏 **운영 실측 (heymanerp 2026-09-17)**: 감사 32,245행 중 **26,727행(82.9%)** 이
     *    「수치는 같은데 표기만 다른」 기록이었다. 한 번 저장에 9행씩 쌓인다:
     *    `sale_price [12100.00]→[12100]` · `tax_dc [0.00]→[0]` · `exchange_rate [1621.0000]→[1621]` …
     *    jin: *「바꾸지 않았는데도 기록이 누적되면 찾기도 쉽지가 않아.」*
     *
     * 🚫 **기존 행은 지우지 않는다** — 감사 기록이다. 새로 안 쌓이게만 한다.
     * ⚠️ 새로 감사 대상 컬럼을 늘리는 모델은 **반드시 이걸 거쳐야** 한다(4개 모델이 같이 쓴다).
     */
    public static function isRealChange(Model $model, string $column): bool
    {
        return $model->wasChanged($column)
            && self::valuesDiffer($model->getOriginal($column), $model->getAttribute($column));
    }

    /**
     * 두 값이 **의미상** 다른가. 숫자는 숫자로, 빈 값(null·'')끼리는 같은 것으로 본다.
     *
     * ⚠️ 허용 오차는 **저장 가능한 최소 단위보다 훨씬 작게** 잡는다 — `decimal(15,2)` 의 최소 변화는
     *    0.01, 환율 `decimal(*,4)` 은 0.0001 이라 1e-9 면 진짜 변경을 놓치지 않는다.
     */
    public static function valuesDiffer(mixed $old, mixed $new): bool
    {
        $isEmpty = fn (mixed $v): bool => $v === null || $v === '';

        if ($isEmpty($old) && $isEmpty($new)) {
            return false;
        }
        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) > 0.000000001;
        }

        return self::stringify($old) !== self::stringify($new);
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
            'user_id' => self::actorId(),
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
            'user_id' => self::actorId(),
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
