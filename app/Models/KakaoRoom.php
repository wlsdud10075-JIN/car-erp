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
        // 우리 편 사람의 부서(ERP 역할)를 명부에서 같이 적는다(jin 11-10 «역할이 있잖아? 부서별로 나눠서 보여주면») — 프롬프트·행 배지가 쓴다
        $roster = self::rosterRoleMap();
        $people = [];
        foreach ($ours as $name) {
            foreach ($roster as $rosterName => $role) {
                if (self::matchName($rosterName, [$name]) !== null) {
                    $people[$name] = $role;
                    break;
                }
            }
        }
        $roles = (array) ($this->roles ?? []);
        $roles['_people'] = $people;
        $this->forceFill(['sides' => [self::SIDE_US => $ours, self::SIDE_THEM => $others], 'roles' => $roles])->save();
    }

    /** 보낸이 → 부서(ERP 역할 라벨). 우리 편 중 명부와 맞은 사람만. */
    public function personRoles(): array
    {
        $p = ($this->roles ?? [])['_people'] ?? [];

        return is_array($p) ? array_filter($p, fn ($v) => is_string($v) && $v !== '') : [];
    }

    /** ERP 명부 이름 → 부서 라벨. 사용자 = role(일반) / 업무관리자·최고관리자(permission) · 영업담당자 명부 = 「영업」. 시스템관리자 제외. */
    public static function rosterRoleMap(): array
    {
        $map = [];
        foreach (User::where('permission', '!=', 'super')->get(['name', 'permission', 'role']) as $u) {
            $name = trim((string) $u->name);
            if (mb_strlen($name) < 2) {
                continue;
            }
            $map[$name] = match ($u->permission) {
                'manager' => '업무관리자',
                'admin' => '최고관리자',
                default => (string) $u->role,
            };
        }
        foreach (Salesman::where('is_active', true)->pluck('name') as $name) {
            $name = trim((string) $name);
            if (mb_strlen($name) >= 2 && ! isset($map[$name])) {
                $map[$name] = '영업';
            }
        }

        return $map;
    }

    public const SIDE_US = '우리';

    public const SIDE_THEM = '상대';

    /**
     * 「나」·「우리 회사」를 사람이 고르지 않고 ERP 명부로 잡는다 (jin 2026-11-10 «ERP 에 사용자 이름 있잖아? 카톡 이름이랑 맞으면 본인을 특정할 수 있지 않아?»).
     * 나 = 이 사용자의 ERP 이름과 맞는 보낸이 · 우리 회사 = ERP 사용자·영업담당자 명부의 이름과 맞는 보낸이 전부. 나머지 = 상대.
     * 이름 대조 = 공백 제거 뒤 같거나, 한쪽이 다른 쪽을 품을 때(「김다정」 ↔ 「김다정 싼카」). 두 글자 미만은 안 맞춘다(오탐).
     * 편(sides)은 방 단위라 이미 잡혀 있으면 그대로 두고 이 사용자의 「나」(pivot my_name)만 잡는다.
     *
     * @return bool 「나」를 잡았나(못 잡으면 화면이 「이 방에서 나는 누구?」 한 칸을 묻는다)
     */
    public function autoSetUpFor(User $user): bool
    {
        $senders = $this->senders();
        $me = self::matchName($user->name, $senders);
        if ($me === null) {
            return false;
        }
        if (! $this->isSetUp()) {
            $ours = [];
            foreach (self::rosterNames($user) as $name) {
                if (($hit = self::matchName($name, $senders)) !== null && $hit !== $me) {
                    $ours[] = $hit;
                }
            }
            $this->setUpSides($me, array_values(array_unique($ours)));
        }
        $this->users()->updateExistingPivot($user->id, ['my_name' => $me]);

        return true;
    }

    /** 우리 회사 사람 이름 후보 = ERP 사용자(시스템관리자 제외) + 영업담당자 명부(활성). 이 사용자 본인은 뺀다. */
    public static function rosterNames(User $user): array
    {
        $names = User::where('permission', '!=', 'super')->where('id', '!=', $user->id)->pluck('name')->all();
        $names = array_merge($names, Salesman::where('is_active', true)->pluck('name')->all());

        return array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $names), fn ($n) => mb_strlen($n) >= 2)));
    }

    /** 명부 이름 하나와 맞는 보낸이 — 정확히 같은 것을 먼저, 그다음 품는 것. 없으면 null. */
    public static function matchName(string $name, array $senders): ?string
    {
        $norm = fn (string $s) => preg_replace('/\s+/u', '', mb_strtolower($s));
        $n = $norm($name);
        if (mb_strlen($n) < 2) {
            return null;
        }
        foreach ($senders as $s) {
            if ($norm($s) === $n) {
                return $s;
            }
        }
        foreach ($senders as $s) {
            $ns = $norm($s);
            if (mb_strlen($ns) >= 2 && (str_contains($ns, $n) || str_contains($n, $ns))) {
                return $s;
            }
        }

        return null;
    }

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
            ->withPivot('my_name', 'selected', 'last_seen_at')->withTimestamps();
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
