<?php

use App\Models\TaskAlarm;
use Livewire\Volt\Component;

new class extends Component
{
    /** [확인] = 봤음 처리. canSeeAlarm 재인가(IDOR 차단) + confirmed_by 서버 지정. */
    public function confirm(int $id): void
    {
        $alarm = TaskAlarm::query()->open()->find($id);
        if (! $alarm) {
            return;
        }
        abort_unless((bool) auth()->user()?->canSeeAlarm($alarm), 403);
        $alarm->update(['confirmed_at' => now(), 'confirmed_by' => auth()->id()]);
    }

    public function with(): array
    {
        $user = auth()->user();
        if (! $user || ! $user->canAccessClearance()) {
            return ['alarms' => collect(), 'unreadCount' => 0, 'moreCount' => 0];
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
        ];
    }
}; ?>

<div wire:poll.60s>
    @if ($unreadCount > 0)
        {{-- A동작: 진입 시 자동으로 뜨되, ✕로 닫으면 "새 알람(더 큰 id)이 올 때까지" 접힌 상태 유지.
             localStorage 에 마지막으로 본 maxId 저장 → 페이지 이동해도 다시 안 뜸. 새 알람(maxId↑) 오면 자동으로 다시 뜸. --}}
        <div data-max-id="{{ $maxUnreadId }}"
             x-data="{
                open: false,
                init() {
                    const seen = parseInt(localStorage.getItem('alarmSeenId') || '0');
                    this.open = parseInt(this.$el.dataset.maxId) > seen;
                },
                dismiss() {
                    localStorage.setItem('alarmSeenId', this.$el.dataset.maxId);
                    this.open = false;
                },
             }"
             class="fixed bottom-4 right-4 z-40 print:hidden">

            {{-- 접힌 상태: 벨 pill --}}
            <button x-show="!open" x-cloak @click="open = true"
                    class="flex items-center gap-2 rounded-full bg-gray-700 px-4 py-2.5 text-sm font-semibold text-white shadow-xl hover:bg-gray-800">
                🔔 {{ __('alarm.bell_title') }}
                <span class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-bold">{{ $unreadCount }}</span>
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

                <div class="max-h-[60vh] overflow-y-auto">
                    @foreach ($alarms as $a)
                        @php
                            $meta = $a->message_meta ?? [];
                            $unpaid = $meta['unpaid_amount_krw'] ?? null;
                            $dday = $a->due_date ? (int) now()->startOfDay()->diffInDays($a->due_date->copy()->startOfDay(), false) : null;
                            $soon = $dday !== null && $dday <= 3;
                        @endphp
                        <div class="border-b border-gray-100 border-l-[3px] px-3 py-2.5 hover:bg-amber-50/50 {{ $soon ? 'border-l-red-500' : 'border-l-amber-400' }}">
                            <a href="{{ route('erp.vehicles.index', ['openVehicle' => $a->vehicle_id]) }}" wire:navigate class="block">
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] font-bold text-gray-800">{{ $meta['vehicle_number'] ?? ('#'.$a->vehicle_id) }}</span>
                                    @if ($dday !== null)
                                        <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $soon ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                            {{ $dday >= 0 ? __('alarm.dday', ['d' => $dday]) : __('alarm.overdue') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-0.5 text-[12.5px] text-gray-600">{{ __('alarm.task_clearance') }}@if ($a->due_date) · {{ $a->due_date->format('m-d') }}@endif</div>
                            </a>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="text-[11.5px] font-semibold {{ $unpaid ? 'text-red-700' : ($unpaid === 0 ? 'text-emerald-700' : 'text-gray-400') }}">
                                    @if ($unpaid)
                                        {{ __('alarm.unpaid', ['amt' => number_format($unpaid)]) }}
                                    @elseif ($unpaid === 0)
                                        {{ __('alarm.paid') }}
                                    @else
                                        {{ __('alarm.fx_missing') }}
                                    @endif
                                </span>
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
