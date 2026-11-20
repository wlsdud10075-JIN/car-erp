<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 클레임 사용 내역 — 어느 클레임에서 얼마를 어느 차 미수에 썼나 (jin 2026-11-20). 쓰기는 App\Services\ClaimUsage 한 곳.
 */
class VehicleClaimUsage extends Model
{
    protected $fillable = ['vehicle_claim_id', 'vehicle_id', 'receivable_history_id', 'amount', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(VehicleClaim::class, 'vehicle_claim_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
