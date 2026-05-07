<?php

use App\Models\Salesman;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search    = '';
    public bool   $showPanel = false;
    public ?int   $editingId = null;

    public string $name        = '';
    public string $user_id_str = '';
    public string $phone       = '';
    public string $email       = '';
    public string $memo        = '';
    public bool   $is_active   = true;

    #[Computed]
    public function salesmen()
    {
        return Salesman::query()
            ->with('user')
            ->when($this->search, fn($q) => $q->where(fn($q2) =>
                $q2->where('name', 'like', "%{$this->search}%")
                   ->orWhere('email', 'like', "%{$this->search}%")
                   ->orWhere('phone', 'like', "%{$this->search}%")
            ))
            ->orderBy('name')
            ->paginate(20);
    }

    #[Computed]
    public function users()
    {
        return User::orderBy('name')->get();
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
        $sm = Salesman::findOrFail($id);
        $this->editingId   = $id;
        $this->name        = $sm->name;
        $this->user_id_str = $sm->user_id ? (string) $sm->user_id : '';
        $this->phone       = $sm->phone ?? '';
        $this->email       = $sm->email ?? '';
        $this->memo        = $sm->memo  ?? '';
        $this->is_active   = $sm->is_active;
        $this->showPanel   = true;
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
            'name'      => $this->name,
            'user_id'   => $this->user_id_str !== '' ? (int) $this->user_id_str : null,
            'phone'     => $this->phone ?: null,
            'email'     => $this->email ?: null,
            'memo'      => $this->memo  ?: null,
            'is_active' => $this->is_active,
        ];

        if ($this->editingId) {
            Salesman::findOrFail($this->editingId)->update($data);
        } else {
            Salesman::create($data);
        }

        unset($this->salesmen);
        session()->flash('success', '영업담당자 정보가 저장됐습니다.');
    }

    public function delete(int $id): void
    {
        Salesman::findOrFail($id)->delete();
        unset($this->salesmen);
        session()->flash('success', '영업담당자가 삭제됐습니다.');
    }

    private function resetForm(): void
    {
        $this->name = $this->user_id_str = $this->phone = $this->email = $this->memo = '';
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
        <h1 class="text-xl font-bold text-gray-800">영업담당자 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->salesmen->total() }}명</p>
    </div>
    <button wire:click="openCreate" class="btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        담당자 등록
    </button>
</div>

{{-- 검색 --}}
<div class="card-tight">
    <input wire:model.live.debounce.400ms="search" type="text" placeholder="이름 · 이메일 · 전화"
           class="input-base w-full sm:w-72" />
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">이름</th>
                <th class="pb-2 pr-4 font-medium">연결 계정</th>
                <th class="pb-2 pr-4 font-medium">전화</th>
                <th class="pb-2 pr-4 font-medium">이메일</th>
                <th class="pb-2 pr-4 font-medium">상태</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->salesmen as $sm)
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $sm->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $sm->name }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->user?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->phone ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->email ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $sm->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $sm->is_active ? '활성' : '비활성' }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('erp.salesmen.cashflow', $sm->id) }}" wire:navigate
                           onclick="event.stopPropagation()"
                           class="text-xs text-violet-600 hover:underline">캐시플로우</a>
                        <button wire:click.stop="delete({{ $sm->id }})"
                                wire:confirm="{{ $sm->name }} 담당자를 삭제하시겠습니까?"
                                class="text-xs text-red-400 hover:text-red-600">삭제</button>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="py-12 text-center text-sm text-gray-400">영업담당자가 없습니다.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->salesmen as $sm)
    <div class="card-tight">
        <div class="flex items-center justify-between">
            <div class="cursor-pointer" wire:click="openEdit({{ $sm->id }})">
                <div class="font-medium text-gray-800">{{ $sm->name }}</div>
                <div class="text-xs text-gray-500">{{ $sm->phone ?? '' }}{{ $sm->email ? ' · '.$sm->email : '' }}</div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge {{ $sm->is_active ? 'badge-green' : 'badge-gray' }}">{{ $sm->is_active ? '활성' : '비활성' }}</span>
                <a href="{{ route('erp.salesmen.cashflow', $sm->id) }}" wire:navigate
                   class="text-xs text-violet-600 hover:underline">캐시플로우</a>
            </div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">영업담당자가 없습니다.</div>
    @endforelse
</div>

<div>{{ $this->salesmen->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? '영업담당자 수정' : '영업담당자 등록' }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 폼 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-3">
        <div>
            <label class="label-base">이름 <span class="text-red-500">*</span></label>
            <input wire:model="name" type="text" class="input-base" placeholder="김영업" />
            @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label-base">연결 계정 <span class="text-xs text-gray-400">(선택)</span></label>
            <select wire:model="user_id_str" class="input-base">
                <option value="">-- 연결 안 함 --</option>
                @foreach($this->users as $u)
                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                @endforeach
            </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label-base">전화</label>
                <input wire:model="phone" type="text" class="input-base" />
            </div>
            <div>
                <label class="label-base">이메일</label>
                <input wire:model="email" type="email" class="input-base" />
            </div>
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
        @if($editingId)
        <div class="pt-2 border-t">
            <a href="{{ route('erp.salesmen.cashflow', $editingId) }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-sm text-violet-600 hover:underline">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                캐시플로우 보기
            </a>
        </div>
        @endif
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
