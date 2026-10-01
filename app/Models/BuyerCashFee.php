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

    /**
     * 적립금 전환 (jin 2026-10-01) — 남은 현금을 적립금으로 돌릴 때 그 현금을 원장에서 빼는 행.
     *
     * 🔑 적립금(`savings_statuses`)과 현금(`buyer_cash_*`)은 다른 원장이라 서로를 모른다. 판매 탭에서
     *    「적립금 적립」을 넣으면 적립금만 생기고 현금은 미배분으로 남아 **양쪽에 다 적립된 것처럼**
     *    보였다(실측 heymanerp EASY DRIVE 158 EUR, 2026-10-01). 이 행이 그 둘을 잇는다 —
     *    `BuyerCashService::transferToSavings()` 가 적립금 EARNED 와 이 행을 한 트랜잭션으로 만든다.
     *    기획 = `docs/design/buyer-cash-ledger.md` §6(확정 #10).
     * 🚫 이 행은 화면에서 지우지 않는다 — 지우면 현금은 돌아오는데 적립금은 남아 다시 이중 크레딧이 된다.
     */
    public const KIND_SAVINGS = 'savings';

    public const KINDS = [self::KIND_FEE, self::KIND_OVERPAY, self::KIND_SAVINGS];

    protected $fillable = [
        'buyer_id', 'currency', 'kind', 'charged_date', 'amount', 'note', 'created_by',
    ];

    /** 모델 훅·화면이 읽는 기본값 — DB default 는 INSERT 때만 적용된다(SKILLS §8 #80). */
    protected $attributes = ['kind' => self::KIND_FEE];

    public function isOverpayCleanup(): bool
    {
        return $this->kind === self::KIND_OVERPAY;
    }

    public function isSavingsTransfer(): bool
    {
        return $this->kind === self::KIND_SAVINGS;
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
