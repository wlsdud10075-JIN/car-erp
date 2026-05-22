<?php

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\SavingsStatus;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    #[Url] public int $perPage = 10;

    // ── 패널 ─────────────────────────────────────────────────────
    public bool   $showPanel  = false;
    public ?int   $editingId  = null;

    // ── 기본정보 ──────────────────────────────────────────────────
    public string $name          = '';
    public string $country_id_str = '';
    public string $contact_name  = '';
    public string $contact_email = '';
    public string $contact_phone = '';
    public string $address       = '';
    public string $memo          = '';
    public bool   $is_active     = true;

    // ── 컨사이니 탭 ────────────────────────────────────────────────
    public array  $consigneeList      = [];
    public bool   $showConsigneeForm  = false;
    public ?int   $editConsigneeId    = null;
    public string $cons_name          = '';
    public string $cons_country_id_str = '';
    public string $cons_contact_name  = '';
    public string $cons_contact_email = '';
    public string $cons_contact_phone = '';
    public string $cons_address       = '';
    public string $cons_memo          = '';
    public bool   $cons_is_active     = true;

    // ── 적립금 탭 ──────────────────────────────────────────────────
    public array  $balances = [];   // ['USD' => 500.00, ...]
    public array  $txnList  = [];   // recent transactions (array)
    public string $txn_currency = 'USD';
    public string $txn_type     = 'EARNED';
    public string $txn_amount   = '';
    public string $txn_note     = '';

    // ─────────────────────────────────────────────────────────────

    public function search(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    #[Computed]
    public function buyers()
    {
        // 회의확장씬 #11 + #2 (2026-05-22) — buyers ↔ salesman 직접 컬럼 없음. vehicles 통한 간접.
        // 영업: 본인 차량의 buyer 만 (vehicles 측 8711e7d 패턴 대칭 — buyers 측엔 이번에 신규 추가)
        // [관리]: subordinates 영업이 거래한 buyer 만 (vehicles.salesman_id IN subordinates)
        // admin/super: 전체 (분기 X)
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && $user->role === '영업' && $user->salesman;
        $restrictToManagerScope = $user && ! $user->isAdmin() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        return Buyer::query()
            ->with('country')
            ->when($restrictToOwnSalesman, fn ($q) => $q->whereHas('vehicles', fn ($q2) => $q2->where('salesman_id', $user->salesman->id)))
            ->when($restrictToManagerScope, fn ($q) => $q->whereHas('vehicles', fn ($q2) => $q2->whereIn('salesman_id', $managerScopeSalesmanIds)))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")
                ->orWhere('contact_email', 'like', "%{$this->search}%")
                ->orWhere('contact_name', 'like', "%{$this->search}%")
            ))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function countries()
    {
        return Country::orderBy('name')->get();
    }

    // ── 패널 열기/닫기 ────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $buyer = Buyer::findOrFail($id);
        $this->editingId     = $id;
        $this->name          = $buyer->name;
        $this->country_id_str = $buyer->country_id ? (string)$buyer->country_id : '';
        $this->contact_name  = $buyer->contact_name  ?? '';
        $this->contact_email = $buyer->contact_email ?? '';
        $this->contact_phone = $buyer->contact_phone ?? '';
        $this->address       = $buyer->address       ?? '';
        $this->memo          = $buyer->memo          ?? '';
        $this->is_active     = $buyer->is_active;

        $this->loadConsignees($id);
        $this->loadSavings($id);
        $this->showPanel = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
        $this->showConsigneeForm = false;
    }

    // ── 바이어 저장 ───────────────────────────────────────────────
    public function save(): void
    {
        $this->validate(['name' => 'required|string|max:100']);

        $data = [
            'name'          => $this->name,
            'country_id'    => $this->country_id_str !== '' ? (int)$this->country_id_str : null,
            'contact_name'  => $this->contact_name  ?: null,
            'contact_email' => $this->contact_email ?: null,
            'contact_phone' => $this->contact_phone ?: null,
            'address'       => $this->address       ?: null,
            'memo'          => $this->memo           ?: null,
            'is_active'     => $this->is_active,
        ];

        if ($this->editingId) {
            Buyer::findOrFail($this->editingId)->update($data);
        } else {
            $buyer = Buyer::create($data);
            $this->editingId = $buyer->id;
        }

        unset($this->buyers);
        session()->flash('success', '바이어 정보가 저장됐습니다.');
    }

    public function delete(int $id): void
    {
        Buyer::findOrFail($id)->delete();
        unset($this->buyers);
        session()->flash('success', '바이어가 삭제됐습니다.');
    }

    // ── 컨사이니 ──────────────────────────────────────────────────
    private function loadConsignees(int $buyerId): void
    {
        $this->consigneeList = Consignee::where('buyer_id', $buyerId)
            ->with('country')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'country_name'  => $c->country?->name ?? '-',
                'contact_name'  => $c->contact_name ?? '',
                'contact_email' => $c->contact_email ?? '',
                'is_active'     => $c->is_active,
            ])
            ->toArray();
    }

    public function openConsigneeForm(?int $id = null): void
    {
        $this->editConsigneeId = $id;
        if ($id) {
            $c = Consignee::findOrFail($id);
            $this->cons_name           = $c->name;
            $this->cons_country_id_str = $c->country_id ? (string)$c->country_id : '';
            $this->cons_contact_name   = $c->contact_name  ?? '';
            $this->cons_contact_email  = $c->contact_email ?? '';
            $this->cons_contact_phone  = $c->contact_phone ?? '';
            $this->cons_address        = $c->address       ?? '';
            $this->cons_memo           = $c->memo          ?? '';
            $this->cons_is_active      = $c->is_active;
        } else {
            $this->cons_name = $this->cons_country_id_str = $this->cons_contact_name
                = $this->cons_contact_email = $this->cons_contact_phone
                = $this->cons_address = $this->cons_memo = '';
            $this->cons_is_active = true;
        }
        $this->showConsigneeForm = true;
    }

    public function closeConsigneeForm(): void
    {
        $this->showConsigneeForm = false;
        $this->editConsigneeId = null;
    }

    public function saveConsignee(): void
    {
        $this->validate(['cons_name' => 'required|string|max:100']);

        if (!$this->editingId) {
            $this->addError('cons_name', '바이어를 먼저 저장해주세요.');
            return;
        }

        $data = [
            'buyer_id'      => $this->editingId,
            'name'          => $this->cons_name,
            'country_id'    => $this->cons_country_id_str !== '' ? (int)$this->cons_country_id_str : null,
            'contact_name'  => $this->cons_contact_name  ?: null,
            'contact_email' => $this->cons_contact_email ?: null,
            'contact_phone' => $this->cons_contact_phone ?: null,
            'address'       => $this->cons_address       ?: null,
            'memo'          => $this->cons_memo          ?: null,
            'is_active'     => $this->cons_is_active,
        ];

        if ($this->editConsigneeId) {
            Consignee::findOrFail($this->editConsigneeId)->update($data);
        } else {
            Consignee::create($data);
        }

        $this->loadConsignees($this->editingId);
        $this->closeConsigneeForm();
    }

    public function deleteConsignee(int $id): void
    {
        Consignee::findOrFail($id)->delete();
        if ($this->editingId) $this->loadConsignees($this->editingId);
    }

    // ── 적립금 ────────────────────────────────────────────────────
    private function loadSavings(int $buyerId): void
    {
        // 통화별 최신 잔액
        $this->balances = SavingsStatus::where('buyer_id', $buyerId)
            ->orderByDesc('id')
            ->get()
            ->unique('currency')
            ->pluck('balance', 'currency')
            ->toArray();

        // 최근 50건 거래내역
        $this->txnList = SavingsStatus::where('buyer_id', $buyerId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn($t) => [
                'id'               => $t->id,
                'currency'         => $t->currency,
                'transaction_type' => $t->transaction_type,
                'savings'          => $t->savings,
                'balance'          => $t->balance,
                'note'             => $t->note ?? '',
                'created_at'       => $t->created_at->format('Y-m-d H:i'),
            ])
            ->toArray();
    }

    public function addSavingsTransaction(): void
    {
        $this->validate(['txn_amount' => 'required|numeric|min:0.01']);

        if (!$this->editingId) return;

        $amount = (float) $this->txn_amount;

        DB::transaction(function () use ($amount) {
            $latest = SavingsStatus::where('buyer_id', $this->editingId)
                ->where('currency', $this->txn_currency)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $currentBalance = $latest?->balance ?? 0.0;
            $savings = ($this->txn_type === 'USED') ? -$amount : $amount;
            $newBalance = $currentBalance + $savings;

            if ($newBalance < 0) {
                $this->addError('txn_amount', "잔액 부족 (현재: {$currentBalance} {$this->txn_currency})");
                return;
            }

            SavingsStatus::create([
                'buyer_id'         => $this->editingId,
                'currency'         => $this->txn_currency,
                'transaction_type' => $this->txn_type,
                'savings'          => $savings,
                'balance'          => $newBalance,
                'note'             => $this->txn_note ?: null,
            ]);
        });

        $this->txn_amount = '';
        $this->txn_note   = '';
        $this->loadSavings($this->editingId);
    }

    public function cancelSavingsTransaction(int $id): void
    {
        if (!$this->editingId) return;

        $original = SavingsStatus::where('buyer_id', $this->editingId)->findOrFail($id);

        DB::transaction(function () use ($original) {
            $latest = SavingsStatus::where('buyer_id', $this->editingId)
                ->where('currency', $original->currency)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $cancelledSavings = -$original->savings;
            $newBalance = ($latest?->balance ?? 0) + $cancelledSavings;

            if ($newBalance < 0) {
                session()->flash('error', '취소 시 잔액이 음수가 됩니다. 취소 불가.');
                return;
            }

            SavingsStatus::create([
                'buyer_id'                => $this->editingId,
                'currency'               => $original->currency,
                'transaction_type'       => 'CANCELLED',
                'savings'                => $cancelledSavings,
                'balance'                => $newBalance,
                'original_transaction_id' => $original->id,
                'note'                   => '취소: ' . ($original->note ?? "#{$original->id}"),
            ]);
        });

        $this->loadSavings($this->editingId);
    }

    private function resetForm(): void
    {
        $this->name = $this->country_id_str = $this->contact_name
            = $this->contact_email = $this->contact_phone
            = $this->address = $this->memo = '';
        $this->is_active = true;
        $this->consigneeList = $this->balances = $this->txnList = [];
        $this->txn_amount = $this->txn_note = '';
        $this->txn_currency = 'USD';
        $this->txn_type = 'EARNED';
        $this->showConsigneeForm = false;
        $this->editConsigneeId = null;
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
        <h1 class="text-xl font-bold text-gray-800">바이어 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->buyers->total() }}개</p>
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
            바이어 등록
        </button>
    </div>
