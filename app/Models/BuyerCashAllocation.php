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
 */
class BuyerCashAllocation extends Model
{
    protected $fillable = [
        'receipt_id', 'final_payment_id', 'vehicle_id', 'amount', 'created_by',
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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
