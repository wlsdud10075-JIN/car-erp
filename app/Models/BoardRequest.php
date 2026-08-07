<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * board → erp 요청·확인 신호 — 카톡으로 하던 "해주세요" 두 마디.
 *
 * 권위 = docs/integration/board-portal-api.md §11 / 계획 = docs/design/board-erp-request-ack.md.
 *
 * 🚫 **금액을 다루지 않는다.** board 가 보내도 버린다 — 매입 지급액·판매 N잔금 기입은
 *    전부 erp 관리 이상의 일이다. 여기 금액 필드를 추가하려거든 §11-2 를 먼저 읽을 것.
 *
 * 닫히는 방식이 type 별로 다르다:
 *   - purchase_payment      = **자동**. 매입 미지급 0 이면 소멸(누를 사람이 있으면 카톡으로 돌아간다).
 *   - sale_payment_confirm  = **수동**. 재무가 차량별로 체크(부분입금이 흔해 기계 판정 불가).
 */
class BoardRequest extends Model
{
    /** 매입 [입금요청] — 차량 1대 단위. */
    public const TYPE_PURCHASE_PAYMENT = 'purchase_payment';

    /** 판매 [판매대금확인] — 바이어 1 + 차량 N대 묶음. */
    public const TYPE_SALE_PAYMENT_CONFIRM = 'sale_payment_confirm';

    public const TYPES = [self::TYPE_PURCHASE_PAYMENT, self::TYPE_SALE_PAYMENT_CONFIRM];

    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_CANCELLED];

    /** 화면 라벨 (jin 확정 명칭 — 대화·UI 통일). */
    public const TYPE_LABELS = [
        self::TYPE_PURCHASE_PAYMENT => '입금요청',
        self::TYPE_SALE_PAYMENT_CONFIRM => '판매대금확인',
    ];

    protected $fillable = [
        'batch_id', 'type', 'vehicle_id', 'buyer_id', 'status',
        'requested_by_email', 'requested_at', 'confirmed_by_id', 'confirmed_at', 'note',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }

    /**
     * 멱등 — 같은 차 + 같은 type 에 이미 열린 요청이 있으면 만들지 않는다.
     * board 가 재전송해도 뱃지가 두 개 생기지 않게 하는 단일 지점.
     */
    public static function hasOpenFor(int $vehicleId, string $type): bool
    {
        return self::query()->where('vehicle_id', $vehicleId)->ofType($type)->open()->exists();
    }

    /**
     * 신호 1건 생성. 이미 열려 있으면 null (호출측이 skipped 로 응답).
     * batch_id 를 넘기면 그 묶음에 붙고, 없으면 새 묶음(입금요청 = 1행짜리 묶음).
     */
    public static function open(
        int $vehicleId,
        string $type,
        string $requestedByEmail,
        ?int $buyerId = null,
        ?string $batchId = null,
        ?string $note = null,
    ): ?self {
        if (! in_array($type, self::TYPES, true) || self::hasOpenFor($vehicleId, $type)) {
            return null;
        }

        return self::create([
            'batch_id' => $batchId ?: (string) Str::uuid(),
            'type' => $type,
            'vehicle_id' => $vehicleId,
            'buyer_id' => $buyerId,
            'status' => self::STATUS_OPEN,
            'requested_by_email' => $requestedByEmail,
            'requested_at' => now(),
            'note' => $note,
        ]);
    }

    /** 확인 처리 — 재무가 체크(수동) 또는 자동 해소. $user 없으면 시스템 자동 해소. */
    public function markDone(?User $user = null): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            return;
        }

        $this->update([
            'status' => self::STATUS_DONE,
            'confirmed_by_id' => $user?->id,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * 묶음 집계 상태 — board 칩(3/5)·erp 카드 헤더 공용 단일 출처.
     * 전부 done = done / 일부 done = partial / 하나도 안 됐으면 open / 남은 게 없으면 cancelled.
     *
     * @param  Collection<int, self>  $lines
     */
    public static function batchStatus($lines): string
    {
        $live = $lines->where('status', '!=', self::STATUS_CANCELLED);
        if ($live->isEmpty()) {
            return self::STATUS_CANCELLED;
        }

        $done = $live->where('status', self::STATUS_DONE)->count();
        if ($done === 0) {
            return self::STATUS_OPEN;
        }

        return $done === $live->count() ? self::STATUS_DONE : 'partial';
    }
}
