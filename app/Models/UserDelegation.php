<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 휴가 대리 위임 (jin 2026-08-07).
 *
 * from(휴가자) → to(대리인). 켜고 끄는 건 본인(from).
 * 넘어가는 것 = **담당 영업 스코프뿐**. 승인 계단·권한 등급은 안 넘어간다.
 *
 * ⚠️ 위임은 연쇄되지 않는다 — A→B, B→C 여도 C 가 A 의 것을 얻지 못한다.
 *   `User::activeDelegators()` 가 직속 1단만 보므로 순환(A→B→A)도 무한루프가 안 된다.
 */
class UserDelegation extends Model
{
    protected $fillable = [
        'from_user_id', 'to_user_id', 'is_active', 'ends_at', 'reason', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ends_at' => 'date',
    ];

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * 지금 실제로 효력이 있는 위임.
     *
     * 만료 판정을 **조회 시점**에 한다 — cron 이 하루 안 돌아도 권한이 새지 않는다.
     * `is_active` 는 "사람이 켜뒀다"는 뜻일 뿐이고, 날짜가 지나면 켜져 있어도 무효다.
     */
    public function scopeEffective(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($q2) => $q2->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString()));
    }

    /** 화면 표시용 — 켜져 있지만 복귀일이 지나 무효가 된 상태. */
    public function isExpired(): bool
    {
        return $this->is_active && $this->ends_at !== null && $this->ends_at->isBefore(now()->startOfDay());
    }
}
