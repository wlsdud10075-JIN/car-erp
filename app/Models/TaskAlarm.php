<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 업무 알람 — ETA 영구 알람(v1) 등 "조건 충족 → 영구 알람 → 확인/자동해소" 레코드.
 *
 * 회의(2026-06-18): 단일출처(Vehicle::scopeAction 재사용)·message_meta whitelist·
 * 자동해소 Hybrid(Vehicle::saved 즉시 + alarms:scan 보정).
 */
class TaskAlarm extends Model
{
    /** message_meta 에 저장 허용된 키 (개인정보 §29 — RRN·성명·계좌·연락처 금지). */
    public const ALLOWED_META = ['vehicle_number', 'eta_date', 'unpaid_amount_krw'];

    protected $fillable = [
        'type', 'vehicle_id', 'target_role', 'due_date', 'message_meta',
        'confirmed_at', 'confirmed_by', 'resolved_at', 'resolved_reason',
    ];

    protected $casts = [
        'due_date' => 'date',
        'message_meta' => 'array',
        'confirmed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** 아직 끝나지 않은 알람 (해소 전). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    /** 안 읽음 = 미해소 + 미확인 (벨 카운트 / 상주 카드). */
    public function scopeUnread(Builder $q): Builder
    {
        return $q->whereNull('resolved_at')->whereNull('confirmed_at');
    }

    public function scopeForRole(Builder $q, ?string $role): Builder
    {
        return $q->where('target_role', $role);
    }

    /** message_meta 를 허용 키로만 제한 (저장 직전 strip). */
    public static function sanitizeMeta(array $meta): array
    {
        return array_intersect_key($meta, array_flip(self::ALLOWED_META));
    }
}
