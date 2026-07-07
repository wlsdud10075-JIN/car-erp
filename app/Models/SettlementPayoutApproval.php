<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 2 — 월배치 정산지급 단계별 승인/반려 감사 로그.
 */
class SettlementPayoutApproval extends Model
{
    public $timestamps = false;

    protected $fillable = ['batch_id', 'approver_id', 'approver_rank', 'action', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SettlementPayoutBatch::class, 'batch_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
