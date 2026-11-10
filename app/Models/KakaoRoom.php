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

    /** 편 구성이 잡혔나 — 안 잡힌 방은 메시지만 쌓이고 분류를 미룬다(사람이 「나」를 고를 때까지). */
    public function isSetUp(): bool
    {
        return $this->sideMembers() !== [];
    }

    /** 이 방에서 지금까지 말한 사람들(최근 순). 설정 화면의 「나」·「우리 회사」 고르기 후보. */
    public function senders(): array
    {
        return $this->messages()->selectRaw('sender, MAX(sent_at) AS last_at')->groupBy('sender')->orderByDesc('last_at')->pluck('sender')->all();
    }

    /**
     * 편 구성을 「우리 / 상대」 두 편으로 잡는다 (jin 2026-11-10 «재무: 최영숙 이걸 적으라고?» — 편 이름을 손으로 적지 않는다).
     * 우리 = 나 + 같은 회사로 고른 사람들, 상대 = 지금까지 말한 나머지 전부. 새 사람이 나타나면 refreshCounterpart() 가 상대에 보탠다.
     */
    public function setUpSides(string $me, array $ours): void
    {
        $ours = array_values(array_unique(array_filter(array_merge([$me], $ours), fn ($n) => trim((string) $n) !== '')));
        $others = array_values(array_diff($this->senders(), $ours));
        $this->forceFill(['sides' => [self::SIDE_US => $ours, self::SIDE_THEM => $others]])->save();
    }

    public const SIDE_US = '우리';

    public const SIDE_THEM = '상대';

    /** 설정 뒤 처음 말한 사람을 「상대」에 자동으로 보탠다(우리 편은 사람이 고른 것만). */
    public function refreshCounterpart(): void
    {
        $sides = $this->sideMembers();
        if ($sides === []) {
            return;
        }
        $known = array_merge(...array_values($sides));
        $new = array_values(array_diff($this->senders(), $known));
        if ($new !== []) {
            $sides[self::SIDE_THEM] = array_values(array_unique(array_merge($sides[self::SIDE_THEM] ?? [], $new)));
            $this->forceFill(['sides' => $sides])->save();
        }
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
