<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBalancePayment extends Model
{
    protected $fillable = [
        'vehicle_id', 'amount', 'payment_date', 'note',
        'confirmed_by_user_id', 'confirmed_at', 'finance_note',
        // 큐 22-C-light — 자동 생성 PBP의 actor 추적 (Spec-E 해소조건)
        'created_by_user_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    /**
     * 큐 20-D — confirmed_at SET 후 UPDATE/DELETE 차단 lock 우회 플래그.
     */
    public static bool $allowConfirmedMutation = false;

    protected static function booted(): void
    {
        static::saved(fn (PurchaseBalancePayment $p) => $p->vehicle?->refreshCaches());
        static::deleted(fn (PurchaseBalancePayment $p) => $p->vehicle?->refreshCaches());

        // 큐 22-C-light (2026-05-20) Spec-F 권고 — paid Settlement 후 신규 PBP 차단.
        // FinalPayment·PBP updating 잠금과 같은 시점에 creating도 동일 잠금.
        // 시드·artisan(auth 없음) 우회 — assertPaidSettlementGuard Service에서 별도 보장.
        static::creating(function (PurchaseBalancePayment $p) {
            if (! auth()->check()) {
                return;
            }
            $vehicle = $p->vehicle;
            if ($vehicle && $vehicle->settlements()->where('settlement_status', 'paid')->exists()) {
                throw new \DomainException('정산이 paid 상태인 차량에 신규 매입 잔금을 추가할 수 없습니다 (회계 무결성).');
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
