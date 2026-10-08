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
    protected $fillable = ['batch_id', 'salesman_id', 'amount', 'kind', 'reason', 'cancel_vehicle_ids', 'created_by'];

    /**
     * 조정 종류 (월정산 v3, jin 2026-10-08). 사유 문자열로 갈리던 것을 칸으로.
     *   manual    수기 조정(정산관리 제출 모달)
     *   carryover 미청산 이월 자동 반영(carryover_clearances 와 짝)
     *   loss      매입취소 손실 차감(cancel_vehicle_ids 보유)
     *   incentive 추가 인센티브 — 사람당 N건, 결재 중 수정 가능, 카드·결재 내역에 따로 표시
     */
    public const KINDS = ['manual', 'carryover', 'loss', 'incentive'];

    public const KIND_MANUAL = 'manual';

    public const KIND_CARRYOVER = 'carryover';

    public const KIND_LOSS = 'loss';

    public const KIND_INCENTIVE = 'incentive';

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
