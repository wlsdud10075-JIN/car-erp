<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 경매보증금 — 경매장에 예치한 돈 (jin 2026-07-27, 안건4 1단계).
 * 돌려받으면 행을 삭제한다 → **목록 합계 = 지금 묶여 있는 돈**. softDelete 라 이력은 DB 에 남는다.
 */
class AuctionDeposit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'deposited_date', 'auction_house', 'amount', 'note', 'created_by',
    ];

    protected $casts = [
        'deposited_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 현재 예치중 합계(원). 2단계에서 자금현황이 이 값을 쓴다. */
    public static function totalKrw(): int
    {
        return (int) static::sum('amount');
    }
}
