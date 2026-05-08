<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalPayment extends Model
{
    protected $fillable = ['vehicle_id', 'amount', 'payment_date', 'note'];

    protected $casts = ['payment_date' => 'date'];

    protected static function booted(): void
    {
        static::saved(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());
        static::deleted(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
