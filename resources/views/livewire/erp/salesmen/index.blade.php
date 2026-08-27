<?php

use App\Models\Salesman;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search    = '';
    #[Url] public int $perPage = 10;
    public bool   $showPanel = false;
    public ?int   $editingId = null;

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public string $name        = '';
    public string $initials    = '';   // 영업담당자 이니셜 (Invoice No. 접두, item 7)
    public string $user_id_str = '';
    public string $phone       = '';
    public string $email       = '';
    public string $memo        = '';
    public bool   $is_active   = true;
    // 2026-05-21 — Salesman.type 입력 제거. User.type 단일 관리로 이동 (/admin/users 폼).
    // Salesman.type 컬럼은 user.type 미러링 결과로만 채워짐 (Vehicle::saved 훅 호환).

    // 2026-08-04 jin — 사내직원 차등정산(tier) 담당자별 on/off. 정산 금액 직결이라 canApprove() 만 수정 가능
    //   ([관리] 이상 = role 관리 · 업무관리자 · 최고관리자 · 시스템관리자).
    public bool   $per_unit_tier_enabled = false;

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
            ->paginate($this->perPage);
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

    /*
    |----------------------------------------------------------------------
    | 퇴사 승계 (jin 2026-08-27) — A 가 하던 일을 B 가 통째로 받는다.
    |----------------------------------------------------------------------
    | 판정·실행은 전부 SalesmanHandoverService 다. 이 화면은 **보여주고 누르는 것**만 한다 —
    | 조건을 여기 옮겨 적으면 「모달엔 옮긴다고 떴는데 안 옮겨진」 행이 생긴다(SKILLS §8 #67).
    */
    public ?int $handoverFromId = null;
    public string $handoverToId = '';
    public string $handoverReason = '';
    /** 넘길 바이어 — **나눠 넘기기의 핵심**. 비우면 아무도 안 넘어간다(전부가 아니다). */
    public array $handoverBuyerIds = [];
    /** 담당 바이어가 없는 진행중 차량을 함께 넘길지 — 바이어를 따라갈 수 없는 차라 사람이 정한다. */
    public bool $handoverIncludeOrphans = true;

    public function openHandover(int $fromId): void
    {
        // 정산 금액을 바꾸는 작업이라 여는 시점에도 막는다(실행 시 서비스가 다시 검사한다).
        if (! auth()->user()?->canApprove()) {
            $this->dispatch('notify', message: __('salesman.handover.forbidden'), type: 'warning');

            return;
        }
        $this->handoverFromId = $fromId;
        $this->handoverToId = '';
        $this->handoverReason = '';
        $this->handoverIncludeOrphans = true;
        // 기본은 **전부 선택** — 한 사람에게 통째로 넘기는 게 흔한 경우라 한 번에 끝나야 한다.
        //   나눌 때만 체크를 푼다.
        $this->handoverBuyerIds = \App\Models\Buyer::where('salesman_id', $fromId)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function handoverSelectAll(): void
    {
        $this->handoverBuyerIds = \App\Models\Buyer::where('salesman_id', $this->handoverFromId)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function handoverSelectNone(): void
    {
        $this->handoverBuyerIds = [];
    }

    public function closeHandover(): void
    {
        $this->handoverFromId = null;
    }

    /** 미리보기 — 받는 사람을 고르기 전엔 null. 실행과 **같은 함수**를 쓴다. */
    #[Computed]
    public function handoverPlan(): ?array
    {
        if (! $this->handoverFromId || $this->handoverToId === '') {
            return null;
        }
        $from = Salesman::find($this->handoverFromId);
        $to = Salesman::find((int) $this->handoverToId);
        if (! $from || ! $to || $from->id === $to->id) {
            return null;
        }

        try {
            return (new \App\Services\SalesmanHandoverService)
                ->preview($from, $to, $this->handoverBuyerIds, $this->handoverIncludeOrphans);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 받을 수 있는 사람 — **활동 중만**. 넘기는 쪽(퇴사자)은 비활성이어도 목록에 남는다. */
    #[Computed]
    public function handoverCandidates()
    {
        return Salesman::where('is_active', true)
            ->when($this->handoverFromId, fn ($q) => $q->where('id', '!=', $this->handoverFromId))
            ->orderBy('name')->get();
    }

    public function runHandover(): void
    {
        $from = Salesman::find($this->handoverFromId);
        $to = Salesman::find((int) $this->handoverToId);
        if (! $from || ! $to) {
            return;
        }

        try {
            $r = (new \App\Services\SalesmanHandoverService)->apply(
                $from, $to, auth()->user(), $this->handoverReason ?: null,
                $this->handoverBuyerIds, $this->handoverIncludeOrphans,
            );
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'warning');

            return;
        }

        $this->handoverFromId = null;
        unset($this->salesmen);
        $this->dispatch('notify', message: __('salesman.handover.done', [
            'buyers' => $r['buyers'], 'vehicles' => $r['vehicles'], 'skipped' => $r['skipped'],
        ]), type: 'success');
    }

    public function openEdit(int $id): void
    {
        $sm = Salesman::findOrFail($id);
        $this->editingId   = $id;
        $this->name        = $sm->name;
        $this->initials    = $sm->initials ?? '';
        $this->user_id_str = $sm->user_id ? (string) $sm->user_id : '';
        $this->phone       = $sm->phone ?? '';
        $this->email       = $sm->email ?? '';
        $this->memo        = $sm->memo  ?? '';
        $this->is_active   = $sm->is_active;
        $this->per_unit_tier_enabled = (bool) $sm->per_unit_tier_enabled;
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
        // 2026-05-21 사용자 결정: User 가 마스터. 편집 모드에서 name/email/user_id/type 은 read-only.
        // /admin/users 폼이 자동으로 Salesman row 생성·동기화. 이 화면은 보충 정보(전화·메모·활성) 입력 전용.
        if ($this->editingId) {
            $this->validate(['name' => 'required|string|max:100'], [], ['name' => __('salesman.field.name')]);
            $sm = Salesman::findOrFail($this->editingId);
            // 보충 필드만 update — name/email/user_id/type 은 손대지 않음 (User 마스터 보호).
            $data = [
                'initials'  => $this->initials ? strtoupper(trim($this->initials)) : null,
                'phone'     => $this->phone ?: null,
                'memo'      => $this->memo  ?: null,
                'is_active' => $this->is_active,
            ];
            // tier 는 정산 금액을 바꾸므로 화면 노출과 별개로 저장 시점에 재인가한다 (SKILLS §8 #26).
            if (auth()->user()?->canApprove()) {
                $data['per_unit_tier_enabled'] = $this->per_unit_tier_enabled;
            }
            $wasTier = (bool) $sm->per_unit_tier_enabled;
            $sm->update($data);
            // 돈을 바꾸는 스위치라 누가 언제 켰는지 남긴다 (Salesman 엔 감사 훅이 없어 여기서 직접).
            if (array_key_exists('per_unit_tier_enabled', $data) && $wasTier !== $this->per_unit_tier_enabled) {
                \App\Models\AuditLog::recordChange($sm, 'per_unit_tier_enabled', $wasTier, $this->per_unit_tier_enabled);
            }
        } else {
            // 예외 경로 — User 없이 영업담당자만 만들 때 (지원 종료 예정, 가급적 안 씀).
            $this->validate(['name' => 'required|string|max:100'], [], ['name' => __('salesman.field.name')]);
            $data = [
                'name'      => $this->name,
                'initials'  => $this->initials ? strtoupper(trim($this->initials)) : null,
                'user_id'   => $this->user_id_str !== '' ? (int) $this->user_id_str : null,
                'phone'     => $this->phone ?: null,
                'email'     => $this->email ?: null,
                'memo'      => $this->memo  ?: null,
                'is_active' => $this->is_active,
            ];
            if ($this->user_id_str !== '') {
                $user = User::find((int) $this->user_id_str);
                if ($user && $user->type) {
                    $data['type'] = $user->type;
                }
            }
            Salesman::create($data);
        }

        unset($this->salesmen);
        // 2026-05-21 사용자 피드백 — 저장 시 시각 피드백 + 패널 자동 닫기.
        $this->dispatch('notify', message: __('salesman.saved'), type: 'success');
        $this->close();
    }

    public function delete(int $id): void
    {
        Salesman::findOrFail($id)->delete();
        unset($this->salesmen);
        session()->flash('success', __('salesman.deleted'));
    }

    public function searchNow(): void
    {
        $this->resetPage();
    }

    private function resetForm(): void
    {
        $this->name = $this->initials = $this->user_id_str = $this->phone = $this->email = $this->memo = '';
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

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('salesman.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('salesman.total', ['count' => $this->salesmen->total()]) }}</p>
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
            {{ __('salesman.create_btn') }}
        </button>
    </div>
</div>

{{-- 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('salesman.search_ph') }}"
           class="input-filter w-64" />
    <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">{{ __('salesman.col.name') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('salesman.col.account') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.phone') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.email') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.status') }}</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->salesmen as $sm)
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $sm->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">
                    {{ $sm->name }}
                    @php $co = $sm->unconsumed_carryover; @endphp
                    @if($co != 0)
                    <span class="badge {{ $co > 0 ? 'badge-green' : 'badge-red' }} ml-1.5 text-[10px]"
                          title="{{ __('salesman.carryover_badge') }}">{{ $co > 0 ? '+' : '−' }}₩{{ number_format(abs($co)) }}</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->user?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->phone ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->email ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $sm->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $sm->is_active ? __('common.active') : __('common.inactive') }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('erp.salesmen.cashflow', $sm->id) }}" wire:navigate
                           onclick="event.stopPropagation()"
                           class="text-xs text-violet-600 hover:underline">{{ __('salesman.cashflow') }}</a>
                        @if(auth()->user()?->canApprove())
                        <button wire:click.stop="openHandover({{ $sm->id }})"
                                class="text-xs text-amber-600 hover:underline">{{ __('salesman.handover.button') }}</button>
                        @endif
                        <button wire:click.stop="delete({{ $sm->id }})"
                                wire:confirm="{{ __('salesman.delete_confirm', ['name' => $sm->name]) }}"
                                class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="py-12 text-center text-sm text-gray-400">{{ __('salesman.empty') }}</td></tr>
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
                <div class="font-medium text-gray-800">
                    {{ $sm->name }}
                    @php $co = $sm->unconsumed_carryover; @endphp
                    @if($co != 0)
                    <span class="badge {{ $co > 0 ? 'badge-green' : 'badge-red' }} text-[10px]">{{ $co > 0 ? '+' : '−' }}₩{{ number_format(abs($co)) }}</span>
                    @endif
                </div>
                <div class="text-xs text-gray-500">{{ $sm->phone ?? '' }}{{ $sm->email ? ' · '.$sm->email : '' }}</div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge {{ $sm->is_active ? 'badge-green' : 'badge-gray' }}">{{ $sm->is_active ? __('common.active') : __('common.inactive') }}</span>
                <a href="{{ route('erp.salesmen.cashflow', $sm->id) }}" wire:navigate
                   class="text-xs text-violet-600 hover:underline">{{ __('salesman.cashflow') }}</a>
            </div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('salesman.empty') }}</div>
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
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('salesman.edit_title') : __('salesman.create_title') }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 폼 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-3">
        {{-- 2026-05-21 — 편집 시 사용자 마스터 안내 배너 --}}
        @if($editingId)
        <div class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-700">
            {!! __('salesman.master_banner', ['link' => '<a href="'.route('admin.users.index').'" wire:navigate class="font-medium underline">'.e(__('salesman.users_link')).'</a>']) !!}
        </div>
        @endif
        <div>
            <label class="label-base">{{ __('salesman.field.name') }} @if(! $editingId)<span class="text-red-500">*</span>@endif</label>
            @if($editingId)
                <div class="input-base bg-gray-50 text-gray-700">{{ $name }}</div>
            @else
                <input wire:model="name" type="text" class="input-base" placeholder="{{ __('salesman.field.name_ph') }}" />
                @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            @endif
        </div>
        {{-- 영업담당자 이니셜 (item 7) — Proforma Invoice No. 접두 {이니셜}MU{차대번호숫자} --}}
        <div>
            <label class="label-base">{{ __('salesman.field.initials') }} <span class="text-xs text-gray-400">{{ __('common.optional') }}</span></label>
            <input wire:model="initials" type="text" maxlength="10" class="input-base uppercase" placeholder="{{ __('salesman.field.initials_ph') }}" />
            <p class="mt-1 text-[11px] text-gray-400">{{ __('salesman.field.initials_note') }}</p>
        </div>
        <div>
            <label class="label-base">{{ __('salesman.field.account') }} @if(! $editingId)<span class="text-xs text-gray-400">{{ __('common.optional') }}</span>@endif</label>
            @if($editingId)
                @php $linkedUser = $user_id_str !== '' ? \App\Models\User::find((int) $user_id_str) : null; @endphp
                <div class="input-base bg-gray-50 text-gray-700">
                    {{ $linkedUser ? "{$linkedUser->name} ({$linkedUser->email})" : __('salesman.field.linked_none') }}
                </div>
            @else
                <select wire:model="user_id_str" class="input-base">
                    <option value="">{{ __('salesman.field.account_none') }}</option>
                    @foreach($this->users as $u)
                    <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="grid grid-cols-2 gap-3">
            {{-- 2026-05-21 — Alpine phoneMask 자동 하이픈 (한국 4 패턴) --}}
            <div>
                <label class="label-base">{{ __('common.phone') }}</label>
                <input wire:model="phone" type="tel" class="input-base"
                       placeholder="01012345678"
                       maxlength="13"
                       x-on:input="$event.target.value = $store.phoneMask.apply($event.target.value); $wire.phone = $event.target.value" />
            </div>
            <div>
                <label class="label-base">{{ __('common.email') }}</label>
                @if($editingId)
                    <div class="input-base bg-gray-50 text-gray-700">{{ $email ?: '-' }}</div>
                @else
                    <input wire:model="email" type="email" class="input-base" />
                @endif
            </div>
        </div>
        {{-- 2026-05-21 — 정산 분류는 /admin/users 에서 관리. 여기서는 연결된 user.type 을 read-only 로 표시 --}}
        @if($editingId)
        @php
            $editingSalesman = \App\Models\Salesman::with('user')->find($editingId);
            $linkedUserType  = $editingSalesman?->user?->type;
            $typeLabel       = $linkedUserType ? __('salesman.type.'.$linkedUserType) : null;
        @endphp
        <div>
            <label class="label-base">{{ __('salesman.field.settlement_type') }}</label>
            <div class="input-base bg-gray-50 text-gray-700">
                @if($typeLabel)
                    {{ $typeLabel }} {{ __('salesman.type_suffix.'.$linkedUserType) }}
                @elseif($editingSalesman?->user_id)
                    <span class="text-amber-600">{{ __('salesman.field.type_unset') }}</span>
                @else
                    <span class="text-gray-400">{{ __('salesman.field.type_no_account') }}</span>
                @endif
            </div>
            <p class="mt-1 text-[11px] text-gray-500">{!! __('salesman.type_note', ['link' => '<a href="'.route('admin.users.index').'" wire:navigate class="text-violet-600 hover:underline">'.e(__('salesman.users_link')).'</a>']) !!}</p>
        </div>
        {{-- 차등정산(tier) — 사내직원 한정. 정산 금액 직결이라 [관리] 이상만 --}}
        @if($linkedUserType === 'employee' && auth()->user()?->canApprove())
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
            <label class="flex items-start gap-2 cursor-pointer">
                <input wire:model="per_unit_tier_enabled" type="checkbox" class="mt-0.5 rounded" />
                <span class="text-sm text-gray-800">
                    {{ __('salesman.field.per_unit_tier') }}
                    <span class="mt-1 block text-[11px] leading-relaxed text-gray-600">
                        {{ __('salesman.field.per_unit_tier_hint') }}
                    </span>
                </span>
            </label>
        </div>
        @endif
        @endif
        <div>
            <label class="label-base">{{ __('common.memo') }}</label>
            <textarea wire:model="memo" class="input-base" rows="2"></textarea>
        </div>
        <div>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model="is_active" type="checkbox" class="rounded" /> {{ __('common.active') }}
            </label>
        </div>
        @if($editingId)
        <div class="pt-2 border-t">
            <a href="{{ route('erp.salesmen.cashflow', $editingId) }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-sm text-violet-600 hover:underline">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                {{ __('salesman.cashflow_view') }}
            </a>
        </div>
        @endif
    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button wire:click="close" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
        </button>
    </div>

