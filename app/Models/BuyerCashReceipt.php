<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 바이어 현금 입금 1건 — 바이어가 보낸 외화를 한국에서 받은 그 건.
 * 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🚫 **적립금(`SavingsStatus`)과 다른 것이다.** 적립금은 「회사가 준 크레딧」이고 이건
 *    「실제로 들어온 현금」이다(jin 2026-09-04 — "진짜 cash 가 들어온 걸 입력하고 그만큼만 쓴다").
 *
 * 🚨 **원화·환율이 없다.** 이 원장은 외화 풀이고 원화 환산은 판매잔금 행(`FinalPayment.exchange_rate`)이
 *    계속 담당한다. 여기에 환율을 넣으면 같은 돈의 원화값 출처가 둘이 되어 정산 환율이 갈린다.
 *
 * 🔑 **잔액은 캐시 컬럼이 아니라 조인이다** — `amount − Σ allocations.amount`.
 *    캐시를 두면 배분·회수 경로마다 갱신을 빠뜨릴 자리가 생긴다(v1 은 안 둔다).
 */
class BuyerCashReceipt extends Model
{
    /** 잔액 비교 허용 오차 — 외화 소수 둘째 자리까지 쓰므로 그 아래는 0 으로 본다. */
    public const EPSILON = 0.005;

    protected $fillable = [
        'buyer_id', 'currency', 'received_date', 'amount', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BuyerCashAllocation::class, 'receipt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 이미 판매잔금으로 나간 금액(외화). */
    public function getAllocatedAmountAttribute(): float
    {
        // 관계가 이미 로드돼 있으면 그걸 쓴다 — 목록에서 행마다 재질의하면 N+1.
        if ($this->relationLoaded('allocations')) {
            return (float) $this->allocations->sum('amount');
        }

        return (float) $this->allocations()->sum('amount');
    }

    /** 남은 현금(외화). 음수로 내려갈 일이 없어야 정상 — 배분이 잔액을 넘지 못하게 막는다. */
    public function getRemainingAmountAttribute(): float
    {
        return round((float) $this->amount - $this->allocated_amount, 2);
    }

    /** 아직 남은 게 있는가(부동소수 오차 흡수). */
    public function getHasRemainingAttribute(): bool
    {
        return $this->remaining_amount > self::EPSILON;
    }

    /**
     * FIFO 소진 순서 — 오래 받은 돈부터 쓴다. 같은 날이면 먼저 기재한 것(id)부터.
     * 🚫 이 순서를 화면·서비스에서 각자 정하지 말 것(갈리면 배분 결과가 화면과 달라진다).
     */
    public function scopeFifo(Builder $q): Builder
    {
        return $q->orderBy('received_date')->orderBy('id');
    }

    public function scopeForBuyerCurrency(Builder $q, int $buyerId, string $currency): Builder
    {
        return $q->where('buyer_id', $buyerId)->where('currency', $currency);
    }

    /**
     * 바이어×통화의 남은 현금 총액. 게이트·화면·엑셀이 전부 이걸 쓴다(단일 출처).
     * 서브쿼리 1회 — 입금 행을 다 불러오지 않는다.
     */
    public static function balanceFor(int $buyerId, string $currency): float
    {
        $received = (float) static::forBuyerCurrency($buyerId, $currency)->sum('amount');
        $allocated = (float) BuyerCashAllocation::query()
            ->whereHas('receipt', fn ($q) => $q->forBuyerCurrency($buyerId, $currency))
            ->sum('amount');

        return round($received - $allocated, 2);
    }
}
