<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 월정산 상신 뒤 바뀐 것 (월정산 v3, 2026-10-09) — 인센티브·조정·급여. 카드의 노란 표시 + 결재 내역의 출처.
 * 결재는 멈춘 단계부터 이어서 가고(초기화 X), 바뀐 칸만 색으로 알린다(jin).
 */
class SettlementPayoutBatchChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['batch_id', 'user_id', 'salesman_id', 'field', 'before', 'after', 'note', 'created_at'];

    protected $casts = ['before' => 'integer', 'after' => 'integer', 'created_at' => 'datetime'];

    public const FIELD_INCENTIVE = 'incentive';

    public const FIELD_ADJUSTMENT = 'adjustment';

    public const FIELD_PAYROLL = 'payroll';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SettlementPayoutBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }
}
