<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 자금 현황 일별 스냅샷 (jin 2026-07-23).
 *   통장 마감잔액(수동) + 그 시점 ERP 캡처(재고·미수·미지급). 파생값(청산가치·손익)은 CapitalStatusService.
 */
class CashSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date', 'balance_krw', 'balance_usd', 'balance_eur',
        'inventory_krw', 'receivable_krw', 'payable_krw', 'fx_usd', 'fx_eur', 'entered_by',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'balance_usd' => 'decimal:2',
        'balance_eur' => 'decimal:2',
        'fx_usd' => 'decimal:2',
        'fx_eur' => 'decimal:2',
    ];

    public function enterer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** 통장 현금 원화환산 합 (KRW + USD·EUR×환율). */
    public function getCashKrwAttribute(): int
    {
        return (int) round(
            $this->balance_krw
            + (float) $this->balance_usd * (float) $this->fx_usd
            + (float) $this->balance_eur * (float) $this->fx_eur
        );
    }
}
