<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalPayment extends Model
{
    protected $fillable = ['vehicle_id', 'transfer_id', 'amount', 'payment_date', 'note'];

    protected $casts = ['payment_date' => 'date'];

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

    protected static function booted(): void
    {
        static::saved(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());
        static::deleted(fn (FinalPayment $p) => $p->vehicle?->refreshCaches());

        // 큐 19-C — transfer로 만들어진 잔금은 직접 수정·삭제 불가.
        static::updating(function (FinalPayment $p) {
            if ($p->getOriginal('transfer_id') !== null && ! self::$allowTransferLinkedMutation) {
                throw new \DomainException('차량 간 자금 이체로 생성된 잔금은 수정할 수 없습니다. 이체 취소(void)는 별도 승인 흐름을 사용하세요.');
            }
        });
        static::deleting(function (FinalPayment $p) {
            if ($p->transfer_id !== null && ! self::$allowTransferLinkedMutation) {
                throw new \DomainException('차량 간 자금 이체로 생성된 잔금은 삭제할 수 없습니다. 이체 취소(void)는 별도 승인 흐름을 사용하세요.');
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
}
