<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Salesman extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'phone', 'email', 'memo', 'is_active',
        // 2026-05-20 #2-2+2-4 — type 분기 (employee 건당 / freelance 비율)
        'type',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public const TYPES = [
        'employee' => '사내직원',
        'freelance' => '프리랜서',
    ];

    /** 정산 type 자동 매핑: employee → per_unit, freelance → ratio. */
    public function defaultSettlementType(): string
    {
        return $this->type === 'freelance' ? 'ratio' : 'per_unit';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? '사내직원';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
