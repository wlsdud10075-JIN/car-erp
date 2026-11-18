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
    public const ALLOWED_META = ['vehicle_number', 'eta_date', 'unpaid_amount_krw', 'shipping_method', 'bl_type', 'currency', 'changes'];

    /**
     * 💱 판매가·환율 수정 알림 (jin 2026-11-18) — 이미 판매가가 있던 차의 판매가·TAX D/C·커미션·판매환율이 **실제로** 바뀌면 뜬다.
     * 처음 기입(판매가 0 → 값)은 안 뜬다. 받는 사람 = 영업 외 전체(User::canSeePriceAlarms). [확인] = 해소(누구 하나가 보면 사라짐).
     * 같은 차의 안 본 알림이 있으면 새로 만들지 않고 `changes` 에 덧붙인다(하루에 여러 번 고쳐도 한 줄).
     */
    public const TYPE_PRICE_CHANGED = 'sale_price_changed';

    public const ROLE_PRICE_WATCH = '관리직';

    /** 지켜보는 칸 => 「실제로 바뀌었다」로 칠 최소 차이(반올림·표기 차이 제외). */
    public const PRICE_WATCH = ['sale_price' => 1, 'tax_dc' => 1, 'commission' => 1, 'exchange_rate' => 0.01];

    /** 한 알림에 쌓는 변경 줄 상한 — 넘으면 오래된 것부터 버린다. */
    private const MAX_CHANGES = 20;

    /** @param  list<array{field:string, old:float, new:float, at:string, by:?int}>  $changes */
    public static function recordPriceChange(Vehicle $vehicle, array $changes): void
    {
        $open = self::query()->where('type', self::TYPE_PRICE_CHANGED)->where('vehicle_id', $vehicle->id)
            ->whereNull('resolved_at')->whereNull('confirmed_at')->latest('id')->first();
        if ($open) {
            $meta = $open->message_meta ?? [];
            $meta['changes'] = array_slice(array_merge($meta['changes'] ?? [], $changes), -self::MAX_CHANGES);
            $meta['currency'] = $vehicle->currency;
            $open->update(['message_meta' => self::sanitizeMeta($meta), 'due_date' => now()->toDateString()]);

            return;
        }
        self::create([
            'type' => self::TYPE_PRICE_CHANGED,
            'vehicle_id' => $vehicle->id,
            'target_role' => self::ROLE_PRICE_WATCH,
            'due_date' => now()->toDateString(),
            'message_meta' => self::sanitizeMeta([
                'vehicle_number' => $vehicle->vehicle_number, 'currency' => $vehicle->currency, 'changes' => $changes,
            ]),
        ]);
    }

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

    /**
     * 사용자가 볼 수 있는 알람만 (목록·벨 카운트 SQL 필터 — canSeeAlarm 의 SQL 짝).
     * type 별로 OR 결합 (한쪽만 고치면 IDOR/누락 — canSeeAlarm 과 lockstep 유지):
     * - 수출통관 알람(eta_clearance·shipping_requested, target_role='수출통관'):
     *   admin·수출통관 전체 / 관리 본인 팀 / 그 외 0건.
     * - 매입 도착 알람(purchase_arrival, target_role='관리'):
     *   admin 전체 / 관리 본인 팀 / 그 외 0건.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->where(function (Builder $outer) use ($user) {
            // 수출통관 알람
            if ($user->canAccessClearance()) {
                $outer->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('target_role', '수출통관');
                    if (! ($user->isAdmin() || $user->isManager() || $user->role === '수출통관')) {
                        $q2->whereHas('vehicle', fn ($v) => $v->whereIn('salesman_id', $user->getSubordinateSalesmanIds()));
                    }
                });
            }
            // 매입 도착 알람 (관리/업무관리자/admin)
            if ($user->isAdmin() || $user->isManager() || $user->role === '관리') {
                $outer->orWhere(function (Builder $q3) use ($user) {
                    $q3->where('target_role', '관리');
                    if (! ($user->isAdmin() || $user->isManager())) {
                        $q3->whereHas('vehicle', fn ($v) => $v->whereIn('salesman_id', $user->getSubordinateSalesmanIds()));
                    }
                });
            }
            // 💱 판매가·환율 수정 알림 — 영업 외 전체(팀 범위 없음, jin 2026-11-18)
            if ($user->canSeePriceAlarms()) {
                $outer->orWhere('target_role', self::ROLE_PRICE_WATCH);
            }
            // 아무 type 도 볼 수 없으면 빈 결과.
            $outer->orWhereRaw('1 = 0');
        });
    }

    /** message_meta 를 허용 키로만 제한 (저장 직전 strip). */
    public static function sanitizeMeta(array $meta): array
    {
        return array_intersect_key($meta, array_flip(self::ALLOWED_META));
    }
}
