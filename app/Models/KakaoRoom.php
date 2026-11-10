<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 카톡 방 — 회사 DB 안에서 방 제목으로 식별. sides = 편 구성(프롬프트의 「방 구성」, 방향 계산의 근거).
 * docs/design/kakao-automation.md §10-2.
 */
class KakaoRoom extends Model
{
    protected $fillable = ['name', 'sides', 'roles', 'last_read_at'];

    protected $casts = ['sides' => 'array', 'roles' => 'array', 'last_read_at' => 'datetime'];

    /** 편 이름 → 역할 설명(문자열만). 프롬프트의 「방 구성」 줄에 붙는다. */
    public function sideRoles(): array
    {
        return array_filter((array) ($this->roles ?? []), fn ($v) => is_string($v) && trim($v) !== '');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'kakao_room_users', 'room_id', 'user_id')
            ->withPivot('my_name')->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(KakaoMessage::class, 'room_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(KakaoRead::class, 'room_id');
    }

    /** 편 이름 → 사람 목록만 (역할 설명 등 다른 키는 뺀다). */
    public function sideMembers(): array
    {
        $out = [];
        foreach ((array) ($this->sides ?? []) as $side => $members) {
            if (is_array($members)) {
                $out[$side] = array_values(array_filter(array_map('strval', $members), fn ($m) => trim($m) !== ''));
            }
        }

        return $out;
    }
}
