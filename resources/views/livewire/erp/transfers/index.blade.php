<?php

use App\Models\FinalPayment;
use App\Models\InterVehicleTransfer;
use App\Models\PurchaseBalancePayment;
use App\Services\InterVehicleTransferService;
use App\Services\PaymentConfirmationService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * 큐 19-F-C — 재무 처리 대기 페이지 (회의록 2026-05-16).
 *
 * 관리 승인 후 실물 자금 처리·시스템 마킹을 기다리는 자금 이체 목록.
 * 5상태 머신 중 2 상태가 본 페이지 대상:
 *   approved_awaiting_finance  → 정방향 이체 재무 확정 대기
 *   voided_awaiting_finance    → 취소 이체 재무 확정 대기
 *
 * 권한 (SoD 분리):
 *   - settlement 미들웨어 통과 (super/admin/정산/관리)
 *   - 추가 가드: canConfirmFinanceTransfer() — 관리 role 명시 차단
 *   - self-confirm 차단: approver_id === auth->id 시 처리 버튼 비활성
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    /** 큐 20-C — 'transfer' (자금 이체) / 'sale_payment' (판매 잔금) / 'purchase_payment' (매입 잔금) */
    #[Url]
    public string $tabType = 'transfer';

    #[Url]
    public string $statusFilter = 'awaiting';   // awaiting / all / executed / voided / finance_rejected

    #[Url]
    public int $perPage = 10;

    public bool $showModal = false;

    public ?int $modalTransferId = null;

    /** 큐 20-C — 잔금 모달용 (sale_payment / purchase_payment) */
    public ?int $modalPaymentId = null;

    public string $financeNote = '';

    /**
     * 22-A-2 — 매입 잔금 모달 자동 표시 (사용자 안건 2).
     * purchase_payment 모달 열 때 vehicle.purchase_seller_* 4컬럼 + purchase_from 을 한 번에 로드.
     * 재무가 매번 차량 편집 패널 매입 탭으로 이동하지 않아도 송금 정보 즉시 확인 + finance_note 자동 기입.
     * purchase_seller_account 는 encrypted cast → accessor 자동 복호화. 권한 가드: canConfirmFinanceTransfer().
     */
    public ?string $modalPurchaseFrom = null;

    public ?string $modalPurchaseBank = null;

    public ?string $modalPurchaseAccount = null;

    public ?string $modalPurchaseHolder = null;

    public ?string $modalPurchaseBankMemo = null;

    /** 2026-05-21 사용자 피드백 — 매입가/매도비/총금액 + 판매가 등 차량 회계 정보 표시. */
    public array $modalVehicleData = [];

    /** 큐 19-K — 'confirm' (재무 처리 완료) / 'reject' (재무 거부) */
    public string $decisionMode = 'confirm';

    public string $rejectReason = '';

    /**
     * 큐 22-C 핵심 (2026-05-20) — 매입 잔금 신규 row 입력 모달 (Spec-E 입력+확정 통합 UI).
     * 재무가 자동 PBP Draft 외에 별도 row 추가 가능 (분할 지급·추가 비용 등).
     * 권한: mount() 의 canConfirmFinanceTransfer 가드 + PBP::creating 의 canConfirmFinance 가드 양쪽 보호.
     */
    public bool $showNewPbpModal = false;

    public string $newPbpVehicleId = '';

    public string $newPbpAmountStr = '';

    public string $newPbpDate = '';

    public string $newPbpNote = '';

    public bool $newPbpImmediateConfirm = true;

    public function mount(): void
    {
        if (! auth()->user()?->canConfirmFinanceTransfer()) {
            abort(403, '재무 확정 권한이 없습니다. (관리 role 은 자기 승인을 직접 처리할 수 없음 — SoD)');
        }
    }

    public function updatedTabType(): void
    {
        $this->resetPage();
        $this->statusFilter = 'awaiting';
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function transfers()
    {
        return InterVehicleTransfer::query()
            ->with(['sourceVehicle', 'targetVehicle', 'buyer', 'requester', 'approver', 'financeConfirmer'])
            ->when($this->statusFilter === 'awaiting', fn ($q) => $q->whereIn('status', [
                InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
                InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
            ]))
            ->when($this->statusFilter === 'executed', fn ($q) => $q->where('status', InterVehicleTransfer::STATUS_EXECUTED))
            ->when($this->statusFilter === 'voided', fn ($q) => $q->where('status', InterVehicleTransfer::STATUS_VOIDED))
            ->when($this->statusFilter === 'finance_rejected', fn ($q) => $q->where('status', InterVehicleTransfer::STATUS_FINANCE_REJECTED))
            ->orderByDesc('updated_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function awaitingCount(): int
    {
        return InterVehicleTransfer::whereIn('status', [
            InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
        ])->count();
    }

    /** 큐 20-C — 판매 잔금 (영업 직접 입력 = transfer_id IS NULL). */
    #[Computed]
    public function salePayments()
    {
        return FinalPayment::query()
            ->with(['vehicle:id,vehicle_number,buyer_id', 'vehicle.buyer:id,name', 'financeConfirmer:id,name'])
            ->whereNull('transfer_id')
            ->when($this->statusFilter === 'awaiting', fn ($q) => $q->whereNull('confirmed_at'))
            ->when($this->statusFilter === 'executed', fn ($q) => $q->whereNotNull('confirmed_at'))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function salePaymentAwaitingCount(): int
    {
        return FinalPayment::whereNull('transfer_id')->whereNull('confirmed_at')->count();
    }

    /** 큐 20-C — 매입 잔금. */
    #[Computed]
    public function purchasePayments()
    {
        return PurchaseBalancePayment::query()
            ->with(['vehicle:id,vehicle_number,purchase_from,salesman_id', 'vehicle.salesman:id,name', 'financeConfirmer:id,name'])
            ->when($this->statusFilter === 'awaiting', fn ($q) => $q->whereNull('confirmed_at'))
            ->when($this->statusFilter === 'executed', fn ($q) => $q->whereNotNull('confirmed_at'))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function purchasePaymentAwaitingCount(): int
    {
        return PurchaseBalancePayment::whereNull('confirmed_at')->count();
    }

    /** 큐 20-C — 잔금 모달 열기 (sale_payment / purchase_payment 공용). */
    public function openPaymentModal(int $id): void
    {
        if ($this->tabType === 'sale_payment') {
            $p = FinalPayment::find($id);
            if (! $p || $p->confirmed_at !== null || $p->transfer_id !== null) {
                $this->dispatch('notify', message: '확정 대기 상태의 판매 잔금만 처리할 수 있습니다.', type: 'warning');

                return;
            }
        } elseif ($this->tabType === 'purchase_payment') {
            $p = PurchaseBalancePayment::find($id);
            if (! $p || $p->confirmed_at !== null) {
                $this->dispatch('notify', message: '확정 대기 상태의 매입 잔금만 처리할 수 있습니다.', type: 'warning');

                return;
            }
        } else {
            return;
        }

        $this->modalPaymentId = $id;
        $this->decisionMode = 'confirm';
        $this->financeNote = '';
        $this->modalPurchaseFrom = null;
        $this->modalPurchaseBank = null;
        $this->modalPurchaseAccount = null;
        $this->modalPurchaseHolder = null;
        $this->modalPurchaseBankMemo = null;
        $this->modalVehicleData = [];

        // 22-A-2 — 매입 잔금 모달일 때 매입처 4컬럼 자동 표시 + finance_note default 자동 기입.
        if ($this->tabType === 'purchase_payment' && isset($p) && $p->vehicle) {
            $v = $p->vehicle;
            $this->modalPurchaseFrom = $v->purchase_from;
            $this->modalPurchaseBank = $v->purchase_seller_bank;
            $this->modalPurchaseAccount = $v->purchase_seller_account;
            $this->modalPurchaseHolder = $v->purchase_seller_holder;
            $this->modalPurchaseBankMemo = $v->purchase_bank_memo;

            // 2026-05-21 사용자 피드백 — 매입가/매도비/총금액 표시.
            $this->modalVehicleData = [
                'purchase_price' => (int) ($v->purchase_price ?? 0),
                'selling_fee' => (int) ($v->selling_fee ?? 0),
                'purchase_total' => (int) ($v->purchase_price ?? 0) + (int) ($v->selling_fee ?? 0),
            ];

            $target = $this->modalPurchaseFrom ?: '매입처';
            $bank = $this->modalPurchaseBank ?: '';
            $account = $this->modalPurchaseAccount ?: '';
            if ($bank !== '' || $account !== '') {
                $this->financeNote = trim("{$target}/{$bank}/{$account}로 송금", '/');
            }
        }

        // 2026-05-21 — 판매 잔금 모달일 때 판매가/커미션/자동하역비/TAX-DC/통화 표시.
        if ($this->tabType === 'sale_payment' && isset($p) && $p->vehicle) {
            $v = $p->vehicle;
            $this->modalVehicleData = [
                'currency' => $v->currency,
                'sale_price' => (int) ($v->sale_price ?? 0),
                'commission' => (int) ($v->commission ?? 0),
                'auto_loading' => (int) ($v->auto_loading ?? 0),
                'tax_dc' => (int) ($v->tax_dc ?? 0),
                'transport_fee' => (int) ($v->transport_fee ?? 0),
                'sale_total' => (int) ($v->sale_total_amount ?? 0),
                'buyer_name' => $v->buyer?->name,
            ];
        }

        $this->showModal = true;
    }

    /** 큐 20-C — 잔금 재무 확정 실행 (sale_payment / purchase_payment 공용). */
    public function confirmPayment(): void
    {
        try {
            $service = app(PaymentConfirmationService::class);
            $note = trim($this->financeNote) !== '' ? trim($this->financeNote) : null;

            if ($this->tabType === 'sale_payment') {
                $p = FinalPayment::find($this->modalPaymentId);
                if (! $p) {
                    throw new \DomainException('판매 잔금을 찾을 수 없습니다.');
                }
                $service->confirmPayment($p, auth()->user(), $note);
                $msg = '판매 잔금 재무 확정 완료 — ledger 반영됨.';
            } elseif ($this->tabType === 'purchase_payment') {
                $p = PurchaseBalancePayment::find($this->modalPaymentId);
                if (! $p) {
                    throw new \DomainException('매입 잔금을 찾을 수 없습니다.');
                }
                $service->confirmPurchasePayment($p, auth()->user(), $note);
                $msg = '매입 잔금 재무 확정 완료 — ledger 반영됨.';
            } else {
                throw new \DomainException('알 수 없는 탭입니다.');
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: '재무 확정 실패: '.$e->getMessage(), type: 'error');
            $this->closeModal();
        }
    }

    public function openModal(int $id, string $mode = 'confirm'): void
    {
        $transfer = InterVehicleTransfer::find($id);
        if (! $transfer || ! in_array($transfer->status, [
            InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
        ], true)) {
            $this->dispatch('notify', message: '재무 처리 대기 상태의 이체만 확정할 수 있습니다.', type: 'warning');

            return;
        }
        if ($transfer->approver_id === auth()->id()) {
            $this->dispatch('notify', message: '본인이 승인한 이체는 직접 재무 확정할 수 없습니다 (SoD).', type: 'warning');

            return;
        }
        // 큐 19-K/L — 거부 모드는 awaiting 2종(정방향/void) 모두 가능.
        if ($mode === 'reject' && ! in_array($transfer->status, [
            InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
        ], true)) {
            $this->dispatch('notify', message: '재무 거부는 awaiting 상태에서만 가능합니다.', type: 'warning');

            return;
        }
        $this->modalTransferId = $id;
        $this->decisionMode = in_array($mode, ['confirm', 'reject'], true) ? $mode : 'confirm';
        $this->financeNote = '';
        $this->rejectReason = '';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->modalTransferId = null;
        $this->modalPaymentId = null;
        $this->decisionMode = 'confirm';
        $this->financeNote = '';
        $this->rejectReason = '';
        $this->modalPurchaseFrom = null;
        $this->modalPurchaseBank = null;
        $this->modalPurchaseAccount = null;
        $this->modalPurchaseHolder = null;
        $this->modalPurchaseBankMemo = null;
        $this->modalVehicleData = [];
    }

    /**
     * 큐 22-C 핵심 (2026-05-20) — 매입 잔금 신규 row 입력+확정 통합 모달.
     */
    #[Computed]
    public function purchaseEligibleVehicles()
    {
        return \App\Models\Vehicle::query()
            ->where('purchase_price', '>', 0)
            ->whereDoesntHave('settlements', fn ($q) => $q->where('settlement_status', 'paid'))
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'vehicle_number', 'purchase_from']);
    }

    public function openNewPbpModal(): void
    {
        $this->showNewPbpModal = true;
        $this->newPbpVehicleId = '';
        $this->newPbpAmountStr = '';
        $this->newPbpDate = now()->toDateString();
        $this->newPbpNote = '';
        $this->newPbpImmediateConfirm = true;
    }

    public function closeNewPbpModal(): void
    {
        $this->showNewPbpModal = false;
        $this->newPbpVehicleId = '';
        $this->newPbpAmountStr = '';
        $this->newPbpDate = '';
        $this->newPbpNote = '';
        $this->newPbpImmediateConfirm = true;
    }

    public function createNewPbp(): void
    {
        $this->validate([
            'newPbpVehicleId' => ['required', 'exists:vehicles,id'],
            'newPbpAmountStr' => ['required', 'numeric', 'gt:0'],
            'newPbpDate' => ['required', 'date'],
            'newPbpNote' => ['nullable', 'string', 'max:255'],
        ], [], [
            'newPbpVehicleId' => '차량',
            'newPbpAmountStr' => '금액',
            'newPbpDate' => '지급일',
            'newPbpNote' => '메모',
        ]);

        try {
            $pbp = PurchaseBalancePayment::create([
                'vehicle_id' => (int) $this->newPbpVehicleId,
                'amount' => (float) str_replace(',', '', $this->newPbpAmountStr),
                'payment_date' => $this->newPbpDate,
                'note' => $this->newPbpNote ?: null,
                'created_by_user_id' => auth()->id(),
            ]);

            if ($this->newPbpImmediateConfirm) {
                app(PaymentConfirmationService::class)
                    ->confirmPurchasePayment($pbp, auth()->user(), '재무 신규 입력 + 즉시 확정');
                $msg = '매입 잔금 row 추가 + 재무 확정 완료.';
            } else {
                $msg = '매입 잔금 Draft row 추가 — 확정은 별도 처리.';
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeNewPbpModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: '매입 잔금 추가 실패: '.$e->getMessage(), type: 'error');
        }
    }

    public function confirm(): void
    {
        $transfer = InterVehicleTransfer::find($this->modalTransferId);
        if (! $transfer) {
            $this->dispatch('notify', message: '이체 정보를 찾을 수 없습니다.', type: 'error');
            $this->closeModal();

            return;
        }

        try {
            $service = app(InterVehicleTransferService::class);
            $note = trim($this->financeNote) !== '' ? trim($this->financeNote) : null;

            if ($transfer->status === InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE) {
                $service->confirmByFinance($transfer, auth()->user(), $note);
                $msg = '재무 확정 완료 — 자금 이동이 시스템에 반영되었습니다.';
            } elseif ($transfer->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE) {
                $service->confirmVoidByFinance($transfer, auth()->user(), $note);
                $msg = '취소 재무 확정 완료 — 원상복구가 시스템에 반영되었습니다.';
            } else {
                throw new \DomainException('재무 처리 대기 상태가 아닙니다.');
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: '재무 확정 실패: '.$e->getMessage(), type: 'error');
            $this->closeModal();
        }
    }

    /**
     * 큐 19-K / 19-L — 재무 거부 (정방향: finance_rejected / void: executed 복귀).
     * status에 따라 rejectByFinance vs rejectVoidByFinance 자동 분기.
     */
    public function reject(): void
    {
        $this->validate(
            ['rejectReason' => ['required', 'string', 'min:5']],
            ['rejectReason.required' => '거부 사유를 5자 이상 입력하세요.', 'rejectReason.min' => '거부 사유를 5자 이상 입력하세요.'],
        );

        $transfer = InterVehicleTransfer::find($this->modalTransferId);
        if (! $transfer) {
            $this->dispatch('notify', message: '이체 정보를 찾을 수 없습니다.', type: 'error');
            $this->closeModal();

            return;
        }

        try {
            $service = app(InterVehicleTransferService::class);
            if ($transfer->status === InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE) {
                $service->rejectByFinance($transfer, auth()->user(), $this->rejectReason);
                $msg = '재무 거부 완료 — 영업에게 사유가 노출되며 새 이체 요청이 가능해집니다.';
            } elseif ($transfer->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE) {
                $service->rejectVoidByFinance($transfer, auth()->user(), $this->rejectReason);
                $msg = '취소 거부 완료 — 이체는 살아있고 영업이 다시 취소 요청 가능합니다.';
            } else {
                throw new \DomainException('재무 거부 대기 상태가 아닙니다.');
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: '재무 거부 실패: '.$e->getMessage(), type: 'error');
            $this->closeModal();
        }
    }
}; ?>

{{-- UX #6 (2026-05-20) — wire:poll.30s — 사이드바 뱃지 + 페이지 데이터 30초 자동 갱신. --}}
<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    {{-- 헤더 --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">재무 처리</h2>
            <p class="mt-1 text-xs text-gray-500">
                자금 이체 + 매입·판매 잔금 재무 확정 통합 페이지. 재무 확정 시점에 ledger 반영.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <select wire:model.live="perPage" class="input-filter">
                <option value="10">10개씩</option>
                <option value="30">30개씩</option>
                <option value="50">50개씩</option>
                <option value="100">100개씩</option>
            </select>
        </div>
    </div>

    {{-- 큐 20-C — 유형 탭 (자금 이체 / 매입 잔금 / 판매 잔금) --}}
    <div class="card flex flex-wrap items-center gap-2">
        @foreach([
            'transfer' => ['차량 간 자금 이체', $this->awaitingCount],
            'sale_payment' => ['판매 잔금', $this->salePaymentAwaitingCount],
            'purchase_payment' => ['매입 잔금', $this->purchasePaymentAwaitingCount],
        ] as $key => [$label, $cnt])
        <button wire:click="$set('tabType', '{{ $key }}')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition
                       {{ $tabType === $key ? 'bg-violet-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $label }}
            @if($cnt > 0)
            <span class="ml-1 rounded-full {{ $tabType === $key ? 'bg-white/30' : 'bg-amber-500' }} px-1.5 text-[10px] font-bold {{ $tabType === $key ? 'text-white' : 'text-white' }}">
                {{ $cnt }}
            </span>
            @endif
        </button>
        @endforeach
    </div>

    {{-- 상태 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <div class="flex gap-1 flex-wrap">
            @if($tabType === 'transfer')
                @foreach(['awaiting' => '재무 대기', 'executed' => '실행 완료', 'voided' => '취소', 'finance_rejected' => '재무 거부', 'all' => '전체'] as $val => $label)
                <button wire:click="$set('statusFilter', '{{ $val }}')"
                        class="rounded-full px-3 py-1 text-xs font-medium transition
                               {{ $statusFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                    @if($val === 'awaiting' && $this->awaitingCount > 0)
                    <span class="ml-1 rounded-full bg-amber-500 px-1.5 text-[10px] font-bold text-white">{{ $this->awaitingCount }}</span>
                    @endif
                </button>
                @endforeach
            @else
                @foreach(['awaiting' => '재무 대기', 'executed' => '확정 완료', 'all' => '전체'] as $val => $label)
                <button wire:click="$set('statusFilter', '{{ $val }}')"
                        class="rounded-full px-3 py-1 text-xs font-medium transition
                               {{ $statusFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </button>
                @endforeach
            @endif
        </div>
    </div>

    @if($tabType === 'transfer')
    {{-- 데스크탑 테이블 --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm border-separate border-spacing-0">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">유형</th>
                    <th class="pb-2 pr-4 font-medium">출처 → 대상</th>
                    <th class="pb-2 pr-4 font-medium">바이어</th>
                    <th class="pb-2 pr-4 font-medium text-right">금액</th>
                    <th class="pb-2 pr-4 font-medium">상태</th>
                    <th class="pb-2 pr-4 font-medium">승인자</th>
                    <th class="pb-2 pr-4 font-medium">경과</th>
                    <th class="pb-2 font-medium text-right">처리</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->transfers as $t)
                @php
                    $isVoid = in_array($t->status, [InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED], true);
                    $isAwaiting = in_array($t->status, [InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE], true);
                    $selfConfirm = $t->approver_id === auth()->id();
                    $decisionRef = $t->updated_at ?? $t->created_at;
                @endphp
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="py-3 pr-4">
                        @if($isVoid)
                        <span class="text-[11px] font-semibold text-red-600">⊘ 취소</span>
                        @else
                        <span class="text-[11px] font-semibold text-violet-600">▶ 이체</span>
                        @endif
                    </td>
                    <td class="py-3 pr-4 text-xs">
                        <div class="space-y-0.5">
                            <div>
                                <span class="text-gray-400">출처</span>
                                <span class="font-mono text-gray-800">{{ $t->sourceVehicle?->vehicle_number ?? '#'.$t->source_vehicle_id }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">대상</span>
                                <span class="font-mono text-gray-800">{{ $t->targetVehicle?->vehicle_number ?? '#'.$t->target_vehicle_id }}</span>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 pr-4 text-gray-700 text-xs">{{ $t->buyer?->name ?? '#'.$t->buyer_id }}</td>
                    <td class="py-3 pr-4 text-right font-semibold {{ $isVoid ? 'text-red-600' : 'text-violet-700' }}">
                        {{ number_format($t->amount, 0) }} {{ $t->currency }}
                    </td>
                    <td class="py-3 pr-4">
                        <span class="badge {{ $t->status_badge }}">{{ $t->status_label }}</span>
                    </td>
                    <td class="py-3 pr-4 text-xs text-gray-600">
                        {{ $t->approver?->name ?? '-' }}
                    </td>
                    <td class="py-3 pr-4 text-xs text-gray-500">
                        @if($isAwaiting)
                            {{ $decisionRef?->diffForHumans() }}
                        @elseif($t->status === InterVehicleTransfer::STATUS_EXECUTED)
                            {{ $t->confirmed_at?->format('Y-m-d H:i') ?? $t->executed_at?->format('Y-m-d H:i') }}
                        @else
                            {{ $t->voided_at?->format('Y-m-d H:i') }}
                        @endif
                    </td>
                    <td class="py-3 text-right">
                        @if($isAwaiting)
                            @if($selfConfirm)
                            <button type="button" disabled
                                    title="본인이 승인한 이체는 직접 재무 확정할 수 없습니다 (SoD)"
                                    class="rounded bg-gray-200 px-2.5 py-1 text-xs font-medium text-gray-400 cursor-not-allowed">
                                SoD 차단
                            </button>
                            @else
                            <div class="flex justify-end gap-1">
                                <button wire:click="openModal({{ $t->id }}, 'confirm')"
                                        class="rounded bg-emerald-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-600">
                                    재무 처리 완료
                                </button>
                                {{-- 큐 19-K/L — awaiting 2종 모두 거부 가능 (정방향: 송금 불가 / void: 환불 불가) --}}
                                @if(in_array($t->status, [InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE], true))
                                <button wire:click="openModal({{ $t->id }}, 'reject')"
                                        title="{{ $t->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE ? '환불 불가 사유로 취소 거부 (이체는 살아있음, 영업이 다시 취소 요청 가능)' : '송금 불가 사유로 거부 (영업이 사유 확인 후 새 요청 가능)' }}"
                                        class="rounded bg-red-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-600">
                                    거부
                                </button>
                                @endif
                            </div>
                            @endif
                        @elseif($t->status === InterVehicleTransfer::STATUS_FINANCE_REJECTED)
                            <span class="text-xs text-red-600">
                                {{ $t->financeRejecter?->name ?? '재무' }} 거부
                            </span>
                        @else
                            <span class="text-xs text-gray-400">
                                @if($t->confirmed_by_user_id)
                                {{ $t->financeConfirmer?->name ?? '재무' }} 확정
                                @else
                                처리됨
                                @endif
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">조건에 맞는 자금 이체가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->transfers as $t)
        @php
            $isVoid = in_array($t->status, [InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED], true);
            $isAwaiting = in_array($t->status, [InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE], true);
            $selfConfirm = $t->approver_id === auth()->id();
        @endphp
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <div class="font-medium text-gray-800">
                    @if($isVoid)
                    <span class="text-red-600">⊘ 취소</span>
                    @else
                    <span class="text-violet-600">▶ 이체</span>
                    @endif
                    · <span class="font-mono text-xs">{{ $t->sourceVehicle?->vehicle_number ?? '#'.$t->source_vehicle_id }}</span>
                    →
                    <span class="font-mono text-xs">{{ $t->targetVehicle?->vehicle_number ?? '#'.$t->target_vehicle_id }}</span>
                </div>
                <span class="badge {{ $t->status_badge }}">{{ $t->status_label }}</span>
            </div>
            <div class="mt-1 text-xs text-gray-600">
                {{ $t->buyer?->name ?? '#'.$t->buyer_id }} · 승인 {{ $t->approver?->name ?? '-' }}
            </div>
            <div class="mt-1 text-sm font-semibold {{ $isVoid ? 'text-red-600' : 'text-violet-700' }}">
                {{ number_format($t->amount, 0) }} {{ $t->currency }}
            </div>
            @if($isAwaiting)
            <div class="mt-2 flex flex-col gap-1">
                @if($selfConfirm)
                <button disabled class="w-full rounded bg-gray-200 px-3 py-1.5 text-xs font-medium text-gray-400 cursor-not-allowed">
                    SoD 차단 — 본인 승인 건 처리 불가
                </button>
                @else
                <button wire:click="openModal({{ $t->id }}, 'confirm')"
                        class="w-full rounded bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white">
                    재무 처리 완료
                </button>
                @if(in_array($t->status, [InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE], true))
                <button wire:click="openModal({{ $t->id }}, 'reject')"
                        class="w-full rounded bg-red-500 px-3 py-1.5 text-xs font-medium text-white">
                    {{ $t->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE ? '거부 (환불 불가)' : '거부 (송금 불가)' }}
                </button>
                @endif
                @endif
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">조건에 맞는 자금 이체가 없습니다.</div>
        @endforelse
    </div>

    <div>{{ $this->transfers->links() }}</div>
    @endif

    @if($tabType === 'sale_payment' || $tabType === 'purchase_payment')
    @php
        $isSale = $tabType === 'sale_payment';
        $payments = $isSale ? $this->salePayments : $this->purchasePayments;
    @endphp

    {{-- 큐 22-C 핵심 (2026-05-20) — 매입 잔금 탭: 재무가 신규 row 직접 추가 (Spec-E 입력+확정 통합). --}}
    @if($tabType === 'purchase_payment')
    <div class="card flex flex-wrap items-center justify-between gap-2">
        <div class="text-xs text-gray-600">
            자동 PBP Draft 외 별도 매입 잔금 입력이 필요한 경우 (분할 지급·추가 비용 등)
        </div>
        <button wire:click="openNewPbpModal"
                class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
            + 신규 매입 잔금 추가
        </button>
    </div>
    @endif

    {{-- 데스크탑 테이블 (판매·매입 잔금 공용) --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm border-separate border-spacing-0">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">차량</th>
                    <th class="pb-2 pr-4 font-medium">{{ $isSale ? '바이어' : '매입처/담당' }}</th>
                    <th class="pb-2 pr-4 font-medium text-right">금액</th>
                    <th class="pb-2 pr-4 font-medium">{{ $isSale ? '입금일' : '지급일' }}</th>
                    <th class="pb-2 pr-4 font-medium">메모</th>
                    <th class="pb-2 pr-4 font-medium">상태</th>
                    <th class="pb-2 font-medium text-right">처리</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $p)
                @php $isAwaiting = $p->confirmed_at === null; @endphp
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="py-3 pr-4 font-mono text-xs text-gray-800">{{ $p->vehicle?->vehicle_number ?? '#'.$p->vehicle_id }}</td>
                    <td class="py-3 pr-4 text-xs text-gray-700">
                        @if($isSale)
                            {{ $p->vehicle?->buyer?->name ?? '-' }}
                        @else
                            <div>{{ $p->vehicle?->purchase_from ?? '-' }}</div>
                            <div class="text-[10px] text-gray-500">{{ $p->vehicle?->salesman?->name ?? '-' }}</div>
                        @endif
                    </td>
                    <td class="py-3 pr-4 text-right font-semibold text-violet-700">
                        {{ number_format((float)$p->amount, 0) }} <span class="text-[10px] text-gray-500">원</span>
                    </td>
                    <td class="py-3 pr-4 text-xs text-gray-600">{{ $p->payment_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-xs text-gray-500 max-w-xs truncate">{{ $p->note ?? '' }}</td>
                    <td class="py-3 pr-4">
                        @if($isAwaiting)
                        <span class="badge badge-amber">대기</span>
                        @else
                        <span class="badge badge-green">확정</span>
                        <div class="mt-0.5 text-[10px] text-gray-500">
                            {{ $p->financeConfirmer?->name ?? '재무' }} · {{ $p->confirmed_at?->format('Y-m-d H:i') }}
                        </div>
                        @endif
                    </td>
                    <td class="py-3 text-right">
                        @if($isAwaiting)
                        <button wire:click="openPaymentModal({{ $p->id }})"
                                class="rounded bg-emerald-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-600">
                            재무 처리 완료
                        </button>
                        @else
                        <span class="text-xs text-gray-400">처리됨</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-12 text-center text-sm text-gray-400">조건에 맞는 잔금이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 (판매·매입 잔금 공용) --}}
    <div class="block sm:hidden space-y-2">
        @forelse($payments as $p)
        @php $isAwaiting = $p->confirmed_at === null; @endphp
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <div class="font-mono text-xs text-gray-800">{{ $p->vehicle?->vehicle_number ?? '#'.$p->vehicle_id }}</div>
                <span class="badge {{ $isAwaiting ? 'badge-amber' : 'badge-green' }}">{{ $isAwaiting ? '대기' : '확정' }}</span>
            </div>
            <div class="mt-1 text-xs text-gray-600">
                @if($isSale)
                    {{ $p->vehicle?->buyer?->name ?? '-' }}
                @else
                    {{ $p->vehicle?->purchase_from ?? '-' }} · {{ $p->vehicle?->salesman?->name ?? '-' }}
                @endif
            </div>
            <div class="mt-1 text-sm font-semibold text-violet-700">
                {{ number_format((float)$p->amount, 0) }} 원
                <span class="ml-2 text-[10px] text-gray-500">{{ $p->payment_date?->format('Y-m-d') ?? '-' }}</span>
            </div>
            @if($p->note)
            <div class="mt-1 text-[11px] text-gray-500">{{ $p->note }}</div>
            @endif
            @if($isAwaiting)
            <button wire:click="openPaymentModal({{ $p->id }})"
                    class="mt-2 w-full rounded bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white">
                재무 처리 완료
            </button>
            @else
            <div class="mt-1 text-[10px] text-gray-500">
                {{ $p->financeConfirmer?->name ?? '재무' }} · {{ $p->confirmed_at?->format('Y-m-d H:i') }}
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">조건에 맞는 잔금이 없습니다.</div>
        @endforelse
    </div>

    <div>{{ $payments->links() }}</div>
    @endif

</div>

{{-- 재무 확정 / 거부 모달 (큐 19-K — decisionMode 분기) --}}
@if($showModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     wire:click.self="closeModal">
    <div class="card max-w-md mx-4 shadow-2xl">
        @if($decisionMode === 'reject')
        @php
            $modalTransfer = $modalTransferId ? \App\Models\InterVehicleTransfer::find($modalTransferId) : null;
            $isVoidReject = $modalTransfer?->status === \App\Models\InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE;
        @endphp
        <h3 class="text-base font-semibold text-red-700">{{ $isVoidReject ? '취소 거부 확인' : '재무 거부 확인' }}</h3>
        <p class="mt-2 text-sm text-gray-600">
            @if($isVoidReject)
            환불 불가·역송금 거부 등 사유로 취소를 거부합니다.
            <strong>이체 자체는 살아있고 final_payment 페어는 그대로 유지</strong>됩니다.
            영업이 사유를 확인하고 다시 취소 요청 가능합니다.
            @else
            통장 잔액 부족·송금 실패·입금자 불일치 등 송금 불가 사유로 거부합니다.
            <strong>final_payment 는 생성되지 않으며 ledger 영향이 없습니다.</strong>
            영업에게 거부 사유가 노출되며 새 이체 요청이 가능해집니다.
            @endif
        </p>
        <div class="mt-3">
            <label class="block text-xs text-gray-500 mb-1">거부 사유 (필수, 5자 이상)</label>
            <textarea wire:model="rejectReason" rows="3"
                      class="input-base"
                      placeholder="예: 통장 잔액 부족 / 입금자명 불일치 / 송금 보류 등"></textarea>
            @error('rejectReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button wire:click="reject"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                재무 거부
            </button>
        </div>
        @else
        @php
            // 큐 20-C — tabType별 confirm 액션 분기
            $confirmAction = $tabType === 'transfer' ? 'confirm' : 'confirmPayment';
            $confirmTitle = match($tabType) {
                'sale_payment' => '판매 잔금 재무 확정',
                'purchase_payment' => '매입 잔금 재무 확정',
                default => '재무 처리 완료 확인',
            };
            $confirmDesc = match($tabType) {
                'sale_payment' => '바이어 입금이 통장에 들어왔는지 확인 후 [재무 처리 완료]를 클릭하세요. 이 시점에 confirmed_at SET → ledger 반영 → 미수금 감소.',
                'purchase_payment' => '매입처 송금이 완료됐는지 확인 후 [재무 처리 완료]를 클릭하세요. 이 시점에 confirmed_at SET → ledger 반영 → 미지급 감소.',
                default => '통장 거래가 정상적으로 처리되었는지 확인 후 [재무 처리 완료] 를 클릭하세요. 이 시점에 시스템 ledger (final_payment) 가 기록되고 양 차량 미수 캐시가 갱신됩니다.',
            };
        @endphp
        <h3 class="text-base font-semibold text-gray-900">{{ $confirmTitle }}</h3>
        <p class="mt-2 text-sm text-gray-600">{{ $confirmDesc }}</p>

        {{-- 22-A-2 — 매입 잔금: 매입처 송금 정보 자동 표시 (사용자 안건 2) --}}
        @if($tabType === 'purchase_payment' && ($modalPurchaseFrom || $modalPurchaseBank || $modalPurchaseAccount))
        <div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs">
            <div class="mb-1 font-medium text-blue-900">송금 대상 (매입처 정보)</div>
            <dl class="grid grid-cols-[80px_1fr] gap-y-1 text-gray-700">
                <dt class="text-gray-500">매입처</dt><dd>{{ $modalPurchaseFrom ?: '-' }}</dd>
                <dt class="text-gray-500">은행</dt><dd>{{ $modalPurchaseBank ?: '-' }}</dd>
                <dt class="text-gray-500">계좌번호</dt><dd class="font-mono">{{ $modalPurchaseAccount ?: '-' }}</dd>
                <dt class="text-gray-500">예금주</dt><dd>{{ $modalPurchaseHolder ?: '-' }}</dd>
                @if($modalPurchaseBankMemo)
                <dt class="text-gray-500">송금 메모</dt><dd class="text-gray-600">{{ $modalPurchaseBankMemo }}</dd>
                @endif
            </dl>
        </div>
        @endif

        {{-- 2026-05-21 사용자 피드백 — 매입 잔금: 매입가/매도비/총금액 표시 --}}
        @if($tabType === 'purchase_payment' && ! empty($modalVehicleData))
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs">
            <div class="mb-1 font-medium text-amber-900">차량 매입 금액</div>
            <dl class="grid grid-cols-[80px_1fr] gap-y-1 text-gray-700">
                <dt class="text-gray-500">매입가</dt><dd class="text-right">₩{{ number_format($modalVehicleData['purchase_price'] ?? 0) }}</dd>
                <dt class="text-gray-500">매도비</dt><dd class="text-right">₩{{ number_format($modalVehicleData['selling_fee'] ?? 0) }}</dd>
                <dt class="text-gray-500 font-medium border-t border-amber-200 pt-1">총금액</dt>
                <dd class="text-right font-bold text-amber-900 border-t border-amber-200 pt-1">₩{{ number_format($modalVehicleData['purchase_total'] ?? 0) }}</dd>
            </dl>
        </div>
        @endif

        {{-- 2026-05-21 사용자 피드백 — 판매 잔금: 판매가/커미션 등 표시 --}}
        @if($tabType === 'sale_payment' && ! empty($modalVehicleData))
        <div class="mt-3 rounded-lg border border-purple-200 bg-purple-50 p-3 text-xs">
            <div class="mb-1 font-medium text-purple-900">차량 판매 금액 {{ $modalVehicleData['buyer_name'] ? '(바이어: '.$modalVehicleData['buyer_name'].')' : '' }}</div>
            @php $curr = $modalVehicleData['currency'] ?? 'KRW'; $sym = $curr === 'KRW' ? '₩' : ''; @endphp
            <dl class="grid grid-cols-[100px_1fr] gap-y-1 text-gray-700">
                <dt class="text-gray-500">판매가</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['sale_price'] ?? 0) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @if(($modalVehicleData['commission'] ?? 0) > 0)
                <dt class="text-gray-500">+ 커미션</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['commission']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                @if(($modalVehicleData['auto_loading'] ?? 0) > 0)
                <dt class="text-gray-500">+ 자동하역비</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['auto_loading']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                @if(($modalVehicleData['tax_dc'] ?? 0) > 0)
                <dt class="text-gray-500">- TAX/DC</dt><dd class="text-right text-red-500">{{ $sym }}{{ number_format($modalVehicleData['tax_dc']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                @if(($modalVehicleData['transport_fee'] ?? 0) > 0)
                <dt class="text-gray-500">+ 운임비</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['transport_fee']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                <dt class="text-gray-500 font-medium border-t border-purple-200 pt-1">총 판매액</dt>
                <dd class="text-right font-bold text-purple-900 border-t border-purple-200 pt-1">{{ $sym }}{{ number_format($modalVehicleData['sale_total'] ?? 0) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
            </dl>
        </div>
        @endif

        <div class="mt-3">
            <label class="block text-xs text-gray-500 mb-1">은행 거래 번호 또는 처리 메모 (선택)</label>
            <textarea wire:model="financeNote" rows="3"
                      class="input-base"
                      placeholder="예: KB 12345-6789 / 신한 거래번호 등 — 입력 시 finance_note 에 기록"></textarea>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button wire:click="{{ $confirmAction }}"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                재무 처리 완료
            </button>
        </div>
        @endif
    </div>
</div>
@endif

{{-- 큐 22-C 핵심 (2026-05-20) — 매입 잔금 신규 row 입력+확정 통합 모달. --}}
@if($showNewPbpModal)
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeNewPbpModal"
     wire:key="new-pbp-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">매입 잔금 신규 추가</h3>
        <p class="mt-1 text-xs text-gray-500">재무가 자동 PBP Draft 외 별도 매입 잔금 row 입력. 즉시 확정 옵션 선택 가능.</p>

        <div class="mt-3 space-y-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">대상 차량</label>
                <select wire:model="newPbpVehicleId" class="input-base">
                    <option value="">-- 차량 선택 --</option>
                    @foreach($this->purchaseEligibleVehicles as $v)
                    <option value="{{ $v->id }}">{{ $v->vehicle_number }} ({{ $v->purchase_from ?: '미지정' }})</option>
                    @endforeach
                </select>
                @error('newPbpVehicleId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">금액 (원)</label>
                <input wire:model="newPbpAmountStr" type="text" class="input-base" placeholder="0" />
                @error('newPbpAmountStr') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">지급일</label>
                <input wire:model="newPbpDate" type="date" class="input-base" />
                @error('newPbpDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">메모 (선택)</label>
                <textarea wire:model="newPbpNote" rows="2" class="input-base"
                          placeholder="예: 2차 잔금, 탁송비, 수리비 등"></textarea>
            </div>

            <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                <input wire:model="newPbpImmediateConfirm" type="checkbox" class="rounded" />
                <span>즉시 재무 확정 (체크 시 ledger 반영 / 미체크 시 Draft 상태로 저장)</span>
            </label>
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeNewPbpModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button wire:click="createNewPbp" wire:loading.attr="disabled" wire:target="createNewPbp"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                <span wire:loading.remove wire:target="createNewPbp">추가</span>
                <span wire:loading wire:target="createNewPbp">처리 중...</span>
            </button>
        </div>
    </div>
</div>
@endif

</div>
