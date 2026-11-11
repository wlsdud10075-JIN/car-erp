<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 읽기 회차 — 누가 어느 방을 언제 읽어 몇 건을 분류했고 토큰·비용이 얼마였나. sides_snapshot = 그때의 방 구성.
 */
class KakaoRead extends Model
{
    /** 읽기 1회 상한에 걸려 이번 회차에 안 담은 건수(응답용, DB 컬럼 아님 — 선언해 두어야 동적 속성이 안 된다, §8 #96-B) */
    public int $leftover = 0;

    /** 이 회차가 끝난 뒤에도 분류 안 된(type null) 건수 — 시간 예산에 밀렸거나(partial) 실패(failed). 화면이 이어서 분류한다(응답용, DB 컬럼 아님) */
    public int $pendingAfter = 0;

    protected $fillable = [
        'room_id', 'user_id', 'sides_snapshot', 'started_at', 'finished_at', 'total', 'new_count',
        'model', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens', 'output_tokens', 'cost_krw', 'status', 'error',
    ];

    protected $casts = ['sides_snapshot' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(KakaoRoom::class, 'room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(KakaoMessage::class, 'read_id');
    }
}
