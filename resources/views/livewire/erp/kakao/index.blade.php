<?php

use App\Models\KakaoCollectorToken;
use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Services\Kakao\KakaoBuckets;
use App\Services\Kakao\KakaoIngestService;
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

    /** @var array<int, string> room_id → 「나」로 고른 보낸이 */
    public array $meFor = [];

    /** @var array<int, array<int, string>> room_id → 「우리 회사」로 체크한 보낸이들 */
    public array $oursFor = [];

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
        abort_unless(auth()->user()->canUseKakaoAssistant(), 403, __('kakao.disabled'));   // 회사 토글 + 공개 범위 칸
        $this->snoozeDate = now()->addDay()->format('Y-m-d');
        $rooms = auth()->user()->kakaoRooms()->get();
        if ($rooms->isEmpty()) {
            $this->showSetup = true;   // 아직 아무 방도 없으면 안내 + 토큰 발급부터
        }
        foreach ($rooms as $room) {   // 「나」가 이미 잡힌 방은 폼 초기값으로
            $this->meFor[$room->id] = (string) $room->pivot->my_name;
            $this->oursFor[$room->id] = array_values(array_diff($room->sideMembers()[KakaoRoom::SIDE_US] ?? [], [(string) $room->pivot->my_name]));
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
            'allRooms' => $user->kakaoRooms()->orderByPivot('selected', 'desc')->orderByPivot('last_seen_at', 'desc')->get(),   // 수집기가 보고한 방 전부(체크 여부 포함)
            'needSetup' => $board['rooms']->filter(fn ($r) => (trim((string) $r->pivot->my_name) === '' || ! $r->isSetUp()) && $r->messages()->exists())->values(),
            'senders' => $board['rooms']->mapWithKeys(fn ($r) => [$r->id => $r->senders()])->all(),
            'pendingCounts' => $board['rooms']->mapWithKeys(fn ($r) => [$r->id => $r->messages()->whereNull('type')->count()])->all(),
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

    // ───────── 방 설정 ─────────

    public function toggleSetup(): void
    {
        $this->showSetup = ! $this->showSetup;
        $this->tokenPlain = null;
    }

    /**
     * 방 설정 = 「나」 하나 + 「우리 회사」 체크 (jin 11-10 «재무: 최영숙 이걸 적으라고? 방이 수십개면 다 적어야해?»).
     * 편 이름은 우리/상대 둘로 고정, 상대는 나머지 보낸이 전부. 저장 즉시 쌓여 있던 미분류 메시지를 분류한다.
     */
    public function saveSetup(int $roomId, KakaoIngestService $ingest): void
    {
        $room = KakaoRoom::findOrFail($roomId);
        abort_unless(auth()->user()->kakaoRooms()->where('kakao_rooms.id', $room->id)->exists(), 403);
        $this->resetErrorBag('meFor.'.$roomId);
        $me = trim((string) ($this->meFor[$roomId] ?? ''));
        $senders = $room->senders();
        if ($me === '' || ! in_array($me, $senders, true)) {
            $this->addError('meFor.'.$roomId, __('kakao.setup_err_me'));

            return;
        }
        $ours = array_values(array_intersect((array) ($this->oursFor[$roomId] ?? []), $senders));
        $room->setUpSides($me, $ours);
        auth()->user()->kakaoRooms()->updateExistingPivot($room->id, ['my_name' => $me]);

        $read = $ingest->classifyPending($room, auth()->user());
        $n = $read?->status === 'done' ? $read->new_count : 0;
        $this->dispatch('notify', message: $n > 0 ? __('kakao.setup_saved', ['n' => $n]) : __('kakao.setup_saved_none'), type: 'success');
    }

    /** 「다시 고르기」 — 편 구성을 비워 설정 카드가 다시 뜨게 한다(메시지·판정은 그대로). */
    public function resetSetup(int $roomId): void
    {
        $room = KakaoRoom::findOrFail($roomId);
        abort_unless(auth()->user()->kakaoRooms()->where('kakao_rooms.id', $room->id)->exists(), 403);
        $room->forceFill(['sides' => null])->save();
        auth()->user()->kakaoRooms()->updateExistingPivot($room->id, ['my_name' => '']);
        $this->meFor[$roomId] = '';
        $this->oursFor[$roomId] = [];
    }

    /** 「읽기」 체크 — 수집기는 체크된 방만 읽는다. 체크를 풀어도 메시지·판정은 남는다. */
    public function toggleRoom(int $roomId): void
    {
        $pivot = auth()->user()->kakaoRooms()->where('kakao_rooms.id', $roomId)->first()?->pivot;
        abort_unless($pivot, 403);
        auth()->user()->kakaoRooms()->updateExistingPivot($roomId, ['selected' => ! $pivot->selected]);
    }

    public function unsubscribeRoom(int $roomId): void
    {
        auth()->user()->kakaoRooms()->detach($roomId);
        $this->dispatch('notify', message: __('kakao.room_unsubscribed'), type: 'success');
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

    {{-- 읽을 방 고르기 — 수집기가 보고한(열려 있던) 방 목록. 체크한 방만 읽고 보여 준다 (jin 11-10 «N개의 방을 추가 항목으로 넣어서 읽기») --}}
    @if($allRooms->isNotEmpty())
        <div class="card-tight mb-4" data-kakao-rooms>
            <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ __('kakao.rooms_pick_title') }}</span>
                <span class="ml-2 text-xs text-gray-400">{{ __('kakao.rooms_pick_hint') }}</span></div>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach($allRooms as $room)
                    @php($seen = $room->pivot->last_seen_at ? \Carbon\Carbon::parse($room->pivot->last_seen_at) : null)
                    <label class="flex h-9 cursor-pointer items-center gap-2 rounded border px-2 text-sm sm:h-8 {{ $room->pivot->selected ? 'border-violet-300 bg-violet-50 text-violet-900' : 'border-gray-200 text-gray-700' }}" wire:key="pick-{{ $room->id }}" data-room-pick="{{ $room->id }}">
                        <input type="checkbox" wire:click="toggleRoom({{ $room->id }})" @checked($room->pivot->selected) class="h-4 w-4">
                        <span class="truncate">{{ $room->name }}</span>
                        @if($seen && $seen->gt(now()->subHours(6))) <span class="badge badge-green">{{ __('kakao.room_open') }}</span> @endif
                        @if($room->pivot->selected && trim((string) $room->pivot->my_name) === '') <span class="badge badge-amber">{{ __('kakao.room_need_me') }}</span> @endif
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 처음 읽은 방 — 「나」 고르기 (설정 패널과 무관하게 항상 보인다. 이걸 안 하면 그 방은 분류가 시작되지 않는다) --}}
    @if($needSetup->isNotEmpty())
        <div class="card mb-4 border-violet-200" data-kakao-need-setup>
            <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ __('kakao.setup_needed_title', ['n' => $needSetup->count()]) }}</span></div>
            <p class="mt-1 text-xs text-gray-500">{{ __('kakao.setup_needed_hint') }}</p>
            @foreach($needSetup as $room)
                <div class="mt-3 rounded border border-gray-100 px-3 py-2" wire:key="setup-{{ $room->id }}" data-setup-room="{{ $room->id }}">
                    <div class="mb-2 text-sm font-medium text-gray-800">{{ $room->name }}
                        @if(($pendingCounts[$room->id] ?? 0) > 0) <span class="badge badge-gray ml-1">{{ __('kakao.room_pending', ['n' => $pendingCounts[$room->id]]) }}</span> @endif
                    </div>
                    @if(empty($senders[$room->id]))
                        <p class="text-xs text-gray-400">{{ __('kakao.setup_no_senders') }}</p>
                    @else
                        <div class="hidden text-[11px] text-gray-400 sm:grid sm:grid-cols-[1fr_4rem_5rem] sm:gap-2"><span></span><span class="text-center">{{ __('kakao.setup_me') }}</span><span class="text-center">{{ __('kakao.setup_ours') }}</span></div>
                        @foreach($senders[$room->id] as $name)
                            <label class="grid grid-cols-[1fr_4rem_5rem] items-center gap-2 rounded px-1 py-1 text-sm text-gray-700 hover:bg-gray-50" wire:key="setup-{{ $room->id }}-{{ md5($name) }}">
                                <span class="truncate">{{ $name }}</span>
                                <span class="flex h-9 items-center justify-center sm:h-7"><input type="radio" wire:model="meFor.{{ $room->id }}" value="{{ $name }}" class="h-4 w-4"></span>
                                <span class="flex h-9 items-center justify-center sm:h-7"><input type="checkbox" wire:model="oursFor.{{ $room->id }}" value="{{ $name }}" class="h-4 w-4"></span>
                            </label>
                        @endforeach
                        @error('meFor.'.$room->id) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="saveSetup({{ $room->id }})" class="btn-primary mt-2 h-9 px-3 text-sm sm:h-8">{{ __('kakao.setup_save') }}</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- 설정 (안내 · 내가 읽는 방 · 수집기 토큰) --}}
    @if($showSetup)
        <div class="mb-4 grid gap-4 lg:grid-cols-2" data-kakao-setup>
            <div class="card">
                <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ __('kakao.how_title') }}</span></div>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700">
                    <li>{{ __('kakao.how_1') }}</li>
                    <li>{{ __('kakao.how_2') }}</li>
                    <li>{{ __('kakao.how_3') }}</li>
                </ol>
                <hr class="section-divider my-3">
                <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ __('kakao.rooms_title') }}</span></div>
                @forelse($board['rooms'] as $room)
                    @php($sides = $room->sideMembers())
                    <div class="mt-2 rounded border border-gray-100 px-3 py-2 text-sm" wire:key="room-sub-{{ $room->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium text-gray-800">{{ $room->name }}</span>
                            <span class="flex gap-1">
                                <button type="button" wire:click="resetSetup({{ $room->id }})" class="h-9 rounded border border-gray-300 px-2 text-xs text-gray-700 sm:h-7">{{ __('kakao.room_change') }}</button>
                                <button type="button" wire:click="unsubscribeRoom({{ $room->id }})" class="h-9 rounded border border-gray-300 px-2 text-xs text-gray-700 sm:h-7">{{ __('kakao.room_unsubscribe') }}</button>
                            </span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            {{ __('kakao.room_my_name') }}: {{ $room->pivot->my_name ?: '—' }}
                            · {{ __('kakao.room_ours') }}: {{ implode(', ', $sides[\App\Models\KakaoRoom::SIDE_US] ?? []) ?: '—' }}
                            · {{ __('kakao.room_them') }}: {{ count($sides[\App\Models\KakaoRoom::SIDE_THEM] ?? []) }}
                        </div>
                    </div>
                @empty
                    <p class="mt-2 text-xs text-gray-400">{{ __('kakao.rooms_none') }}</p>
                @endforelse
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
