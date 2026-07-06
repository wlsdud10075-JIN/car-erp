<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class FinalPayment extends Model
{
    protected $fillable = [
        'vehicle_id', 'transfer_id', 'type', 'amount', 'payment_date', 'note',
        // 회의확장씬 #7 (2026-05-22) — 잔금 row 별 입금 시점 환율 저장.
        'exchange_rate',
        // 회의확장씬 #6 보강 (2026-05-23) — KRW 환산 snapshot (amount × exchange_rate). saving 훅 자동 계산.
        'amount_krw',
        'confirmed_by_user_id', 'confirmed_at', 'finance_note',
    ];

    /**
     * 정산 재설계 선행결함2 (2026-07-06, c-2) — 재무확정 잔금의 사후 정정을 감사 추적하는 컬럼.
     * 확정(confirmed_at≠null) 후 잠금해제 토큰으로 정정 시 updated 훅이 old→new 를 AuditLog 기록.
     */
    public const AUDITED_LEDGER_COLUMNS = ['amount', 'exchange_rate', 'payment_date', 'amount_krw'];

    protected $casts = [
        'payment_date' => 'date',
        'confirmed_at' => 'datetime',
        'exchange_rate' => 'decimal:4',
        'amount_krw' => 'decimal:2',
    ];

    /**
     * 큐 10 H5 — ReceivableHistory.syncFinalPayment 안에서 FinalPayment를 생성할 때
     * 역방향(FinalPayment::created → ReceivableHistory::create) 발동을 차단하는 short-lived flag.
     * ReceivableHistory에서 직접 set한 뒤 try/finally로 해제.
     */
    public static bool $skipReceivableSync = false;

    /**
     * 큐 19-C 보강 — 자금 이체로 생성된 final_payment(transfer_id≠null)는 append-only.
     * Service의 void 흐름(19-E)에서만 보호 우회 (반대 부호 거래 추가 = 변경 아닌 신규).
     */
    public static bool $allowTransferLinkedMutation = false;

    /**
     * 큐 20-D — confirmed_at SET 후 UPDATE/DELETE 차단 lock 우회 플래그.
     * 정상 흐름에서는 retroactive 변경 차단(회계 무결성). InterVehicleTransferService 의
     * void 페어 생성(append-only)은 위 $allowTransferLinkedMutation 플래그로 분기되며 별개.
     */
    public static bool $allowConfirmedMutation = false;

    protected static function booted(): void
    {
        // 회의확장씬 #6 보강 (2026-05-23) — amount_krw 자동 계산 (amount × exchange_rate snapshot).
        // amount 또는 exchange_rate 가 dirty 일 때만 재계산 → confirmed lock 과 자연 정합.
        // exchange_rate IS NULL → amount_krw NULL 유지 (미설정 보존).
        static::saving(function (FinalPayment $p) {
            if (! $p->exists || $p->isDirty('amount') || $p->isDirty('exchange_rate')) {
                $rate = $p->exchange_rate !== null ? (float) $p->exchange_rate : null;
                $amount = (float) ($p->amount ?? 0);
                $p->amount_krw = ($rate !== null && $amount > 0) ? round($amount * $rate, 2) : null;
            }
        });

        static::saved(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());
        static::deleted(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());

        // 큐 22-A-2 — paid Settlement 후 신규 FP 차단 (PBP creating 훅과 대칭).
        // 시드·artisan(auth 없음) 우회 — assertPaidSettlementGuard Service 별도 보장.
        static::creating(function (FinalPayment $p) {
            if (! auth()->check()) {
                return;
            }
            $vehicle = $p->vehicle;
            if ($vehicle && $vehicle->settlements()->where('settlement_status', 'paid')->exists()) {
                throw new \DomainException('정산이 paid 상태인 차량에 신규 판매 잔금을 추가할 수 없습니다 (회계 무결성).');
            }
        });

        // 큐 19-C — transfer로 만들어진 잔금은 직접 수정·삭제 불가.
        static::updating(function (FinalPayment $p) {
            if ($p->getOriginal('transfer_id') !== null && ! self::$allowTransferLinkedMutation) {
                throw new \DomainException('차량 간 자금 이체로 생성된 잔금은 수정할 수 없습니다. 이체 취소(void)는 별도 승인 흐름을 사용하세요.');
            }

            // 큐 20-D — confirmed_at SET 후 retroactive UPDATE 차단 (회계 무결성).
            // 2026-07-06 (정산 재설계) — 선행결함1: exchange_rate/amount_krw 잠금 추가.
            //   선행결함2(c-2): 금액·날짜·환율 정정은 관리 승인 잠금해제 토큰 1회 소비 시 허용
            //   (확정 해제·이체 링크는 토큰으로도 불가 — 절대 보호). $allowConfirmedMutation 는 시스템 우회.
            $originalConfirmedAt = $p->getOriginal('confirmed_at');
            if ($originalConfirmedAt !== null && ! self::$allowConfirmedMutation) {
                // 구조적 변경(확정 해제·이체 링크)은 c-2 잠금해제로도 불가.
                if ($p->isDirty('confirmed_at') || $p->isDirty('transfer_id')) {
                    throw new \DomainException('재무 확정된 잔금의 confirmed_at / transfer_id 는 수정할 수 없습니다 (회계 무결성).');
                }
                // 금액·날짜·환율 정정 = 잠금해제 토큰(c-2) 1회 소비 시에만 통과.
                //   토큰 발급 = VehicleLedgerUnlockService::unlockForFinalPayment(관리 승인 + 사유 10자 + AuditLog).
                //   실제 old→new 변경 기록은 updated 훅.
                if ($p->isDirty('amount') || $p->isDirty('payment_date')
                    || $p->isDirty('exchange_rate') || $p->isDirty('amount_krw')) {
                    if ($p->consumeLedgerUnlockToken() === null) {
                        throw new \DomainException('재무 확정된 잔금의 amount / payment_date / exchange_rate 는 수정할 수 없습니다 (회계 무결성). 수정하려면 관리 승인 잠금해제가 필요합니다.');
                    }
                }
            }
        });
        static::deleting(function (FinalPayment $p) {
            if ($p->transfer_id !== null && ! self::$allowTransferLinkedMutation) {
                throw new \DomainException('차량 간 자금 이체로 생성된 잔금은 삭제할 수 없습니다. 이체 취소(void)는 별도 승인 흐름을 사용하세요.');
            }
            // 큐 20-D — confirmed_at SET 후 DELETE 차단.
            if ($p->confirmed_at !== null && ! self::$allowConfirmedMutation) {
                throw new \DomainException('재무 확정된 잔금은 삭제할 수 없습니다 (회계 무결성).');
            }
        });

        // 선행결함2 (2026-07-06, c-2) — 재무확정 잔금의 사후 정정(금액·환율·날짜) old→new AuditLog.
        //   확정 후 변경은 잠금해제 토큰 경유로만 도달 → "잠금해제 사유"(서비스 기록) + "실제 변경값"(여기) 2단 추적.
        //   미확정 잔금의 일상 수정은 감사 대상 아님(getOriginal('confirmed_at') null → skip).
        static::updated(function (FinalPayment $p) {
            if ($p->getOriginal('confirmed_at') === null) {
                return;
            }
            foreach (self::AUDITED_LEDGER_COLUMNS as $col) {
                if ($p->wasChanged($col)) {
                    AuditLog::recordChange($p, $col, $p->getOriginal($col), $p->getAttribute($col));
                }
            }
        });

        // 큐 10 H5 — FinalPayment 신규 생성 시 ReceivableHistory(method=deposit) 자동 미러링.
        // ReceivableHistory에서 시작된 생성은 skipReceivableSync로 차단 (중복 방지).
        static::created(function (FinalPayment $p) {
            if (self::$skipReceivableSync) {
                return;
            }
            ReceivableHistory::create([
                'vehicle_id' => $p->vehicle_id,
                'final_payment_id' => $p->id,
                'collected_at' => $p->payment_date ?? now()->toDateString(),
                'collector_id' => null,
                'method' => 'deposit',
                'amount' => $p->amount,
                'note' => '판매 잔금 자동 미러링',
            ]);
        });
    }

    /**
     * 선행결함2 (2026-07-06, c-2) — 잔금 row 단위 잠금해제 cache key 단일 출처.
     * Vehicle::ledgerUnlockCacheKey(차량 단위)와 별개 네임스페이스 — 잔금 개별 정정용.
     */
    public static function ledgerUnlockCacheKey(int $finalPaymentId): string
    {
        return "final_payment_ledger_unlock:{$finalPaymentId}";
    }

    /**
     * 잠금해제 토큰 1회 소비 (읽기 + 즉시 삭제) — 정정 저장 1회 후 자동 재잠금.
     * VehicleLedgerUnlockService::unlockForFinalPayment 로 발급된 토큰을 updating 훅이 소비.
     */
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

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InterVehicleTransfer::class, 'transfer_id');
    }

    public function financeConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
