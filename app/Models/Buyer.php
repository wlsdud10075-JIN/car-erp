<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Buyer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'country_id', 'salesman_id',
        'contact_name', 'contact_email',
        'contact_phone', 'address', 'memo', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    // 회의확장씬 #5-1 (2026-05-22) — 바이어 영업담당자 직접 지정.
    // [관리] 솔팅에서 buyers.salesman_id IN subordinates_salesman_ids 로 직접 사용.
    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function consignees(): HasMany
    {
        return $this->hasMany(Consignee::class);
    }

    public function savingsStatuses(): HasMany
    {
        return $this->hasMany(SavingsStatus::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
