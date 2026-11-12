<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 카톡 메시지가 말한 차 1대 — 본문 식별자(self) 또는 앞 글에서 이어받음(inherit). KakaoVehicleLinks 가 쓴다.
 */
class KakaoMessageVehicle extends Model
{
    public const VIA = ['self', 'inherit'];

    protected $fillable = ['message_id', 'vehicle_id', 'ident', 'via', 'from_message_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(KakaoMessage::class, 'message_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function fromMessage(): BelongsTo
    {
        return $this->belongsTo(KakaoMessage::class, 'from_message_id');
    }
}
