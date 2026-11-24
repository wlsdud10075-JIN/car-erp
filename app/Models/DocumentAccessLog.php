<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAccessLog extends Model
{
    // 문서 종류별 한글 라벨 (감사 화면 표시용)
    public const DOCUMENT_TYPES = [
        'deregistration' => '말소신청서',
        'deregistration_contract' => '말소 계약서',
        'deregistration_set' => '말소 서류 묶음',
        'deregistration_certificate' => '말소등록증',
        'deregistration_upload' => '말소등록증 업로드',
        'poa' => '위임장',
        'registration_application' => '등록증재발급신청서',
        'transfer_certificate' => '양도증명서',
        'invoice' => '인보이스',
        'sales_contract' => '판매계약서',
        'container_invoice_packing' => '컨테이너 인보이스·패킹',
        'container_contract' => '컨테이너 계약서',
        'roro_invoice_packing' => 'RORO 인보이스·패킹',
        'roro_contract' => 'RORO 계약서',
        'clearance' => '통관 서류 묶음',
        'clearance_v2' => '통관 서류 묶음 (2판)',
        'export_declaration' => '수출신고서',
        'bill_of_lading' => 'B/L',
        'shipping_photos' => '선박 사진',
        'ro_cipl' => 'RORO 인보이스·패킹 (옛 양식)',
        'con_cipl' => '컨테이너 인보이스·패킹 (옛 양식)',
        'bank_remittance' => '은행 서류 — 전신문',          // 2026-11-24 은행 서류 모달
        'bank_invoice' => '은행 서류 — 은행용 Proforma',
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
        // 전자서명 계약 이벤트는 `sales_contract_{event}` 로 쌓인다 — 앞머리로 묶어 한글로.
        if (str_starts_with((string) $this->document_type, 'sales_contract_')) {
            return '판매계약서 전자서명';
        }

        return self::DOCUMENT_TYPES[$this->document_type] ?? $this->document_type;
    }
}
