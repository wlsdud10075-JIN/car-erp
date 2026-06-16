<?php

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
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
    // 회의확장씬 #2 Phase 3-1 (a) (2026-05-23) — 영업담당자 필터 + 영업담당자별 정렬.
    #[Url] public string $salesmanFilter = '';

    // ── 패널 ─────────────────────────────────────────────────────
    public bool   $showPanel  = false;
    public ?int   $editingId  = null;

    // ── 기본정보 ──────────────────────────────────────────────────
    public string $name          = '';
    public string $country_id_str = '';
    // 회의확장씬 #5-1 (2026-05-22) — 영업담당자 직접 지정.
    public string $salesman_id_str = '';
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
    // 회의확장씬 #4 (2026-05-22) — Consignee ID 2컬럼 입력
    public string $cons_id_type       = '';
    public string $cons_id_value      = '';
    // deep-interview 2026-05-28 Q1 — EORI/TAX 식별번호.
    public string $cons_eori_number   = '';
    public string $cons_tax_number    = '';

    // ── 적립금 탭 ──────────────────────────────────────────────────
    public array  $balances = [];   // ['USD' => 500.00, ...]
    public array  $txnList  = [];   // recent transactions (array)
    public string $txn_currency = 'USD';
    public string $txn_type     = 'EARNED';
    public string $txn_amount   = '';
    public string $txn_note     = '';

    // ─────────────────────────────────────────────────────────────

    /**
     * 회의확장씬 #2 개발예정 Phase 3-1 (c) (2026-05-23) — 바이어 미수금 게이지.
     * 분모: Σ(sale_total_amount × exchange_rate) — SKILLS §13 단일 출처.
     * 분자: Σ(sale_unpaid_amount_krw_cache) — Vehicle saving 훅 자동 갱신.
     * KRW 차량 또는 환율 미입력 차량은 분모·분자 모두 제외 (의미 없는 비율 방지).
     */
    #[Computed]
    public function buyerReceivable(): ?array
    {
        if (! $this->editingId) {
            return null;
        }
        $buyer = Buyer::find($this->editingId);
        if (! $buyer) {
            return null;
        }

        $vehicles = $buyer->vehicles()->get();
        if ($vehicles->isEmpty()) {
            return null;
        }

        $totalKrw = 0;
        $unpaidKrw = 0;
        foreach ($vehicles as $v) {
            $rate = (float) ($v->exchange_rate ?? 0);
            $total = (float) ($v->sale_total_amount ?? 0);
            if ($total > 0 && $rate > 0) {
                $totalKrw += (int) ($total * $rate);
            }
            $unpaidKrw += (int) ($v->sale_unpaid_amount_krw_cache ?? 0);
        }

        if ($totalKrw <= 0) {
            return null;
        }

        $paidKrw = max(0, $totalKrw - $unpaidKrw);
        $paidPct = max(0, min(100, $paidKrw / $totalKrw * 100));

        return [
            'total_krw' => $totalKrw,
            'unpaid_krw' => $unpaidKrw,
            'paid_krw' => $paidKrw,
            'paid_pct' => $paidPct,
            'vehicle_count' => $vehicles->count(),
        ];
    }

    /**
     * 2026-05-28 — 바이어별 누적 셀러부담액 (영업 협상 카드 — "우리가 그동안 이만큼 떠안았다").
     * 2026-06-16 — 송금수수료 + 손실(write_off) 합산. 표시는 통합 한 줄, 내역은 건수로만 구분.
     *   · 수수료 = FinalPayment type='fee' confirmed 의 amount_krw 합 (이미 KRW 환산됨)
     *   · 손실   = ReceivableHistory method='write_off' 의 amount × 환율 (KRW 차량은 amount 그대로)
     * 환율 미입력 외화는 KRW 산정 불가 → 제외 (수수료의 amount_krw null 제외와 동일 정책).
     */
    #[Computed]
    public function buyerFees(): ?array
    {
        if (! $this->editingId) {
            return null;
        }

        // 수수료 — 이미 KRW 환산된 amount_krw 합산
        $feeRows = FinalPayment::query()
            ->whereHas('vehicle', fn ($q) => $q->where('buyer_id', $this->editingId))
            ->where('type', 'fee')
            ->whereNotNull('confirmed_at')
            ->get(['amount_krw']);
        $feeKrw = (int) $feeRows->sum('amount_krw');
        $feeCount = $feeRows->count();

        // 손실(셀러부담) — 차량 통화 × 환율로 KRW 환산
        $woRows = ReceivableHistory::query()
            ->where('method', 'write_off')
            ->whereHas('vehicle', fn ($q) => $q->where('buyer_id', $this->editingId))
            ->with('vehicle:id,currency,exchange_rate')
            ->get();
        $woKrw = 0;
        $woCount = 0;
        foreach ($woRows as $r) {
            $v = $r->vehicle;
            if (! $v) {
                continue;
            }
            $krw = $v->currency === 'KRW'
                ? (float) $r->amount
                : (float) $r->amount * (float) ($v->exchange_rate ?? 0);
            if ($krw <= 0) {
                continue;   // 환율 미입력 외화 → 제외
            }
            $woKrw += $krw;
            $woCount++;
        }

        $totalKrw = $feeKrw + (int) $woKrw;
        if ($totalKrw <= 0) {
            return null;
        }

        return [
            'total_krw' => $totalKrw,
            'fee_count' => $feeCount,
            'wo_count' => $woCount,
            'count' => $feeCount + $woCount,
        ];
    }

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
        // 회의확장씬 #11 + #2 (2026-05-22) — 영업/[관리] 본인 바이어 솔팅.
        // E-2 보강 (2026-05-22) — buyers.salesman_id 직접 관계 우선 + vehicles 간접 fallback (운영 호환).
        //   - 직접: buyer.salesman_id IN ids
        //   - 간접 fallback: vehicle.salesman_id IN ids (기존 운영 데이터 — buyer.salesman_id NULL row 호환)
        //   - 운영자가 UI 에서 buyer.salesman_id 일괄 입력하면 자연스럽게 직접 관계로 수렴
        // admin/super: 전체 (분기 X)
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && $user->role === '영업' && $user->salesman;
        $restrictToManagerScope = $user && ! $user->isAdmin() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        return Buyer::query()
            ->with(['country', 'salesman'])
            ->when($restrictToOwnSalesman, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('salesman_id', $user->salesman->id)
                ->orWhereHas('vehicles', fn ($q3) => $q3->where('salesman_id', $user->salesman->id))
            ))
            ->when($restrictToManagerScope, fn ($q) => $q->where(fn ($q2) => $q2
                ->whereIn('salesman_id', $managerScopeSalesmanIds)
                ->orWhereHas('vehicles', fn ($q3) => $q3->whereIn('salesman_id', $managerScopeSalesmanIds))
            ))
            ->when($this->salesmanFilter !== '', fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")
                ->orWhere('contact_email', 'like', "%{$this->search}%")
                ->orWhere('contact_name', 'like', "%{$this->search}%")
            ))
            // 회의확장씬 #2 (2026-05-23) — 영업담당자별 그룹 정렬 (담당자별 묶음 + 이름순).
            ->orderByRaw('salesman_id IS NULL ASC')   // NULL 마지막
            ->orderBy('salesman_id')
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    // 회의확장씬 #5-1 (2026-05-22) — 바이어 폼 영업담당자 select 옵션.
    // 영업 role 본인은 자동 채움. [관리] 본인 담당 영업만 노출 (vehicles/index salesmen() 패턴 차용).
    #[Computed]
    public function salesmen()
    {
        $q = \App\Models\Salesman::where('is_active', true)->orderBy('name');

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && $user->role === '관리') {
            $q->whereIn('id', $user->getSubordinateSalesmanIds());
        }

        return $q->get(['id', 'name']);
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
        $this->salesman_id_str = $buyer->salesman_id ? (string) $buyer->salesman_id : '';
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
        $this->validate([
            'name' => 'required|string|max:100',
            // 회의확장씬 #5-1 (2026-05-22) — 영업담당자 nullable + exists 검증.
            'salesman_id_str' => 'nullable|integer|exists:salesmen,id',
        ]);

        // 영업 role 신규 등록 시 본인 salesman 자동 채움 (vehicles/index L1240~1241 패턴 대칭).
        $user = auth()->user();
        if ($this->salesman_id_str === '' && ! $this->editingId
            && $user && ! $user->isAdmin() && $user->role === '영업' && $user->salesman) {
            $this->salesman_id_str = (string) $user->salesman->id;
        }

        $data = [
            'name'          => $this->name,
            'country_id'    => $this->country_id_str !== '' ? (int) $this->country_id_str : null,
            'salesman_id'   => $this->salesman_id_str !== '' ? (int) $this->salesman_id_str : null,
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
        session()->flash('success', __('buyer.saved'));
    }

    public function delete(int $id): void
    {
        Buyer::findOrFail($id)->delete();
        unset($this->buyers);
        session()->flash('success', __('buyer.deleted'));
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
            $this->cons_id_type        = $c->id_type      ?? '';
            $this->cons_id_value       = $c->id_value     ?? '';
            $this->cons_eori_number    = $c->eori_number  ?? '';
            $this->cons_tax_number     = $c->tax_number   ?? '';
            $this->cons_contact_name   = $c->contact_name  ?? '';
            $this->cons_contact_email  = $c->contact_email ?? '';
            $this->cons_contact_phone  = $c->contact_phone ?? '';
            $this->cons_address        = $c->address       ?? '';
            $this->cons_memo           = $c->memo          ?? '';
            $this->cons_is_active      = $c->is_active;
        } else {
            $this->cons_name = $this->cons_country_id_str = $this->cons_contact_name
                = $this->cons_contact_email = $this->cons_contact_phone
                = $this->cons_address = $this->cons_memo
                = $this->cons_id_type = $this->cons_id_value
                = $this->cons_eori_number = $this->cons_tax_number = '';
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
        // 회의확장씬 #4 (2026-05-22) — id_type 선택 시 id_value 필수.
        $this->validate([
            'cons_name' => 'required|string|max:100',
            'cons_id_type' => 'nullable|in:'.implode(',', array_keys(\App\Models\Consignee::ID_TYPES)),
            'cons_id_value' => 'nullable|string|max:50|required_with:cons_id_type',
        ], [], [
            'cons_name' => '컨사이니명',
            'cons_id_type' => 'ID 종류',
            'cons_id_value' => 'ID 번호',
        ]);

        if (! $this->editingId) {
            $this->addError('cons_name', '바이어를 먼저 저장해주세요.');

            return;
        }

        $data = [
            'buyer_id'      => $this->editingId,
            'name'          => $this->cons_name,
            'country_id'    => $this->cons_country_id_str !== '' ? (int) $this->cons_country_id_str : null,
            'id_type'       => $this->cons_id_type  ?: null,
            'id_value'      => $this->cons_id_value ?: null,
            'eori_number'   => $this->cons_eori_number ?: null,
            'tax_number'    => $this->cons_tax_number  ?: null,
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
                session()->flash('error', __('buyer.savings.neg_balance'));
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
        $this->name = $this->country_id_str = $this->salesman_id_str = $this->contact_name
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
        <h1 class="text-xl font-bold text-gray-800">{{ __('buyer.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('common.total', ['count' => $this->buyers->total()]) }}</p>
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
            {{ __('buyer.create_btn') }}
        </button>
    </div>
</div>

{{-- 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="{{ __('buyer.search_ph') }}"
           class="input-filter w-64" />
    {{-- 회의확장씬 #2 Phase 3-1 (a) (2026-05-23) — 영업담당자 select 필터 --}}
    <select wire:model.live="salesmanFilter" class="input-filter">
        <option value="">{{ __('buyer.all_salesmen') }}</option>
        @foreach($this->salesmen as $sm)
            <option value="{{ $sm->id }}">{{ $sm->name }}</option>
        @endforeach
    </select>
    <button wire:click="search" class="btn-search">{{ __('common.search') }}</button>
</div>

{{-- 테이블 --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">{{ __('buyer.col_name') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.contact') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.country') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.email') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.phone') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.status') }}</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->buyers as $b)
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $b->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $b->name }}</td>
                <td class="py-3 pr-4 text-gray-500">
                    @if($b->salesman)
                        <span class="badge badge-blue">{{ $b->salesman->name }}</span>
                    @else
                        <span class="text-gray-300">{{ __('buyer.unassigned') }}</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->country?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->contact_email ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->contact_phone ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $b->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $b->is_active ? __('common.active') : __('common.inactive') }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $b->id }})"
                            wire:confirm="{{ __('buyer.delete_confirm', ['name' => $b->name]) }}"
                            class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="py-12 text-center text-sm text-gray-400">{{ __('buyer.empty') }}</td></tr>
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
        <span class="badge {{ $b->is_active ? 'badge-green' : 'badge-gray' }}">{{ $b->is_active ? __('common.active') : __('common.inactive') }}</span>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('buyer.empty') }}</div>
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
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('buyer.edit_title') : __('buyer.create_title') }}</h2>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 탭 --}}
    <div class="flex border-b px-5">
        @foreach(['basic','consignees','savings'] as $k)
        <button @click="tab='{{ $k }}'"
                :class="tab==='{{ $k }}' ? 'border-b-2 border-violet-600 text-violet-600' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-3 text-sm font-medium transition flex-shrink-0">{{ __('buyer.tab.'.$k) }}</button>
        @endforeach
    </div>

    {{-- 탭 컨텐츠 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5">

        {{-- ── 기본정보 --}}
        <div x-show="tab==='basic'" x-cloak>

            {{-- 회의확장씬 Phase 3-1 (c) (2026-05-23) — 바이어 미수금 게이지 (기존 차량 있는 경우만) --}}
            @if($this->buyerReceivable)
            @php $br = $this->buyerReceivable; @endphp
            <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
                <div class="flex items-center justify-between text-xs text-gray-500 mb-1.5">
                    <span class="font-semibold text-gray-700">{{ __('buyer.receivable.title') }}</span>
                    <span>{{ __('buyer.receivable.summary', ['count' => $br['vehicle_count'], 'amount' => number_format($br['total_krw'])]) }}</span>
                </div>
                <div class="h-3 bg-white rounded-full overflow-hidden border border-gray-200">
                    @if($br['unpaid_krw'] <= 0)
                    <div class="h-full bg-emerald-500" style="width: 100%"></div>
                    @else
                    <div class="h-full bg-amber-500" style="width: {{ $br['paid_pct'] }}%"></div>
                    @endif
                </div>
                <div class="mt-1.5 flex items-center justify-between text-xs">
                    @if($br['unpaid_krw'] <= 0)
                    <span class="font-medium text-emerald-700">{{ __('buyer.receivable.fully_paid') }}</span>
                    @else
                    <span class="font-medium text-amber-700">{{ __('buyer.receivable.paid_pct', ['pct' => number_format($br['paid_pct'], 1)]) }}</span>
                    <span class="text-red-600 font-medium">{{ __('buyer.receivable.unpaid', ['amount' => number_format($br['unpaid_krw'])]) }}</span>
                    @endif
                </div>
            </div>
            @endif

            {{-- 2026-05-28 — 누적 송금 수수료 (셀러 부담) — 영업 협상 카드용 --}}
            @if($this->buyerFees)
            @php $bf = $this->buyerFees; @endphp
            <div class="mb-4 rounded-lg border border-violet-200 bg-violet-50 p-3">
                <div class="flex items-center justify-between">
                    <div class="text-xs">
                        <div class="font-semibold text-violet-700">{{ __('buyer.fees.title') }}</div>
                        <div class="mt-0.5 text-violet-600">{{ __('buyer.fees.desc') }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-base font-bold text-violet-700">₩{{ number_format($bf['total_krw']) }}</div>
                        @if($bf['wo_count'] > 0)
                            <div class="text-xs text-violet-500">{{ __('buyer.fees.breakdown', ['fee' => $bf['fee_count'], 'wo' => $bf['wo_count']]) }}</div>
                        @else
                            <div class="text-xs text-violet-500">{{ __('buyer.fees.count', ['count' => $bf['count']]) }}</div>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            <div class="space-y-3">
                <div>
                    <label class="label-base">{{ __('buyer.field.name') }} <span class="text-red-500">*</span></label>
                    <input wire:model="name" type="text" class="input-base" placeholder="{{ __('buyer.field.name_ph') }}" />
                    @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label-base">{{ __('common.country') }}</label>
                    <x-country-picker name="country_id_str" :value="$country_id_str" />
                </div>
                {{-- 회의확장씬 #5-1 (2026-05-22) — 영업담당자 직접 지정 ([관리] 솔팅 직접 관계) --}}
                <div>
                    <label class="label-base">{{ __('buyer.field.salesman') }}</label>
                    <select wire:model="salesman_id_str" class="input-base">
                        <option value="">{{ __('buyer.field.salesman_unassigned') }}</option>
                        @foreach($this->salesmen as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">{{ __('buyer.field.salesman_note') }}</p>
                    @error('salesman_id_str')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">{{ __('buyer.field.contact_name') }}</label>
                        <input wire:model="contact_name" type="text" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.phone') }}</label>
                        <input wire:model="contact_phone" type="text" class="input-base" />
                    </div>
                </div>
                <div>
                    <label class="label-base">{{ __('common.email') }}</label>
                    <input wire:model="contact_email" type="email" class="input-base" />
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
        </div>

        {{-- ── 컨사이니 --}}
        <div x-show="tab==='consignees'" x-cloak>
            @if(!$editingId)
            <p class="text-sm text-gray-400">{{ __('buyer.cons.save_first') }}</p>
            @else
            <div class="space-y-2">
                @forelse($consigneeList as $c)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2.5">
                    <div>
                        <div class="text-sm font-medium text-gray-800">{{ $c['name'] }}</div>
                        <div class="text-xs text-gray-400">{{ $c['country_name'] }}{{ $c['contact_email'] ? ' · '.$c['contact_email'] : '' }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge {{ $c['is_active'] ? 'badge-green' : 'badge-gray' }} text-[10px]">{{ $c['is_active'] ? __('common.active') : __('common.inactive') }}</span>
                        <button wire:click="openConsigneeForm({{ $c['id'] }})" class="text-xs text-violet-600 hover:underline">{{ __('common.edit') }}</button>
                        <button wire:click="deleteConsignee({{ $c['id'] }})"
                                wire:confirm="{{ __('buyer.cons.delete_confirm') }}"
                                class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400">{{ __('buyer.cons.empty') }}</p>
                @endforelse

                {{-- 추가 버튼 --}}
                @if(!$showConsigneeForm)
                <button wire:click="openConsigneeForm()" class="mt-2 text-sm text-violet-600 hover:underline">{{ __('buyer.cons.add') }}</button>
                @endif
            </div>

            {{-- 인라인 폼 --}}
            @if($showConsigneeForm)
            <div class="mt-4 rounded-xl border border-violet-200 bg-violet-50 p-4 space-y-3">
                <h3 class="text-sm font-semibold text-violet-700">{{ $editConsigneeId ? __('buyer.cons.form_edit') : __('buyer.cons.form_add') }}</h3>
                <div>
                    <label class="label-base">{{ __('buyer.cons.name') }} <span class="text-red-500">*</span></label>
                    <input wire:model="cons_name" type="text" class="input-base" />
                    @error('cons_name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                {{-- 회의확장씬 #4 (2026-05-22) — ID 2컬럼. id_type 선택 시 id_value 필수 --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">{{ __('buyer.cons.id_type') }}</label>
                        <select wire:model="cons_id_type" class="input-base">
                            <option value="">{{ __('common.select') }}</option>
                            @foreach(\App\Models\Consignee::ID_TYPES as $key => $label)
                            <option value="{{ $key }}">{{ __('consignee.id_type.'.$key) }}</option>
                            @endforeach
                        </select>
                        @error('cons_id_type')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('buyer.cons.id_value') }}</label>
                        <input wire:model="cons_id_value" type="text" class="input-base" maxlength="50" />
                        @error('cons_id_value')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label class="label-base">{{ __('common.country') }}</label>
                    <x-country-picker name="cons_country_id_str" :value="$cons_country_id_str" />
                </div>
                {{-- deep-interview 2026-05-28 Q1 — EORI/TAX --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">EORI Number</label>
                        <input wire:model="cons_eori_number" type="text" class="input-base" placeholder="{{ __('buyer.field.eori_ph') }}" />
                    </div>
                    <div>
                        <label class="label-base">TAX Number</label>
                        <input wire:model="cons_tax_number" type="text" class="input-base" placeholder="{{ __('buyer.field.tax_ph') }}" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">{{ __('common.contact') }}</label>
                        <input wire:model="cons_contact_name" type="text" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.email') }}</label>
                        <input wire:model="cons_contact_email" type="email" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.phone') }}</label>
                        <input wire:model="cons_contact_phone" type="text" class="input-base" />
                    </div>
                </div>
                <div>
                    <label class="label-base">{{ __('common.address') }}</label>
                    <input wire:model="cons_address" type="text" class="input-base" />
                </div>
                <div>
                    <label class="label-base">{{ __('common.memo') }}</label>
                    <textarea wire:model="cons_memo" class="input-base" rows="1"></textarea>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="cons_is_active" type="checkbox" class="rounded" /> {{ __('common.active') }}
                    </label>
                </div>
                <div class="flex gap-2 pt-1">
                    <button wire:click="saveConsignee" class="btn-primary text-xs py-1.5 px-3">{{ __('common.save') }}</button>
                    <button wire:click="closeConsigneeForm" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600">{{ __('common.cancel') }}</button>
                </div>
            </div>
            @endif
            @endif
        </div>

        {{-- ── 적립금 --}}
        <div x-show="tab==='savings'" x-cloak>
            @if(!$editingId)
            <p class="text-sm text-gray-400">{{ __('buyer.savings.save_first') }}</p>
            @else

            {{-- 회의확장씬 #12 Phase 3-1 (b) (2026-05-23) — 카드 → 통화별 게이지 변환 --}}
            @if(count($balances))
            @php
                // 게이지 정규화 기준 — 모든 통화 잔액 절대값 중 최대
                $maxBal = max(array_map(fn ($v) => abs((float) $v), $balances));
                $maxBal = $maxBal > 0 ? $maxBal : 1;   // div by 0 방지
            @endphp
            <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-2">
                <div class="text-[10px] font-semibold uppercase text-gray-400">{{ __('buyer.savings.balances_title') }}</div>
                @foreach($balances as $cur => $bal)
                @php
                    $bal = (float) $bal;
                    $pct = $maxBal > 0 ? max(2, min(100, abs($bal) / $maxBal * 100)) : 0;
                    $isNeg = $bal < 0;
                    $barColor = $isNeg ? 'bg-red-400' : ($bal > 0 ? 'bg-emerald-400' : 'bg-gray-300');
                    $textColor = $isNeg ? 'text-red-600' : ($bal > 0 ? 'text-emerald-700' : 'text-gray-500');
                @endphp
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-10 font-mono font-semibold text-gray-600">{{ $cur }}</span>
                    <div class="flex-1 h-3 bg-white rounded-full overflow-hidden border border-gray-200">
                        <div class="h-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <span class="w-32 text-right font-medium {{ $textColor }}">{{ number_format($bal, 2) }}</span>
                </div>
                @endforeach
            </div>
            @else
            <p class="mb-4 text-xs text-gray-400">{{ __('buyer.savings.no_balance') }}</p>
            @endif

            {{-- 거래 추가 폼 --}}
            <div class="mb-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer.savings.add_txn') }}</h3>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                        <label class="label-base">{{ __('buyer.savings.currency') }}</label>
                        <select wire:model="txn_currency" class="input-base">
                            @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $cur)
                            <option value="{{ $cur }}">{{ $cur }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label-base">{{ __('buyer.savings.type') }}</label>
                        <select wire:model="txn_type" class="input-base">
                            <option value="EARNED">{{ __('buyer.savings.type_earned') }}</option>
                            <option value="USED">{{ __('buyer.savings.type_used') }}</option>
                            <option value="REFUND">{{ __('buyer.savings.type_refund') }}</option>
                            <option value="ADJUSTMENT">{{ __('buyer.savings.type_adjustment') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">{{ __('buyer.savings.amount') }}</label>
                        <input wire:model="txn_amount" type="text" class="input-base" placeholder="0.00" />
                        @error('txn_amount')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.memo') }}</label>
                        <input wire:model="txn_note" type="text" class="input-base" />
                    </div>
                </div>
                <button wire:click="addSavingsTransaction" class="btn-primary mt-3 text-xs py-1.5">{{ __('buyer.savings.add_btn') }}</button>
            </div>

            {{-- 거래 내역 --}}
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b text-left text-gray-400">
                            <th class="pb-1.5 pr-3">{{ __('buyer.savings.col_date') }}</th>
                            <th class="pb-1.5 pr-3">{{ __('buyer.savings.currency') }}</th>
                            <th class="pb-1.5 pr-3">{{ __('buyer.savings.type') }}</th>
                            <th class="pb-1.5 pr-3 text-right">{{ __('buyer.savings.col_amount') }}</th>
                            <th class="pb-1.5 pr-3 text-right">{{ __('buyer.savings.col_balance') }}</th>
                            <th class="pb-1.5">{{ __('common.memo') }}</th>
                            <th class="pb-1.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($txnList as $t)
                        @php
                            $typeLabel = match($t['transaction_type']) {
                                'EARNED' => __('buyer.savings.t_earned'), 'USED' => __('buyer.savings.t_used'),
                                'REFUND' => __('buyer.savings.t_refund'), 'ADJUSTMENT' => __('buyer.savings.t_adjustment'), 'CANCELLED' => __('buyer.savings.t_cancelled'),
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
                                        wire:confirm="{{ __('buyer.savings.cancel_confirm') }}"
                                        class="text-gray-300 hover:text-red-400" title="{{ __('buyer.savings.cancel_title') }}">×</button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-400">{{ __('buyer.savings.no_history') }}</td></tr>
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
            <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
            </button>
        </div>
        <div x-show="tab!=='basic'">
            <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.close') }}</button>
        </div>
    </div>

</div>

{{-- 큐 18: close confirm 모달 (.card) --}}
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
