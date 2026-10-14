<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 계좌 × 날짜 × 통화 잔액 (jin 2026-10-14). 날짜 합계는 CashAccountBalanceService 가 cash_snapshots 로 넘긴다. */
class CashAccountBalance extends Model
{
    protected $fillable = ['cash_account_id', 'snapshot_date', 'currency', 'balance', 'entered_by'];

    protected $casts = ['snapshot_date' => 'date', 'balance' => 'decimal:2'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }
}
