<?php

use App\Models\ApprovalRequest;
use App\Models\TaskAlarm;
use Livewire\Volt\Component;

new class extends Component
{
    /** 직전 폴링까지 본 최대 알람 id — 이 이후 새 알람이 생기면 토스트 1번. */
    public int $toastSeenId = 0;

    /** 📌 승인 대기 — 직전 폴링까지 본 최대 승인요청 id (jin 2026-11-19). 알람과 따로 센다(id 체계가 다르다). */
    public int $approvalSeenId = 0;

    public function mount(): void
    {
        $this->toastSeenId = $this->maxUnreadId();   // 기존 알람은 토스트 안 함(첫 로드 기준선)
        $this->approvalSeenId = (int) ($this->pendingApprovals()?->max('id') ?? 0);
    }

    /**
     * 📌 승인큐 대기 건 — **승인큐 화면·사이드바 뱃지와 같은 집합**(`actionable()` + pending). 승인권자(관리 이상)만.
     * 🚫 조건을 여기서 바꾸지 말 것 — 벨에는 있는데 큐에는 없는(또는 반대) 숫자가 된다(§8 #44, 08-01 뱃지 사고).
     */
    private function pendingApprovals()
    {
        $user = auth()->user();

        return $user && $user->canApprove()
            ? ApprovalRequest::actionable()->where('status', ApprovalRequest::STATUS_PENDING)
            : null;
    }

    private function maxUnreadId(): int
    {
        $user = auth()->user();
        if (! $user || ! $user->canSeeAlarms()) {
            return 0;
        }

        return (int) (TaskAlarm::query()->visibleTo($user)->unread()->max('id') ?? 0);
    }

    /**
     * 폴링 — 직전 본 id 이후 새 알람(board 도착·ETA 등)이 생겼으면 떴다 사라지는 토스트 1번.
     * 배지/벨(영구)은 별도 유지 — 토스트는 "지금 보고 있는 사람" 즉시 인지용 보조. (jin 2026-06-24)
     */
    public function poll(): void
    {
        $user = auth()->user();
        // 📌 새 승인 요청 — 보고 있는 승인권자에게 토스트 1번. 구역 자체는 결정될 때까지 벨에 남는다.
        if ($q = $this->pendingApprovals()) {
            $newIds = (clone $q)->where('id', '>', $this->approvalSeenId)->pluck('id');
            if ($newIds->isNotEmpty()) {
                $this->dispatch('notify', message: __('alarm.toast_approval', ['n' => $newIds->count()]), type: 'warning');
                $this->approvalSeenId = (int) $newIds->max();
            }
        }
        if (! $user || ! $user->canSeeAlarms()) {
            return;
        }
        $fresh = TaskAlarm::query()->visibleTo($user)->unread()
            ->where('id', '>', $this->toastSeenId)->get(['id', 'type']);
        if ($fresh->isEmpty()) {
            return;
        }
        $arrival = $fresh->where('type', 'purchase_arrival')->count();
        $msg = $arrival > 0
            ? __('alarm.toast_arrival', ['n' => $arrival])
            : __('alarm.toast_new', ['n' => $fresh->count()]);
        $this->dispatch('notify', message: $msg, type: 'info');
        $this->toastSeenId = (int) $fresh->max('id');
    }

    /** [확인] = 봤음 처리. canSeeAlarm 재인가(IDOR 차단) + confirmed_by 서버 지정. */
    public function confirm(int $id): void
    {
        $alarm = TaskAlarm::query()->open()->find($id);
        if (! $alarm) {
            return;
        }
        abort_unless((bool) auth()->user()?->canSeeAlarm($alarm), 403);
        $updates = ['confirmed_at' => now(), 'confirmed_by' => auth()->id()];
        // 매입 도착 알람 — 수동 [확인] = 해소(사라짐). jin 2026-06-23.
        if (in_array($alarm->type, ['purchase_arrival', TaskAlarm::TYPE_PRICE_CHANGED], true)) {   // 💱 판매가 수정도 [확인] = 해소
            $updates['resolved_at'] = now();
            $updates['resolved_reason'] = 'manual_confirm';
        }
        $alarm->update($updates);
    }

    /** 📌 승인 대기 구역 데이터 — 승인권자: 대기 전체(오래된 것부터 5건) / 그 외: 내가 ERP 에서 올린 대기 건. */
    private function approvalSection(): array
    {
        $user = auth()->user();
        $empty = ['approvals' => collect(), 'approvalCount' => 0, 'approvalMore' => 0, 'maxApprovalId' => 0, 'myRequests' => collect()];
        if (! $user) {
            return $empty;
        }
        if ($q = $this->pendingApprovals()) {
            $count = (clone $q)->count();
            $rows = (clone $q)->with('requester:id,name')->orderBy('created_at')->orderBy('id')->limit(5)->get();

            return ['approvals' => $rows, 'approvalCount' => $count, 'approvalMore' => max(0, $count - $rows->count()),
                'maxApprovalId' => (int) ((clone $q)->max('id') ?? 0), 'myRequests' => collect()];
        }
        // 승인권 없는 사람(수출통관·재무) — 자기가 올린 요청이 어떻게 됐는지만. 영업은 ERP 를 안 쓴다(board 에서 본다).
        $empty['myRequests'] = ApprovalRequest::actionable()->where('status', ApprovalRequest::STATUS_PENDING)
            ->where('requester_id', $user->id)->orderBy('created_at')->limit(5)->get();

        return $empty;
    }

    public function with(): array
    {
        $user = auth()->user();
        if (! $user || ! $user->canSeeAlarms()) {
            return ['alarms' => collect(), 'unreadCount' => 0, 'moreCount' => 0, 'maxUnreadId' => 0] + $this->approvalSection();
        }

        $base = TaskAlarm::query()->visibleTo($user)->unread();
        $unreadCount = (clone $base)->count();
        $maxUnreadId = (int) ((clone $base)->max('id') ?? 0);   // "새 알람" 판별 키 (id 증가)
        $alarms = $base->with('vehicle')->orderBy('due_date')->limit(5)->get();

        return [
            'alarms' => $alarms,
            'unreadCount' => $unreadCount,
            'maxUnreadId' => $maxUnreadId,
            'moreCount' => max(0, $unreadCount - $alarms->count()),
        ] + $this->approvalSection();
    }
}; ?>