</div>
@endif


{{-- ══ 퇴사 승계 모달 (jin 2026-08-27) ═══════════════════════════════════════
     되돌리기가 없는 일괄 작업이라 **미리보기 없이 실행하지 않는다.**
     「옮길 것」과 「건너뛸 것 + 사유 + 차량번호」를 나란히 보여준다 — 카운터로 뭉개면
     정작 손봐야 할 몇 대가 숫자에 묻힌다(SKILLS §8 #67).                        --}}
@if($handoverFromId)
@php $from = \App\Models\Salesman::find($handoverFromId); $plan = $this->handoverPlan; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeHandover">
    <div class="w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="border-b px-5 py-4">
            <h3 class="text-base font-semibold text-gray-800">{{ __('salesman.handover.title', ['name' => $from?->name]) }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ __('salesman.handover.rule') }}</p>
        </div>

        <div class="max-h-[60vh] space-y-4 overflow-y-auto px-5 py-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-base">{{ __('salesman.handover.to') }}</label>
                    <select wire:model.live="handoverToId" class="input-base">
                        <option value="">-</option>
                        @foreach($this->handoverCandidates as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->type_label }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">{{ __('salesman.handover.reason') }}</label>
                    <input wire:model="handoverReason" type="text" class="input-base" placeholder="{{ __('salesman.handover.reason_ph') }}" />
                </div>
            </div>

            @if($plan)
            {{-- 승계 표시를 켤지 — **판정을 문장으로 적는다.** 코드에만 있으면 사람이 반대로 조작한다(§8 #60) --}}
            <div class="rounded-lg border {{ $plan['marks_inherited'] ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-gray-50' }} p-3 text-xs leading-relaxed text-gray-700">
                {{ $plan['marks_inherited'] ? __('salesman.handover.mark_on') : __('salesman.handover.mark_off') }}
            </div>

            {{-- 바이어 다중선택 — **나눠 넘기기**(jin 2026-08-27). 한 사람이 다 못 받으면 골라서 넘기고,
                 다시 눌러 남은 것을 다른 사람에게 넘긴다. 넘어간 바이어는 다음에 열면 목록에서 빠진다. --}}
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <p class="text-xs font-semibold text-gray-700">
                        {{ __('salesman.handover.pick') }}
                        <span class="ml-1 font-normal text-gray-500">{{ __('salesman.handover.picked', ['n' => count($handoverBuyerIds), 'total' => count($plan['candidates'])]) }}</span>
                    </p>
                    <div class="flex gap-2 text-[11px]">
                        <button wire:click="handoverSelectAll" class="text-violet-600 hover:underline">{{ __('salesman.handover.all') }}</button>
                        <button wire:click="handoverSelectNone" class="text-gray-400 hover:underline">{{ __('salesman.handover.none') }}</button>
                    </div>
                </div>
                <div class="max-h-44 space-y-0.5 overflow-y-auto rounded border border-gray-200 p-2">
                    @forelse($plan['candidates'] as $c)
                    <label wire:key="hb-{{ $c['id'] }}" class="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-xs hover:bg-gray-50">
                        <input type="checkbox" wire:model.live="handoverBuyerIds" value="{{ $c['id'] }}" class="rounded" />
                        <span class="text-gray-800">{{ $c['name'] }}</span>
                        @if($c['in_progress'])
                        <span class="ml-auto text-[11px] text-gray-400">{{ __('salesman.handover.n_vehicles', ['n' => $c['in_progress']]) }}</span>
                        @endif
                    </label>
                    @empty
                    <p class="py-2 text-center text-xs text-gray-400">{{ __('salesman.handover.no_buyers') }}</p>
                    @endforelse
                </div>
                @php $rewrites = collect($plan['buyers'])->where('rewrites_history', true); @endphp
                @if($rewrites->count())
                <p class="mt-1.5 text-[11px] text-amber-700">{{ __('salesman.handover.rewrites', ['n' => $rewrites->count()]) }}</p>
                @endif
            </div>

            <div>
                <p class="mb-1.5 text-xs font-semibold text-gray-700">{{ __('salesman.handover.moving') }}</p>
                <div class="space-y-1.5 text-xs">
                    <div class="rounded border border-gray-200 p-2">
                        <span class="font-medium text-gray-800">{{ __('salesman.handover.buyers', ['n' => count($plan['buyers'])]) }}</span>
                        <span class="text-gray-400">·</span>
                        <span class="font-medium text-gray-800">{{ __('salesman.handover.vehicles', ['n' => count($plan['vehicles'])]) }}</span>
                        @if(count($plan['vehicles']))
                        <p class="mt-1 text-gray-500">{{ collect($plan['vehicles'])->pluck('vehicle_number')->take(20)->implode(' · ') }}{{ count($plan['vehicles']) > 20 ? ' …' : '' }}</p>
                        @endif
                        <p class="mt-1 text-[11px] text-gray-400">{{ __('salesman.handover.follows') }}</p>
                    </div>

                    {{-- 바이어를 따라갈 수 없는 차 — 자동으로 처리하면 나눠 넘길 때 첫 번째 사람이
                         조용히 다 가져간다. 사람이 보고 정한다(SKILLS §8 #60). --}}
                    @if(count($plan['orphan_vehicles']))
                    <label class="flex cursor-pointer items-start gap-2 rounded border border-gray-200 p-2">
                        <input type="checkbox" wire:model.live="handoverIncludeOrphans" class="mt-0.5 rounded" />
                        <span>
                            <span class="font-medium text-gray-800">{{ __('salesman.handover.orphans', ['n' => count($plan['orphan_vehicles'])]) }}</span>
                            <span class="mt-0.5 block text-[11px] text-gray-500">{{ collect($plan['orphan_vehicles'])->pluck('vehicle_number')->take(20)->implode(' · ') }}</span>
                        </span>
                    </label>
                    @endif
                </div>
            </div>

            @if(count($plan['skipped']))
            <div>
                <p class="mb-1.5 text-xs font-semibold text-gray-700">{{ __('salesman.handover.skipping') }}</p>
                @foreach(collect($plan['skipped'])->groupBy('reason') as $reason => $rows)
                <div class="mb-1 rounded border border-gray-200 bg-gray-50 p-2 text-xs">
                    <span class="font-medium text-gray-800">{{ __('salesman.handover.skip.'.$reason, ['n' => $rows->count()]) }}</span>
                    <p class="mt-1 text-gray-500">{{ $rows->pluck('vehicle_number')->take(20)->implode(' · ') }}{{ $rows->count() > 20 ? ' …' : '' }}</p>
                </div>
                @endforeach
            </div>
            @endif
            @endif
        </div>

        <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
            <button wire:click="closeHandover" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="runHandover" class="btn-primary" @disabled(! $plan) wire:loading.attr="disabled" wire:target="runHandover">
                <span wire:loading.remove wire:target="runHandover">{{ __('salesman.handover.run') }}</span><span wire:loading wire:target="runHandover">{{ __('common.saving') }}</span>
            </button>
        </div>
    </div>
</div>
@endif

</div>
