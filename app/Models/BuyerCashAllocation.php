<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 배분 1줄 — 「이 입금에서 이 판매잔금으로 얼마」. 기획 = docs/design/buyer-cash-ledger.md
 *
 * 한 잔금이 여러 입금을 소진할 수 있어(FIFO) 잔금 1건에 이 행이 2줄 이상 붙을 수 있다.
 *
 * ⚠️ **이 행을 직접 지워 회수하지 말 것.** 회수는 「그 잔금 행을 지우는 것」이고
 *    (`final_payment_id` cascadeOnDelete) 그러면 이 행도 같이 사라진다.
 *    여기만 지우면 잔금은 남고 현금만 돌아와 **미수와 현금이 어긋난다**.
 *
 * [수수료] 2026-09-08 — 수수료도 같은 테이블을 쓴다. `fee_id` 가 차 있고
 *    `final_payment_id`/`vehicle_id` 는 비어 있다. 같은 테이블에 둔 이유 = 입금의
 *    `remaining_amount` 뺄셈이 **한 곳**이어야 하기 때문이다. 둘로 나누면
 *    「잔액은 0 인데 쓸 수 있다고 나오는」 화면이 생긴다(SKILLS §8 #45).
 *    => 둘 중 **정확히 하나만** 채워진다. 판정은 `isFee()`.
 */
class BuyerCashAllocation extends Model
{
    protected $fillable = [
        'receipt_id', 'final_payment_id', 'fee_id', 'vehicle_id', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(BuyerCashReceipt::class, 'receipt_id');
    }

    public function finalPayment(): BelongsTo
    {
        return $this->belongsTo(FinalPayment::class, 'final_payment_id');
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(BuyerCashFee::class, 'fee_id');
    }

    /** 수수료로 턴 행인가 (2026-09-08). 판매잔금 배분과 **같은 테이블**을 쓰므로 이걸로 가른다. */
    public function isFee(): bool
    {
        return $this->fee_id !== null;
    }

    /**
     * 판매탭 「송금 수수료」(`final_payments.type='fee'`)로 나간 배분인가 — 2026-09-09 부터 생긴다.
     *
     * 🧭 `isFee()` 와 다르다. 이쪽은 **차량이 붙어 있다**(그 차 판매탭에 기입했으므로) —
     *    그래서 화면이 차량번호를 그대로 쓰고 뱃지만 덧붙인다. 구분을 안 하면 6 EUR 짜리
     *    수수료가 「아주 작은 잔금」으로 보여 사람이 이중으로 또 털게 된다.
     *
     * ⚠️ `finalPayment` 관계를 부분 select 로 eager load 할 때 **`type` 을 빼면 늘 false 가 된다**
     *    (예외 0 · 화면은 정상 렌더). 단일 출처 = `BuyerAccountService` 의 with 목록.
     */
    public function isVehicleFee(): bool
    {
        return $this->fee_id === null && $this->finalPayment?->type === 'fee';
    }

    /**
     * 🗓️ 이 배분 줄이 말하는 날짜 — **단일 출처** (jin 2026-09-10 제보).
     *
     * 잔금은 수금일(`payment_date`), 원장 수수료는 수수료일(`charged_date`)이다.
     *
     * 🚫 **`created_at` 을 쓰지 말 것** — `BuyerCashService::allocate()` 는 잔금이 바뀔 때마다
     *    배분을 지우고 FIFO 로 다시 깐다. 그래서 그 값은 「FIFO 가 마지막으로 돌아간 시각」이고,
     *    돈이 오간 날과 무관하다(실측: 09-09 잔금의 배분 행이 09-10 에 생성돼 있었다).
     */
    public function usedDate(): ?string
    {
        return $this->isFee()
            ? $this->fee?->charged_date?->format('Y-m-d')
            : $this->finalPayment?->payment_date?->format('Y-m-d');
    }

    /**
     * 🔁 **나중에 받은 돈으로 메운 줄인가** — 잔금 수금일 < 입금 수령일 (jin 2026-09-10 제보).
     *
     * FIFO 재배분의 정상적인 결과다: 먼저 받은 돈을 다른 차가 차지하면, 그 잔금은 **나중 입금**으로
     * 밀려난다. 그런데 화면에는 「09-10 에 받은 돈이 09-09 에 쓰였다」로만 보여 **시간이 거꾸로 간
     * 것처럼** 읽힌다(실측 heymanerp: 09-10 입금이 368머4746 의 09-09 잔금 0.49 를 메웠다).
     *
     * ⚠️ 금액은 틀리지 않는다 — 설명이 빠진 것이다. 두 화면이 **같은 판정**을 쓰게 여기 둔다
     *    (각자 비교식을 적으면 한쪽만 표시돼 더 헷갈린다 — SKILLS §8 #44).
     *
     * @param  \DateTimeInterface|string|null  $receiptDate  그 입금의 수령일
     */
    public function isBackfillFor($receiptDate): bool
    {
        $used = $this->usedDate();
        if ($used === null || $receiptDate === null) {
            return false;
        }
        $received = $receiptDate instanceof \DateTimeInterface
            ? $receiptDate->format('Y-m-d')
            : substr((string) $receiptDate, 0, 10);

        return $used < $received;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
