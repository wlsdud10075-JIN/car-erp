<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 미청산 이월 청산 기록 (2026-06-10). 퇴사자/관계종료 시 stranded carryover 를 1회 정리.
 * amount_krw = 청산 시점 net(부호): + = 담당자에게 지급(지급대기 해소) / − = 담당자에게서 회수(청구 해소).
 */
class CarryoverClearance extends Model
{
    // 2026-10-06 jin — 월배치 자동 흡수분은 payout_batch_id 를 단다(반려 시 되돌리기). 사람이 누른 퇴사자 청산은 NULL.
    protected $fillable = ['salesman_id', 'payout_batch_id', 'amount_krw', 'direction', 'cleared_by', 'note'];

    protected $casts = ['amount_krw' => 'integer'];

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SettlementPayoutBatch::class, 'payout_batch_id');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }
}
