<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 월정산 결재선 한 칸 (월정산 v3, 2026-10-09) — 상신할 때 고른 「부장 → 전무 → 대표」 중 한 사람.
 * 행이 있는 배치만 steps 모드. 건너뛴 직급은 행이 없다. 상태 pending / approved / rejected.
 */
class SettlementPayoutBatchStep extends Model
{
    protected $fillable = ['batch_id', 'seq', 'title', 'approver_user_id', 'status', 'acted_at', 'note'];

    protected $casts = ['seq' => 'integer', 'acted_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SettlementPayoutBatch::class, 'batch_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
