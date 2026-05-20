<?php

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search    = '';
    public string $buyerFilter = '';
    #[Url] public int $perPage = 10;

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public bool  $showPanel = false;
    public ?int  $editingId = null;

    public string $name           = '';
    public string $buyer_id_str   = '';
    public string $country_id_str = '';
    public string $contact_name   = '';
    public string $contact_email  = '';
    public string $contact_phone  = '';
    public string $address        = '';
    public string $memo           = '';
    public bool   $is_active      = true;

    #[Computed]
    public function consignees()
    {
        return Consignee::query()
            ->with(['buyer', 'country'])
            ->when($this->search, fn($q) => $q->where(fn($q2) =>
                $q2->where('name', 'like', "%{$this->search}%")
                   ->orWhere('contact_email', 'like', "%{$this->search}%")
            ))
            ->when($this->buyerFilter, fn($q) => $q->where('buyer_id', $this->buyerFilter))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function buyers()
    {
        return Buyer::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function countries()
    {
        return Country::orderBy('name')->get();
    }

    public function search(): void
    {
        $this->resetPage();
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
        $c = Consignee::findOrFail($id);
        $this->editingId      = $id;
        $this->name           = $c->name;
        $this->buyer_id_str   = $c->buyer_id   ? (string)$c->buyer_id   : '';
        $this->country_id_str = $c->country_id ? (string)$c->country_id : '';
        $this->contact_name   = $c->contact_name  ?? '';
        $this->contact_email  = $c->contact_email ?? '';
        $this->contact_phone  = $c->contact_phone ?? '';
        $this->address        = $c->address       ?? '';
        $this->memo           = $c->memo          ?? '';
        $this->is_active      = $c->is_active;
        $this->showPanel      = true;
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
            'name'          => $this->name,
            'buyer_id'      => $this->buyer_id_str   !== '' ? (int)$this->buyer_id_str   : null,
            'country_id'    => $this->country_id_str !== '' ? (int)$this->country_id_str : null,
            'contact_name'  => $this->contact_name  ?: null,
            'contact_email' => $this->contact_email ?: null,
            'contact_phone' => $this->contact_phone ?: null,
            'address'       => $this->address       ?: null,
            'memo'          => $this->memo           ?: null,
            'is_active'     => $this->is_active,
        ];

        if ($this->editingId) {
            Consignee::findOrFail($this->editingId)->update($data);
        } else {
            Consignee::create($data);
        }

        unset($this->consignees);
        $this->close();
        session()->flash('success', '컨사이니가 저장됐습니다.');
    }

    public function delete(int $id): void
    {
        Consignee::findOrFail($id)->delete();
        unset($this->consignees);
        session()->flash('success', '컨사이니가 삭제됐습니다.');
    }

    private function resetForm(): void
    {
        $this->name = $this->buyer_id_str = $this->country_id_str = $this->contact_name
            = $this->contact_email = $this->contact_phone = $this->address = $this->memo = '';
        $this->is_active = true;
    }
}; ?>

<div wire:poll.30s>
@if(session('success'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,3000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('success') }}
</div>
@endif

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">컨사이니 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->consignees->total() }}개</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">10개씩</option>
            <option value="30">30개씩</option>
            <option value="50">50개씩</option>
            <option value="100">100개씩</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            컨사이니 등록
        </button>
    </div>
</div>

<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="이름 · 이메일"
           class="input-filter w-52" />
    <select wire:model="buyerFilter" class="input-filter">
        <option value="">전체 바이어</option>
        @foreach($this->buyers as $b)
        <option value="{{ $b->id }}">{{ $b->name }}</option>
        @endforeach
    </select>
    <button wire:click="search" class="btn-search">조회</button>
</div>

<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">컨사이니명</th>
                <th class="pb-2 pr-4 font-medium">바이어</th>
                <th class="pb-2 pr-4 font-medium">국가</th>
                <th class="pb-2 pr-4 font-medium">이메일</th>
                <th class="pb-2 pr-4 font-medium">상태</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->consignees as $c)
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $c->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $c->name }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $c->buyer?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $c->country?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $c->contact_email ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $c->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $c->is_active ? '활성' : '비활성' }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $c->id }})"
                            wire:confirm="{{ $c->name }} 컨사이니를 삭제하시겠습니까?"
                            class="text-xs text-red-400 hover:text-red-600">삭제</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="py-12 text-center text-sm text-gray-400">컨사이니가 없습니다.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="block sm:hidden space-y-2">
    @forelse($this->consignees as $c)
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $c->id }})">
        <div>
            <div class="font-medium text-gray-800">{{ $c->name }}</div>
            <div class="text-xs text-gray-500">{{ $c->buyer?->name ?? '' }}{{ $c->country ? ' · '.$c->country->name : '' }}</div>
        </div>
        <span class="badge {{ $c->is_active ? 'badge-green' : 'badge-gray' }}">{{ $c->is_active ? '활성' : '비활성' }}</span>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">컨사이니가 없습니다.</div>
    @endforelse
</div>

<div>{{ $this->consignees->links() }}</div>

</div>

@if($showPanel)
{{-- 큐 18: close confirm — dirty 추적 + .card 모달 (forwarding과 동일 패턴) --}}
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
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[500px]"
     @input="dirty = true" @change="dirty = true">

    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? '컨사이니 수정' : '컨사이니 등록' }}</h2>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-3">
        <div>
            <label class="label-base">컨사이니명 <span class="text-red-500">*</span></label>
            <input wire:model="name" type="text" class="input-base" />
            @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-base">바이어</label>
            <select wire:model="buyer_id_str" class="input-base">
                <option value="">-- 선택 --</option>
                @foreach($this->buyers as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label-base">국가</label>
            <select wire:model="country_id_str" class="input-base">
                <option value="">-- 선택 --</option>
                @foreach($this->countries as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label-base">담당자</label>
                <input wire:model="contact_name" type="text" class="input-base" />
            </div>
            <div>
                <label class="label-base">전화</label>
                <input wire:model="contact_phone" type="text" class="input-base" />
            </div>
        </div>
        <div>
            <label class="label-base">이메일</label>
            <input wire:model="contact_email" type="email" class="input-base" />
        </div>
        <div>
            <label class="label-base">주소</label>
            <input wire:model="address" type="text" class="input-base" />
        </div>
        <div>
            <label class="label-base">메모</label>
            <textarea wire:model="memo" class="input-base" rows="2"></textarea>
        </div>
        <div>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model="is_active" type="checkbox" class="rounded" /> 활성
            </label>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">저장</span><span wire:loading wire:target="save">저장 중...</span>
        </button>
    </div>

</div>

{{-- 큐 18: close confirm 모달 (.card) --}}
<div x-show="confirmOpen" x-cloak x-transition.opacity
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     @click.self="confirmOpen = false">
    <div class="card max-w-sm mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">변경 사항이 있습니다</h3>
        <p class="mt-2 text-sm text-gray-600">저장하지 않고 닫으면 변경 내용이 사라집니다.</p>
        <div class="mt-5 flex justify-end gap-2">
            <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button @click="confirmDiscard()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">닫기</button>
        </div>
    </div>
</div>

</div>
@endif

</div>
