<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 가수금 — 대표·관계사가 회사에 넣은 돈 (jin 2026-07-27, 안건4 1단계).
 * 회수(반제)하면 행을 삭제한다 → 목록 합계 = 현재 잔액. softDelete 라 이력은 DB 에 남는다.
 */
class AdvanceReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'received_date', 'company_name', 'person_name', 'amount', 'note', 'created_by',
    ];

    protected $casts = [
        'received_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 현재 잔액(원) — 삭제되지 않은 행의 합. 2단계에서 자금현황이 이 값을 쓴다. */
    public static function totalKrw(): int
    {
        return (int) static::sum('amount');
    }
}
