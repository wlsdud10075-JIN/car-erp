<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 사용자별 항목 상태 — 사용자가 건드린 메시지에만 행이 생긴다(없으면 계산값 그대로).
 * state: todo(다시 할 일로) | waiting(응답 확인 대기) | snoozed(미룸) | done(완료) | ignored(무시) | not_mine(내 일 아님)
 */
class KakaoItem extends Model
{
    public const STATES = ['todo', 'waiting', 'snoozed', 'done', 'ignored', 'not_mine'];

    protected $fillable = ['message_id', 'user_id', 'state', 'type_override', 'snooze_until', 'snooze_reason', 'done_at'];

    protected $casts = ['snooze_until' => 'datetime', 'done_at' => 'datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(KakaoMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
