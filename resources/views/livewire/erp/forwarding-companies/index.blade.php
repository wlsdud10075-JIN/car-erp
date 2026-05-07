<?php

use App\Models\ForwardingCompany;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search    = '';
    public bool   $showPanel = false;
    public ?int   $editingId = null;

    public string $name         = '';
    public string $contact_name = '';
    public string $email        = '';
    public string $phone        = '';
    public string $address      = '';
    public string $memo         = '';
    public bool   $is_active    = true;

    #[Computed]
    public function companies()
    {
        return ForwardingCompany::query()
            ->when($this->search, fn($q) => $q->where(fn($q2) =>
                $q2->where('name', 'like', "%{$this->search}%")
                   ->orWhere('contact_name', 'like', "%{$this->search}%")
                   ->orWhere('email', 'like', "%{$this->search}%")
            ))
            ->orderBy('name')
            ->paginate(20);
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

    public function search(): void
    {
        $this->resetPage();
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
        session()->flash('success', '포워딩사 정보가 저장됐습니다.');
    }

    public function delete(int $id): void
    {
        ForwardingCompany::findOrFail($id)->delete();
        unset($this->companies);
        session()->flash('success', '포워딩사가 삭제됐습니다.');
    }

    private function resetForm(): void
    {
        $this->name = $this->contact_name = $this->email = $this->phone = $this->address = $this->memo = '';
        $this->is_active = true;
    }
}; ?>

<div>
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
        <h1 class="text-xl font-bold text-gray-800">포워딩사 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->companies->total() }}개</p>
    </div>
    <button wire:click="openCreate" class="btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        포워딩사 등록
    </button>
</div>

{{-- 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="상호 · 담당자 · 이메일"
           class="input-filter w-64" />
    <button wire:click="search" class="btn-search">조회</button>
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">상호</th>
                <th class="pb-2 pr-4 font-medium">담당자</th>
                <th class="pb-2 pr-4 font-medium">이메일</th>
                <th class="pb-2 pr-4 font-medium">전화</th>
                <th class="pb-2 pr-4 font-medium">상태</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->companies as $fc)
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $fc->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $fc->name }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $fc->contact_name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $fc->email ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $fc->phone ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $fc->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $fc->is_active ? '활성' : '비활성' }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $fc->id }})"
                            wire:confirm="{{ $fc->name }}을 삭제하시겠습니까?"
                            class="text-xs text-red-400 hover:text-red-600">삭제</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="py-12 text-center text-sm text-gray-400">포워딩사가 없습니다.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->companies as $fc)
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $fc->id }})">
        <div>
            <div class="font-medium text-gray-800">{{ $fc->name }}</div>
            <div class="text-xs text-gray-500">{{ $fc->contact_name ?? '' }}{{ $fc->email ? ' · '.$fc->email : '' }}</div>
        </div>
        <span class="badge {{ $fc->is_active ? 'badge-green' : 'badge-gray' }}">{{ $fc->is_active ? '활성' : '비활성' }}</span>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">포워딩사가 없습니다.</div>
    @endforelse
</div>

<div>{{ $this->companies->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? '포워딩사 수정' : '포워딩사 등록' }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 폼 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-3">
        <div>
            <label class="label-base">상호 <span class="text-red-500">*</span></label>
            <input wire:model="name" type="text" class="input-base" placeholder="SSANCAR LOGISTICS" />
            @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label-base">담당자명</label>
                <input wire:model="contact_name" type="text" class="input-base" />
            </div>
            <div>
                <label class="label-base">전화</label>
                <input wire:model="phone" type="text" class="input-base" />
            </div>
        </div>
        <div>
            <label class="label-base">이메일</label>
            <input wire:model="email" type="email" class="input-base" />
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

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button wire:click="close" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">저장</span><span wire:loading wire:target="save">저장 중...</span>
        </button>
    </div>

</div>
@endif

</div>
