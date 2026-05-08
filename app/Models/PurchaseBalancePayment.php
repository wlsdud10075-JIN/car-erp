<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBalancePayment extends Model
{
    protected $fillable = ['vehicle_id', 'amount', 'payment_date', 'note'];

    protected $casts = ['payment_date' => 'date'];

    protected static function booted(): void
    {
        static::saved(fn (PurchaseBalancePayment $p) => $p->vehicle?->refreshProgressCache());
        static::deleted(fn (PurchaseBalancePayment $p) => $p->vehicle?->refreshProgressCache());
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
