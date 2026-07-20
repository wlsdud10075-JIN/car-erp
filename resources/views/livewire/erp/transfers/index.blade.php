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

    // 🔒 매입 지급 락 (#2) — 2번째 지급~ && 그 차 판매금 <50% 입금 시 차단. 관리 승인 우회(1회).
    public bool $showPaymentGate = false;

    public array $paymentGateInfo = [];

    public string $paymentGateReason = '';

    public bool $paymentGateApproved = false;

    public function mount(): void
    {
        if (! auth()->user()?->canConfirmFinanceTransfer()) {
            abort(403, __('transfer.forbidden'));
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

    /** 큐 20-C — 판매 잔금 (영업 직접 입력 = transfer_id IS NULL).
     * 회의확장씬 (2026-05-22) — vehicle soft delete 시 PBP/FP 자동 제외 (whereHas('vehicle')).
     * Vehicle SoftDeletes + PBP/FP 는 미사용 → vehicles.deleted_at IS NULL 자동 매칭.
     */
    #[Computed]
    public function salePayments()
    {
        return FinalPayment::query()
            ->with(['vehicle:id,vehicle_number,buyer_id', 'vehicle.buyer:id,name', 'financeConfirmer:id,name'])
            ->whereHas('vehicle')
            ->whereNull('transfer_id')
            ->when($this->statusFilter === 'awaiting', fn ($q) => $q->whereNull('confirmed_at'))
            ->when($this->statusFilter === 'executed', fn ($q) => $q->whereNotNull('confirmed_at'))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function salePaymentAwaitingCount(): int
    {
        return FinalPayment::whereHas('vehicle')
            ->whereNull('transfer_id')
            ->whereNull('confirmed_at')
            ->count();
    }

    /** 큐 20-C — 매입 잔금. */
    #[Computed]
    public function purchasePayments()
    {
        return PurchaseBalancePayment::query()
            ->with(['vehicle:id,vehicle_number,purchase_from,salesman_id', 'vehicle.salesman:id,name', 'financeConfirmer:id,name'])
            ->whereHas('vehicle')
            ->when($this->statusFilter === 'awaiting', fn ($q) => $q->whereNull('confirmed_at'))
            ->when($this->statusFilter === 'executed', fn ($q) => $q->whereNotNull('confirmed_at'))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function purchasePaymentAwaitingCount(): int
    {
        return PurchaseBalancePayment::whereHas('vehicle')->whereNull('confirmed_at')->count();
    }

    /** 큐 20-C — 잔금 모달 열기 (sale_payment / purchase_payment 공용). */
    public function openPaymentModal(int $id): void
    {
        if ($this->tabType === 'sale_payment') {
            $p = FinalPayment::find($id);
            if (! $p || $p->confirmed_at !== null || $p->transfer_id !== null) {
                $this->dispatch('notify', message: __('transfer.msg.only_awaiting_sale'), type: 'warning');

                return;
            }
        } elseif ($this->tabType === 'purchase_payment') {
            $p = PurchaseBalancePayment::find($id);
            if (! $p || $p->confirmed_at !== null) {
                $this->dispatch('notify', message: __('transfer.msg.only_awaiting_purchase'), type: 'warning');

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

            $target = $this->modalPurchaseFrom ?: __('transfer.remit_target_fallback');
            $bank = $this->modalPurchaseBank ?: '';
            // claudefinalreview 3-2 — 송금메모에서 계좌번호 제외(암호화 우회 방지).
            // 계좌는 화면(modalPurchaseAccount)에만 복호화 표시, finance_note 평문엔 미저장.
            if ($bank !== '' || ($this->modalPurchaseFrom ?: '') !== '') {
                $parts = array_values(array_filter([$target, $bank], fn ($s) => $s !== ''));
                $this->financeNote = implode('/', $parts).__('transfer.remit_suffix');
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
                    throw new \DomainException(__('transfer.msg.sale_not_found'));
                }
                $service->confirmPayment($p, auth()->user(), $note);
                $msg = __('transfer.msg.sale_confirmed');
            } elseif ($this->tabType === 'purchase_payment') {
                $p = PurchaseBalancePayment::find($this->modalPaymentId);
                if (! $p) {
                    throw new \DomainException(__('transfer.msg.purchase_not_found'));
                }
                $service->confirmPurchasePayment($p, auth()->user(), $note);
                $msg = __('transfer.msg.purchase_confirmed');
            } else {
                throw new \DomainException(__('transfer.msg.unknown_tab'));
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('transfer.msg.confirm_failed', ['error' => $e->getMessage()]), type: 'error');
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
            $this->dispatch('notify', message: __('transfer.msg.only_awaiting_transfer'), type: 'warning');

            return;
        }
        if ($transfer->approver_id === auth()->id()) {
            $this->dispatch('notify', message: __('transfer.msg.self_confirm_block'), type: 'warning');

            return;
        }
        // 큐 19-K/L — 거부 모드는 awaiting 2종(정방향/void) 모두 가능.
        if ($mode === 'reject' && ! in_array($transfer->status, [
            InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
        ], true)) {
            $this->dispatch('notify', message: __('transfer.msg.reject_only_awaiting'), type: 'warning');

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
        $this->resetPaymentGate();
    }

    public function closeNewPbpModal(): void
    {
        $this->showNewPbpModal = false;
        $this->newPbpVehicleId = '';
        $this->newPbpAmountStr = '';
        $this->newPbpDate = '';
        $this->newPbpNote = '';
        $this->newPbpImmediateConfirm = true;
        $this->resetPaymentGate();
    }

    private function resetPaymentGate(): void
    {
        $this->showPaymentGate = false;
        $this->paymentGateInfo = [];
        $this->paymentGateReason = '';
        $this->paymentGateApproved = false;
    }

    /**
     * 🔒 매입 지급 락 (#2) — 첫 매입 지급(계약금 성격)은 허용, 기존 PBP 1건 이상일 때(2번째~)
     * 그 차 판매 미수율 > 50% 면 게이트. 판매 전(ratio null)은 게이트 안 함.
     */
    private function shouldGatePurchasePayment(\App\Models\Vehicle $vehicle): bool
    {
        if ($vehicle->purchaseBalancePayments()->count() < 1) {
            return false;   // 첫 지급(계약금) — 자유
        }
        $ratio = $vehicle->unpaid_ratio;

        return $ratio !== null && $ratio > \App\Models\Setting::lockThreshold('purchase_payment');
    }

    public function approvePaymentGate(): void
    {
        abort_unless(auth()->user()?->canApproveUnpaidExport(), 403);
        if (trim($this->paymentGateReason) === '') {
            $this->addError('paymentGateReason', __('transfer.pbp_gate.reason_required'));

            return;
        }
        $this->paymentGateApproved = true;
        $this->showPaymentGate = false;
        $this->createNewPbp();   // 재실행 — 게이트 통과 + override 감사
    }

    public function cancelPaymentGate(): void
    {
        $this->showPaymentGate = false;
        $this->paymentGateReason = '';
        $this->paymentGateApproved = false;
    }

    public function createNewPbp(): void
    {
        $this->validate([
            'newPbpVehicleId' => ['required', 'exists:vehicles,id'],
            'newPbpAmountStr' => ['required', 'numeric', 'gt:0'],
            'newPbpDate' => ['required', 'date'],
            'newPbpNote' => ['nullable', 'string', 'max:255'],
        ], [], [
            'newPbpVehicleId' => __('transfer.attr.vehicle'),
            'newPbpAmountStr' => __('transfer.attr.amount'),
            'newPbpDate' => __('transfer.attr.date'),
            'newPbpNote' => __('transfer.attr.memo'),
        ]);

        // 🔒 매입 지급 락 (#2) — 락 ON + 2번째 지급~ + 그 차 판매금 <50% 입금 → 차단, 관리 승인 우회.
        $vehicle = \App\Models\Vehicle::find((int) $this->newPbpVehicleId);
        if (! $this->paymentGateApproved
            && $vehicle
            && \App\Models\Setting::lockEnabled('purchase_payment')
            && $this->shouldGatePurchasePayment($vehicle)) {
            $this->paymentGateInfo = [
                'vehicle' => $vehicle->vehicle_number,
                'ratio' => round(($vehicle->unpaid_ratio ?? 0) * 100, 1),
            ];
            $this->paymentGateReason = '';
            $this->showPaymentGate = true;

            return;   // 차단 — 승인 모달
        }

        try {
            $pbp = PurchaseBalancePayment::create([
                'vehicle_id' => (int) $this->newPbpVehicleId,
                'amount' => (float) str_replace(',', '', $this->newPbpAmountStr),
                'payment_date' => $this->newPbpDate,
                'note' => $this->newPbpNote ?: null,
                'created_by_user_id' => auth()->id(),
            ]);

            // 미수 우회 승인 기록 — 누가 언제 <50% 차량 매입 지급을 승인했나 (감사).
            if ($this->paymentGateApproved && $vehicle) {
                \App\Models\AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => \App\Models\Vehicle::class,
                    'auditable_id' => $vehicle->id,
                    'action' => 'purchase_payment_gate_override',
                    'column_name' => 'purchase_balance_payments',
                    'old_value' => '판매 미수율 '.round(($vehicle->unpaid_ratio ?? 0) * 100, 1).'%',
                    'new_value' => '매입 지급 승인: '.$this->paymentGateReason,
                    'ip_address' => request()?->ip(),
                ]);
            }

            if ($this->newPbpImmediateConfirm) {
                app(PaymentConfirmationService::class)
                    ->confirmPurchasePayment($pbp, auth()->user(), __('transfer.msg.pbp_immediate_note'));
                $msg = __('transfer.msg.pbp_added_confirmed');
            } else {
                $msg = __('transfer.msg.pbp_added_draft');
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeNewPbpModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('transfer.msg.pbp_add_failed', ['error' => $e->getMessage()]), type: 'error');
        }
    }

    public function confirm(): void
    {
        $transfer = InterVehicleTransfer::find($this->modalTransferId);
        if (! $transfer) {
            $this->dispatch('notify', message: __('transfer.msg.transfer_not_found'), type: 'error');
            $this->closeModal();

            return;
        }

        try {
            $service = app(InterVehicleTransferService::class);
            $note = trim($this->financeNote) !== '' ? trim($this->financeNote) : null;

            if ($transfer->status === InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE) {
                $service->confirmByFinance($transfer, auth()->user(), $note);
                $msg = __('transfer.msg.transfer_confirmed');
            } elseif ($transfer->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE) {
                $service->confirmVoidByFinance($transfer, auth()->user(), $note);
                $msg = __('transfer.msg.void_confirmed');
            } else {
                throw new \DomainException(__('transfer.msg.not_awaiting'));
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('transfer.msg.confirm_failed', ['error' => $e->getMessage()]), type: 'error');
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
            ['rejectReason.required' => __('transfer.msg.reject_reason_min'), 'rejectReason.min' => __('transfer.msg.reject_reason_min')],
        );

        $transfer = InterVehicleTransfer::find($this->modalTransferId);
        if (! $transfer) {
            $this->dispatch('notify', message: __('transfer.msg.transfer_not_found'), type: 'error');
            $this->closeModal();

            return;
        }

        try {
            $service = app(InterVehicleTransferService::class);
            if ($transfer->status === InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE) {
                $service->rejectByFinance($transfer, auth()->user(), $this->rejectReason);
                $msg = __('transfer.msg.reject_done');
            } elseif ($transfer->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE) {
                $service->rejectVoidByFinance($transfer, auth()->user(), $this->rejectReason);
                $msg = __('transfer.msg.void_reject_done');
            } else {
                throw new \DomainException(__('transfer.msg.not_awaiting_reject'));
            }

            $this->dispatch('notify', message: $msg, type: 'success');
            $this->closeModal();
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('transfer.msg.reject_failed', ['error' => $e->getMessage()]), type: 'error');
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
            <h2 class="text-xl font-bold text-gray-800">{{ __('transfer.title') }}</h2>
            <p class="mt-1 text-xs text-gray-500">
                {{ __('transfer.subtitle') }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <select wire:model.live="perPage" class="input-filter">
                <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
                <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
                <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
                <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
            </select>
        </div>
    </div>

    {{-- 큐 20-C — 유형 탭 (자금 이체 / 매입 잔금 / 판매 잔금) --}}
    <div class="card flex flex-wrap items-center gap-2">
        @foreach([
            'transfer' => [__('transfer.tab.transfer'), $this->awaitingCount],
            'sale_payment' => [__('transfer.tab.sale_payment'), $this->salePaymentAwaitingCount],
            'purchase_payment' => [__('transfer.tab.purchase_payment'), $this->purchasePaymentAwaitingCount],
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
                @foreach(['awaiting' => __('transfer.filter.awaiting'), 'executed' => __('transfer.filter.executed_transfer'), 'voided' => __('transfer.filter.voided'), 'finance_rejected' => __('transfer.filter.finance_rejected'), 'all' => __('transfer.filter.all')] as $val => $label)
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
                @foreach(['awaiting' => __('transfer.filter.awaiting'), 'executed' => __('transfer.filter.executed_payment'), 'all' => __('transfer.filter.all')] as $val => $label)
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
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.type') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.route') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.buyer') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right">{{ __('transfer.col.amount') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.status') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.approver') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.elapsed') }}</th>
                    <th class="pb-2 font-medium text-right">{{ __('transfer.col.action') }}</th>
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
                        <span class="text-[11px] font-semibold text-red-600">{{ __('transfer.type_void') }}</span>
                        @else
                        <span class="text-[11px] font-semibold text-violet-600">{{ __('transfer.type_transfer') }}</span>
                        @endif
                    </td>
                    <td class="py-3 pr-4 text-xs">
                        <div class="space-y-0.5">
                            <div>
                                <span class="text-gray-400">{{ __('transfer.col.source') }}</span>
                                <span class="font-mono text-gray-800">{{ $t->sourceVehicle?->vehicle_number ?? '#'.$t->source_vehicle_id }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">{{ __('transfer.col.target') }}</span>
                                <span class="font-mono text-gray-800">{{ $t->targetVehicle?->vehicle_number ?? '#'.$t->target_vehicle_id }}</span>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 pr-4 text-gray-700 text-xs">{{ $t->buyer?->name ?? '#'.$t->buyer_id }}</td>
                    <td class="py-3 pr-4 text-right font-semibold {{ $isVoid ? 'text-red-600' : 'text-violet-700' }}">
                        {{ number_format($t->amount, 0) }} {{ $t->currency }}
                    </td>
                    <td class="py-3 pr-4">
                        <span class="badge {{ $t->status_badge }}">{{ __('transfer.status.'.$t->status) }}</span>
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
                                    title="{{ __('transfer.sod_block_title') }}"
                                    class="rounded bg-gray-200 px-2.5 py-1 text-xs font-medium text-gray-400 cursor-not-allowed">
                                {{ __('transfer.sod_block') }}
                            </button>
                            @else
                            <div class="flex justify-end gap-1">
                                <button wire:click="openModal({{ $t->id }}, 'confirm')"
                                        class="rounded bg-emerald-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-600">
                                    {{ __('transfer.process_done') }}
                                </button>
                                {{-- 큐 19-K/L — awaiting 2종 모두 거부 가능 (정방향: 송금 불가 / void: 환불 불가) --}}
                                @if(in_array($t->status, [InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE], true))
                                <button wire:click="openModal({{ $t->id }}, 'reject')"
                                        title="{{ $t->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE ? __('transfer.reject_void_title') : __('transfer.reject_normal_title') }}"
                                        class="rounded bg-red-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-600">
                                    {{ __('transfer.reject') }}
                                </button>
                                @endif
                            </div>
                            @endif
                        @elseif($t->status === InterVehicleTransfer::STATUS_FINANCE_REJECTED)
                            <span class="text-xs text-red-600">
                                {{ __('transfer.rejected_by', ['name' => $t->financeRejecter?->name ?? __('transfer.finance_fallback')]) }}
                            </span>
                        @else
                            <span class="text-xs text-gray-400">
                                @if($t->confirmed_by_user_id)
                                {{ __('transfer.confirmed_by', ['name' => $t->financeConfirmer?->name ?? __('transfer.finance_fallback')]) }}
                                @else
                                {{ __('transfer.processed') }}
                                @endif
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">{{ __('transfer.empty_transfer') }}</td></tr>
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
                    <span class="text-red-600">{{ __('transfer.type_void') }}</span>
                    @else
                    <span class="text-violet-600">{{ __('transfer.type_transfer') }}</span>
                    @endif
                    · <span class="font-mono text-xs">{{ $t->sourceVehicle?->vehicle_number ?? '#'.$t->source_vehicle_id }}</span>
                    →
                    <span class="font-mono text-xs">{{ $t->targetVehicle?->vehicle_number ?? '#'.$t->target_vehicle_id }}</span>
                </div>
                <span class="badge {{ $t->status_badge }}">{{ __('transfer.status.'.$t->status) }}</span>
            </div>
            <div class="mt-1 text-xs text-gray-600">
                {{ $t->buyer?->name ?? '#'.$t->buyer_id }} · {{ __('transfer.col.approver') }} {{ $t->approver?->name ?? '-' }}
            </div>
            <div class="mt-1 text-sm font-semibold {{ $isVoid ? 'text-red-600' : 'text-violet-700' }}">
                {{ number_format($t->amount, 0) }} {{ $t->currency }}
            </div>
            @if($isAwaiting)
            <div class="mt-2 flex flex-col gap-1">
                @if($selfConfirm)
                <button disabled class="w-full rounded bg-gray-200 px-3 py-1.5 text-xs font-medium text-gray-400 cursor-not-allowed">
                    {{ __('transfer.sod_block_mobile') }}
                </button>
                @else
                <button wire:click="openModal({{ $t->id }}, 'confirm')"
                        class="w-full rounded bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white">
                    {{ __('transfer.process_done') }}
                </button>
                @if(in_array($t->status, [InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE, InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE], true))
                <button wire:click="openModal({{ $t->id }}, 'reject')"
                        class="w-full rounded bg-red-500 px-3 py-1.5 text-xs font-medium text-white">
                    {{ $t->status === InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE ? __('transfer.reject_void_mobile') : __('transfer.reject_normal_mobile') }}
                </button>
                @endif
                @endif
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('transfer.empty_transfer') }}</div>
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
            {{ __('transfer.new_pbp_hint') }}
        </div>
        <button wire:click="openNewPbpModal"
                class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
            {{ __('transfer.new_pbp_btn') }}
        </button>
    </div>
    @endif

    {{-- 데스크탑 테이블 (판매·매입 잔금 공용) --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm border-separate border-spacing-0">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.vehicle') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ $isSale ? __('transfer.col.buyer') : __('transfer.col.buyer_or_purchase') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right">{{ __('transfer.col.amount') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ $isSale ? __('transfer.col.date_sale') : __('transfer.col.date_purchase') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.memo') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('transfer.col.status') }}</th>
                    <th class="pb-2 font-medium text-right">{{ __('transfer.col.action') }}</th>
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
                        {{ number_format((float)$p->amount, 0) }} <span class="text-[10px] text-gray-500">{{ __('transfer.unit_won') }}</span>
                    </td>
                    <td class="py-3 pr-4 text-xs text-gray-600">{{ $p->payment_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-xs text-gray-500 max-w-xs truncate">{{ $p->note ?? '' }}</td>
                    <td class="py-3 pr-4">
                        @if($isAwaiting)
                        <span class="badge badge-amber">{{ __('transfer.pending') }}</span>
                        @else
                        <span class="badge badge-green">{{ __('transfer.confirmed') }}</span>
                        <div class="mt-0.5 text-[10px] text-gray-500">
                            {{ $p->financeConfirmer?->name ?? __('transfer.finance_fallback') }} · {{ $p->confirmed_at?->format('Y-m-d H:i') }}
                        </div>
                        @endif
                    </td>
                    <td class="py-3 text-right">
                        @if($isAwaiting)
                        <button wire:click="openPaymentModal({{ $p->id }})"
                                class="rounded bg-emerald-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-600">
                            {{ __('transfer.process_done') }}
                        </button>
                        @else
                        <span class="text-xs text-gray-400">{{ __('transfer.processed') }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-12 text-center text-sm text-gray-400">{{ __('transfer.empty_payment') }}</td></tr>
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
                <span class="badge {{ $isAwaiting ? 'badge-amber' : 'badge-green' }}">{{ $isAwaiting ? __('transfer.pending') : __('transfer.confirmed') }}</span>
            </div>
            <div class="mt-1 text-xs text-gray-600">
                @if($isSale)
                    {{ $p->vehicle?->buyer?->name ?? '-' }}
                @else
                    {{ $p->vehicle?->purchase_from ?? '-' }} · {{ $p->vehicle?->salesman?->name ?? '-' }}
                @endif
            </div>
            <div class="mt-1 text-sm font-semibold text-violet-700">
                {{ number_format((float)$p->amount, 0) }} {{ __('transfer.unit_won') }}
                <span class="ml-2 text-[10px] text-gray-500">{{ $p->payment_date?->format('Y-m-d') ?? '-' }}</span>
            </div>
            @if($p->note)
            <div class="mt-1 text-[11px] text-gray-500">{{ $p->note }}</div>
            @endif
            @if($isAwaiting)
            <button wire:click="openPaymentModal({{ $p->id }})"
                    class="mt-2 w-full rounded bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white">
                {{ __('transfer.process_done') }}
            </button>
            @else
            <div class="mt-1 text-[10px] text-gray-500">
                {{ $p->financeConfirmer?->name ?? __('transfer.finance_fallback') }} · {{ $p->confirmed_at?->format('Y-m-d H:i') }}
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('transfer.empty_payment') }}</div>
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
        <h3 class="text-base font-semibold text-red-700">{{ $isVoidReject ? __('transfer.reject_modal.title_void') : __('transfer.reject_modal.title_normal') }}</h3>
        <p class="mt-2 text-sm text-gray-600">
            @if($isVoidReject)
            {{ __('transfer.reject_modal.desc_void_1') }}
            <strong>{{ __('transfer.reject_modal.desc_void_strong') }}</strong>{{ __('transfer.reject_modal.desc_void_2') }}
            @else
            {{ __('transfer.reject_modal.desc_normal_1') }}
            <strong>{{ __('transfer.reject_modal.desc_normal_strong') }}</strong>
            {{ __('transfer.reject_modal.desc_normal_2') }}
            @endif
        </p>
        <div class="mt-3">
            <label class="block text-xs text-gray-500 mb-1">{{ __('transfer.reject_modal.reason_label') }}</label>
            <textarea wire:model="rejectReason" rows="3"
                      class="input-base"
                      placeholder="{{ __('transfer.reject_modal.reason_ph') }}"></textarea>
            @error('rejectReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="reject"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                {{ __('transfer.reject_modal.submit') }}
            </button>
        </div>
        @else
        @php
            // 큐 20-C — tabType별 confirm 액션 분기
            $confirmAction = $tabType === 'transfer' ? 'confirm' : 'confirmPayment';
            $confirmTitle = match($tabType) {
                'sale_payment' => __('transfer.confirm_modal.title_sale'),
                'purchase_payment' => __('transfer.confirm_modal.title_purchase'),
                default => __('transfer.confirm_modal.title_default'),
            };
            $confirmDesc = match($tabType) {
                'sale_payment' => __('transfer.confirm_modal.desc_sale'),
                'purchase_payment' => __('transfer.confirm_modal.desc_purchase'),
                default => __('transfer.confirm_modal.desc_default'),
            };
        @endphp
        <h3 class="text-base font-semibold text-gray-900">{{ $confirmTitle }}</h3>
        <p class="mt-2 text-sm text-gray-600">{{ $confirmDesc }}</p>

        {{-- 22-A-2 — 매입 잔금: 매입처 송금 정보 자동 표시 (사용자 안건 2) --}}
        @if($tabType === 'purchase_payment' && ($modalPurchaseFrom || $modalPurchaseBank || $modalPurchaseAccount))
        <div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs">
            <div class="mb-1 font-medium text-blue-900">{{ __('transfer.confirm_modal.remit_title') }}</div>
            <dl class="grid grid-cols-[80px_1fr] gap-y-1 text-gray-700">
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.remit_from') }}</dt><dd>{{ $modalPurchaseFrom ?: '-' }}</dd>
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.remit_bank') }}</dt><dd>{{ $modalPurchaseBank ?: '-' }}</dd>
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.remit_account') }}</dt><dd class="font-mono">{{ $modalPurchaseAccount ?: '-' }}</dd>
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.remit_holder') }}</dt><dd>{{ $modalPurchaseHolder ?: '-' }}</dd>
                @if($modalPurchaseBankMemo)
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.remit_memo') }}</dt><dd class="text-gray-600">{{ $modalPurchaseBankMemo }}</dd>
                @endif
            </dl>
        </div>
        @endif

        {{-- 2026-05-21 사용자 피드백 — 매입 잔금: 매입가/매도비/총금액 표시 --}}
        @if($tabType === 'purchase_payment' && ! empty($modalVehicleData))
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs">
            <div class="mb-1 font-medium text-amber-900">{{ __('transfer.confirm_modal.purchase_amount_title') }}</div>
            <dl class="grid grid-cols-[80px_1fr] gap-y-1 text-gray-700">
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.purchase_price') }}</dt><dd class="text-right">₩{{ number_format($modalVehicleData['purchase_price'] ?? 0) }}</dd>
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.selling_fee') }}</dt><dd class="text-right">₩{{ number_format($modalVehicleData['selling_fee'] ?? 0) }}</dd>
                <dt class="text-gray-500 font-medium border-t border-amber-200 pt-1">{{ __('transfer.confirm_modal.purchase_total') }}</dt>
                <dd class="text-right font-bold text-amber-900 border-t border-amber-200 pt-1">₩{{ number_format($modalVehicleData['purchase_total'] ?? 0) }}</dd>
            </dl>
        </div>
        @endif

        {{-- 2026-05-21 사용자 피드백 — 판매 잔금: 판매가/커미션 등 표시 --}}
        @if($tabType === 'sale_payment' && ! empty($modalVehicleData))
        <div class="mt-3 rounded-lg border border-purple-200 bg-purple-50 p-3 text-xs">
            <div class="mb-1 font-medium text-purple-900">{{ __('transfer.confirm_modal.sale_amount_title') }} {{ $modalVehicleData['buyer_name'] ? '('.__('transfer.confirm_modal.sale_buyer').': '.$modalVehicleData['buyer_name'].')' : '' }}</div>
            @php $curr = $modalVehicleData['currency'] ?? 'KRW'; $sym = $curr === 'KRW' ? '₩' : ''; @endphp
            <dl class="grid grid-cols-[100px_1fr] gap-y-1 text-gray-700">
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.sale_price') }}</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['sale_price'] ?? 0) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @if(($modalVehicleData['commission'] ?? 0) > 0)
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.commission') }}</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['commission']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                @if(($modalVehicleData['auto_loading'] ?? 0) > 0)
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.auto_loading') }}</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['auto_loading']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                @if(($modalVehicleData['tax_dc'] ?? 0) > 0)
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.tax_dc') }}</dt><dd class="text-right text-red-500">{{ $sym }}{{ number_format($modalVehicleData['tax_dc']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                @if(($modalVehicleData['transport_fee'] ?? 0) > 0)
                <dt class="text-gray-500">{{ __('transfer.confirm_modal.transport_fee') }}</dt><dd class="text-right">{{ $sym }}{{ number_format($modalVehicleData['transport_fee']) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
                @endif
                <dt class="text-gray-500 font-medium border-t border-purple-200 pt-1">{{ __('transfer.confirm_modal.sale_total') }}</dt>
                <dd class="text-right font-bold text-purple-900 border-t border-purple-200 pt-1">{{ $sym }}{{ number_format($modalVehicleData['sale_total'] ?? 0) }} {{ $curr !== 'KRW' ? $curr : '' }}</dd>
            </dl>
        </div>
        @endif

        <div class="mt-3">
            <label class="block text-xs text-gray-500 mb-1">{{ __('transfer.confirm_modal.note_label') }}</label>
            <textarea wire:model="financeNote" rows="3"
                      class="input-base"
                      placeholder="{{ __('transfer.confirm_modal.note_ph') }}"></textarea>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="{{ $confirmAction }}"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                {{ __('transfer.process_done') }}
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
        <h3 class="text-base font-semibold text-gray-900">{{ __('transfer.new_pbp_modal.title') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('transfer.new_pbp_modal.subtitle') }}</p>

        <div class="mt-3 space-y-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('transfer.new_pbp_modal.vehicle_label') }}</label>
                <select wire:model="newPbpVehicleId" class="input-base">
                    <option value="">{{ __('transfer.new_pbp_modal.vehicle_select') }}</option>
                    @foreach($this->purchaseEligibleVehicles as $v)
                    <option value="{{ $v->id }}">{{ $v->vehicle_number }} ({{ $v->purchase_from ?: __('transfer.new_pbp_modal.vehicle_unassigned') }})</option>
                    @endforeach
                </select>
                @error('newPbpVehicleId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('transfer.new_pbp_modal.amount_label') }}</label>
                <input wire:model="newPbpAmountStr" type="text" class="input-base" placeholder="0" />
                @error('newPbpAmountStr') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('transfer.new_pbp_modal.date_label') }}</label>
                <input wire:model="newPbpDate" type="date" class="input-base" />
                @error('newPbpDate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('transfer.new_pbp_modal.memo_label') }}</label>
                <textarea wire:model="newPbpNote" rows="2" class="input-base"
                          placeholder="{{ __('transfer.new_pbp_modal.memo_ph') }}"></textarea>
            </div>

            <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                <input wire:model="newPbpImmediateConfirm" type="checkbox" class="rounded" />
                <span>{{ __('transfer.new_pbp_modal.immediate') }}</span>
            </label>
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeNewPbpModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="createNewPbp" wire:loading.attr="disabled" wire:target="createNewPbp"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                <span wire:loading.remove wire:target="createNewPbp">{{ __('transfer.new_pbp_modal.submit') }}</span>
                <span wire:loading wire:target="createNewPbp">{{ __('transfer.new_pbp_modal.processing') }}</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- 🔒 매입 지급 락 (#2) 승인 모달 — 2번째 지급~ && 그 차 판매금 <50% 입금. 관리/관리자만 승인. --}}
@if($showPaymentGate)
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/60"
     wire:click.self="cancelPaymentGate" wire:key="pbp-gate-modal">
    <div class="card max-w-md mx-4 shadow-2xl border-rose-200" @click.stop>
        <h3 class="text-base font-semibold text-rose-700">🔒 {{ __('transfer.pbp_gate.title') }}</h3>
        <p class="mt-2 text-sm text-gray-700">
            {{ __('transfer.pbp_gate.body', ['vehicle' => $paymentGateInfo['vehicle'] ?? '', 'ratio' => $paymentGateInfo['ratio'] ?? 0]) }}
        </p>
        @if(auth()->user()?->canApproveUnpaidExport())
            <div class="mt-3">
                <label class="block text-xs text-gray-500 mb-1">{{ __('transfer.pbp_gate.reason_label') }}</label>
                <textarea wire:model="paymentGateReason" rows="2" class="input-base"
                          placeholder="{{ __('transfer.pbp_gate.reason_ph') }}"></textarea>
                @error('paymentGateReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancelPaymentGate" type="button"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
                <button wire:click="approvePaymentGate" wire:loading.attr="disabled" wire:target="approvePaymentGate"
                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">{{ __('transfer.pbp_gate.approve') }}</button>
            </div>
        @else
            <p class="mt-3 rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ __('transfer.pbp_gate.need_manager') }}</p>
            <div class="mt-4 flex justify-end">
                <button wire:click="cancelPaymentGate" type="button"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            </div>
        @endif
    </div>
</div>
@endif

</div>
