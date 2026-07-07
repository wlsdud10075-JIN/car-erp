<?php

use App\Models\TaskAlarm;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);
    }

    public function confirm(int $id): void
    {
        $alarm = TaskAlarm::query()->open()->find($id);
        if (! $alarm) {
            return;
        }
        abort_unless((bool) auth()->user()?->canSeeAlarm($alarm), 403);
        $updates = ['confirmed_at' => now(), 'confirmed_by' => auth()->id()];
        // 매입 도착 알람 — 수동 [확인] = 해소(사라짐). jin 2026-06-23.
        if ($alarm->type === 'purchase_arrival') {
            $updates['resolved_at'] = now();
            $updates['resolved_reason'] = 'manual_confirm';
        }
        $alarm->update($updates);
    }

    /** 데이터 보정 — ETA 없는 차량에 도착일 인라인 입력. canScopeVehicle 재인가. */
    public function setEta(int $vehicleId, ?string $date): void
    {
        $user = auth()->user();
        $v = \App\Models\Vehicle::find($vehicleId);
        if (! $v) {
            return;
        }
        abort_unless((bool) $user?->canScopeVehicle($v), 403);

        if (! $date) {
            $this->dispatch('notify', message: __('alarm.eta_required'), type: 'warning');

            return;
        }
        try {
            $eta = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $date);
            if (! $eta) {
                throw new \InvalidArgumentException('bad date');
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('alarm.eta_invalid'), type: 'error');

            return;
        }

        $v->update(['eta_date' => $eta->toDateString()]);
        $this->dispatch('notify', message: __('alarm.eta_saved', ['v' => $v->vehicle_number]), type: 'success');
    }

    public function with(): array
    {
        $user = auth()->user();

        $alarms = TaskAlarm::query()
            ->visibleTo($user)
            ->open()
            ->with(['vehicle.buyer', 'vehicle.exportBuyer'])   // 바이어명은 message_meta 아닌 관계로(개인정보 whitelist 보존)
            ->orderByRaw('confirmed_at IS NOT NULL')   // 미확인 먼저
            ->orderBy('due_date')
            ->get();

        // board 현지확인(지역별 accordion) 응용 — 바이어별 그룹 + 클릭 펼침.
        //   통관 바이어(export) 우선, 없으면 판매 바이어. 미지정은 한 그룹.
        $buyerGroups = $alarms
            ->groupBy(fn ($a) => ($b = $a->vehicle?->exportBuyer ?: $a->vehicle?->buyer)?->id
                ? 'b:'.$b->id : 'unassigned')
            ->map(function ($items) {
                $v = $items->first()->vehicle;
                $b = $v?->exportBuyer ?: $v?->buyer;

                return [
                    'name' => $b?->name ?? __('alarm.buyer_unassigned'),
                    'count' => $items->count(),
                    'unread' => $items->whereNull('confirmed_at')->count(),
                    'items' => $items,
                ];
            })
            ->sortByDesc('unread')   // 미확인 많은 바이어 위로
            ->values();

        // 데이터 보정 — 선적했는데 ETA 없는 차량 (관리는 본인 팀만).
        $cq = \App\Models\Vehicle::query()->whereNull('deleted_at')->action('eta_missing')->with('salesman');
        if (! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
            $cq->whereIn('salesman_id', $user->getSubordinateSalesmanIds());
        }
        $correctionVehicles = $cq->orderBy('shipping_date')->limit(50)->get();

        return ['buyerGroups' => $buyerGroups, 'correctionVehicles' => $correctionVehicles];
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">🔔 {{ __('alarm.inbox_title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('alarm.inbox_sub') }}</p>
    </div>

    {{-- 데이터 보정 — 선적했는데 도착일(ETA) 없음. 바로 입력 → 채우면 통관서류 알람 자동 예약. --}}
    @if ($correctionVehicles->isNotEmpty())
        <div class="card mb-4">
            <div class="mb-1 flex items-center gap-2">
                <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                <h2 class="text-sm font-bold text-gray-700">{{ __('alarm.correction_title') }}</h2>
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800">{{ $correctionVehicles->count() }}</span>
            </div>
            <p class="mb-3 ml-4 text-xs text-gray-500">{{ __('alarm.correction_sub') }}</p>
            <div class="divide-y divide-gray-50">
                @foreach ($correctionVehicles as $cv)
                    <div class="flex flex-wrap items-center gap-2 py-2" wire:key="corr-{{ $cv->id }}">
                        <a href="{{ route('erp.vehicles.index', ['openVehicle' => $cv->id]) }}" wire:navigate class="w-24 font-bold text-gray-800 hover:text-violet-700">{{ $cv->vehicle_number }}</a>
                        <span class="text-xs text-gray-400">ETD {{ $cv->shipping_date?->format('Y-m-d') ?? '—' }}</span>
                        <span class="text-xs text-gray-500">{{ $cv->salesman?->name ?? '—' }}</span>
                        <div class="ml-auto flex items-center gap-2" x-data="{ d: '' }">
                            <input type="date" x-model="d" class="input-base !w-auto !py-1 text-xs">
                            <button @click="if(d){ $wire.setEta({{ $cv->id }}, d); d=''; }"
                                    class="rounded-md bg-violet-600 px-3 py-1 text-xs font-semibold text-white hover:bg-violet-700">{{ __('alarm.eta_save') }}</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 통관서류 알람 — board 현지확인(지역별) 응용: 바이어별 그룹 + 클릭 시 펼침(accordion). --}}
    @if ($buyerGroups->isEmpty())
        <div class="card text-center text-sm text-gray-400">{{ __('alarm.empty') }}</div>
    @else
        @foreach ($buyerGroups as $g)
            <div class="card mb-3" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-bold text-gray-800">🧑 {{ $g['name'] }}</span>
                        <span class="text-xs text-gray-400">· {{ $g['count'] }}{{ __('dashboard.unit_count') }}</span>
                        @if ($g['unread'] > 0)
                            <span class="badge badge-amber">{{ __('alarm.status_unread') }} {{ $g['unread'] }}</span>
                        @else
                            <span class="badge badge-gray">{{ __('alarm.all_seen') }}</span>
                        @endif
                    </span>
                    <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 flex-shrink-0 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="open" x-transition x-cloak class="mt-3 divide-y divide-gray-50">
                    @foreach ($g['items'] as $a)
                        @php
                            $meta = $a->message_meta ?? [];
                            $type = $a->type ?? '';
                            $isShip = $type === 'shipping_requested';
                            $isArrival = $type === 'purchase_arrival';
                            $isDocDeadline = $type === 'document_deadline';
                            $unpaid = $meta['unpaid_amount_krw'] ?? null;
                            $dday = $a->due_date ? (int) now()->startOfDay()->diffInDays($a->due_date->copy()->startOfDay(), false) : null;
                            $soon = ! $isShip && ! $isArrival && $dday !== null && $dday <= 3;
                        @endphp
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <a href="{{ route('erp.vehicles.index', ['openVehicle' => $a->vehicle_id]) }}" wire:navigate class="w-24 font-bold text-gray-800 hover:text-violet-700">
                                {{ $meta['vehicle_number'] ?? ('#'.$a->vehicle_id) }}
                            </a>
                            <span class="text-xs tabular-nums text-gray-400">{{ $a->due_date?->format('Y-m-d') ?? '—' }}</span>
                            @if ($isShip)
                                <span class="rounded-full bg-teal-100 px-1.5 py-0.5 text-[11px] font-bold text-teal-700">{{ __('alarm.task_shipping') }} {{ $meta['shipping_method'] ?? '' }}</span>
                            @elseif ($isArrival)
                                <span class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[11px] font-bold text-blue-700">{{ __('alarm.badge_new') }}</span>
                                <span class="text-[12px] font-semibold text-blue-700">{{ __('alarm.arrival_action') }}</span>
                            @elseif ($isDocDeadline)
                                @if ($dday !== null)
                                    <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $soon ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                        {{ $dday >= 0 ? __('alarm.dday', ['d' => $dday]) : __('alarm.overdue') }}
                                    </span>
                                @endif
                                <span class="text-[12px] font-semibold text-amber-700">{{ __('alarm.doc_deadline_action') }}</span>
                            @else
                                @if ($dday !== null)
                                    <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $soon ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                        {{ $dday >= 0 ? __('alarm.dday', ['d' => $dday]) : __('alarm.overdue') }}
                                    </span>
                                @endif
                                <span class="text-[12px] font-semibold {{ $unpaid ? 'text-red-700' : ($unpaid === 0 ? 'text-emerald-700' : 'text-gray-400') }}">
                                    @if ($unpaid){{ __('alarm.unpaid', ['amt' => number_format($unpaid)]) }}@elseif ($unpaid === 0){{ __('alarm.paid') }}@else{{ __('alarm.fx_missing') }}@endif
                                </span>
                            @endif
                            <div class="ml-auto">
                                @if ($a->confirmed_at)
                                    <span class="badge badge-gray">{{ __('alarm.status_confirmed') }}</span>
                                @else
                                    <button wire:click="confirm({{ $a->id }})" class="rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700 hover:bg-violet-100">
                                        {{ __('alarm.confirm') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>
