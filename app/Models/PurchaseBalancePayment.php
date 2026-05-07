<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBalancePayment extends Model
{
    protected $fillable = ['vehicle_id', 'amount', 'payment_date', 'note'];

    protected $casts = ['payment_date' => 'date'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
