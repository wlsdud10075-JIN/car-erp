<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAccessLog extends Model
{
    // 문서 종류별 한글 라벨 (감사 화면 표시용)
    public const DOCUMENT_TYPES = [
        'deregistration' => '말소신청서',
        'registration_application' => '등록증재발급신청서',
        'transfer_certificate' => '양도증명서',
        'invoice' => 'Proforma Invoice',
        'sales_contract' => 'Sales Contract',
        'ro_cipl' => 'RO CIPL',
        'con_cipl' => 'CON CIPL',
    ];

    protected $fillable = [
        'user_id', 'vehicle_id', 'document_type', 'ip_address', 'source', 'actor_email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function getDocumentLabelAttribute(): string
    {
        return self::DOCUMENT_TYPES[$this->document_type] ?? $this->document_type;
    }
}
