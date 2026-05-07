<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settlement extends Model
{
    protected $fillable = [
        'vehicle_id', 'salesman_id', 'settlement_type', 'settlement_ratio',
        'per_unit_amount', 'other_deduction', 'settlement_status',
        'confirmed_at', 'paid_at', 'note',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }
}
