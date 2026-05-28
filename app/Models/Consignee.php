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
        'eori_number', 'tax_number',
        'contact_name', 'contact_email',
        'contact_phone', 'address', 'memo', 'is_active',
    ];

    // claudefinalreview 3-3 — id_value 저장 시 암호화(at-rest). rrn(주민번호) 평문 저장 방지.
    // 차량 RRN 과 동일 정책. id_value 는 SQL 검색에 안 쓰여 암호화 무영향(단, DB 직접조회로
    // 번호 검색은 불가 — 1인 운영 컨텍스트에서 영향 없음). null 은 그대로 null 유지.
    protected $casts = [
        'is_active' => 'boolean',
        'id_value' => 'encrypted',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
