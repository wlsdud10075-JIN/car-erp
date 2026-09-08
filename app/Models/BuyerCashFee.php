<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 바이어 현금 수수료 1건 — 기획 = `docs/design/buyer-cash-ledger.md`.
 *
 * 바이어가 보낸 돈을 쓰다 보면 **한참 뒤에 송금 수수료가 잡혀** 실제 들어온 돈이 기재액보다
 * 조금 적었던 것으로 드러난다. 그러면 원장에 영영 안 없어지는 잔돈이 남는다. 그걸 터는 행이다.
 *
 * 🔑 **이 행 자체는 금액만 들고 있고, 실제로 현금을 갉아먹는 건 `buyer_cash_allocations` 다**
 *    (판매잔금 배분과 같은 테이블·같은 FIFO). 그래서 입금의 `remaining_amount` 와
 *    `BuyerCashReceipt::balanceFor` 가 **손대지 않아도** 수수료를 반영한다 — 뺄셈이 한 곳이다.
 *
 * 되돌리기 = 이 행을 지우면 배분이 cascade 로 사라져 현금이 그대로 돌아온다.
 */
class BuyerCashFee extends Model
{
    protected $fillable = [
        'buyer_id', 'currency', 'charged_date', 'amount', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'charged_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BuyerCashAllocation::class, 'fee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
