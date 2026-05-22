<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consignee extends Model
{
    use SoftDeletes;

    // 회의확장씬 #4 (2026-05-22) — ID 종류 enum-like (application 검증).
    // 신규 ID 종류 추가 시 본 상수만 갱신 + UI select 자동 반영.
    public const ID_TYPES = [
        'rrn' => '주민번호',
        'passport' => '여권번호',
        'business' => '사업자번호',
    ];

    protected $fillable = [
        'name', 'buyer_id', 'country_id',
        'id_type', 'id_value',
        'contact_name', 'contact_email',
        'contact_phone', 'address', 'memo', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
