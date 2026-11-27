<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 🏦 은행 서류 1건 (jin 2026-11-24) — 전신문 + 은행용 Proforma + 은행 메일 기록. 여러 대 송금이면 차 여러 대에 이어진다.
 */
class BankRemittance extends Model
{
    protected $fillable = [
        'remittance_path', 'remittance_name', 'remitter_name', 'ocr_name', 'ocr_status',
        'invoice_path', 'invoice_name', 'invoice_revised_at', 'invoice_revised_by', 'mailed_to', 'mailed_at', 'sent_bundle_path', 'sent_bundle_name', 'created_by',
    ];

    protected $casts = ['mailed_at' => 'datetime', 'invoice_revised_at' => 'datetime'];

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'bank_remittance_vehicle');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
