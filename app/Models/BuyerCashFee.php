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
    /** 송금 수수료 — 실제로 들어온 돈이 기재액보다 적었을 때 그 차액을 턴다. */
    public const KIND_FEE = 'fee';

    /**
     * 과입금 정리 (2026-09-09) — 과입금을 적립금·잡손실로 돌리면 감액된 잔금만큼 현금이 지갑으로
     * **되돌아온다.** 그 되돌아온 몫을 원장에서 빼는 행이다.
     *
     * 🚨 이게 없으면 **이중 크레딧**이 된다 — 적립금은 적립금대로 생기고 현금도 되돌아와, 과입금
     *    30 에 크레딧이 60 이 된다(재현 실측). 2026-09-09 이전엔 이 행이 없어서 그 상태였다.
     */
    public const KIND_OVERPAY = 'overpay';

    public const KINDS = [self::KIND_FEE, self::KIND_OVERPAY];

    protected $fillable = [
        'buyer_id', 'currency', 'kind', 'charged_date', 'amount', 'note', 'created_by',
    ];

    /** 모델 훅·화면이 읽는 기본값 — DB default 는 INSERT 때만 적용된다(SKILLS §8 #80). */
    protected $attributes = ['kind' => self::KIND_FEE];

    public function isOverpayCleanup(): bool
    {
        return $this->kind === self::KIND_OVERPAY;
    }

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
