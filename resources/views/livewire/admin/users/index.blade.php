<?php

use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Hash;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search      = '';
    public string $permFilter  = '';
    #[Url] public int $perPage = 10;

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public bool   $showPanel   = false;
    public ?int   $editingId   = null;

    public string $name        = '';
    public string $email       = '';
    public string $password    = '';
    public string $permission  = 'user';
    public string $role        = '영업';
    // 2026-05-21 — 정산 분류 (role='영업' 일 때만 입력). default 빈 값 → role=영업 신규 등록 시 명시 선택 강제.
    public string $type        = '';

    #[Computed]
    public function users()
    {
        $isSuperAdmin = auth()->user()->isSuperAdmin();

        return User::query()
            ->when(! $isSuperAdmin, fn($q) => $q->where('permission', '!=', 'super'))
            ->when($this->search, fn($q) => $q->where(fn($q2) =>
                $q2->where('name', 'like', "%{$this->search}%")
                   ->orWhere('email', 'like', "%{$this->search}%")
            ))
            ->when($this->permFilter, fn($q) => $q->where('permission', $this->permFilter))
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
        $user = User::findOrFail($id);

        // admin은 super 계정 편집 불가
        if (! auth()->user()->isSuperAdmin() && $user->isSuperAdmin()) {
            return;
        }

        $this->editingId  = $id;
        $this->name       = $user->name;
        $this->email      = $user->email;
        $this->password   = '';
        $this->permission = $user->permission ?? 'user';
        $this->role       = $user->role       ?? '영업';
        $this->type       = $user->type       ?? '';
        $this->showPanel  = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        $rules = [
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|max:255|unique:users,email' . ($this->editingId ? ",{$this->editingId}" : ''),
            'permission' => 'required|in:super,admin,user',
            'role'       => 'required|in:전체,영업,통관,정산,관리',
            // 2026-05-21 — role='영업' 일 때만 type 필수. 그 외 role 은 type 무시(null 저장).
            'type'       => 'nullable|in:employee,freelance|required_if:role,영업',
        ];

        if (! $this->editingId) {
            $rules['password'] = 'required|string|min:8';
        } elseif ($this->password !== '') {
            $rules['password'] = 'string|min:8';
        }

        $this->validate($rules, [], [
            'name'  => '이름',
            'email' => '이메일',
            'role'  => '역할',
            'type'  => '정산 분류',
        ]);

        // admin은 super 권한 부여 불가
        if (! auth()->user()->isSuperAdmin() && $this->permission === 'super') {
            $this->addError('permission', '시스템관리자 권한은 부여할 수 없습니다.');
            return;
        }

        // 2026-05-21 — role='영업' 일 때만 type 저장. 그 외 role 은 null 로 정규화.
        $typeValue = $this->role === '영업' ? $this->type : null;

        $data = [
            'name'              => $this->name,
            'email'             => $this->email,
            'permission'        => $this->permission,
            'role'              => $this->role,
            'type'              => $typeValue,
            'email_verified_at' => now(),
        ];

        if (! $this->editingId) {
            $data['password'] = Hash::make($this->password);
            $user = User::create($data);
        } else {
            if ($this->password !== '') {
                $data['password'] = Hash::make($this->password);
            }
            $user = User::findOrFail($this->editingId);
            $user->update($data);
        }

        // 2026-05-21 — User-Salesman 자동 미러링 (사용자 결정: 영업 user = 영업담당자).
        // 운영 흐름 자동화: 영업계정 등록 → 자동 영업담당자 row 생성 → 사이드바·차량 목록·캐시플로우에 즉시 노출.
        // 폼 내부에서만 호출 (Vehicle::saved 훅처럼 booted 에 박지 않음) — 테스트의 Salesman::create + user_id 패턴과 충돌 회피.
        if ($this->role === '영업') {
            \App\Models\Salesman::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'type'      => $typeValue,
                    'is_active' => true,
                ]
            );
        } else {
            // 영업 → 다른 role 전환 시: 기존 Salesman 비활성화 (차량 FK 보호 위해 삭제 X).
            \App\Models\Salesman::where('user_id', $user->id)->update(['is_active' => false]);
        }

        unset($this->users);
        session()->flash('success', '사용자 정보가 저장됐습니다.');
    }

    public function delete(int $id): void
    {
        $target = User::findOrFail($id);

        // 본인 계정 삭제 불가
        if ($target->id === auth()->id()) {
            session()->flash('error', '본인 계정은 삭제할 수 없습니다.');
            return;
        }
        // admin은 super 삭제 불가
        if (! auth()->user()->isSuperAdmin() && $target->isSuperAdmin()) {
            return;
        }

        $target->delete();
        unset($this->users);
        session()->flash('success', '사용자가 삭제됐습니다.');
    }

    private function resetForm(): void
    {
        $this->name = $this->email = $this->password = '';
        $this->permission = 'user';
        $this->role = '영업';
        $this->type = '';
    }
}; ?>

<div wire:poll.30s>
@if(session('success'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,3000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,4000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-red-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('error') }}
</div>
@endif

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">사용자 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->users->total() }}명</p>
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
            사용자 추가
        </button>
    </div>
</div>

