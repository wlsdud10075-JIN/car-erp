<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
    /** 통관서류 알람 — 도착일이 이만큼 지났으면 더 챙길 일이 아니다. */
    public const SETTLED_ETA_DAYS = 30;

    /**
     * 새 차 도착 — 「매입중을 벗어났다」로 지우려면 알림이 이만큼은 떠 있었어야 한다.
     * board 가 판매 정보까지 채워 보내는 차는 도착하자마자 「판매중」이라, 이 유예가 없으면 아무도 못 보고 사라진다.
     * (매입 지급 줄이 확정된 경우는 유예 없이 바로 — 누군가 그 차를 처리했다는 뜻이다.)
     */
    public const SETTLED_ARRIVAL_SEEN_DAYS = 3;

    /** 「일이 끝나면 저절로 사라지는」 알림 종류 => 해소 사유. 판정은 resolveSettled 한 곳. */
    public const SETTLED_TYPES = [
        'purchase_arrival' => 'settled_purchase',
        'shipping_requested' => 'settled_shipping',
        'bl_requested' => 'settled_bl',
        'eta_clearance' => 'settled_eta',
    ];

    /**
     * 🧹 **일이 끝난 알림을 정리한다** (jin 2026-11-20 «불필요한 알림은 좀 없어지지 않나»).
     *
     * 실측 ssancarerp: 선적요청 160 · 새 차 도착 103 · 통관서류 52 건이 안 읽힌 채 쌓여 있었다 — 차는 이미 선적·판매까지
     * 갔는데 [확인]을 눌러야만 사라져서다(매입잔금 요청처럼 「지급하면 사라지는」 알림은 안 쌓였다).
     *
     *   새 차 도착   → 매입 지급 줄이 하나라도 확정됐다 / 또는 진행 단계가 「매입중」을 벗어났고 알림이 SETTLED_ARRIVAL_SEEN_DAYS 일 넘게 떠 있었다
     *   선적요청     → 반입지 또는 선적일이 들어갔다
     *   B/L 요청     → B/L 파일이 올라갔다
     *   통관서류     → 통관 체크가 됐거나 도착일이 SETTLED_ETA_DAYS 일 넘게 지났다
     *   (공통)       → 차량이 삭제됐다
     *
     * 부르는 곳: 차량·매입 지급 저장 직후(그 차만) + 매일 06:00 `alarms:scan`(전체 — raw update·시간 경과분 보정, 게이트와 무관).
     * 🚫 선적 변경요청(shipping_change_requested)·board 입금요청·판매가 수정 알림은 여기서 안 건드린다(각자 처리 경로가 있다).
     *
     * @return array<string, int> 종류(+ 'vehicle_deleted') => 정리한(또는 dry-run 이면 정리될) 건수
     */
    public static function resolveSettled(?int $vehicleId = null, bool $dryRun = false): array
    {
        $etaCutoff = now()->subDays(self::SETTLED_ETA_DAYS)->toDateString();
        $vehicles = fn () => DB::table('vehicles')->select('id')->whereNull('deleted_at');
        $conditions = [
            'purchase_arrival' => fn (Builder $q) => $q->where(fn ($w) => $w
                ->whereIn('vehicle_id', DB::table('purchase_balance_payments')->select('vehicle_id')->whereNotNull('confirmed_at'))
                ->orWhere(fn ($x) => $x->where('created_at', '<=', now()->subDays(self::SETTLED_ARRIVAL_SEEN_DAYS))
                    ->whereIn('vehicle_id', $vehicles()->whereNotNull('progress_status_cache')->where('progress_status_cache', '!=', '매입중')))),
            'shipping_requested' => fn (Builder $q) => $q->whereIn('vehicle_id', $vehicles()->where(fn ($w) => $w
                ->where(fn ($x) => $x->whereNotNull('bl_loading_location')->where('bl_loading_location', '!=', ''))
                ->orWhereNotNull('shipping_date'))),
            'bl_requested' => fn (Builder $q) => $q->whereIn('vehicle_id', $vehicles()->whereNotNull('bl_document')->where('bl_document', '!=', '')),
            'eta_clearance' => fn (Builder $q) => $q->whereIn('vehicle_id', $vehicles()->where(fn ($w) => $w
                ->where('is_export_cleared', true)->orWhere('eta_date', '<', $etaCutoff))),
        ];

        $out = [];
        $base = fn (string $type) => self::query()->where('type', $type)->whereNull('resolved_at')
            ->when($vehicleId !== null, fn ($q) => $q->where('vehicle_id', $vehicleId));
        foreach ($conditions as $type => $cond) {
            $q = $cond($base($type));
            $out[$type] = $dryRun ? $q->count() : $q->update(['resolved_at' => now(), 'resolved_reason' => self::SETTLED_TYPES[$type]]);
        }
        $gone = self::query()->whereIn('type', array_keys(self::SETTLED_TYPES))->whereNull('resolved_at')
            ->when($vehicleId !== null, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->whereNotIn('vehicle_id', $vehicles());
        $out['vehicle_deleted'] = $dryRun ? $gone->count() : $gone->update(['resolved_at' => now(), 'resolved_reason' => 'vehicle_deleted']);

        return $out;
    }

    /** 그 차에 정리 대상 종류의 열린 알림이 있을 때만 판정한다 — 저장 훅이 매번 부르므로 없으면 쿼리 1개로 끝낸다. */
    public static function resolveSettledFor(int $vehicleId): void
    {
        if (self::query()->where('vehicle_id', $vehicleId)->whereIn('type', array_keys(self::SETTLED_TYPES))->whereNull('resolved_at')->exists()) {
            self::resolveSettled($vehicleId);
        }
    }

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