<div wire:poll.30s="poll">
    @if ($unreadCount > 0 || $approvalCount > 0 || $myRequests->isNotEmpty())
        {{-- A동작: 진입 시 자동으로 뜨되, ✕로 닫으면 "새 알람(더 큰 id)이 올 때까지" 접힌 상태 유지.
             localStorage 에 마지막으로 본 maxId 저장 → 페이지 이동해도 다시 안 뜸. 새 알람(maxId↑) 오면 자동으로 다시 뜸. --}}
        <div data-max-id="{{ $maxUnreadId }}" data-max-approval-id="{{ $maxApprovalId }}"
             x-data="{
                open: false,
                init() {
                    const seen = parseInt(localStorage.getItem('alarmSeenId') || '0');
                    const seenApproval = parseInt(localStorage.getItem('approvalSeenId') || '0');
                    // 📱 폰(sm 미만)에서는 새 알람이 있어도 접힌 벨로 시작 — 330px 카드가 390px 화면의 절반을 덮는다
                    //    (jin 2026-10-13 A안). 건수 배지는 벨에 남는다. 데스크탑은 종전대로 자동으로 펼친다.
                    const wide = window.matchMedia('(min-width: 640px)').matches;
                    // 📌 새 승인 요청(더 큰 id)이 와도 다시 펼친다 — 접어 둔 뒤 온 요청을 놓치지 않게.
                    //    단 승인큐 화면에서는 승인 건 때문에 펼치지 않는다 — 그 화면이 곧 그 목록이고, 펼친 벨이 [승인]·[거부] 버튼을 가린다.
                    const onQueue = window.location.pathname.indexOf('/erp/approvals') === 0;
                    this.open = wide && (parseInt(this.$el.dataset.maxId) > seen || (! onQueue && parseInt(this.$el.dataset.maxApprovalId) > seenApproval));
                },
                dismiss() {
                    localStorage.setItem('alarmSeenId', this.$el.dataset.maxId);
                    localStorage.setItem('approvalSeenId', this.$el.dataset.maxApprovalId);
                    this.open = false;
                },
             }"
             class="fixed bottom-4 right-4 z-40 print:hidden">

            {{-- 접힌 상태: 폰(sm 미만)은 벨 아이콘 + 건수 배지만, sm 이상은 종전 「🔔 통관서류 알람 N」 pill (jin 2026-10-13). --}}
            <button x-show="!open" x-cloak @click="open = true" title="{{ __('alarm.bell_title') }}"
                    class="flex items-center gap-2 rounded-full bg-gray-700 px-3 py-2.5 text-sm font-semibold text-white shadow-xl hover:bg-gray-800 sm:px-4 {{ $approvalCount > 0 ? 'ring-2 ring-red-400' : '' }}">
                <span class="text-base leading-none sm:text-sm">🔔</span><span class="hidden sm:inline">{{ __('alarm.bell_title') }}</span>
                {{-- 📌 승인 대기는 일반 알람 숫자와 따로 — 「결정해야 할 것」과 「봐야 할 것」은 다르다(jin 2026-11-19). --}}
                @if ($approvalCount > 0)
                <span class="inline-flex h-[18px] items-center justify-center whitespace-nowrap rounded-full bg-red-500 px-1.5 text-[11px] font-bold" data-bell-approval-count>{{ __('alarm.bell_approval', ['n' => $approvalCount]) }}</span>
                @endif
                @if ($unreadCount > 0)
                <span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full {{ $approvalCount > 0 ? 'bg-gray-500' : 'bg-red-500' }} px-1 text-[11px] font-bold">{{ $unreadCount }}</span>
                @endif
            </button>

            {{-- 열린 상태: 카드 스택 (하드캡 5 + "외 N건") --}}
            <div x-show="open" class="w-[330px] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl">
                <div class="flex items-center justify-between bg-gray-700 px-3 py-2 text-sm font-semibold text-white">
                    <span>🔔 {{ __('alarm.bell_title') }}</span>
                    <span class="flex items-center gap-2">
                        <span class="text-[11px] font-medium text-gray-300">{{ $alarms->count() }}@if ($moreCount > 0) · {{ __('alarm.more_n', ['n' => $moreCount]) }}@endif</span>
                        <button @click="dismiss()" title="{{ __('alarm.collapse') }}" class="px-1 text-gray-300 hover:text-white">✕</button>
                    </span>
                </div>

                {{-- 세로 60vh → 80vh (jin 2026-11-19 «지금보다 1/3 은 더 커져도») --}}
                <div class="max-h-[80vh] overflow-y-auto">
                    {{-- 📌 승인 대기 — [확인]이 없다. 승인·거절돼야 사라진다. 누르면 승인큐로. --}}
                    @if ($approvalCount > 0)
                    <div class="border-b-2 border-red-200 bg-red-50" data-bell-approvals>
                        <div class="flex items-center justify-between px-3 pt-2 text-[11.5px] font-bold text-red-700">
                            <span>📌 {{ __('alarm.approval_title', ['n' => $approvalCount]) }}</span>
                            <span class="font-medium text-red-400">{{ __('alarm.approval_hint') }}</span>
                        </div>
                        @foreach ($approvals as $ar)
                            @php $mine = $ar->isRequestedBy(auth()->user()); @endphp
                            <a href="{{ route('erp.approvals.index') }}" wire:navigate wire:key="bell-approval-{{ $ar->id }}"
                               class="block border-l-[3px] px-3 py-2 {{ $loop->first ? '' : 'border-t border-red-100' }} {{ $mine ? 'border-l-gray-300 bg-white' : 'border-l-red-500 hover:bg-red-100' }}">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-[13px] font-bold {{ $mine ? 'text-gray-500' : 'text-gray-800' }}">{{ $ar->bellTitle() }}</span>
                                    <span class="shrink-0 whitespace-nowrap rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $mine ? 'bg-gray-100 text-gray-500' : 'bg-red-100 text-red-700' }}">{{ $mine ? __('alarm.approval_mine') : $ar->action_label }}</span>
                                </div>
                                <div class="mt-0.5 text-[12px] text-gray-600">
                                    {{ $ar->requester?->name ?? ($ar->payload['salesman_name'] ?? $ar->payload['requested_by_name'] ?? '-') }}@if ($ar->bellDetail() !== '') · <b class="text-red-700">{{ $ar->bellDetail() }}</b>@endif · {{ $ar->created_at->diffForHumans() }}
                                </div>
                                <div class="mt-0.5 text-[11.5px] font-semibold {{ $mine ? 'text-gray-400' : 'text-red-700' }}">{{ $mine ? __('alarm.approval_mine_hint') : __('alarm.approval_go') }}</div>
                            </a>
                        @endforeach
                        @if ($approvalMore > 0)
                            <a href="{{ route('erp.approvals.index') }}" wire:navigate class="block border-t border-red-100 py-1.5 text-center text-[12px] font-semibold text-red-700 hover:bg-red-100">{{ __('alarm.see_more', ['n' => $approvalMore]) }}</a>
                        @endif
                    </div>
                    @endif
                    {{-- 승인권 없는 사람 — 내가 올린 요청이 아직 대기 중인지 --}}
                    @if ($myRequests->isNotEmpty())
                    <div class="border-b-2 border-gray-200 bg-gray-50" data-bell-my-requests>
                        <div class="px-3 pt-2 text-[11.5px] font-bold text-gray-600">📤 {{ __('alarm.my_requests_title', ['n' => $myRequests->count()]) }}</div>
                        @foreach ($myRequests as $mr)
                            <div class="border-l-[3px] border-l-gray-400 px-3 py-2" wire:key="bell-mine-{{ $mr->id }}">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-[13px] font-bold text-gray-800">{{ $mr->bellTitle() }}</span>
                                    <span class="shrink-0 whitespace-nowrap rounded-full bg-amber-100 px-1.5 py-0.5 text-[11px] font-bold text-amber-800">{{ __('alarm.my_requests_waiting') }}</span>
                                </div>
                                <div class="mt-0.5 text-[12px] text-gray-600">{{ $mr->action_label }} · {{ $mr->created_at->diffForHumans() }}</div>
                            </div>
                        @endforeach
                    </div>
                    @endif
                    @if ($unreadCount > 0 && ($approvalCount > 0 || $myRequests->isNotEmpty()))
                        <div class="px-3 pt-2 text-[11.5px] font-bold text-gray-500">{{ __('alarm.section_alarms', ['n' => $unreadCount]) }}</div>
                    @endif
                    @foreach ($alarms as $a)
                        @php
                            $meta = $a->message_meta ?? [];
                            $type = $a->type ?? '';
                            $isShip = $type === 'shipping_requested';
                            $isArrival = $type === 'purchase_arrival';
                            $isDocDeadline = $type === 'document_deadline';
                            $isBalanceDue = $type === 'purchase_balance_due';
                            $isPrice = $type === \App\Models\TaskAlarm::TYPE_PRICE_CHANGED;   // 💱 판매가·환율 수정(jin 2026-11-18)
                            // board 요청·확인 신호 (2026-08-09) — 처리 자리는 차량관리 드로어다.
                            //   type 을 여기서 열거하지 말 것: 신호가 늘면 색·문구가 조용히 빠진다.
                            $boardMeta = \App\Models\BoardRequest::metaByAlarmType($type);
                            $isBoard = $boardMeta !== [];
                            $isBoardPurchase = $isBoard && ($boardMeta['color'] ?? '') !== 'purple';
                            $unpaid = $meta['unpaid_amount_krw'] ?? null;
                            $dday = $a->due_date ? (int) now()->startOfDay()->diffInDays($a->due_date->copy()->startOfDay(), false) : null;
                            $soon = ! $isPrice && ! $isShip && ! $isArrival && ! $isBoard && $dday !== null && $dday <= 3;
                            // board 신호도 차량관리 드로어로 연다 — 거기서 뱃지·금액을 함께 본다(jin 2026-08-09).
                            $href = route('erp.vehicles.index', ['openVehicle' => $a->vehicle_id]);
                        @endphp
                        <div class="border-b border-gray-100 border-l-[3px] px-3 py-2.5 hover:bg-amber-50/50 {{ $isBoard ? ($isBoardPurchase ? 'border-l-blue-500' : 'border-l-purple-500') : ($isPrice ? 'border-l-red-500' : ($isArrival ? 'border-l-blue-500' : ($isShip ? 'border-l-teal-500' : ($soon ? 'border-l-red-500' : 'border-l-amber-400')))) }}">
                            <a href="{{ $href }}" wire:navigate class="block">
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] font-bold text-gray-800">{{ $meta['vehicle_number'] ?? ('#'.$a->vehicle_id) }}</span>
                                    @if ($isPrice)
                                        <span class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[11px] font-bold text-rose-700">{{ __('alarm.badge_price') }}</span>
                                    @elseif ($isBoard)
                                        <span class="rounded-full {{ $isBoardPurchase ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }} px-1.5 py-0.5 text-[11px] font-bold">{{ __('alarm.badge_board') }}</span>
                                    @elseif ($isShip)
                                        <span class="rounded-full bg-teal-100 px-1.5 py-0.5 text-[11px] font-bold text-teal-700">{{ $meta['shipping_method'] ?? '' }}</span>
                                    @elseif ($isArrival)
                                        <span class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[11px] font-bold text-blue-700">{{ __('alarm.badge_new') }}</span>
                                    @elseif ($isBalanceDue && $dday !== null)
                                        <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $soon ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                            {{ $dday >= 0 ? __('alarm.balance_dday', ['d' => $dday]) : __('alarm.balance_overdue') }}
                                        </span>
                                    @elseif ($dday !== null)
                                        <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $soon ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                            {{ $dday >= 0 ? __('alarm.dday', ['d' => $dday]) : __('alarm.overdue') }}
                                        </span>
                                    @endif
                                </div>
                                @if ($isPrice)
                                <x-erp.price-change-lines :meta="$meta" :limit="2" class="mt-0.5" />
                                @else
                                <div class="mt-0.5 text-[12.5px] text-gray-600">{{ $isBoard ? __($boardMeta['task']) : ($isArrival ? __('alarm.task_arrival') : ($isShip ? __('alarm.task_shipping') : ($isDocDeadline ? __('alarm.task_document_deadline') : ($isBalanceDue ? __('alarm.task_balance_due') : __('alarm.task_clearance'))))) }}@if (! $isShip && ! $isArrival && ! $isBoard && $a->due_date) · {{ $a->due_date->format('m-d') }}@endif</div>
                                @endif
                            </a>
                            <div class="mt-1 flex items-center justify-between">
                                @if ($isPrice)
                                    <span class="text-[11.5px] font-semibold text-rose-700">{{ __('alarm.price_action') }}</span>
                                @elseif ($isBoard)
                                    <span class="text-[11.5px] font-semibold {{ $isBoardPurchase ? 'text-blue-700' : 'text-purple-700' }}">{{ __($boardMeta['action']) }}</span>
                                @elseif ($isShip)
                                    <span class="text-[11.5px] font-semibold text-teal-700">{{ __('alarm.task_shipping') }}</span>
                                @elseif ($isArrival)
                                    <span class="text-[11.5px] font-semibold text-blue-700">{{ __('alarm.arrival_action') }}</span>
                                @elseif ($isDocDeadline)
                                    <span class="text-[11.5px] font-semibold text-amber-700">{{ __('alarm.doc_deadline_action') }}</span>
                                @elseif ($isBalanceDue)
                                    <span class="text-[11.5px] font-semibold text-red-700">{{ $unpaid ? __('alarm.balance_due_action', ['amt' => number_format($unpaid)]) : __('alarm.balance_due_short') }}</span>
                                @else
                                    <span class="text-[11.5px] font-semibold {{ $unpaid ? 'text-red-700' : ($unpaid === 0 ? 'text-emerald-700' : 'text-gray-400') }}">
                                        @if ($unpaid)
                                            {{ __('alarm.unpaid', ['amt' => number_format($unpaid)]) }}
                                        @elseif ($unpaid === 0)
                                            {{ __('alarm.paid') }}
                                        @else
                                            {{ __('alarm.fx_missing') }}
                                        @endif
                                    </span>
                                @endif
                                <button wire:click="confirm({{ $a->id }})"
                                        class="rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700 hover:bg-violet-100">
                                    {{ __('alarm.confirm') }}
                                </button>
                            </div>
                        </div>
                    @endforeach

                    @if ($moreCount > 0)
                        <a href="{{ route('erp.alarms.index') }}" wire:navigate
                           class="block bg-violet-50/50 py-2 text-center text-[12px] font-semibold text-violet-700 hover:bg-violet-100">
                            {{ __('alarm.see_more', ['n' => $moreCount]) }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
