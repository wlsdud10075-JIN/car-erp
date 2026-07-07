<?php

use App\Models\ForwardingCompany;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    // item 7 (jin 2026-07-07) — 마스터 CRUD → "포워딩사별 선적 현황" 개편.
    //   기간(선적일/BL발행일) 필터 + 운임비 통화별 합산 + 선박명/컨테이너/수출신고번호 검색.
    //   CRUD(추가/편집/삭제)는 슬라이드 패널로 보존.
    public string $search = '';
    #[Url] public string $dateType = 'shipping';   // shipping = shipping_date / bl = bl_issue_date
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';

    public bool $showPanel = false;
    public ?int $editingId = null;

    // CRUD 폼
    public string $name         = '';
    public string $contact_name = '';
    public string $email        = '';
    public string $phone        = '';
    public string $address      = '';
    public string $memo         = '';
    public bool   $is_active    = true;

    public function searchNow(): void
    {
        unset($this->shipments, $this->companies);
    }

    /** 필터된 선적 차량 → forwarding_company_id 별 그룹. 통화별 합산·목록의 단일 출처. */
    #[Computed]
    public function shipments()
    {
        $col = $this->dateType === 'bl' ? 'bl_issue_date' : 'shipping_date';
        $term = trim($this->search);

        return Vehicle::query()
            ->whereNotNull('forwarding_company_id')
            ->whereNull('deleted_at')
            ->when($this->dateFrom !== '', fn ($q) => $q->where($col, '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where($col, '<=', $this->dateTo))
            ->when($term !== '', fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$term}%")
                ->orWhere('vessel_name', 'like', "%{$term}%")
                ->orWhere('container_number', 'like', "%{$term}%")
                ->orWhere('export_declaration_number', 'like', "%{$term}%")))
            ->orderByDesc($col)
            ->get(['id', 'forwarding_company_id', 'vehicle_number', 'shipping_date', 'bl_issue_date',
                'vessel_name', 'container_number', 'export_declaration_number', 'transport_fee', 'currency'])
            ->groupBy('forwarding_company_id');
    }

    /** 표시할 포워딩사 — 차량 필터(검색/기간)가 있으면 매칭 선적이 있는 곳만. 없으면 전체. */
    #[Computed]
    public function companies()
    {
        $companies = ForwardingCompany::query()->orderBy('name')->get();
        $hasVehicleFilter = $this->search !== '' || $this->dateFrom !== '' || $this->dateTo !== '';

        if ($hasVehicleFilter) {
            $shipments = $this->shipments;

            return $companies->filter(fn ($fc) => $shipments->has($fc->id))->values();
        }

        return $companies;
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $fc = ForwardingCompany::findOrFail($id);
        $this->editingId    = $id;
        $this->name         = $fc->name;
        $this->contact_name = $fc->contact_name ?? '';
        $this->email        = $fc->email        ?? '';
        $this->phone        = $fc->phone        ?? '';
        $this->address      = $fc->address      ?? '';
        $this->memo         = $fc->memo         ?? '';
        $this->is_active    = $fc->is_active;
        $this->showPanel    = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        $this->validate(['name' => 'required|string|max:100']);

        $data = [
            'name'         => $this->name,
            'contact_name' => $this->contact_name ?: null,
            'email'        => $this->email        ?: null,
            'phone'        => $this->phone        ?: null,
            'address'      => $this->address      ?: null,
            'memo'         => $this->memo         ?: null,
            'is_active'    => $this->is_active,
        ];

        if ($this->editingId) {
            ForwardingCompany::findOrFail($this->editingId)->update($data);
        } else {
            ForwardingCompany::create($data);
        }

        unset($this->companies);
        $this->close();
        session()->flash('success', __('forwarding.saved'));
    }

    public function delete(int $id): void
    {
        ForwardingCompany::findOrFail($id)->delete();
        unset($this->companies, $this->shipments);
        session()->flash('success', __('forwarding.deleted'));
    }

    private function resetForm(): void
    {
        $this->name = $this->contact_name = $this->email = $this->phone = $this->address = $this->memo = '';
        $this->is_active = true;
    }
}; ?>

