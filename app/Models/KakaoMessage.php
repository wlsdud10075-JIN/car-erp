<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 카톡 메시지 1건 + 방 단위 분류 결과. 방향은 저장하지 않는다(KakaoDirection 이 보는 사람마다 계산).
 */
class KakaoMessage extends Model
{
    public const TYPES = ['요청', '질문', '대답', '확인', '정보', '잡담', '첨부'];

    protected $fillable = [
        'room_id', 'read_id', 'msg_key', 'sent_at', 'sender', 'body',
        'type', 'to_side', 'mention', 'ref', 'vehicle_no', 'vin_tail', 'amount',
        'resolves_id', 'vehicle_id', 'ai',
    ];

    protected $casts = ['sent_at' => 'datetime', 'ai' => 'array'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(KakaoRoom::class, 'room_id');
    }

    public function read(): BelongsTo
    {
        return $this->belongsTo(KakaoRead::class, 'read_id');
    }

    public function resolves(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resolves_id');
    }

    public function resolvedBy(): HasMany
    {
        return $this->hasMany(self::class, 'resolves_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KakaoItem::class, 'message_id');
    }

    /** KakaoDirection::of() 에 넣는 판정 배열 (사용자 유형 수정이 있으면 그걸 우선). */
    public function judgmentFor(?KakaoItem $item = null): array
    {
        return [
            'type' => $item?->type_override ?? $this->type,
            'to' => $this->to_side ?? '없음',
            'mention' => $this->mention,
        ];
    }
}
