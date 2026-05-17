<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBalancePayment extends Model
{
    protected $fillable = [
        'vehicle_id', 'amount', 'payment_date', 'note',
        'confirmed_by_user_id', 'confirmed_at', 'finance_note',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn (PurchaseBalancePayment $p) => $p->vehicle?->refreshCaches());
        static::deleted(fn (PurchaseBalancePayment $p) => $p->vehicle?->refreshCaches());
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function financeConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
