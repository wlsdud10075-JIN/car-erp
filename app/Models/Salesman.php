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

    public function carryoverClearances(): HasMany
    {
        return $this->hasMany(CarryoverClearance::class);
    }

    /**
     * 미청산 이월 잔액 (KRW) — Σ closed 정산의 carryover_out − Σ carryover_in − Σ 청산액.
     * Settlement::creating 흡수 훅(SKILLS §5-5)과 동일 공식 = 단일 출처.
     * 활성 담당자는 다음 정산이 흡수해 보통 0, 마지막/퇴사 건이면 stranded 잔액으로 남음.
     * 퇴사자 청산(CarryoverClearance) 시 Σ청산액 차감으로 0 → 흡수 훅도 같이 차감해 재흡수(이중계상) 방지.
     * 양수 = 담당자에게 지급 대기 / 음수 = 담당자에게 청구 대상.
     */
    public function getUnconsumedCarryoverAttribute(): int
    {
        $out = (float) $this->settlements()
            ->where('secondary_status', 'closed')
            ->whereNotNull('carryover_out_krw')
            ->sum('carryover_out_krw');
        $in = (float) $this->settlements()
            ->whereNotNull('carryover_in_krw')
            ->sum('carryover_in_krw');
        $cleared = (float) $this->carryoverClearances()->sum('amount_krw');

        return (int) round($out - $in - $cleared);
    }
}
