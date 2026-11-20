<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function usages(): HasMany
    {
        return $this->hasMany(VehicleClaimUsage::class);
    }

    /**
     * 아직 쓸 돈이 남은 **인정** 클레임 (jin 2026-11-20) — 바이어 목록 「클레임 N건」 뱃지·필터칩·건수가 전부 이 스코프를 쓴다.
     * 남은 돈 = 확정 금액 − 차량 미수에 쓴 합(App\Services\ClaimUsage). 다 쓰면 빠진다.
     */
    public function scopeOpen($query)
    {
        return $query->where('vehicle_claims.result', 'accepted')->whereRaw(
            'vehicle_claims.confirmed_amount > COALESCE((SELECT SUM(u.amount) FROM vehicle_claim_usages u WHERE u.vehicle_claim_id = vehicle_claims.id), 0)'
        );
    }
}
