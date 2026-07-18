<?php

use App\Models\Salesman;
use App\Models\SettlementPayoutBatch;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    // Phase 2 — 월배치 정산지급 승인큐. 제출자([관리]/업무관리자) 상태확인 + 승인자(현재 계단) 결정.
    public ?int $expandedId = null;

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    // 월배치 수동 조정란 (jin 2026-07-08) — pending 배치에 담당자별 +/− 조정 기입.
    public ?int $adjustingId = null;

    public string $adjSalesmanId = '';

    public string $adjAmount = '';

    public string $adjReason = '';

    // 매입취소 손실 요약 필터 (jin 2026-07-18) — 마감(cancelled_closed) 손실을 마감일(cancelled_at) 기간으로.
    public string $lossFrom = '';

    public string $lossTo = '';

    public function mount(): void
    {
        $this->lossFrom = now()->startOfMonth()->format('Y-m-d');
        $this->lossTo = now()->endOfMonth()->format('Y-m-d');
    }

    /**
     * 매입취소 미수마감 손실 요약 (Option A 수기 반영용) — 담당자(프리랜서)별 소계 + 전체 합계.
     * 사내직원(몫 0)·이미 반영(cancel_loss_settled_at)·기간 밖 제외. 관리자가 소계를 월배치 조정에 −금액으로 수기 입력.
     */
    #[Computed]
    public function cancelLosses(): array
    {
        $rows = Vehicle::query()
            ->where('cancel_status', Vehicle::CANCEL_CLOSED)
            ->whereNull('cancel_loss_settled_at')
            ->whereNotNull('cancel_shortfall_krw')
            ->when($this->lossFrom, fn ($q) => $q->whereDate('cancelled_at', '>=', $this->lossFrom))
            ->when($this->lossTo, fn ($q) => $q->whereDate('cancelled_at', '<=', $this->lossTo))
            ->with('salesman:id,name,type')
            ->get(['id', 'vehicle_number', 'salesman_id', 'cancel_status', 'cancel_shortfall_krw', 'cancelled_at']);

        $groups = [];
        $grand = 0;
        foreach ($rows as $v) {
            $half = $v->cancel_freelancer_loss_krw;   // 프리랜서만 > 0
            if ($half <= 0) {
                continue;   // 사내직원 = 회사 전액 부담, 청구 대상 아님
            }
            $sid = (int) $v->salesman_id;
            if (! isset($groups[$sid])) {
                $groups[$sid] = ['salesman_id' => $sid, 'name' => $v->salesman?->name ?? '#'.$sid, 'items' => [], 'subtotal' => 0];
            }
            $groups[$sid]['items'][] = ['plate' => $v->vehicle_number, 'shortfall' => (int) $v->cancel_shortfall_krw, 'half' => $half];
            $groups[$sid]['subtotal'] += $half;
            $grand += $half;
        }

        return ['groups' => array_values($groups), 'grand_total' => $grand];
    }

    /** 담당자 손실 소계를 조정 폼에 −금액으로 프리필 (배치 조정모드 필요). */
    public function prefillCancelLoss(int $salesmanId): void
    {
        if ($this->adjustingId === null) {
            $this->dispatch('notify', message: __('payout_batch.cancel_loss.pick_batch'), type: 'warning');

            return;
        }
        $group = collect($this->cancelLosses['groups'])->firstWhere('salesman_id', $salesmanId);
        if (! $group) {
            return;
        }
        $plates = collect($group['items'])->pluck('plate')->implode(', ');
        $this->adjSalesmanId = (string) $salesmanId;
        $this->adjAmount = (string) (-1 * (int) $group['subtotal']);
        $this->adjReason = __('payout_batch.cancel_loss.reason', ['plates' => $plates]);
    }

    /** 담당자의 (기간 내·미반영) 손실을 '반영됨' 처리 → 요약에서 제외(이중청구 방지). */
    public function markCancelLossSettled(int $salesmanId): void
    {
        abort_unless((bool) auth()->user()?->canSubmitPayoutBatch(), 403);
        Vehicle::where('cancel_status', Vehicle::CANCEL_CLOSED)
            ->whereNull('cancel_loss_settled_at')
            ->where('salesman_id', $salesmanId)
            ->when($this->lossFrom, fn ($q) => $q->whereDate('cancelled_at', '>=', $this->lossFrom))
            ->when($this->lossTo, fn ($q) => $q->whereDate('cancelled_at', '<=', $this->lossTo))
            ->update(['cancel_loss_settled_at' => now()]);
        unset($this->cancelLosses);
        $this->dispatch('notify', message: __('payout_batch.cancel_loss.settled'), type: 'success');
    }

    #[Computed]
    public function batches()
    {
        return SettlementPayoutBatch::with([
            'submitter', 'approvals.approver', 'settlements.vehicle', 'settlements.salesman',
            'adjustments.salesman', 'adjustments.creator',
        ])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function salesmen()
    {
        return Salesman::orderBy('name')->get(['id', 'name']);
    }

    public function startAdjust(int $id): void
    {
        $this->adjustingId = $id;
        $this->adjSalesmanId = '';
        $this->adjAmount = '';
        $this->adjReason = '';
    }

    public function cancelAdjust(): void
    {
        $this->adjustingId = null;
        $this->adjSalesmanId = $this->adjAmount = $this->adjReason = '';
    }

    public function addAdjustment(int $batchId): void
    {
        $amount = (int) str_replace(',', '', trim($this->adjAmount));
        if ($this->adjSalesmanId === '' || $amount === 0 || trim($this->adjReason) === '') {
            $this->dispatch('notify', message: __('payout_batch.adjust.invalid'), type: 'warning');

            return;
        }
        $batch = SettlementPayoutBatch::findOrFail($batchId);
        try {
            $batch->addAdjustment(auth()->user(), (int) $this->adjSalesmanId, $amount, trim($this->adjReason));
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->adjSalesmanId = $this->adjAmount = $this->adjReason = '';
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.adjust.added'), type: 'success');
    }

    public function removeAdjustment(int $batchId, int $adjId): void
    {
        $batch = SettlementPayoutBatch::findOrFail($batchId);
        try {
            $batch->removeAdjustment(auth()->user(), $adjId);
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.adjust.removed'), type: 'success');
    }

    public function levelLabel(int $level): string
    {
        return match ($level) {
            2 => __('nav.permission.manager'),
            3 => __('nav.permission.admin'),
            default => (string) $level,
        };
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function approve(int $id): void
    {
        $batch = SettlementPayoutBatch::findOrFail($id);
        try {
            $batch->approveBy(auth()->user());
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.notify.approved'), type: 'success');
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(): void
    {
        if (trim($this->rejectReason) === '') {
            $this->dispatch('notify', message: __('payout_batch.notify.reason_required'), type: 'warning');

            return;
        }
        $batch = SettlementPayoutBatch::findOrFail($this->rejectingId);
        try {
            $batch->rejectBy(auth()->user(), trim($this->rejectReason));
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->rejectingId = null;
        $this->rejectReason = '';
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.notify.rejected'), type: 'success');
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('payout_batch.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('payout_batch.subtitle') }}</p>
    </div>

    {{-- 매입취소 손실 요약 (jin 2026-07-18) — 마감 손실 담당자(프리랜서)별 소계/총계. 월배치 조정 수기 입력 참고. --}}
    <div class="card mb-4 border-rose-200 bg-rose-50/30">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-700">{{ __('payout_batch.cancel_loss.title') }}</h2>
            <div class="flex items-center gap-1.5 text-xs">
                <input type="date" wire:model.live="lossFrom" class="rounded border-gray-300 text-xs" />
                <span class="text-gray-400">~</span>
                <input type="date" wire:model.live="lossTo" class="rounded border-gray-300 text-xs" />
            </div>
        </div>
        @php $cl = $this->cancelLosses; @endphp
        @if(empty($cl['groups']))
            <p class="mt-2 text-xs text-gray-400">{{ __('payout_batch.cancel_loss.empty') }}</p>
        @else
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b text-left text-gray-400">
                        <th class="py-1 pr-3">{{ __('payout_batch.cancel_loss.salesman') }}</th>
                        <th class="py-1 pr-3">{{ __('payout_batch.cancel_loss.vehicles') }}</th>
                        <th class="py-1 pr-3 text-right">{{ __('payout_batch.cancel_loss.subtotal') }}</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cl['groups'] as $g)
                    <tr class="border-b border-gray-100 align-top">
                        <td class="py-1.5 pr-3 font-medium text-gray-700">{{ $g['name'] }}</td>
                        <td class="py-1.5 pr-3 text-gray-500">
                            @foreach($g['items'] as $it)
                                <span class="mr-1.5 inline-block whitespace-nowrap">{{ $it['plate'] }} <span class="text-gray-400">({{ number_format($it['half']) }})</span></span>
                            @endforeach
                        </td>
                        <td class="py-1.5 pr-3 text-right font-semibold text-rose-600">-{{ number_format($g['subtotal']) }}</td>
                        <td class="py-1.5 text-right whitespace-nowrap">
                            <button type="button" wire:click="prefillCancelLoss({{ $g['salesman_id'] }})"
                                    class="rounded border border-rose-300 bg-white px-2 py-0.5 text-[11px] text-rose-600 hover:bg-rose-50">{{ __('payout_batch.cancel_loss.prefill') }}</button>
                            <button type="button" wire:click="markCancelLossSettled({{ $g['salesman_id'] }})"
                                    wire:confirm="{{ __('payout_batch.cancel_loss.settle_confirm') }}"
                                    class="rounded border border-gray-300 bg-white px-2 py-0.5 text-[11px] text-gray-500 hover:bg-gray-50">{{ __('payout_batch.cancel_loss.settle') }}</button>
                        </td>
                    </tr>
                    @endforeach
                    <tr>
                        <td class="py-1.5 pr-3 font-semibold text-gray-700" colspan="2">{{ __('payout_batch.cancel_loss.grand_total') }}</td>
                        <td class="py-1.5 pr-3 text-right font-bold text-rose-700">-{{ number_format($cl['grand_total']) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-[11px] text-gray-400">{{ __('payout_batch.cancel_loss.note') }}</p>
        @endif
    </div>

    <div class="space-y-3">
        @forelse($this->batches as $b)
            @php
                $statusBadge = ['pending' => 'badge-amber', 'approved' => 'badge-green', 'rejected' => 'badge-red', 'cancelled' => 'badge-gray'][$b->status] ?? 'badge-gray';
                $canDecide = $b->canDecide(auth()->user());
                $bySalesman = $b->settlements->groupBy(fn ($s) => $s->salesman?->name ?? __('payout_batch.no_salesman'));
                // 담당자별 조정 합(음수 포함) — 개인 소계에 반영 (jin 2026-07-14). 배치 총액은 recomputeTotal 이 이미 반영.
                $adjBySalesman = $b->adjustments->groupBy(fn ($a) => $a->salesman?->name ?? __('payout_batch.no_salesman'))->map(fn ($g) => (int) $g->sum('amount'));
            @endphp
            <div class="card-tight">
                {{-- 헤더 --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <button type="button" wire:click="toggle({{ $b->id }})" class="flex flex-1 items-center gap-2 text-left">
                        <span class="font-semibold text-gray-800">{{ $b->month }}</span>
                        <span class="badge {{ $statusBadge }}">{{ __('payout_batch.status.'.$b->status) }}</span>
                        <span class="pill-count">{{ __('payout_batch.count', ['n' => $b->settlement_count]) }}</span>
                        <span class="text-sm font-medium text-primary-text">₩{{ number_format($b->total_payout) }}</span>
                    </button>
                    <div class="text-xs text-gray-500">
                        {{ __('payout_batch.submitter') }}: {{ $b->submitter?->name ?? '-' }}
                        @if($b->status === 'pending')
                            · <span class="text-amber-600">{{ __('payout_batch.next_level', ['role' => $this->levelLabel($b->current_level)]) }}</span>
                        @endif
                    </div>
                    @if($b->status === 'pending' && $canDecide)
                    <div class="flex items-center gap-2">
                        <button wire:click="approve({{ $b->id }})" wire:confirm="{{ __('payout_batch.confirm_approve') }}"
                                class="btn-primary text-xs">{{ __('payout_batch.approve') }}</button>
                        <button wire:click="startReject({{ $b->id }})" class="text-xs text-red-500 hover:text-red-700">{{ __('payout_batch.reject') }}</button>
                    </div>
                    @endif
                </div>

                {{-- 반려 사유 입력 --}}
                @if($rejectingId === $b->id)
                <div class="mt-2 flex items-center gap-2 rounded-md border border-red-100 bg-red-50 px-2 py-2">
                    <input type="text" wire:model="rejectReason" wire:keydown.enter="confirmReject"
                           placeholder="{{ __('payout_batch.reject_reason_ph') }}" class="input-base flex-1 text-xs" />
                    <button wire:click="confirmReject" class="rounded bg-red-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-700">{{ __('payout_batch.reject_confirm') }}</button>
                    <button wire:click="cancelReject" class="text-xs text-gray-500">{{ __('common.cancel') }}</button>
                </div>
                @endif

                {{-- 승인 이력 --}}
                @if($b->approvals->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-400">
                    @foreach($b->approvals as $a)
                    <span>{{ $a->action === 'approved' ? '✓' : '✕' }} {{ $a->approver?->name }} · {{ $a->created_at?->format('m-d H:i') }}@if($a->note) — {{ $a->note }}@endif</span>
                    @endforeach
                </div>
                @endif
                @if($b->status === 'rejected' && $b->reject_reason)
                <div class="mt-1 text-[11px] text-red-500">{{ __('payout_batch.rejected_reason', ['reason' => $b->reject_reason]) }}</div>
                @endif

                {{-- 드릴다운: 사람별 → 차량별. max-w-md 로 내역↔금액 간격 축소(카드 전폭 양끝 벌어짐 방지, jin 2026-07-07) --}}
                @if($expandedId === $b->id)
                <div class="mt-3 max-w-md space-y-2 border-t border-gray-100 pt-3">
                    @foreach($bySalesman as $name => $group)
                    @php
                        $payoutSum = (int) $group->sum(fn ($s) => $s->actual_payout);
                        $adjSum = (int) ($adjBySalesman[$name] ?? 0);
                        $netSum = $payoutSum + $adjSum;
                    @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs font-medium text-gray-700">
                            <span>{{ $name }}</span>
                            <span>{{ __('payout_batch.count', ['n' => $group->count()]) }} · ₩{{ number_format($netSum) }}@if($adjSum !== 0) <span class="text-[10px] {{ $adjSum < 0 ? 'text-red-500' : 'text-green-600' }}">({{ $adjSum < 0 ? '−' : '+' }}₩{{ number_format(abs($adjSum)) }} {{ __('payout_batch.adjust.reflected') }})</span>@endif</span>
                        </div>
                        <div class="mt-1 space-y-0.5 pl-3">
                            @foreach($group as $s)
                            <div class="flex items-center justify-between text-[11px] text-gray-500">
                                <span>{{ $s->vehicle?->vehicle_number ?? ('#'.$s->vehicle_id) }}</span>
                                <span class="tabular-nums">₩{{ number_format($s->actual_payout) }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach

                    {{-- 월배치 수동 조정 (과지급 환수·특별지급 등 — 배치 총액에 +/− 반영, 개별 정산 무손상) --}}
                    @php $canAdjust = $b->status === 'pending' && (auth()->user()?->canSubmitPayoutBatch() ?? false); @endphp
                    @if($b->adjustments->isNotEmpty() || $canAdjust)
                    <div class="mt-2 border-t border-dashed border-gray-200 pt-2">
                        <div class="mb-1 text-[10px] font-semibold uppercase text-gray-400">{{ __('payout_batch.adjust.title') }}</div>
                        @foreach($b->adjustments as $adj)
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="text-gray-600">{{ $adj->salesman?->name ?? '-' }} · {{ $adj->reason }}</span>
                            <span class="flex items-center gap-1.5">
                                <span class="tabular-nums font-medium {{ $adj->amount < 0 ? 'text-red-600' : 'text-green-600' }}">{{ $adj->amount < 0 ? '−' : '+' }}₩{{ number_format(abs($adj->amount)) }}</span>
                                @if($canAdjust)
                                <button type="button" wire:click="removeAdjustment({{ $b->id }}, {{ $adj->id }})" class="text-gray-400 hover:text-red-500">×</button>
                                @endif
                            </span>
                        </div>
                        @endforeach
                        @if($canAdjust)
                            @if($adjustingId === $b->id)
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 rounded-md border border-indigo-100 bg-indigo-50 px-2 py-2">
                                <select wire:model="adjSalesmanId" class="input-base text-xs" style="width:auto">
                                    <option value="">{{ __('payout_batch.adjust.salesman') }}</option>
                                    @foreach($this->salesmen as $sm)<option value="{{ $sm->id }}">{{ $sm->name }}</option>@endforeach
                                </select>
                                <input type="text" wire:model="adjAmount" placeholder="{{ __('payout_batch.adjust.amount') }}" class="input-base w-28 text-xs" />
                                <input type="text" wire:model="adjReason" placeholder="{{ __('payout_batch.adjust.reason') }}" class="input-base flex-1 text-xs" />
                                <button type="button" wire:click="addAdjustment({{ $b->id }})" class="rounded bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">{{ __('payout_batch.adjust.add') }}</button>
                                <button type="button" wire:click="cancelAdjust" class="text-xs text-gray-500">{{ __('common.cancel') }}</button>
                            </div>
                            @else
                            <button type="button" wire:click="startAdjust({{ $b->id }})" class="mt-1 text-[11px] font-medium text-indigo-600 hover:underline">+ {{ __('payout_batch.adjust.add_line') }}</button>
                            @endif
                            <p class="mt-1 text-[10px] text-gray-400">{{ __('payout_batch.adjust.hint') }}</p>
                        @endif
                    </div>
                    @endif
                </div>
                @endif
            </div>
        @empty
            <div class="py-12 text-center text-sm text-gray-400">{{ __('payout_batch.empty') }}</div>
        @endforelse
    </div>
</div>
