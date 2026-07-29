<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsStatus extends Model
{
    protected $fillable = [
        'buyer_id', 'vehicle_id', 'currency', 'exchange_rate', 'transaction_type',
        'savings', 'balance', 'original_transaction_id', 'note',
    ];

    /**
     * ⚠️ `exchange_rate` 는 **적립(유입) 시점 환율**이다 — 사용 시점이 아니다.
     * 크레딧의 원화 가치는 적립될 때 정해지므로, 어느 적립분이 나갔는지(FIFO)가 환산을 좌우한다.
     * 계산은 App\Services\SavingsLedger 단일 출처.
     */
    protected $casts = [
        'savings' => 'decimal:2',
        'balance' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsStatus::class, 'original_transaction_id');
    }
}
