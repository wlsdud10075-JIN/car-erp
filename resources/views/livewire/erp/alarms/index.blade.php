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
        $alarm->update(['confirmed_at' => now(), 'confirmed_by' => auth()->id()]);
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
            ->with('vehicle')
            ->orderByRaw('confirmed_at IS NOT NULL')   // 미확인 먼저
            ->orderBy('due_date')
            ->get();

        // 데이터 보정 — 선적했는데 ETA 없는 차량 (관리는 본인 팀만).
        $cq = \App\Models\Vehicle::query()->whereNull('deleted_at')->action('eta_missing')->with('salesman');
        if (! $user->isAdmin() && $user->role === '관리') {
            $cq->whereIn('salesman_id', $user->getSubordinateSalesmanIds());
        }
        $correctionVehicles = $cq->orderBy('shipping_date')->limit(50)->get();

        return ['alarms' => $alarms, 'correctionVehicles' => $correctionVehicles];
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

    @if ($alarms->isEmpty())
        <div class="card text-center text-sm text-gray-400">{{ __('alarm.empty') }}</div>
    @else
        {{-- 데스크탑: 테이블 --}}
        <div class="card hidden sm:block">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                        <th class="px-2 py-2">{{ __('alarm.col_vehicle') }}</th>
                        <th class="px-2 py-2">{{ __('alarm.col_eta') }}</th>
                        <th class="px-2 py-2">{{ __('alarm.col_dday') }}</th>
                        <th class="px-2 py-2">{{ __('alarm.col_unpaid') }}</th>
                        <th class="px-2 py-2">{{ __('alarm.col_status') }}</th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($alarms as $a)
                        @php
                            $meta = $a->message_meta ?? [];
                            $unpaid = $meta['unpaid_amount_krw'] ?? null;
                            $dday = $a->due_date ? (int) now()->startOfDay()->diffInDays($a->due_date->copy()->startOfDay(), false) : null;
                            $soon = $dday !== null && $dday <= 3;
                        @endphp
                        <tr class="border-b border-gray-50 hover:bg-gray-50">
                            <td class="px-2 py-2.5">
                                <a href="{{ route('erp.vehicles.index', ['openVehicle' => $a->vehicle_id]) }}" wire:navigate class="font-bold text-gray-800 hover:text-violet-700">
                                    {{ $meta['vehicle_number'] ?? ('#'.$a->vehicle_id) }}
                                </a>
                            </td>
                            <td class="px-2 py-2.5 tabular-nums text-gray-600">{{ $a->due_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-2 py-2.5">
                                @if ($dday !== null)
                                    <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $soon ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                        {{ $dday >= 0 ? __('alarm.dday', ['d' => $dday]) : __('alarm.overdue') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-2 py-2.5 text-[12.5px] font-semibold {{ $unpaid ? 'text-red-700' : ($unpaid === 0 ? 'text-emerald-700' : 'text-gray-400') }}">
                                @if ($unpaid){{ __('alarm.unpaid', ['amt' => number_format($unpaid)]) }}@elseif ($unpaid === 0){{ __('alarm.paid') }}@else{{ __('alarm.fx_missing') }}@endif
                            </td>
                            <td class="px-2 py-2.5">
                                @if ($a->confirmed_at)
                                    <span class="badge badge-gray">{{ __('alarm.status_confirmed') }}</span>
                                @else
                                    <span class="badge badge-amber">{{ __('alarm.status_unread') }}</span>
                                @endif
                            </td>
                            <td class="px-2 py-2.5 text-right">
                                @unless ($a->confirmed_at)
                                    <button wire:click="confirm({{ $a->id }})" class="rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700 hover:bg-violet-100">
                                        {{ __('alarm.confirm') }}
                                    </button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- 모바일: 카드 --}}
        <div class="space-y-2 sm:hidden">
            @foreach ($alarms as $a)
                @php
                    $meta = $a->message_meta ?? [];
                    $unpaid = $meta['unpaid_amount_krw'] ?? null;
                    $dday = $a->due_date ? (int) now()->startOfDay()->diffInDays($a->due_date->copy()->startOfDay(), false) : null;
                    $soon = $dday !== null && $dday <= 3;
                @endphp
                <div class="card-sm">
                    <div class="flex items-center justify-between">
                        <a href="{{ route('erp.vehicles.index', ['openVehicle' => $a->vehicle_id]) }}" wire:navigate class="font-bold text-gray-800">
                            {{ $meta['vehicle_number'] ?? ('#'.$a->vehicle_id) }}
                        </a>
                        @if ($dday !== null)
                            <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $soon ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                {{ $dday >= 0 ? __('alarm.dday', ['d' => $dday]) : __('alarm.overdue') }}
                            </span>
                        @endif
                    </div>
                    <div class="mt-1 flex items-center justify-between text-xs">
                        <span class="text-gray-500">{{ $a->due_date?->format('Y-m-d') ?? '—' }}</span>
                        <span class="font-semibold {{ $unpaid ? 'text-red-700' : ($unpaid === 0 ? 'text-emerald-700' : 'text-gray-400') }}">
                            @if ($unpaid){{ __('alarm.unpaid', ['amt' => number_format($unpaid)]) }}@elseif ($unpaid === 0){{ __('alarm.paid') }}@else{{ __('alarm.fx_missing') }}@endif
                        </span>
                    </div>
                    @unless ($a->confirmed_at)
                        <button wire:click="confirm({{ $a->id }})" class="mt-2 w-full rounded-md border border-violet-200 bg-violet-50 py-1.5 text-[12px] font-semibold text-violet-700">
                            {{ __('alarm.confirm') }}
                        </button>
                    @endunless
                </div>
            @endforeach
        </div>
    @endif
</div>
