<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 포워딩사 운임 인보이스(지급 청산 기록) — jin 2026-07-16.
 * paid_at = 지급여부 단일 출처. 차량 매칭은 표시용(그룹키)일 뿐, 지급 판정은 이 row 가 소유.
 * 정산·미수와 무관(격리) — transport_fee 는 매출측이라 회계 computed 에 절대 연결하지 않는다.
 */
class ForwardingInvoice extends Model
{
    protected $fillable = [
        'forwarding_company_id', 'group_type', 'group_key',
        'currency', 'amount', 'invoice_date', 'paid_at', 'memo', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_date' => 'date',
        'paid_at' => 'datetime',
    ];

    /** 묶음 기준 — 우선순위: container › declaration › vessel (Vehicle 데이터로 자동 분류). */
    public const GROUP_TYPES = ['container', 'declaration', 'vessel'];

    public function forwardingCompany(): BelongsTo
    {
        return $this->belongsTo(ForwardingCompany::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->paid_at !== null;
    }
}
