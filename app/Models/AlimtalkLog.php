<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 카카오 알림톡(BizM) 발송 감사 로그 — 어떤 템플릿을·누구에게·언제 보냈고 성공/실패했는지.
 *
 * status: sent(발송 성공, msgid 있음) / failed(BizM 오류·예외) / skipped(게이트 off·미설정 등 발송 안 함).
 * vehicle_id·user_id 는 트리거 맥락이 있으면 채우고, 없으면(일일요약 등) null.
 */
class AlimtalkLog extends Model
{
    protected $fillable = [
        'vehicle_id', 'user_id', 'template_code', 'phone',
        'message', 'msgid', 'status', 'error',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