</div>

{{-- 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="이름 · 이메일 · 담당자"
           class="input-filter w-64" />
    <button wire:click="search" class="btn-search">조회</button>
</div>

{{-- 테이블 --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">바이어명</th>
                <th class="pb-2 pr-4 font-medium">국가</th>
                <th class="pb-2 pr-4 font-medium">이메일</th>
                <th class="pb-2 pr-4 font-medium">전화</th>
                <th class="pb-2 pr-4 font-medium">상태</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->buyers as $b)
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $b->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $b->name }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->country?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->contact_email ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->contact_phone ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $b->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $b->is_active ? '활성' : '비활성' }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $b->id }})"
                            wire:confirm="{{ $b->name }} 바이어를 삭제하시겠습니까?"
                            class="text-xs text-red-400 hover:text-red-600">삭제</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="py-12 text-center text-sm text-gray-400">바이어가 없습니다.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->buyers as $b)
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $b->id }})">
        <div>
            <div class="font-medium text-gray-800">{{ $b->name }}</div>
            <div class="text-xs text-gray-500">{{ $b->country?->name ?? '' }} {{ $b->contact_email ? '· '.$b->contact_email : '' }}</div>
        </div>
        <span class="badge {{ $b->is_active ? 'badge-green' : 'badge-gray' }}">{{ $b->is_active ? '활성' : '비활성' }}</span>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">바이어가 없습니다.</div>
    @endforelse
