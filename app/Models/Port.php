<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Port extends Model
{
    protected $fillable = ['type', 'name', 'code', 'is_active', 'allow_shipping_wait'];

    protected $casts = [
        'is_active' => 'boolean',
        // 선적대기 허용 항로 (jin 2026-07-18) — discharge 타입에만 의미. RORO 차량 C5 우회.
        'allow_shipping_wait' => 'boolean',
    ];

    public const TYPES = [
        'loading' => 'Port of Loading (출발항)',
        'unloading' => '반입지 (한국 부두)',
        'discharge' => 'Discharge Port (목적항)',
    ];

    /** scope: 활성 + 특정 type. 드롭다운에서 사용. */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type)->where('is_active', true)->orderBy('name');
    }

    /** 표시용 — code 있으면 "name (code)" 형식. */
    public function getDisplayNameAttribute(): string
    {
        return $this->code ? "{$this->name} ({$this->code})" : $this->name;
    }
}
