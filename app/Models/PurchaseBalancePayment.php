<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
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

    /**
     * 정산 락 개편 (jin 2026-07-24) — 마감(closed) 후 토큰 정정 시 감사 추적 대상 (FinalPayment 대칭).
     * confirmed 후 잠금해제 토큰으로 정정 시 updated 훅이 old→new 를 AuditLog 기록.
     */
    public const AUDITED_LEDGER_COLUMNS = ['amount', 'payment_date'];

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

        // 큐 20-D + 정산 락 개편(2026-07-24) — confirmed_at SET 후 소급 UPDATE 가드.
        //   구조적(확정 해제)은 절대 차단. 금액·날짜 정정은 2차 정산 마감(closed) 전 자유(정산이 흡수),
        //   마감 후엔 잠금해제 토큰(unlockForPurchasePayment) 1회 소비 시에만 통과. old→new 는 updated 훅 감사.
        static::updating(function (PurchaseBalancePayment $p) {
            $originalConfirmedAt = $p->getOriginal('confirmed_at');
            if ($originalConfirmedAt !== null && ! self::$allowConfirmedMutation) {
                if ($p->isDirty('confirmed_at')) {
                    throw new \DomainException('재무 확정된 매입 잔금의 confirmed_at 은 수정할 수 없습니다 (회계 무결성).');
                }
                if ($p->isDirty('amount') || $p->isDirty('payment_date')) {
                    if ($p->vehicle?->hasClosedSecondarySettlement()
                        && $p->consumeLedgerUnlockToken() === null) {
                        throw new \DomainException('2차 정산 마감된 차량의 확정 매입 잔금 amount / payment_date 는 잠금 해제(관리 승인) 후에만 수정할 수 있습니다 (회계 무결성).');
                    }
                }
            }
        });
        static::deleting(function (PurchaseBalancePayment $p) {
            // 정산 락 개편(2026-07-24) — 2차 정산 마감(closed) 후에만 확정 매입 잔금 삭제 차단.
            if ($p->confirmed_at !== null && ! self::$allowConfirmedMutation
                && $p->vehicle?->hasClosedSecondarySettlement()) {
                throw new \DomainException('2차 정산 마감된 차량의 재무 확정 매입 잔금은 삭제할 수 없습니다 (회계 무결성).');
            }
        });

        // 정산 락 개편(2026-07-24) — 마감 후 토큰 정정(금액·날짜) old→new AuditLog (FinalPayment 대칭).
        static::updated(function (PurchaseBalancePayment $p) {
            if ($p->getOriginal('confirmed_at') === null) {
                return;
            }
            foreach (self::AUDITED_LEDGER_COLUMNS as $col) {
                if ($p->wasChanged($col)) {
                    AuditLog::recordChange($p, $col, $p->getOriginal($col), $p->getAttribute($col));
                }
            }
        });
    }

    /** 정산 락 개편(2026-07-24) — 매입 잔금 개별 정정 잠금해제 cache key (FinalPayment 대칭). */
    public static function ledgerUnlockCacheKey(int $pbpId): string
    {
        return "purchase_balance_payment_ledger_unlock:{$pbpId}";
    }

    /** 잠금해제 토큰 1회 소비 (읽기 + 즉시 삭제). unlockForPurchasePayment 발급분을 updating 이 소비. */
    public function consumeLedgerUnlockToken(): ?array
    {
        if (! $this->id) {
            return null;
        }

        return Cache::pull(self::ledgerUnlockCacheKey($this->id));
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
