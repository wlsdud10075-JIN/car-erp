<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consignee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'buyer_id', 'country_id', 'contact_name', 'contact_email',
        'contact_phone', 'address', 'memo', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