{{-- 필터 --}}
<div class="card-tight flex flex-wrap items-center gap-3">
    <input wire:model.live.debounce.400ms="search" type="text" placeholder="이름 · 이메일"
           class="input-base w-full sm:w-60" />
    <select wire:model.live="permFilter" class="input-base w-full sm:w-auto">
        <option value="">전체 권한</option>
        @if(auth()->user()->isSuperAdmin())
        <option value="super">시스템관리자</option>
        @endif
        <option value="admin">최고관리자</option>
        <option value="user">일반사용자</option>
    </select>
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">이름</th>
                <th class="pb-2 pr-4 font-medium">이메일</th>
                <th class="pb-2 pr-4 font-medium">권한</th>
                <th class="pb-2 pr-4 font-medium">역할</th>
                <th class="pb-2 pr-4 font-medium">마지막 로그인</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->users as $u)
            @php
                $permBadge = match($u->permission) {
                    'super' => 'badge-purple', 'admin' => 'badge-blue', default => 'badge-gray',
                };
                $permLabel = match($u->permission) {
                    'super' => '시스템관리자', 'admin' => '최고관리자', default => '일반사용자',
                };
            @endphp
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $u->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">
                    {{ $u->name }}
                    @if($u->id === auth()->id())
                    <span class="ml-1 text-[10px] text-gray-400">(나)</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-500">{{ $u->email }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $permBadge }}">{{ $permLabel }}</span>
                </td>
                <td class="py-3 pr-4 text-gray-500">
                    {{ $u->permission === 'user' ? ($u->role ?? '-') : '-' }}
                </td>
                <td class="py-3 pr-4 text-gray-400 text-xs">
                    {{ $u->last_login_at?->format('Y-m-d H:i') ?? '없음' }}
                </td>
                <td class="py-3 text-right">
                    @if($u->id !== auth()->id())
                    <button wire:click.stop="delete({{ $u->id }})"
                            wire:confirm="{{ $u->name }} 사용자를 삭제하시겠습니까?"
                            class="text-xs text-red-400 hover:text-red-600">삭제</button>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="py-12 text-center text-sm text-gray-400">사용자가 없습니다.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->users as $u)
    @php
        $permBadge = match($u->permission) { 'super' => 'badge-purple', 'admin' => 'badge-blue', default => 'badge-gray' };
        $permLabel = match($u->permission) { 'super' => '시스관리자', 'admin' => '최고관리자', default => '일반사용자' };
    @endphp
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $u->id }})">
        <div>
            <div class="font-medium text-gray-800">{{ $u->name }}{{ $u->id === auth()->id() ? ' (나)' : '' }}</div>
            <div class="text-xs text-gray-500">{{ $u->email }}</div>
        </div>
        <div class="flex items-center gap-2">
            <span class="badge {{ $permBadge }}">{{ $permLabel }}</span>
            @if($u->permission === 'user')
            <span class="text-xs text-gray-400">{{ $u->role ?? '-' }}</span>
            @endif
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">사용자가 없습니다.</div>
    @endforelse
</div>

<div>{{ $this->users->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? '사용자 수정' : '사용자 추가' }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 폼 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-4">

        {{-- 기본 정보 --}}
        <div class="space-y-3">
            <div>
                <label class="label-base">이름 <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" class="input-base" placeholder="홍길동" />
                @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">이메일 <span class="text-red-500">*</span></label>
                <input wire:model="email" type="email" class="input-base" placeholder="user@car-erp.test" />
                @error('email')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">
                    비밀번호
                    @if($editingId)<span class="text-xs text-gray-400">(빈 칸 = 변경 안 함)</span>@else<span class="text-red-500">*</span>@endif
                </label>
                <input wire:model="password" type="password" class="input-base" placeholder="8자 이상" autocomplete="new-password" />
                @error('password')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- 권한 / 역할 --}}
        <div class="space-y-3 border-t pt-4">
            <div class="section-header">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">권한 설정</span>
            </div>
            <div>
                <label class="label-base">권한 <span class="text-red-500">*</span></label>
                <select wire:model.live="permission" class="input-base">
                    @if(auth()->user()->isSuperAdmin())
                    <option value="super">시스템관리자 (super)</option>
                    @endif
                    <option value="admin">최고관리자 (admin)</option>
                    <option value="user">일반사용자 (user)</option>
                </select>
                @error('permission')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @if($permission === 'user')
            <div>
                <label class="label-base">역할 <span class="text-red-500">*</span></label>
                <select wire:model.live="role" class="input-base">
                    @foreach(App\Models\User::ROLES as $r)
                    <option value="{{ $r }}">{{ $r }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">역할에 따라 접근 가능한 메뉴가 달라집니다.</p>
                @error('role')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            {{-- 2026-05-21 — role='영업' 일 때만 정산 분류 노출. 거래완료 시 자동 정산의 settlement_type 결정 --}}
            @if($role === '영업')
            <div>
                <label class="label-base">정산 분류 <span class="text-red-500">*</span></label>
                <select wire:model="type" class="input-base">
                    <option value="">— 선택 —</option>
                    @foreach(App\Models\User::TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }} ({{ $key === 'employee' ? '건당' : '비율' }} 정산)</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">거래완료 시 자동 생성되는 정산 방식 결정 — 누락 방지를 위해 명시 선택 필수</p>
                @error('type')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif
            @endif
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
