<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBalancePayment extends Model
{
    protected $fillable = [
        'vehicle_id', 'amount', 'payment_date', 'note',
        'confirmed_by_user_id', 'confirmed_at', 'finance_note',
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
