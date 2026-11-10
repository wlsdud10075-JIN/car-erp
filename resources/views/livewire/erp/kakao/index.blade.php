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

    /** @var array<int, string> 에이전트가 방금 알려 준 「지금 열려 있는 방」 제목 — 페이지 JS 가 reportOpenRooms 로 넣는다(§11, 폴링 없음) */
    public array $openRooms = [];

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
        $this->autoSetUpRooms();
    }

    /**
     * 「나」가 아직 없는 방은 ERP 명부로 자동으로 잡는다(jin 11-10 «왜 클릭을 하고 나눠야 하는데?») — 잡히면 쌓인 메시지를 바로 분류.
     * mount 와 reportOpenRooms(에이전트가 방을 처음 붙인 직후) 둘 다 부른다 — 11-10 실사고: 페이지를 열 때는 방이 아직 안 붙어 있어 mount 가 건너뛰고,
     * 그 뒤 붙은 방은 「나」 고르기 카드가 떴는데 우리 회사 미리 체크도 비어 있어 수출팀 방이 「나 혼자 우리」로 저장됐다.
     */
    private function autoSetUpRooms(): void
    {
        $user = auth()->user();
        foreach ($user->kakaoRooms()->get() as $room) {
            if (trim((string) $room->pivot->my_name) === '' && $room->autoSetUpFor($user)) {
                $room->refresh();
                $room->pivot->my_name = (string) $user->kakaoRooms()->where('kakao_rooms.id', $room->id)->first()?->pivot->my_name;
                app(KakaoIngestService::class)->classifyPending($room, $user);   // 잡힌 그 순간 한 번만 — 매 페이지 로드마다 돌리면 「확인 필요」(분류 실패분)를 매번 다시 과금한다
            }
            $this->meFor[$room->id] = (string) $room->pivot->my_name;   // 폼 초기값(못 잡은 방은 빈칸 — 「이 방에서 나는 누구?」)
            if (trim((string) $room->pivot->my_name) === '' && ! $room->isSetUp()) {   // 못 잡은 방: 우리 회사 후보는 명부와 맞는 사람으로 미리 체크
                $senders = $room->senders();
                $this->oursFor[$room->id] = array_values(array_unique(array_filter(array_map(fn ($n) => KakaoRoom::matchName($n, $senders), KakaoRoom::rosterNames($user)))));
            } else {
                $this->oursFor[$room->id] = array_values(array_diff($room->sideMembers()[KakaoRoom::SIDE_US] ?? [], [(string) $room->pivot->my_name]));
            }
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
            'devices' => $this->showSetup ? $user->kakaoCollectorTokens()->whereNull('revoked_at')->orderByDesc('id')->get() : collect(),
            // §11 페이지 JS 가 127.0.0.1 에이전트를 부를 때 쓰는 것 — 내 활성 기기의 local_secret(본인 세션에만 내려간다)·기대 버전·포트·체크된 방
            'agent' => [
                'port' => (int) config('services.kakao_agent.port', 47321),
                'version' => (string) config('services.kakao_agent.version', '1.0.0'),
                'secrets' => $user->kakaoCollectorTokens()->whereNull('revoked_at')->whereNotNull('local_secret')->orderByDesc('id')
                    ->get(['device_name', 'local_secret'])->map(fn ($t) => ['device' => (string) $t->device_name, 'secret' => (string) $t->local_secret])->values()->all(),
                'selected' => $user->kakaoRooms()->wherePivot('selected', true)->pluck('name')->values()->all(),
            ],
            'types' => KakaoMessage::TYPES,
        ];
    }

    /**
     * §11 — 에이전트가 알려 준 「지금 열려 있는 방」(페이지 JS → 여기). API 의 KakaoRoomsController::report 와 같은 일:
     * 방 자동 등록 + 이 사용자에게 last_seen_at. 체크는 사람이 한다(toggleRoom). 폴링 없음 — 페이지를 열 때와 [지금 읽기] 뒤에만.
     *
     * @param  array<int, string>  $names
     */
    public function reportOpenRooms(array $names): void
    {
        $user = auth()->user();
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => mb_substr(trim((string) $n), 0, 120), $names), fn ($n) => $n !== '')));
        $names = array_slice($names, 0, 200);
        foreach ($names as $name) {
            $room = KakaoRoom::firstOrCreate(['name' => $name]);
            if ($room->users()->where('users.id', $user->id)->exists()) {
                $room->users()->updateExistingPivot($user->id, ['last_seen_at' => now()]);
            } else {
                $room->users()->attach($user->id, ['my_name' => '', 'selected' => false, 'last_seen_at' => now()]);
            }
        }
        $this->openRooms = $names;
        $this->autoSetUpRooms();   // 방금 붙은 방의 「나」·「우리 회사」를 바로 잡는다(mount 때는 방이 아직 없었다)
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

    // ───────── 연결된 PC (§11 — 발급은 「이 PC 연결하기」 .bat 다운로드가 한다, KakaoInstallerController) ─────────

    public function revokeToken(int $tokenId): void
    {
        auth()->user()->kakaoCollectorTokens()->whereKey($tokenId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
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
        <div class="flex flex-wrap items-center gap-2">
            {{-- §11 이 PC 의 에이전트 상태 — 페이지 JS 가 127.0.0.1 /ping 결과로 채운다(폴링 없음) --}}
            <span class="text-xs text-gray-500" data-kakao-agent-status data-state="checking" wire:ignore>{{ __('kakao.agent_checking') }}</span>
            {{-- 에이전트는 떠 있는데 다른 계정(또는 폐기된 토큰)에 묶여 있을 때 — .bat 없이 지금 계정으로 다시 묶는다 --}}
            <button type="button" class="hidden h-9 rounded border border-violet-300 px-2 text-xs text-violet-800 hover:bg-violet-50 sm:h-8" data-kakao-rebind wire:ignore>{{ __('kakao.agent_rebind') }}</button>
            <button type="button" class="btn-primary h-9 px-3 text-sm disabled:opacity-40 sm:h-8" data-kakao-read disabled wire:ignore>{{ __('kakao.read_now') }}</button>
            <button type="button" wire:click="toggleSetup" class="tab-pill h-9 px-3 text-sm sm:h-8">{{ $showSetup ? __('kakao.setup_close') : __('kakao.setup') }}</button>
        </div>
    </div>

    {{-- §11 읽기 진행 줄 — [지금 읽기] 동안 에이전트 /progress 를 보여 주고 끝나면 화면을 새로 그린다 --}}
    <div class="card-tight mb-4 hidden whitespace-pre-line font-mono text-xs text-gray-700" data-kakao-progress wire:ignore></div>

    {{-- 돌아가는 동안 화면을 덮는 창 (jin 11-10 «뭔가 하는 중이라는 걸 모달로 — 멈춘 줄 알고 다른 걸 눌렀다») — 읽기·이 글 열기. 페이지 JS 가 열고 닫는다 --}}
    <div class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" data-kakao-overlay wire:ignore role="dialog" aria-modal="true">
        <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
            <div class="flex items-center gap-3">
                <span class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-violet-300" style="border-top-color:#4c3fb1"></span>
                <span class="text-sm font-semibold text-gray-800" data-kakao-overlay-title></span>
            </div>
            <p class="mt-2 text-xs text-gray-500" data-kakao-overlay-hint></p>
            <div class="mt-3 max-h-56 overflow-y-auto whitespace-pre-line rounded bg-gray-50 p-2 font-mono text-xs text-gray-700" data-kakao-overlay-lines></div>
        </div>
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
                    <label class="flex h-9 cursor-pointer items-center gap-2 rounded border px-2 text-sm sm:h-8 {{ $room->pivot->selected ? 'border-violet-300 bg-violet-50 text-violet-900' : 'border-gray-200 text-gray-700' }}" wire:key="pick-{{ $room->id }}" data-room-pick="{{ $room->id }}" data-room-name="{{ $room->name }}" data-room-selected="{{ $room->pivot->selected ? 1 : 0 }}">
                        <input type="checkbox" wire:click="toggleRoom({{ $room->id }})" @checked($room->pivot->selected) class="h-4 w-4">
                        <span class="truncate" title="{{ $room->name }}">{{ $room->name }}</span>
                        @if(in_array($room->name, $openRooms, true) || ($openRooms === [] && $seen && $seen->gt(now()->subHours(6)))) <span class="badge badge-green">{{ __('kakao.room_open') }}</span> @endif
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
                        {{-- jin 11-10 «왜 클릭을 하고 나눠야 하는데?» — 보통은 ERP 명부로 자동으로 잡혀 이 카드가 안 뜬다. 여기 온 건 카톡 이름이 ERP 이름과 안 맞는 방뿐:
                             「이 방에서 나는 누구?」 한 칸만 고른다. 우리 회사 사람은 명부와 맞는 이름이 미리 체크돼 있다(고칠 일 거의 없음). --}}
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <label for="me-{{ $room->id }}" class="text-gray-700">{{ __('kakao.setup_me_q', ['name' => auth()->user()->name]) }}</label>
                            <select id="me-{{ $room->id }}" wire:model="meFor.{{ $room->id }}" class="input-base h-9 w-full text-sm sm:h-8 sm:w-56">
                                <option value="">{{ __('kakao.setup_me_pick') }}</option>
                                @foreach($senders[$room->id] as $name)
                                    <option value="{{ $name }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <details class="mt-2 text-xs text-gray-600">
                            <summary class="cursor-pointer">{{ __('kakao.setup_ours_summary', ['n' => count($oursFor[$room->id] ?? [])]) }}</summary>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @foreach($senders[$room->id] as $name)
                                    <label class="flex h-9 cursor-pointer items-center gap-1 rounded border border-gray-200 px-2 sm:h-7" wire:key="setup-{{ $room->id }}-{{ md5($name) }}">
                                        <input type="checkbox" wire:model="oursFor.{{ $room->id }}" value="{{ $name }}" class="h-4 w-4" aria-label="{{ $name }} {{ __('kakao.setup_ours') }}"><span class="truncate" title="{{ $name }}">{{ $name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                        @error('meFor.'.$room->id) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="saveSetup({{ $room->id }})" class="btn-primary mt-2 h-9 px-3 text-sm sm:h-8">{{ __('kakao.setup_save') }}</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- 설정 (안내 · 내가 읽는 방 · 이 PC 연결·연결된 PC) --}}
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

            <div class="card" data-kakao-connect>
                <div class="section-header"><span class="section-dot bg-teal-500"></span><span class="section-title">{{ __('kakao.token_title') }}</span></div>
                <p class="mt-1 text-xs text-gray-500">{{ __('kakao.token_hint') }}</p>
                {{-- §11 「이 PC 연결하기」 = 1회용 설치 .bat 다운로드(토큰·local_secret 포함). 세션 POST — JS 없이도 된다 --}}
                <form method="POST" action="{{ route('erp.kakao.installer') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="btn-primary h-9 px-3 text-sm sm:h-8">{{ __('kakao.token_connect') }}</button>
                </form>
                <p class="mt-2 text-xs text-gray-500">{{ __('kakao.token_connect_hint') }}</p>
                <hr class="section-divider my-3">
                <div class="section-header"><span class="section-dot bg-teal-500"></span><span class="section-title">{{ __('kakao.devices_title') }}</span></div>
                <ul class="mt-2 space-y-1 text-xs text-gray-700">
                    @forelse($devices as $t)
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded border border-gray-100 px-2 py-1" wire:key="tok-{{ $t->id }}" data-kakao-device="{{ $t->id }}">
                            <span>{{ $t->device_name ?: __('kakao.device_unnamed') }} · {{ __('kakao.token_last_used') }} {{ $t->last_used_at?->format('m/d H:i') ?? __('kakao.token_never') }}</span>
                            <button type="button" wire:click="revokeToken({{ $t->id }})" class="h-9 rounded border border-red-200 px-2 text-xs text-red-700 sm:h-7">{{ __('kakao.token_revoke') }}</button>
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
                                    <span>{{ __('kakao.from_to', ['from' => $r['sender'].(! empty($r['sender_role']) ? '('.$r['sender_role'].')' : ''), 'to' => $r['to'] ?: '—']) }}</span>
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
                                {{-- 「이 글 열기」 = 카톡에서 그 방을 열고 이 메시지 위치로(에이전트 /focus). 보이는 버튼은 3개까지 — 주 버튼·미루기·이 글 열기 (jin 11-10 «⋯에 숨기지 말고») --}}
                                <button type="button" data-kakao-focus="{{ $r['room'] }}" data-kakao-snippet="{{ mb_substr(\App\Services\Kakao\KakaoExportParser::firstLine($r['body']), 0, 40) }}" class="h-9 rounded border border-amber-300 bg-amber-50 px-2 text-xs text-amber-900 sm:h-7">{{ __('kakao.btn_open_kakao') }}</button>
                                <div class="relative">
                                    <button type="button" class="h-9 rounded border border-gray-300 px-2 text-xs text-gray-700 sm:h-7" @click="menu = (menu === {{ $r['id'] }} ? null : {{ $r['id'] }})" aria-label="{{ __('kakao.btn_more') }}">⋯</button>
                                    <div x-show="menu === {{ $r['id'] }}" x-cloak @click.outside="menu = null" class="absolute right-0 z-20 mt-1 w-40 rounded border border-gray-200 bg-white py-1 text-xs text-gray-700 shadow-lg">
                                        @if($r['vehicle_id'])
                                            <a href="{{ route('erp.vehicles.index', ['openVehicle' => $r['vehicle_id']]) }}" wire:navigate @click="menu = null" class="block w-full px-3 py-2 text-left text-blue-700 hover:bg-gray-50">{{ __('kakao.btn_open_vehicle') }}</a>
                                        @endif
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

    {{-- §11 「사용 화면은 ERP 하나」 — 이 PC 의 창 없는 에이전트(http://127.0.0.1:47321)를 브라우저가 직접 부른다. 폴링 없음(§1 #3):
         페이지를 열 때 /ping → /rooms → reportOpenRooms, [지금 읽기] 때 /read → /progress(끝날 때까지만) → 화면 새로 그리기.
         비밀값 = 내 활성 기기의 local_secret(본인 세션에만 내려온다). 상태 줄·버튼·진행 칸은 wire:ignore 라 Livewire 가 되돌리지 않는다. --}}
    @script
    <script>
    (() => {
        const cfg = @js($agent);
        const rebindUrl = @js(route('erp.kakao.rebind'));
        const csrf = @js(csrf_token());
        const t = @js([
            'connected' => __('kakao.agent_connected'), 'missing' => __('kakao.agent_missing'), 'outdated' => __('kakao.agent_outdated'),
            'unauthorized' => __('kakao.agent_unauthorized'), 'blocked' => __('kakao.agent_blocked'),
            'none' => __('kakao.read_none_checked'), 'started' => __('kakao.read_started'), 'failed' => __('kakao.read_failed'),
            'rebinding' => __('kakao.agent_rebinding'), 'rebind_failed' => __('kakao.agent_rebind_failed'), 'focus_failed' => __('kakao.focus_failed'), 'focus_denied' => __('kakao.focus_denied'),
            'reading' => __('kakao.overlay_reading'), 'reading_hint' => __('kakao.overlay_reading_hint'), 'opening' => __('kakao.overlay_opening'), 'opening_hint' => __('kakao.overlay_opening_hint'),
        ]);
        const root = $wire.$el;
        const base = 'http://127.0.0.1:' + cfg.port;
        const el = (sel) => root.querySelector(sel);
        const fmt = (s, vars) => String(s).replace(/:(\w+)/g, (m, k) => (k in vars ? vars[k] : m));
        let secret = null, lastOpen = [], polling = null;

        const setStatus = (state, text) => {
            const s = el('[data-kakao-agent-status]');
            if (s) { s.dataset.state = state; s.textContent = text; }
            const b = el('[data-kakao-read]');
            if (b) b.disabled = state !== 'connected';
            const rb = el('[data-kakao-rebind]');   // 에이전트는 떠 있는데 내 계정이 아닐 때만 「내 계정으로 연결」
            if (rb) rb.classList.toggle('hidden', state !== 'unauthorized');
        };
        const rebind = async () => {   // ERP 가 새 토큰을 내주고 → 페이지가 127.0.0.1 에이전트에 심는다(.bat 재실행과 같은 효과, 사용자 전환·토큰 폐기 뒤)
            setStatus('checking', t.rebinding);
            try {
                const r = await fetch(rebindUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, credentials: 'same-origin' });
                if (!r.ok) throw new Error('ERP ' + r.status);
                const body = await r.json();
                const a = await agent('/rebind', { method: 'POST', body }, false);
                if (a.status !== 200 || !a.body.ok) throw new Error(a.body.erp_message || a.body.message || ('agent ' + a.status));
                cfg.secrets = [{ device: a.body.device, secret: body.local_secret }, ...(cfg.secrets || [])];
                if (await ping()) { await loadRooms(); await $wire.$refresh(); }
            } catch (e) { setStatus('unauthorized', fmt(t.rebind_failed, { message: String(e.message || e) })); }
        };
        const toast = (message, type = 'warning') => window.dispatchEvent(new CustomEvent('notify', { detail: { message, type } }));   // 공통 토스트(사이드바 @notify.window) — 행 옆에서 눌렀으니 페이지 위 진행 칸은 안 보인다
        const focusRoom = async (room, snippet) => {   // 방을 열고(닫혀 있으면 메인 창 검색) 그 메시지 위치로(방 검색칸에 포커스가 있을 때만 키를 친다)
            let r;
            showProgress([]);
            overlay(true, fmt(t.opening, { room }), t.opening_hint);
            try { r = await agent('/focus', { method: 'POST', body: { room, snippet: snippet || '' } }); } catch (e) { overlay(false); toast(fmt(t.focus_failed, { message: String(e) })); return; }
            overlay(false);
            if (r.status !== 200 || !r.body.ok) toast(r.body.message || fmt(t.focus_failed, { message: r.status === 200 ? t.focus_denied : 'HTTP ' + r.status }));
            else if (snippet && r.body.jumped === false) toast(r.body.message || t.focus_denied);
        };
        const agent = async (path, opts = {}, withSecret = true) => {
            const headers = { Accept: 'application/json' };
            if (opts.body) headers['Content-Type'] = 'application/json';
            if (withSecret && secret) headers['X-Kakao-Secret'] = secret;
            const r = await fetch(base + path, { method: opts.method || 'GET', headers, body: opts.body ? JSON.stringify(opts.body) : undefined, mode: 'cors', credentials: 'omit', cache: 'no-store' });
            return { status: r.status, body: await r.json().catch(() => ({})) };
        };
        const pickSecret = async (device) => {   // 기기 이름이 맞는 비밀값부터, 안 맞으면 내 기기 전부 한 번씩(이 PC 가 어느 행인지 모를 때)
            const list = cfg.secrets || [];
            const first = list.find((s) => s.device && s.device === device);
            for (const s of first ? [first, ...list.filter((x) => x !== first)] : list) {
                secret = s.secret;
                if ((await agent('/progress')).status === 200) return true;
            }
            secret = null;
            return false;
        };
        const ping = async () => {
            let r;
            try { r = await agent('/ping', {}, false); } catch (e) { setStatus('missing', (cfg.secrets || []).length ? t.missing + ' ' + t.blocked : t.missing); return false; }
            if (r.status !== 200 || !r.body.ok) { setStatus('missing', t.missing); return false; }
            const p = r.body;
            if (p.version !== cfg.version) { setStatus('outdated', fmt(t.outdated, { version: p.version })); return false; }
            if (p.erp_state === 'unauthorized' || p.erp_state === 'unconfigured' || !(await pickSecret(p.device))) { setStatus('unauthorized', t.unauthorized); return false; }
            setStatus('connected', fmt(t.connected, { device: p.device, version: p.version }));
            if (p.busy) startPolling();
            return true;
        };
        const loadRooms = async () => {
            const r = await agent('/rooms');
            if (r.status !== 200) return;
            lastOpen = r.body.rooms || [];
            await $wire.reportOpenRooms(lastOpen);
        };
        // 체크된 방 전부 — 닫힌 방은 에이전트가 카톡 메인 창 검색으로 열고 읽는다(jin 11-10). 열린 방을 먼저 읽게 순서만 앞으로
        const checkedOpenRooms = () => [...root.querySelectorAll('[data-room-pick]')]
            .filter((l) => l.querySelector('input')?.checked).map((l) => l.dataset.roomName)
            .sort((a, b) => (lastOpen.includes(b) ? 1 : 0) - (lastOpen.includes(a) ? 1 : 0));
        const showProgress = (lines, show = true) => {
            const box = el('[data-kakao-progress]');
            if (box) { box.classList.toggle('hidden', !show); box.textContent = lines.join('\n'); }
            const ol = el('[data-kakao-overlay-lines]');
            if (ol) { ol.textContent = lines.join('\n'); ol.scrollTop = ol.scrollHeight; }
        };
        const overlay = (open, title = '', hint = '') => {   // 돌아가는 동안 화면을 덮는다 — 다른 걸 누르거나 「멈췄나」 오해하지 않게
            const o = el('[data-kakao-overlay]');
            if (!o) return;
            o.classList.toggle('hidden', !open);
            o.classList.toggle('flex', open);
            if (open) { el('[data-kakao-overlay-title]').textContent = title; el('[data-kakao-overlay-hint]').textContent = hint; }
        };
        const startPolling = () => {
            if (polling) return;
            const b = el('[data-kakao-read]');
            if (b) b.disabled = true;
            overlay(true, t.reading, t.reading_hint);
            polling = setInterval(async () => {
                let r;
                try { r = await agent('/progress'); } catch (e) { return; }
                if (r.status !== 200) return;
                showProgress(r.body.lines || []);
                if (r.body.done) {
                    clearInterval(polling); polling = null;
                    if (b) b.disabled = false;
                    overlay(false);
                    await $wire.$refresh();
                    await loadRooms();
                }
            }, 1500);
        };
        const readNow = async () => {
            await loadRooms();   // 방금 닫은 방·새로 연 방을 반영
            const rooms = checkedOpenRooms();
            if (!rooms.length) { showProgress([t.none]); return; }
            showProgress([t.started]);
            overlay(true, t.reading, t.reading_hint);
            let r;
            try { r = await agent('/read', { method: 'POST', body: { rooms } }); } catch (e) { overlay(false); showProgress([fmt(t.failed, { message: String(e) })]); return; }
            if (r.status !== 202) { overlay(false); showProgress([fmt(t.failed, { message: r.body.message || ('HTTP ' + r.status) })]); return; }
            startPolling();
        };

        root.addEventListener('click', (e) => {
            if (e.target.closest('[data-kakao-read]')) readNow();
            else if (e.target.closest('[data-kakao-rebind]')) rebind();
            else { const f = e.target.closest('[data-kakao-focus]'); if (f) focusRoom(f.dataset.kakaoFocus, f.dataset.kakaoSnippet); }
        });
        ping().then((ok) => { if (ok) loadRooms(); });
    })();
    </script>
    @endscript
</div>
