<?php

use App\Models\Port;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search   = '';
    public string $typeFilter = '';
    #[Url] public int $perPage = 30;

    public bool $showPanel = false;
    public ?int $editingId = null;

    public string $type      = 'loading';
    public string $name      = '';
    public string $code      = '';
    public bool   $is_active = true;
    // 선적대기 허용 항로 (jin 2026-07-18, item 2) — discharge(목적항)에만 의미. RORO 차량 C5(50%) 우회.
    public bool   $allow_shipping_wait = false;

    // 회의확장씬 2026-05-22 — [관리] 도 접근/편집 가능 (canManagePorts).
    // 라우트 'auth, verified' 만 — Volt mount 가드로 권한 검증.
    public function mount(): void
    {
        abort_unless(auth()->user()?->canManagePorts(), 403);
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 30;
        }
        $this->resetPage();
    }

    #[Computed]
    public function ports()
    {
        return Port::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate($this->perPage);
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
        $p = Port::findOrFail($id);
        $this->editingId = $id;
        $this->type      = $p->type;
        $this->name      = $p->name;
        $this->code      = $p->code ?? '';
        $this->is_active = $p->is_active;
        $this->allow_shipping_wait = $p->allow_shipping_wait;
        $this->showPanel = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        $this->validate([
            'type' => 'required|in:loading,unloading,discharge',
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50',
        ], [], [
            'type' => __('port.field.type'),
            'name' => __('port.field.name'),
            'code' => __('port.field.code'),
        ]);

        $data = [
            'type'      => $this->type,
            'name'      => $this->name,
            'code'      => $this->code ?: null,
            'is_active' => $this->is_active,
            // 선적대기 허용은 목적항(discharge)에만 유효 — 그 외 타입은 강제 false.
            'allow_shipping_wait' => $this->type === 'discharge' ? $this->allow_shipping_wait : false,
        ];

        if ($this->editingId) {
            Port::findOrFail($this->editingId)->update($data);
        } else {
            // (name, type) unique — 중복 시 validation 메시지
            $exists = Port::where('name', $this->name)->where('type', $this->type)->exists();
            if ($exists) {
                $this->addError('name', __('port.dup'));
                return;
            }
            Port::create($data);
        }

        unset($this->ports);
        $this->dispatch('notify', message: __('port.saved'), type: 'success');
        $this->close();
    }

    public function toggleActive(int $id): void
    {
        $p = Port::findOrFail($id);
        $p->update(['is_active' => ! $p->is_active]);
        unset($this->ports);
        $this->dispatch('notify', message: $p->is_active ? __('port.activated') : __('port.deactivated'), type: 'success');
    }

    public function search(): void
    {
        $this->resetPage();
    }

    private function resetForm(): void
    {
        $this->type = 'loading';
        $this->name = $this->code = '';
        $this->is_active = true;
        $this->allow_shipping_wait = false;
    }
}; ?>

<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('port.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('port.subtitle', ['count' => $this->ports->total()]) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
            <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('port.create_btn') }}
        </button>
    </div>
</div>

{{-- 필터 --}}
<div class="card-tight flex flex-wrap items-center gap-3">
    <input wire:model.live.debounce.400ms="search" type="text" placeholder="{{ __('port.search_ph') }}"
           class="input-base w-full sm:w-60" />
    <select wire:model.live="typeFilter" class="input-base w-full sm:w-auto">
        <option value="">{{ __('port.all_types') }}</option>
        @foreach(\App\Models\Port::TYPES as $key => $label)
        <option value="{{ $key }}">{{ __('port.type.'.$key) }}</option>
        @endforeach
    </select>
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">{{ __('port.col.type') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('port.col.name') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('port.col.code') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.status') }}</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->ports as $p)
            @php
                $typeBadge = match($p->type) {
                    'loading' => 'badge-blue', 'unloading' => 'badge-purple', 'discharge' => 'badge-amber',
                };
            @endphp
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $p->id }})">
                <td class="py-3 pr-4">
                    <span class="badge {{ $typeBadge }}">{{ __('port.type.'.$p->type) }}</span>
                </td>
                <td class="py-3 pr-4 font-medium text-gray-800">
                    {{ $p->name }}
                    @if($p->allow_shipping_wait)<span class="badge badge-amber ml-1 text-[10px]">{{ __('port.badge.shipping_wait') }}</span>@endif
                </td>
                <td class="py-3 pr-4 text-gray-500 font-mono text-xs">{{ $p->code ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $p->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $p->is_active ? __('common.active') : __('common.inactive') }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="toggleActive({{ $p->id }})"
                            class="text-xs text-violet-600 hover:underline">
                        {{ $p->is_active ? __('port.deactivate') : __('port.activate') }}
                    </button>
                </td>
            </tr>
            @empty
            <tr><td colspan="5" class="py-12 text-center text-sm text-gray-400">{{ __('port.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->ports as $p)
    @php
        $typeBadge = match($p->type) {
            'loading' => 'badge-blue', 'unloading' => 'badge-purple', 'discharge' => 'badge-amber',
        };
    @endphp
    <div class="card-tight" wire:click="openEdit({{ $p->id }})">
        <div class="flex items-center justify-between">
            <div>
                <div class="font-medium text-gray-800">{{ $p->name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ $p->code ?? '' }}</div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge {{ $typeBadge }}">{{ __('port.type.'.$p->type) }}</span>
                <span class="badge {{ $p->is_active ? 'badge-green' : 'badge-gray' }}">{{ $p->is_active ? __('common.active') : __('common.inactive') }}</span>
            </div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('port.empty') }}</div>
    @endforelse
</div>

<div>{{ $this->ports->links() }}</div>

</div>

{{-- 슬라이드 패널 --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]">

    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('port.edit_title') : __('port.create_title') }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-4">
        <div>
            <label class="label-base">{{ __('port.field.type') }} <span class="text-red-500">*</span></label>
            <select wire:model.live="type" class="input-base">
                @foreach(\App\Models\Port::TYPES as $key => $label)
                <option value="{{ $key }}">{{ __('port.type.'.$key) }}</option>
                @endforeach
            </select>
            @error('type')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-base">{{ __('port.field.name') }} <span class="text-red-500">*</span></label>
            <input wire:model="name" type="text" class="input-base" placeholder="{{ __('port.field.name_ph') }}" />
            @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-base">{{ __('port.field.code') }} <span class="text-xs text-gray-400">{{ __('common.optional') }}</span></label>
            <input wire:model="code" type="text" class="input-base font-mono" placeholder="{{ __('port.field.code_ph') }}" />
            <p class="mt-1 text-[11px] text-gray-400">{{ __('port.field.code_note') }}</p>
            @error('code')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model="is_active" type="checkbox" class="rounded" /> {{ __('port.field.active_note') }}
            </label>
        </div>
        {{-- 선적대기 허용 (jin 2026-07-18, item 2) — 목적항(discharge)에만 노출 --}}
        @if($type === 'discharge')
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
            <label class="flex items-center gap-2 text-sm font-medium text-amber-800 cursor-pointer">
                <input wire:model="allow_shipping_wait" type="checkbox" class="rounded" /> {{ __('port.field.allow_shipping_wait') }}
            </label>
            <p class="mt-1 text-[11px] text-amber-600">{{ __('port.field.allow_shipping_wait_note') }}</p>
        </div>
        @endif
    </div>

    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button wire:click="close" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
        </button>
    </div>

</div>
@endif

</div>
