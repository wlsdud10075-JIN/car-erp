<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class PurchaseBalancePayment extends Model
{
    protected $fillable = [
        'vehicle_id', 'amount', 'payment_date', 'note',
        'confirmed_by_user_id', 'confirmed_at', 'finance_note',
        // 큐 22-C-light — 자동 생성 PBP의 actor 추적 (Spec-E 해소조건)
        'created_by_user_id',
        // 큐 22-C-D (2026-05-20) — type enum: down / selling_fee / balance.
        'type',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    /**
     * 큐 20-D — confirmed_at SET 후 UPDATE/DELETE 차단 lock 우회 플래그.
     */
    public static bool $allowConfirmedMutation = false;

    /**
     * 큐 22-C 핵심 (2026-05-20) — Vehicle::saved 자동 PBP Draft 생성 시 canConfirmFinance 가드 우회 flag.
     * 영업 사용자가 매입가 입력하면 시스템이 자동 PBP Draft 생성 (의도된 흐름). 그때만 가드 skip.
     */
    public static bool $skipCreatingGuard = false;

    /**
     * Vehicle::saved 가 만드는 자동 매입 잔금 Draft 의 note 마커.
     * 생성부(Vehicle::saved)와 재조정부(vehicles/index save) 가 같은 문자열을 참조하도록 단일 출처화.
     */
    public const AUTO_DRAFT_NOTE = '자동 생성 — 영업 매입 정보 저장 시';

    protected static function booted(): void
    {
        static::saved(function (PurchaseBalancePayment $p) {
            $p->vehicle?->refreshCaches();

            // 계약금(down) 입력 → 매입 도착 알람 자동 해소 (jin 2026-06-23, purchase_arrival).
            if ($p->type === 'down' && (int) $p->amount > 0 && Schema::hasTable('task_alarms')) {
                TaskAlarm::where('type', 'purchase_arrival')
                    ->where('vehicle_id', $p->vehicle_id)
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => now(), 'resolved_reason' => 'down_payment']);
            }

            // 잔금 완납 → 매매상 잔금 10일 알림 즉시 해소 (jin 2026-07-12, scan 보정 전 반응성).
            //   open 알람 있을 때만 fresh 미지급 계산 (non-karaba·무알람은 exists() 에서 short-circuit).
            if (Schema::hasTable('task_alarms')
                && TaskAlarm::where('type', 'purchase_balance_due')->where('vehicle_id', $p->vehicle_id)->whereNull('resolved_at')->exists()
                && (int) ($p->vehicle?->fresh()?->purchase_unpaid_amount ?? 1) <= 0) {
                TaskAlarm::where('type', 'purchase_balance_due')
                    ->where('vehicle_id', $p->vehicle_id)
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => now(), 'resolved_reason' => 'balance_paid']);
            }
        });
        static::deleted(fn (PurchaseBalancePayment $p) => $p->vehicle?->refreshCaches());

        // 큐 22-C 핵심 (2026-05-20) — Defense-in-depth: canConfirmFinance() 가드.
        // 영업이 transfers·Livewire 우회로 직접 PBP::create 시도 시 모델 레이어 차단.
        // Vehicle::saved 자동 PBP Draft 생성 흐름은 $skipCreatingGuard flag 로 우회.
        //
        // ⚠️ paid 정산 차량 creating 차단은 제거됨 (jin 2026-07-24, 54가6191 케이스).
        //   이 회사는 '정산 후 매입 잔금 지급'이 정상 업무 흐름이라 기존 paid creating 차단이 정당한
        //   업무(매입 완납 기록)를 영영 막던 과잉가드였음. PBP는 정산 마진(purchase_price 기반)·
        //   confirmed_snapshot 에 영향 없이 purchase_unpaid_amount(현금흐름)만 갱신 → 회계 무결성 안 깨짐.
        //   진짜 소급 변경 방어는 updating/deleting(확정 후 amount·삭제 차단)으로 유지, paid 후 지급은
        //   confirmPurchasePayment 에서 AuditLog(purchase_payment_after_paid)로 추적한다.
        static::creating(function (PurchaseBalancePayment $p) {
            if (! auth()->check()) {
                return;
            }

            // 큐 22-C 핵심 — canConfirmFinance 가드 (자동 생성 우회 flag)
            if (! self::$skipCreatingGuard && ! auth()->user()->canConfirmFinance()) {
                throw new \DomainException('매입 잔금 row 생성 권한이 없습니다. 재무 권한자만 직접 추가할 수 있습니다 (시스템 자동 생성 흐름은 제외).');
            }
        });

        // 큐 20-D — confirmed_at SET 후 retroactive UPDATE 차단 (회계 무결성).
        static::updating(function (PurchaseBalancePayment $p) {
            $originalConfirmedAt = $p->getOriginal('confirmed_at');
            if ($originalConfirmedAt !== null && ! self::$allowConfirmedMutation) {
                if ($p->isDirty('confirmed_at') || $p->isDirty('amount') || $p->isDirty('payment_date')) {
                    throw new \DomainException('재무 확정된 매입 잔금의 amount / payment_date / confirmed_at 은 수정할 수 없습니다 (회계 무결성).');
                }
            }
        });
        static::deleting(function (PurchaseBalancePayment $p) {
            if ($p->confirmed_at !== null && ! self::$allowConfirmedMutation) {
                throw new \DomainException('재무 확정된 매입 잔금은 삭제할 수 없습니다 (회계 무결성).');
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function financeConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
