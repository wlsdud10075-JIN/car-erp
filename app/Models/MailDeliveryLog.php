<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 바이어 문서 메일전달 감사 로그 — 누가·언제·어떤 문서를·누구에게 보냈는지.
 */
class MailDeliveryLog extends Model
{
    protected $fillable = [
        'vehicle_id', 'user_id', 'channel', 'from_address',
        'to_email', 'subject', 'document_names', 'status', 'error',
    ];

    protected $casts = ['document_names' => 'array'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
