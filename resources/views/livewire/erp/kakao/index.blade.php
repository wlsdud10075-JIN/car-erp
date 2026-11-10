<?php

use App\Models\KakaoCollectorToken;
use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Services\Kakao\KakaoBuckets;
use App\Services\Kakao\KakaoTodoBoard;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 📱 「내 카톡 할 일」 (jin 2026-11-10, 기획 docs/design/kakao-automation.md §5 v4 목업 · §10 구현 설계)
 *
 * - 읽기는 내 PC 수집기가 [지금 읽기]를 눌렀을 때만(POST /api/kakao/ingest). 이 페이지는 **보기·처리**만 한다.
 * - 분류는 방 단위 1회(kakao_messages). 방향·묶음은 **보는 사람마다** 계산(KakaoDirection·KakaoBuckets) — render 때 한 번(§8 #96-C: Computed·poll 금지).
 * - 행 동작은 kakao_items(message_id, user_id) 에만 쓴다. 메시지 본체는 안 건드린다. 모든 동작은 「내가 구독한 방의 메시지인가」를 다시 본다(§8 #26 IDOR).
 * - 미루기는 반드시 다시 나타날 조건을 고른다(§1 #10) — 시각 2종·날짜·「상대가 답하면」(조건형: snooze_until null + 사유).
 * - 묶음 9개 전부 기본 접힘(§1 #8). 「놓쳤을 가능성」 자동 펼침은 열린 결정(§8 (b)) — v1 은 접힘.
 * - 🚫 Volt 프로퍼티와 메서드 동명 금지(§8 #32) — 메서드는 동사(markDone·snoozeItem·saveRoom…).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public bool $showSetup = false;

    // 방 등록 폼
    public string $roomName = '';
    public string $roomSides = '';
    public string $roomRoles = '';

    /** @var array<int, string> room_id → 내 표시명 (구독 폼) */
    public array $myNameFor = [];

    // 수집기 토큰
    public string $deviceName = '';
    public ?string $tokenPlain = null;

    // 미루기 폼 (행 하나)
    public ?int $snoozeId = null;
    public string $snoozeDate = '';
    public string $snoozeReason = '';

    // 분류 수정 (행 하나)
    public ?int $fixId = null;
    public string $fixType = '';

    public function mount(): void
    {
        abort_unless(Setting::kakaoAssistantEnabled(), 403, __('kakao.disabled'));
        $this->snoozeDate = now()->addDay()->format('Y-m-d');
        if (auth()->user()->kakaoRooms()->doesntExist()) {
            $this->showSetup = true;   // 아직 아무 방도 없으면 설정부터
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        $board = KakaoTodoBoard::forUser($user, now());

        return [
            'board' => $board,
            'order' => KakaoBuckets::ORDER,
            'labels' => KakaoBuckets::LABELS,
            'hints' => KakaoBuckets::HINTS,
            'otherRooms' => $this->showSetup ? KakaoTodoBoard::otherRooms($user) : collect(),
            'tokens' => $this->showSetup ? $user->kakaoCollectorTokens()->orderByDesc('id')->get() : collect(),
            'types' => KakaoMessage::TYPES,
        ];
    }

    // ───────── 행 동작 (kakao_items 만) ─────────

    /** 내가 구독한 방의 메시지인지 다시 확인 — wire:click 의 id 는 사용자 입력이다. */
    private function ownMessage(int $id): KakaoMessage
    {
        $msg = KakaoMessage::findOrFail($id);
        abort_unless(auth()->user()->kakaoRooms()->where('kakao_rooms.id', $msg->room_id)->exists(), 403);

        return $msg;
    }

    private function upsertItem(int $id, array $attrs): void
    {
        $msg = $this->ownMessage($id);
        KakaoItem::updateOrCreate(
            ['message_id' => $msg->id, 'user_id' => auth()->id()],
            array_merge(['snooze_until' => null, 'snooze_reason' => null, 'done_at' => null], $attrs),
        );
        $this->snoozeId = null;
        $this->fixId = null;
    }

    public function markDone(int $id): void
    {
        $this->upsertItem($id, ['state' => 'done', 'done_at' => now()]);
    }

    public function markNotMine(int $id): void
    {
        $this->upsertItem($id, ['state' => 'not_mine']);
    }

    public function ignoreItem(int $id): void
    {
        $this->upsertItem($id, ['state' => 'ignored']);
    }

    public function reopenItem(int $id): void
    {
        $this->upsertItem($id, ['state' => 'todo']);
    }

    public function openSnooze(int $id): void
    {
        $this->ownMessage($id);
        $this->snoozeId = $id;
        $this->snoozeReason = '';
    }

    /** preset = today16 | tomorrow10 | date | reply */
    public function snoozeItem(int $id, string $preset): void
    {
        $until = match ($preset) {
            'today16' => now()->setTime(16, 0),
            'tomorrow10' => now()->addDay()->setTime(10, 0),
            'date' => \Carbon\Carbon::parse($this->validate(['snoozeDate' => ['required', 'date_format:Y-m-d']])['snoozeDate'])->setTime(10, 0),
            'reply' => null,
            default => abort(422),
        };
        $reason = trim($this->snoozeReason);
        if ($preset === 'reply' && $reason === '') {
            $reason = __('kakao.snooze_reply');
        }
        $this->upsertItem($id, ['state' => 'snoozed', 'snooze_until' => $until, 'snooze_reason' => $reason !== '' ? mb_substr($reason, 0, 120) : null]);
    }

    public function openFix(int $id): void
    {
        $msg = $this->ownMessage($id);
        $this->fixId = $id;
        $this->fixType = $msg->type ?? '';
    }

    /** 분류 수정 = 사용자 라벨(type_override). 모델 판정(ai)은 그대로 남아 증류 자료가 된다. */
    public function overrideType(int $id): void
    {
        abort_unless(in_array($this->fixType, KakaoMessage::TYPES, true), 422);
        $msg = $this->ownMessage($id);
        $item = KakaoItem::firstOrNew(['message_id' => $msg->id, 'user_id' => auth()->id()]);
        $item->state = $item->state ?? 'todo';
        $item->type_override = $this->fixType;
        $item->save();
        $this->fixId = null;
        $this->dispatch('notify', message: __('kakao.type_fixed'), type: 'success');
    }

    // ───────── 방 · 구독 ─────────

    public function toggleSetup(): void
    {
        $this->showSetup = ! $this->showSetup;
        $this->tokenPlain = null;
    }

    public function saveRoom(): void
    {
        $data = $this->validate([
            'roomName' => ['required', 'string', 'max:120'],
            'roomSides' => ['required', 'string', 'max:4000'],
            'roomRoles' => ['nullable', 'string', 'max:2000'],
        ]);
        $sides = self::parseSides($data['roomSides']);
        if ($sides === []) {
            $this->addError('roomSides', __('kakao.room_err_sides'));

            return;
        }
        $roles = array_intersect_key(self::parseRoles($data['roomRoles'] ?? ''), $sides);   // 있는 편의 설명만
        KakaoRoom::updateOrCreate(['name' => trim($data['roomName'])], ['sides' => $sides, 'roles' => $roles]);
        $this->reset('roomName', 'roomSides', 'roomRoles');
        $this->dispatch('notify', message: __('kakao.room_saved'), type: 'success');
    }

    public function subscribeRoom(int $roomId): void
    {
        $room = KakaoRoom::findOrFail($roomId);
        $this->resetErrorBag('myNameFor.'.$roomId);
        $myName = trim((string) ($this->myNameFor[$roomId] ?? ''));
        if ($myName === '' || \App\Services\Kakao\KakaoDirection::sideOf($myName, $room->sideMembers()) === null) {
            $this->addError('myNameFor.'.$roomId, __('kakao.room_err_myname'));

            return;
        }
        auth()->user()->kakaoRooms()->syncWithoutDetaching([$room->id => ['my_name' => $myName]]);
        $this->dispatch('notify', message: __('kakao.room_subscribed'), type: 'success');
    }

    public function unsubscribeRoom(int $roomId): void
    {
        auth()->user()->kakaoRooms()->detach($roomId);
        $this->dispatch('notify', message: __('kakao.room_unsubscribed'), type: 'success');
    }

    /** 「편 이름: 이름, 이름」 줄들 → ['편' => [이름…]] */
    public static function parseSides(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$side, $names] = explode(':', $line, 2);
            $side = trim($side);
            $members = array_values(array_filter(array_map('trim', preg_split('/[,，]/u', $names)), fn ($n) => $n !== ''));
            if ($side !== '' && $members !== []) {
                $out[$side] = array_values(array_unique(array_merge($out[$side] ?? [], $members)));
            }
        }

        return $out;
    }

    public static function parseRoles(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$side, $desc] = explode(':', $line, 2);
            if (trim($side) !== '' && trim($desc) !== '') {
                $out[trim($side)] = mb_substr(trim($desc), 0, 200);
            }
        }

        return $out;
    }

    // ───────── 수집기 토큰 ─────────

    public function issueToken(): void
    {
        [, $plain] = KakaoCollectorToken::issue(auth()->user(), mb_substr(trim($this->deviceName), 0, 80) ?: null);
        $this->tokenPlain = $plain;   // 1회 표시. 저장은 해시뿐
        $this->deviceName = '';
    }

    public function revokeToken(int $tokenId): void
    {
        auth()->user()->kakaoCollectorTokens()->whereKey($tokenId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->tokenPlain = null;
    }
}; ?>

<div class="p-3 md:p-6" x-data="{ open: {}, menu: null }">
    {{-- 헤더 --}}
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('kakao.title') }}</h2>
            <p class="mt-1 text-xs text-gray-500">
                @php($roomsText = $board['rooms']->isEmpty() ? __('kakao.rooms_none') : $board['rooms']->pluck('name')->take(3)->implode(' · ').($board['rooms']->count() > 3 ? ' 외 '.($board['rooms']->count() - 3) : ''))
                @if($board['last_read'])
                    {{ __('kakao.status_line', ['time' => $board['last_read']->created_at->format('m/d H:i'), 'days' => $board['window_days'], 'rooms' => $roomsText]) }}
                @else
                    {{ __('kakao.status_never', ['days' => $board['window_days'], 'rooms' => $roomsText]) }}
                @endif
                · {{ __('kakao.cost_today', ['krw' => number_format($board['cost_today'])]) }}
            </p>
        </div>
        <button type="button" wire:click="toggleSetup" class="tab-pill h-9 px-3 text-sm sm:h-8">{{ $showSetup ? __('kakao.setup_close') : __('kakao.setup') }}</button>
    </div>

    @if($board['counts']['review'] > 0)
        <div class="card-tight mb-4 border-amber-200 bg-amber-50 text-sm text-amber-800">{{ __('kakao.needs_review', ['n' => $board['counts']['review']]) }}</div>
    @endif

    {{-- 설정 (방 · 구독 · 수집기 토큰) --}}
    @if($showSetup)
        <div class="mb-4 grid gap-4 lg:grid-cols-2" data-kakao-setup>
            <div class="card">
                <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ __('kakao.rooms_title') }}</span></div>
                @forelse($board['rooms'] as $room)
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded border border-gray-100 px-3 py-2 text-sm" wire:key="room-sub-{{ $room->id }}">
                        <div><span class="font-medium text-gray-800">{{ $room->name }}</span>
                            <span class="ml-2 text-xs text-gray-500">{{ __('kakao.room_my_name') }}: {{ $room->pivot->my_name }}</span></div>
                        <button type="button" wire:click="unsubscribeRoom({{ $room->id }})" class="h-9 rounded border border-gray-300 px-2 text-xs text-gray-700 sm:h-7">{{ __('kakao.room_unsubscribe') }}</button>
                    </div>
                @empty
                    <p class="mt-2 text-xs text-gray-400">{{ __('kakao.rooms_none') }}</p>
                @endforelse
                @foreach($otherRooms as $room)
                    <div class="mt-2 flex flex-wrap items-center gap-2 rounded border border-dashed border-gray-200 px-3 py-2 text-sm" wire:key="room-other-{{ $room->id }}">
                        <span class="text-gray-700">{{ $room->name }}</span>
                        <input type="text" wire:model="myNameFor.{{ $room->id }}" placeholder="{{ __('kakao.room_my_name') }}" class="input-base h-9 w-48 text-xs sm:h-7">
                        <button type="button" wire:click="subscribeRoom({{ $room->id }})" class="btn-primary h-9 px-3 text-xs sm:h-7">{{ __('kakao.room_subscribe') }}</button>
                        @error('myNameFor.'.$room->id) <span class="basis-full text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                @endforeach

                <hr class="section-divider my-3">
                <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ __('kakao.room_add_title') }}</span></div>
                <div class="mt-2 space-y-2">
                    <input type="text" wire:model="roomName" placeholder="{{ __('kakao.room_name') }}" class="input-base w-full text-sm">
                    @error('roomName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <textarea wire:model="roomSides" rows="4" placeholder="{{ __('kakao.room_sides') }}&#10;{{ str_replace("\n", '&#10;', __('kakao.room_sides_hint')) }}" class="input-base w-full text-sm"></textarea>
                    @error('roomSides') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <textarea wire:model="roomRoles" rows="2" placeholder="{{ __('kakao.room_roles') }}" class="input-base w-full text-sm"></textarea>
                    <button type="button" wire:click="saveRoom" class="btn-primary h-9 px-3 text-sm sm:h-8">{{ __('kakao.room_save') }}</button>
                </div>
            </div>

            <div class="card">
                <div class="section-header"><span class="section-dot bg-teal-500"></span><span class="section-title">{{ __('kakao.token_title') }}</span></div>
                <p class="mt-1 text-xs text-gray-500">{{ __('kakao.token_hint') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <input type="text" wire:model="deviceName" placeholder="{{ __('kakao.token_device') }}" class="input-base h-9 w-56 text-sm sm:h-8">
                    <button type="button" wire:click="issueToken" class="btn-primary h-9 px-3 text-sm sm:h-8">{{ __('kakao.token_issue') }}</button>
                </div>
                @if($tokenPlain)
                    <div class="mt-3 rounded border border-teal-200 bg-teal-50 p-2 text-xs" data-token-plain>
                        <div class="font-medium text-teal-800">{{ __('kakao.token_plain') }}</div>
                        <code class="mt-1 block select-all break-all text-teal-900">{{ $tokenPlain }}</code>
                    </div>
                @endif
                <ul class="mt-3 space-y-1 text-xs text-gray-700">
                    @forelse($tokens as $t)
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded border border-gray-100 px-2 py-1" wire:key="tok-{{ $t->id }}">
                            <span class="{{ $t->revoked_at ? 'line-through text-gray-400' : '' }}">{{ $t->device_name ?: '—' }} · {{ __('kakao.token_last_used') }} {{ $t->last_used_at?->format('m/d H:i') ?? __('kakao.token_never') }}</span>
                            @unless($t->revoked_at)
                                <button type="button" wire:click="revokeToken({{ $t->id }})" class="h-9 rounded border border-red-200 px-2 text-xs text-red-700 sm:h-7">{{ __('kakao.token_revoke') }}</button>
                            @endunless
                        </li>
                    @empty
                        <li class="text-gray-400">{{ __('kakao.token_none') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif

    {{-- 묶음 9개 (전부 접힘) --}}
    @foreach($order as $key)
        @php($rows = $board['buckets'][$key])
        @php($tone = match($key) { 'missed' => 'border-red-200 bg-red-50', 'now' => 'border-amber-200', 'review' => 'border-amber-100', default => 'border-gray-200' })
        <div class="card-tight mb-2 {{ $tone }}" wire:key="bucket-{{ $key }}" data-bucket="{{ $key }}">
            <button type="button" class="flex w-full items-center justify-between gap-2 text-left" @click="open['{{ $key }}'] = !open['{{ $key }}']">
                <span class="flex items-center gap-2">
                    <span class="pill-count">{{ count($rows) }}</span>
                    <span class="text-sm font-semibold text-gray-800">{{ $labels[$key] }}</span>
                    <span class="hidden text-xs text-gray-400 sm:inline">{{ $hints[$key] }}</span>
                </span>
                <span class="text-xs text-gray-500" x-text="open['{{ $key }}'] ? '{{ __('kakao.collapse') }}' : '{{ __('kakao.expand') }}'"></span>
            </button>

            <div x-show="open['{{ $key }}']" x-cloak class="mt-2 space-y-2">
                @forelse($rows as $r)
                    <div class="rounded border border-gray-100 bg-white px-3 py-2" wire:key="row-{{ $key }}-{{ $r['id'] }}" data-row="{{ $r['id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-gray-800">{{ $r['title'] }}</div>
                                <div class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-gray-500">
                                    <span>{{ __('kakao.from_to', ['from' => $r['sender'], 'to' => $r['to'] ?: '—']) }}</span>
                                    <span>{{ $r['ago'] }} · {{ $r['room'] }}</span>
                                    <span class="badge badge-gray">{{ $r['type'] ?: '?' }}</span>
                                    <span class="badge badge-purple">{{ $r['reason'] }}</span>
                                    @if($r['auto']) <span class="badge badge-green">{{ __('kakao.auto_done') }}</span> @endif
                                    @if($r['vehicle_no']) <span class="badge badge-blue">{{ $r['vehicle_no'] }}</span> @endif
                                    @if($r['amount']) <span class="badge badge-amber">{{ $r['amount'] }}</span> @endif
                                    @if($r['ref']) <span class="badge badge-teal">{{ $r['ref'] }}</span> @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                @if(in_array($key, ['missed', 'now', 'today', 'snoozed', 'review', 'none', 'teammate']))
                                    <button type="button" wire:click="markDone({{ $r['id'] }})" class="btn-primary h-9 px-2 text-xs sm:h-7">{{ $key === 'snoozed' ? __('kakao.btn_do_now') : __('kakao.btn_done') }}</button>
                                @elseif($key === 'waiting' || ($key === 'done' && $r['auto']))
                                    <button type="button" wire:click="markDone({{ $r['id'] }})" class="btn-primary h-9 px-2 text-xs sm:h-7">{{ __('kakao.btn_confirm_reply') }}</button>
                                @else
                                    <button type="button" wire:click="reopenItem({{ $r['id'] }})" class="h-9 rounded border border-gray-300 px-2 text-xs text-gray-700 sm:h-7">{{ __('kakao.btn_reopen') }}</button>
                                @endif
                                @if(! in_array($key, ['done']))
                                    <button type="button" wire:click="openSnooze({{ $r['id'] }})" class="h-9 rounded border border-gray-300 px-2 text-xs text-gray-700 sm:h-7">{{ __('kakao.btn_snooze') }}</button>
                                @endif
                                @if($r['vehicle_id'])
                                    <a href="{{ route('erp.vehicles.index', ['openVehicle' => $r['vehicle_id']]) }}" wire:navigate class="inline-flex h-9 items-center rounded border border-blue-200 px-2 text-xs text-blue-700 sm:h-7">{{ __('kakao.btn_open_vehicle') }}</a>
                                @endif
                                <div class="relative">
                                    <button type="button" class="h-9 rounded border border-gray-300 px-2 text-xs text-gray-700 sm:h-7" @click="menu = (menu === {{ $r['id'] }} ? null : {{ $r['id'] }})" aria-label="{{ __('kakao.btn_more') }}">⋯</button>
                                    <div x-show="menu === {{ $r['id'] }}" x-cloak @click.outside="menu = null" class="absolute right-0 z-20 mt-1 w-40 rounded border border-gray-200 bg-white py-1 text-xs text-gray-700 shadow-lg">
                                        <button type="button" wire:click="openFix({{ $r['id'] }})" @click="menu = null" class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50">{{ __('kakao.btn_fix_type') }}</button>
                                        <button type="button" wire:click="markNotMine({{ $r['id'] }})" @click="menu = null" class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50">{{ __('kakao.btn_not_mine') }}</button>
                                        <button type="button" wire:click="ignoreItem({{ $r['id'] }})" @click="menu = null" class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50">{{ __('kakao.btn_ignore') }}</button>
                                        <button type="button" wire:click="reopenItem({{ $r['id'] }})" @click="menu = null" class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50">{{ __('kakao.btn_reopen') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if($snoozeId === $r['id'])
                            <div class="mt-2 rounded border border-violet-200 bg-violet-50 p-2 text-xs" data-snooze-form>
                                <div class="mb-1 font-medium text-violet-800">{{ __('kakao.snooze_title') }}</div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" wire:click="snoozeItem({{ $r['id'] }}, 'today16')" class="h-9 rounded border border-violet-300 bg-white px-2 text-gray-700 sm:h-7">{{ __('kakao.snooze_today') }}</button>
                                    <button type="button" wire:click="snoozeItem({{ $r['id'] }}, 'tomorrow10')" class="h-9 rounded border border-violet-300 bg-white px-2 text-gray-700 sm:h-7">{{ __('kakao.snooze_tomorrow') }}</button>
                                    <span class="flex items-center gap-1"><input type="date" wire:model="snoozeDate" class="input-base h-9 text-xs sm:h-7">
                                        <button type="button" wire:click="snoozeItem({{ $r['id'] }}, 'date')" class="h-9 rounded border border-violet-300 bg-white px-2 text-gray-700 sm:h-7">{{ __('kakao.snooze_date') }}</button></span>
                                    <button type="button" wire:click="snoozeItem({{ $r['id'] }}, 'reply')" class="h-9 rounded border border-violet-300 bg-white px-2 text-gray-700 sm:h-7">{{ __('kakao.snooze_reply') }}</button>
                                    <input type="text" wire:model="snoozeReason" placeholder="{{ __('kakao.snooze_reason') }}" class="input-base h-9 w-48 text-xs sm:h-7">
                                    <button type="button" wire:click="$set('snoozeId', null)" class="h-9 px-2 text-gray-500 sm:h-7">{{ __('kakao.cancel') }}</button>
                                </div>
                                @error('snoozeDate') <p class="mt-1 text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if($fixId === $r['id'])
                            <div class="mt-2 flex flex-wrap items-center gap-2 rounded border border-gray-200 bg-gray-50 p-2 text-xs" data-fix-form>
                                <select wire:model="fixType" class="input-base h-9 text-xs text-gray-700 sm:h-7">
                                    @foreach($types as $t) <option value="{{ $t }}" class="text-gray-700">{{ $t }}</option> @endforeach
                                </select>
                                <button type="button" wire:click="overrideType({{ $r['id'] }})" class="btn-primary h-9 px-2 sm:h-7">{{ __('kakao.btn_fix_type') }}</button>
                                <button type="button" wire:click="$set('fixId', null)" class="h-9 px-2 text-gray-500 sm:h-7">{{ __('kakao.cancel') }}</button>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-gray-400">{{ __('kakao.empty_bucket') }}</p>
                @endforelse
            </div>
        </div>
    @endforeach
</div>
