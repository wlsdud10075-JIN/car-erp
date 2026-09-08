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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