<div wire:poll.60s>
@if(session('success'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,3000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('success') }}
</div>
@endif

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('forwarding.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('common.total', ['count' => $this->companies->count()]) }}</p>
    </div>
    <button wire:click="openCreate" class="btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        {{ __('forwarding.add') }}
    </button>
</div>

{{-- 필터바: 기간 + 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <select wire:model.live="dateType" class="input-filter">
        <option value="shipping">{{ __('forwarding.date_shipping') }}</option>
        <option value="bl">{{ __('forwarding.date_bl') }}</option>
    </select>
    <input wire:model.live="dateFrom" type="text" data-date class="input-filter w-32" placeholder="{{ __('forwarding.date_from') }}" />
    <span class="text-gray-400 text-sm">~</span>
    <input wire:model.live="dateTo" type="text" data-date class="input-filter w-32" placeholder="{{ __('forwarding.date_to') }}" />
    <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('forwarding.search_ph') }}"
           class="input-filter w-64" />
    <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
</div>

{{-- 포워딩사별 선적 현황 카드 --}}
<div class="space-y-2">
    @forelse($this->companies as $fc)
        @php
            $ships = $this->shipments[$fc->id] ?? collect();
            $feeByCurrency = $ships->groupBy('currency')->map(fn ($g) => (int) $g->sum('transport_fee'))->filter();
            $dcol = $dateType === 'bl' ? 'bl_issue_date' : 'shipping_date';
        @endphp
        <div class="card-tight" x-data="{ open: false }">
            {{-- 헤더 행 --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <button type="button" @click="open = !open" class="flex flex-1 items-center gap-2 text-left">
                    <svg class="h-4 w-4 text-gray-400 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    <span class="font-semibold text-gray-800">{{ $fc->name }}</span>
                    <span class="badge {{ $fc->is_active ? 'badge-green' : 'badge-gray' }}">{{ $fc->is_active ? __('common.active') : __('common.inactive') }}</span>
                    <span class="pill-count">{{ __('forwarding.shipment_count', ['count' => $ships->count()]) }}</span>
                </button>
                {{-- 운임비 통화별 합계 --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    @forelse($feeByCurrency as $cur => $sum)
                        <span class="badge badge-blue">{{ $cur }} {{ number_format($sum) }}</span>
                    @empty
                        <span class="text-xs text-gray-400">{{ __('forwarding.no_fee') }}</span>
                    @endforelse
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="openEdit({{ $fc->id }})" class="text-xs text-gray-500 hover:text-violet-700">{{ __('common.edit') }}</button>
                    <button wire:click="delete({{ $fc->id }})" wire:confirm="{{ __('forwarding.delete_confirm', ['name' => $fc->name]) }}" class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                </div>
            </div>
            {{-- 선적 차량 목록 (아코디언) --}}
            <div x-show="open" x-cloak class="mt-3 overflow-x-auto border-t border-gray-100 pt-3">
                @if($ships->isEmpty())
                    <p class="py-3 text-center text-xs text-gray-400">{{ __('forwarding.no_shipment') }}</p>
                @else
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-[11px] text-gray-500">
                                <th class="pb-1.5 pr-3 font-medium">{{ __('vehicle.col.number') }}</th>
                                <th class="pb-1.5 pr-3 font-medium">{{ __('forwarding.col_ship_date') }}</th>
                                <th class="pb-1.5 pr-3 font-medium">{{ __('vehicle.field.vessel') }}</th>
                                <th class="pb-1.5 pr-3 font-medium">{{ __('vehicle.col.container_number') }}</th>
                                <th class="pb-1.5 pr-3 font-medium">{{ __('vehicle.col.export_declaration_number') }}</th>
                                <th class="pb-1.5 pr-3 text-right font-medium">{{ __('forwarding.col_transport_fee') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($ships as $v)
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 pr-3 font-medium text-gray-700"><a href="{{ route('erp.vehicles.index', ['openVehicle' => $v->id]) }}" wire:navigate class="hover:text-violet-700">{{ $v->vehicle_number }}</a></td>
                                <td class="py-2 pr-3 text-gray-500">{{ $v->$dcol?->format('Y-m-d') ?? '-' }}</td>
                                <td class="py-2 pr-3 text-gray-500">{{ $v->vessel_name ?: '-' }}</td>
                                <td class="py-2 pr-3 font-mono text-gray-600">{{ $v->container_number ?: '-' }}</td>
                                <td class="py-2 pr-3 font-mono text-gray-600">{{ $v->export_declaration_number ?: '-' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-gray-700">{{ $v->transport_fee ? $v->currency.' '.number_format($v->transport_fee) : '-' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('forwarding.empty') }}</div>
    @endforelse
</div>

</div>

{{-- ══ 슬라이드 패널 (CRUD 보존) ══ --}}
@if($showPanel)
<div x-data="{
    dirty: false,
    confirmOpen: false,
    attemptClose() {
        if (this.confirmOpen) { this.confirmOpen = false; return; }
        if (this.dirty) { this.confirmOpen = true; } else { $wire.close(); }
    },
    confirmDiscard() { this.confirmOpen = false; $wire.close(); },
}" @keyup.escape.window="attemptClose()">
<div class="fixed inset-0 z-40 bg-black/40" @click="attemptClose()"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]"
     @input="dirty = true" @change="dirty = true">

    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('forwarding.panel_edit') : __('forwarding.add') }}</h2>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-3">
        <div>
            <label class="label-base">{{ __('forwarding.field_name') }} <span class="text-red-500">*</span></label>
            <input wire:model="name" type="text" class="input-base" placeholder="SSANCAR LOGISTICS" />
            @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label-base">{{ __('forwarding.field_contact') }}</label>
                <input wire:model="contact_name" type="text" class="input-base" />
            </div>
            <div>
                <label class="label-base">{{ __('common.phone') }}</label>
                <input wire:model="phone" type="text" class="input-base" />
            </div>
        </div>
        <div>
            <label class="label-base">{{ __('common.email') }}</label>
            <input wire:model="email" type="email" class="input-base" />
        </div>
        <div>
            <label class="label-base">{{ __('common.address') }}</label>
            <input wire:model="address" type="text" class="input-base" />
        </div>
        <div>
            <label class="label-base">{{ __('common.memo') }}</label>
            <textarea wire:model="memo" class="input-base" rows="2"></textarea>
        </div>
        <div>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model="is_active" type="checkbox" class="rounded" /> {{ __('common.active') }}
            </label>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
        </button>
    </div>

</div>

<div x-show="confirmOpen" x-cloak x-transition.opacity
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     @click.self="confirmOpen = false">
    <div class="card max-w-sm mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">{{ __('common.unsaved_title') }}</h3>
        <p class="mt-2 text-sm text-gray-600">{{ __('common.unsaved_body') }}</p>
        <div class="mt-5 flex justify-end gap-2">
            <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button @click="confirmDiscard()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">{{ __('common.close') }}</button>
        </div>
    </div>
</div>

</div>
@endif
</div>
