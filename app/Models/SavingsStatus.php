<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsStatus extends Model
{
    protected $fillable = [
        'buyer_id', 'vehicle_id', 'currency', 'transaction_type',
        'savings', 'balance', 'original_transaction_id', 'note',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsStatus::class, 'original_transaction_id');
    }
}
