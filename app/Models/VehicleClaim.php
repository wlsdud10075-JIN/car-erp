<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 클레임 1건 — board 결재가 끝난 것을 받은 기록(jin 2026-11-11, claim-management.md §10). 적립금·미수·정산과 무관.
 */
class VehicleClaim extends Model
{
    public const RESULTS = ['accepted', 'declined'];

    public const TYPES = ['refund', 'credit'];

    public const CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'];

    protected $fillable = [
        'source', 'board_claim_id', 'revision', 'vehicle_id', 'buyer_id', 'buyer_name', 'vehicle_number', 'vin',
        'confirmed_amount', 'currency', 'compensation_type', 'result', 'result_reason', 'approved_at', 'approved_by_name',
        'handler_name', 'salesman_email', 'board_buyer_claim_count', 'board_url',
    ];

    protected $casts = ['approved_at' => 'datetime', 'confirmed_amount' => 'decimal:2'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }
}