</div>

<div>{{ $this->buyers->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
{{-- 큐 18: close confirm — 기존 tab Alpine과 dirty 추적 병합 --}}
<div x-data="{
    tab: 'basic',
    dirty: false,
    confirmOpen: false,
    attemptClose() {
        if (this.confirmOpen) { this.confirmOpen = false; return; }
        if (this.dirty) { this.confirmOpen = true; } else { $wire.close(); }
    },
    confirmDiscard() { this.confirmOpen = false; $wire.close(); },
}" @keyup.escape.window="attemptClose()">
<div class="fixed inset-0 z-40 bg-black/40" @click="attemptClose()"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[580px]"
     @input="dirty = true" @change="dirty = true">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? '바이어 수정' : '바이어 등록' }}</h2>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 탭 --}}
    <div class="flex border-b px-5">
        @foreach([['basic','기본정보'],['consignees','컨사이니'],['savings','적립금']] as [$k,$l])
        <button @click="tab='{{ $k }}'"
                :class="tab==='{{ $k }}' ? 'border-b-2 border-violet-600 text-violet-600' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-3 text-sm font-medium transition flex-shrink-0">{{ $l }}</button>
        @endforeach
    </div>

    {{-- 탭 컨텐츠 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5">

        {{-- ── 기본정보 --}}
        <div x-show="tab==='basic'" x-cloak>
            <div class="space-y-3">
                <div>
                    <label class="label-base">바이어명 <span class="text-red-500">*</span></label>
                    <input wire:model="name" type="text" class="input-base" placeholder="TOKYO AUTO TRADING" />
                    @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
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
                        <label class="label-base">담당자명</label>
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
        </div>

        {{-- ── 컨사이니 --}}
        <div x-show="tab==='consignees'" x-cloak>
            @if(!$editingId)
            <p class="text-sm text-gray-400">기본정보를 먼저 저장한 후 컨사이니를 추가할 수 있습니다.</p>
            @else
            <div class="space-y-2">
                @forelse($consigneeList as $c)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2.5">
                    <div>
                        <div class="text-sm font-medium text-gray-800">{{ $c['name'] }}</div>
                        <div class="text-xs text-gray-400">{{ $c['country_name'] }}{{ $c['contact_email'] ? ' · '.$c['contact_email'] : '' }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge {{ $c['is_active'] ? 'badge-green' : 'badge-gray' }} text-[10px]">{{ $c['is_active'] ? '활성' : '비활성' }}</span>
                        <button wire:click="openConsigneeForm({{ $c['id'] }})" class="text-xs text-violet-600 hover:underline">수정</button>
                        <button wire:click="deleteConsignee({{ $c['id'] }})"
                                wire:confirm="컨사이니를 삭제하시겠습니까?"
                                class="text-xs text-red-400 hover:text-red-600">삭제</button>
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400">컨사이니가 없습니다.</p>
                @endforelse

                {{-- 추가 버튼 --}}
                @if(!$showConsigneeForm)
                <button wire:click="openConsigneeForm()" class="mt-2 text-sm text-violet-600 hover:underline">+ 컨사이니 추가</button>
                @endif
            </div>

            {{-- 인라인 폼 --}}
            @if($showConsigneeForm)
            <div class="mt-4 rounded-xl border border-violet-200 bg-violet-50 p-4 space-y-3">
                <h3 class="text-sm font-semibold text-violet-700">{{ $editConsigneeId ? '컨사이니 수정' : '컨사이니 추가' }}</h3>
                <div>
                    <label class="label-base">컨사이니명 <span class="text-red-500">*</span></label>
                    <input wire:model="cons_name" type="text" class="input-base" />
                    @error('cons_name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label-base">국가</label>
                    <select wire:model="cons_country_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->countries as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">담당자</label>
                        <input wire:model="cons_contact_name" type="text" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">이메일</label>
                        <input wire:model="cons_contact_email" type="email" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">전화</label>
                        <input wire:model="cons_contact_phone" type="text" class="input-base" />
                    </div>
                </div>
                <div>
                    <label class="label-base">주소</label>
                    <input wire:model="cons_address" type="text" class="input-base" />
                </div>
                <div>
                    <label class="label-base">메모</label>
                    <textarea wire:model="cons_memo" class="input-base" rows="1"></textarea>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="cons_is_active" type="checkbox" class="rounded" /> 활성
                    </label>
                </div>
                <div class="flex gap-2 pt-1">
                    <button wire:click="saveConsignee" class="btn-primary text-xs py-1.5 px-3">저장</button>
                    <button wire:click="closeConsigneeForm" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600">취소</button>
                </div>
            </div>
            @endif
            @endif
        </div>

        {{-- ── 적립금 --}}
        <div x-show="tab==='savings'" x-cloak>
            @if(!$editingId)
            <p class="text-sm text-gray-400">기본정보를 먼저 저장한 후 적립금을 관리할 수 있습니다.</p>
            @else

            {{-- 잔액 현황 --}}
            @if(count($balances))
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach($balances as $cur => $bal)
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-center">
                    <div class="text-xs text-gray-400">{{ $cur }}</div>
                    <div class="text-base font-bold {{ $bal >= 0 ? 'text-gray-800' : 'text-red-600' }}">
                        {{ number_format($bal, 2) }}
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <p class="mb-4 text-xs text-gray-400">적립금 거래 내역이 없습니다.</p>
            @endif

            {{-- 거래 추가 폼 --}}
            <div class="mb-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">거래 추가</h3>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                        <label class="label-base">통화</label>
                        <select wire:model="txn_currency" class="input-base">
                            @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $cur)
                            <option value="{{ $cur }}">{{ $cur }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label-base">유형</label>
                        <select wire:model="txn_type" class="input-base">
                            <option value="EARNED">적립 (EARNED)</option>
                            <option value="USED">사용 (USED)</option>
                            <option value="REFUND">반환 (REFUND)</option>
                            <option value="ADJUSTMENT">조정 (ADJUSTMENT)</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">금액</label>
                        <input wire:model="txn_amount" type="text" class="input-base" placeholder="0.00" />
                        @error('txn_amount')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label-base">메모</label>
                        <input wire:model="txn_note" type="text" class="input-base" />
                    </div>
                </div>
                <button wire:click="addSavingsTransaction" class="btn-primary mt-3 text-xs py-1.5">추가</button>
            </div>

            {{-- 거래 내역 --}}
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b text-left text-gray-400">
                            <th class="pb-1.5 pr-3">일시</th>
                            <th class="pb-1.5 pr-3">통화</th>
                            <th class="pb-1.5 pr-3">유형</th>
                            <th class="pb-1.5 pr-3 text-right">금액</th>
                            <th class="pb-1.5 pr-3 text-right">잔액</th>
                            <th class="pb-1.5">메모</th>
                            <th class="pb-1.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($txnList as $t)
                        @php
                            $typeLabel = match($t['transaction_type']) {
                                'EARNED' => '적립', 'USED' => '사용',
                                'REFUND' => '반환', 'ADJUSTMENT' => '조정', 'CANCELLED' => '취소',
                                default => $t['transaction_type'],
                            };
                            $typeColor = match($t['transaction_type']) {
                                'EARNED','REFUND' => 'text-green-600',
                                'USED' => 'text-red-500',
                                'CANCELLED' => 'text-gray-400',
                                default => 'text-amber-600',
                            };
                        @endphp
                        <tr>
                            <td class="py-1.5 pr-3 text-gray-400 whitespace-nowrap">{{ $t['created_at'] }}</td>
                            <td class="py-1.5 pr-3 font-mono">{{ $t['currency'] }}</td>
                            <td class="py-1.5 pr-3 {{ $typeColor }} font-medium">{{ $typeLabel }}</td>
                            <td class="py-1.5 pr-3 text-right font-mono {{ $t['savings'] >= 0 ? 'text-green-600' : 'text-red-500' }}">
                                {{ $t['savings'] >= 0 ? '+' : '' }}{{ number_format($t['savings'], 2) }}
                            </td>
                            <td class="py-1.5 pr-3 text-right font-mono text-gray-700">{{ number_format($t['balance'], 2) }}</td>
                            <td class="py-1.5 text-gray-400 max-w-[120px] truncate">{{ $t['note'] }}</td>
                            <td class="py-1.5 pl-2">
                                @if($t['transaction_type'] !== 'CANCELLED')
                                <button wire:click="cancelSavingsTransaction({{ $t['id'] }})"
                                        wire:confirm="이 거래를 취소하시겠습니까?"
                                        class="text-gray-300 hover:text-red-400" title="취소">×</button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-400">내역이 없습니다.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif
        </div>

    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <div x-show="tab==='basic'" class="flex gap-2">
            <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">저장</span><span wire:loading wire:target="save">저장 중...</span>
            </button>
        </div>
        <div x-show="tab!=='basic'">
            <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">닫기</button>
        </div>
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
