<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalPayment extends Model
{
    protected $fillable = [
        'vehicle_id', 'transfer_id', 'amount', 'payment_date', 'note',
        'confirmed_by_user_id', 'confirmed_at', 'finance_note',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'confirmed_at' => 'datetime',
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
        static::saved(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());
        static::deleted(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());

        // 큐 19-C — transfer로 만들어진 잔금은 직접 수정·삭제 불가.
        static::updating(function (FinalPayment $p) {
            if ($p->getOriginal('transfer_id') !== null && ! self::$allowTransferLinkedMutation) {
                throw new \DomainException('차량 간 자금 이체로 생성된 잔금은 수정할 수 없습니다. 이체 취소(void)는 별도 승인 흐름을 사용하세요.');
            }

            // 큐 20-D — confirmed_at SET 후 retroactive UPDATE 차단 (회계 무결성).
            // confirmed_at → confirmed_at 이외 변경은 허용 (예: PaymentConfirmationService에서 SET 자체).
            $originalConfirmedAt = $p->getOriginal('confirmed_at');
            if ($originalConfirmedAt !== null && ! self::$allowConfirmedMutation) {
                // confirmed_at 자체를 변경하는 경우만 차단 (re-confirm 또는 unlock 시도)
                if ($p->isDirty('confirmed_at') || $p->isDirty('amount') || $p->isDirty('payment_date') || $p->isDirty('transfer_id')) {
                    throw new \DomainException('재무 확정된 잔금의 amount / payment_date / confirmed_at / transfer_id 는 수정할 수 없습니다 (회계 무결성).');
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
