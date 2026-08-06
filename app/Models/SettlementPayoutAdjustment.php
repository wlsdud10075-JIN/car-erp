<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 월배치 수동 조정 (jin 2026-07-08) — 정산 공식 밖의 담당자별 +/− 조정.
 * 배치(SettlementPayoutBatch) 총액에만 반영. 개별 정산 무손상. pending 배치에서만 편집.
 */
class SettlementPayoutAdjustment extends Model
{
    protected $fillable = ['batch_id', 'salesman_id', 'amount', 'reason', 'cancel_vehicle_ids', 'created_by'];

    protected $casts = [
        'amount' => 'integer',
        // 매입취소 손실 조정이 덮는 차량 id 목록 (jin 2026-08-06). NULL = 일반 수동 조정.
        // 배치 최종 승인 시 이 차량들의 cancel_loss_settled_at 을 찍는다.
        'cancel_vehicle_ids' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SettlementPayoutBatch::class, 'batch_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
