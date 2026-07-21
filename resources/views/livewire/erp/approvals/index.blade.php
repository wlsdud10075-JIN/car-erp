<?php

use App\Models\ApprovalRequest;
use App\Models\InterVehicleTransfer;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url] public string $statusFilter = 'pending';   // pending / all / approved / rejected
    #[Url] public string $actionFilter = '';          // 전체 또는 4 타입
    #[Url] public int $perPage = 10;

    // 결정 모달
    public bool $showDecisionModal = false;
    public ?int $decisionId = null;
    public string $decisionMode = 'approve';   // approve / reject
    public string $decisionNote = '';

    public function mount(): void
    {
        if (! auth()->user()?->canApprove()) {
            abort(403, __('approval.toast.no_perm'));
        }
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    #[Computed]
    public function requests()
    {
        $page = ApprovalRequest::query()
            ->with(['requester', 'approver', 'target'])
            ->when($this->statusFilter !== 'all',
                fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->actionFilter,
                fn ($q) => $q->where('action_type', $this->actionFilter))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);

        // 큐 19-F — 자금 이체 / 이체 취소 행에 transfer.status 일괄 매핑 (N+1 회피).
        // approved 상태이지만 transfer 가 approved_awaiting_finance 면 '재무 처리 대기' 라벨 표시.
        $reqs = $page->getCollection();
        // 자금 이체 + 보증금 매입 선지급 모두 approval_request_id 로 transfer.status 매핑 (재무 대기 배지용).
        $financeStepTypes = [ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER, ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING];
        $transferApprovalIds = $reqs
            ->filter(fn ($r) => in_array($r->action_type, $financeStepTypes, true))
            ->pluck('id');
        $voidTransferIds = $reqs
            ->filter(fn ($r) => $r->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
            ->map(fn ($r) => $r->payload['transfer_id'] ?? null)
            ->filter();

        $byApprovalReq = $transferApprovalIds->isEmpty()
            ? collect()
            : InterVehicleTransfer::whereIn('approval_request_id', $transferApprovalIds)
                ->pluck('status', 'approval_request_id');
        // 큐 19-L — void 행은 transfer.status + void_finance_rejected_at 둘 다 필요
        // (executed 복귀 + 거부 메타 있으면 "취소 거부" 라벨 분기)
        $voidTransferMap = $voidTransferIds->isEmpty()
            ? collect()
            : InterVehicleTransfer::whereIn('id', $voidTransferIds)
                ->get(['id', 'status', 'void_finance_rejected_at'])
                ->keyBy('id');

        $reqs->each(function ($r) use ($byApprovalReq, $voidTransferMap, $financeStepTypes) {
            if (in_array($r->action_type, $financeStepTypes, true)) {
                $r->setAttribute('related_transfer_status', $byApprovalReq[$r->id] ?? null);
                $r->setAttribute('related_transfer_void_rejected', false);
            } elseif ($r->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID) {
                $tId = $r->payload['transfer_id'] ?? null;
                $t = $tId ? ($voidTransferMap[$tId] ?? null) : null;
                $r->setAttribute('related_transfer_status', $t?->status);
                $r->setAttribute('related_transfer_void_rejected', $t?->void_finance_rejected_at !== null);
            }
        });

        return $page;
    }

    #[Computed]
    public function pendingCount(): int
    {
        return ApprovalRequest::where('status', 'pending')->count();
    }

    /** 결정 모달에 표시할 요청 요약 (내용 먼저 보고 승인/거부, jin 2026-07-21). */
    #[Computed]
    public function decisionRequest(): ?ApprovalRequest
    {
        return $this->decisionId ? ApprovalRequest::with('requester')->find($this->decisionId) : null;
    }

    public function openApproveModal(int $id): void
    {
        $this->decisionId = $id;
        $this->decisionMode = 'approve';
        $this->decisionNote = '';
        $this->showDecisionModal = true;
    }

    public function openRejectModal(int $id): void
    {
        $this->decisionId = $id;
        $this->decisionMode = 'reject';
        $this->decisionNote = '';
        $this->showDecisionModal = true;
    }

    public function closeDecisionModal(): void
    {
        $this->showDecisionModal = false;
        $this->decisionId = null;
        $this->decisionNote = '';
    }

    public function decide(): void
    {
        $req = ApprovalRequest::find($this->decisionId);
        if (! $req || $req->status !== 'pending') {
            $this->dispatch('notify', message: __('approval.toast.already'), type: 'warning');
            $this->closeDecisionModal();

            return;
        }

        // 큐 21 부수 fix — Self-approve 가드 (Security 권고, 회의록 2026-05-18).
        // SoD(Segregation of Duties) — 본인이 요청한 안건을 본인이 승인할 수 없음.
        if ($req->requester_id === auth()->id()) {
            $this->dispatch('notify',
                message: __('approval.toast.self'),
                type: 'error');
            $this->closeDecisionModal();

            return;
        }

        if ($this->decisionMode === 'reject') {
            $this->validate(['decisionNote' => ['required', 'string', 'min:5']],
                ['decisionNote.required' => __('approval.toast.reject_min')]);
        }

        try {
            DB::transaction(function () use ($req) {
                $req->update([
                    'status' => $this->decisionMode === 'approve' ? 'approved' : 'rejected',
                    'approver_id' => auth()->id(),
                    'decision_note' => $this->decisionNote ?: null,
                    'decided_at' => now(),
                ]);
                // 큐 14-4-2 — approve 시 실제 액션 실행 (settlement paid 전환 등).
                if ($this->decisionMode === 'approve') {
                    $req->execute();
                }
            });
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('approval.toast.fail', ['error' => $e->getMessage()]), type: 'error');
            $this->closeDecisionModal();

            return;
        }

        $this->dispatch('notify',
            message: $this->decisionMode === 'approve' ? __('approval.toast.done_approve') : __('approval.toast.done_reject'),
            type: 'success');
        $this->closeDecisionModal();
    }
}; ?>

<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    {{-- 헤더 --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('approval.title') }}</h2>
            <p class="mt-1 text-xs text-gray-500">
                {!! __('approval.subtitle', ['count' => '<span class="font-semibold text-amber-600">'.$this->pendingCount.'</span>']) !!}
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

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <div class="flex gap-1">
            @foreach(['pending', 'approved', 'rejected', 'all'] as $val)
            <button wire:click="$set('statusFilter', '{{ $val }}')"
                    class="rounded-full px-3 py-1 text-xs font-medium transition
                           {{ $statusFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ __('approval.filter.'.$val) }}
            </button>
            @endforeach
        </div>
        <div class="h-4 w-px bg-gray-200 hidden sm:block"></div>
        <select wire:model.live="actionFilter" class="input-filter">
            <option value="">{{ __('approval.all_actions') }}</option>
            @foreach(\App\Models\ApprovalRequest::TYPES as $code => $label)
            <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- 데스크탑 테이블 --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm border-separate border-spacing-0">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('approval.col.created') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('approval.col.action') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('approval.col.requester') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('approval.col.target') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('approval.col.reason') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('approval.col.status') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('approval.col.decider') }}</th>
                    <th class="pb-2 font-medium text-right">{{ __('approval.col.handle') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->requests as $r)
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="py-3 pr-4 text-gray-500">{{ $r->created_at->format('Y-m-d H:i') }}</td>
                    <td class="py-3 pr-4 font-medium text-gray-800">{{ $r->action_label }}</td>
                    <td class="py-3 pr-4 text-gray-700">{{ $r->requester?->name ?? '-' }}</td>
                    <td class="py-3 pr-4 text-gray-600 text-xs">
                        @if($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_BUYER_OVERLAP)
                            @php $p = $r->payload ?? []; @endphp
                            <div class="font-semibold text-gray-800">{{ $p['buyer_name'] ?? __('approval.t.buyer', ['id' => $r->target_id]) }}</div>
                            <div class="text-gray-500">
                                {{ __('approval.t.vehicle') }} <span class="font-mono text-gray-700">{{ $p['new_vehicle_number'] ?? __('approval.t.unassigned') }}</span>
                                @if(isset($p['overlap_count'], $p['overlap_amount_krw']))
                                · {{ __('approval.t.overlap', ['count' => $p['overlap_count'], 'amount' => number_format($p['overlap_amount_krw'])]) }}
                                @endif
                            </div>
                            @if(! empty($p['overlap_vehicle_numbers']))
                            <div class="text-[10px] text-gray-400 truncate max-w-[260px]">{{ __('approval.t.overlap_vehicles') }} {{ implode(', ', $p['overlap_vehicle_numbers']) }}</div>
                            @endif
                        @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)
                            @php $p = $r->payload ?? []; @endphp
                            <div class="space-y-0.5">
                                <div>
                                    <span class="text-gray-400">{{ __('approval.t.source') }}</span>
                                    <span class="font-mono text-gray-800">{{ $p['source_vehicle_number'] ?? '#'.($p['source_vehicle_id'] ?? '?') }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-400">{{ __('approval.t.target') }}</span>
                                    <span class="font-mono text-gray-800">{{ $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?') }}</span>
                                </div>
                                <div class="font-semibold text-violet-700">
                                    {{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? 'KRW' }}
                                </div>
                            </div>
                        @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
                            @php $p = $r->payload ?? []; @endphp
                            <div class="space-y-0.5">
                                <div class="text-red-700 font-semibold text-[11px]">{{ __('approval.t.void', ['id' => $p['transfer_id'] ?? '?']) }}</div>
                                <div>
                                    <span class="text-gray-400">{{ __('approval.t.source') }}</span>
                                    <span class="font-mono text-gray-800">{{ $p['source_vehicle_number'] ?? '#'.($p['source_vehicle_id'] ?? '?') }}</span>
                                    →
                                    <span class="text-gray-400">{{ __('approval.t.target') }}</span>
                                    <span class="font-mono text-gray-800">{{ $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?') }}</span>
                                </div>
                                <div class="font-semibold text-red-600">
                                    {{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? 'KRW' }} {{ __('approval.t.restore') }}
                                </div>
                            </div>
                        @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING)
                            @php $p = $r->payload ?? []; @endphp
                            <div class="space-y-0.5">
                                <div>
                                    <span class="text-gray-400">{{ __('approval.t.source') }}</span>
                                    <span class="font-mono text-gray-800">{{ $p['source_vehicle_number'] ?? '#'.($p['source_vehicle_id'] ?? '?') }}</span>
                                    →
                                    <span class="text-gray-400">{{ __('approval.t.target') }}</span>
                                    <span class="font-mono text-gray-800">{{ $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?') }}</span>
                                </div>
                                <div class="font-semibold text-indigo-700">
                                    ₩{{ number_format($p['amount_krw'] ?? 0) }}
                                    <span class="text-[10px] font-normal text-gray-400">({{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? '' }} · {{ __('approval.t.purchase_funding') }})</span>
                                </div>
                            </div>
                        @elseif($r->target_type && $r->target_id)
                            <span class="font-mono">{{ class_basename($r->target_type) }} #{{ $r->target_id }}</span>
                        @else - @endif
                    </td>
                    <td class="py-3 pr-4 text-gray-500 max-w-[200px] truncate" title="{{ $r->reason }}">{{ $r->reason ?? '-' }}</td>
                    <td class="py-3 pr-4">
                        {{-- 큐 19-F — 자금 이체/이체 취소 행은 transfer.status 컨텍스트 라벨 우선 표시. --}}
                        @php
                            $ts = $r->getAttributeValue('related_transfer_status');
                            $isTransferRow = in_array($r->action_type, [
                                ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER,
                                ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID,
                                ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING,
                            ], true);
                        @endphp
                        @php $voidRejected = $r->getAttributeValue('related_transfer_void_rejected'); @endphp
                        @if($isTransferRow && $r->status === 'approved' && $ts === 'approved_awaiting_finance')
                            <span class="badge badge-blue">{{ __('approval.tstatus.approved_awaiting') }}</span>
                        @elseif($isTransferRow && $r->status === 'approved' && $ts === 'voided_awaiting_finance')
                            <span class="badge badge-amber">{{ __('approval.tstatus.voided_awaiting') }}</span>
                        @elseif($isTransferRow && $r->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID && $r->status === 'approved' && $ts === 'executed' && $voidRejected)
                            <span class="badge badge-red">{{ __('approval.tstatus.void_rejected') }}</span>
                        @elseif($isTransferRow && $r->status === 'approved' && $ts === 'executed')
                            <span class="badge badge-green">{{ __('approval.tstatus.executed') }}</span>
                        @elseif($isTransferRow && $r->status === 'approved' && $ts === 'voided')
                            <span class="badge badge-gray">{{ __('approval.tstatus.voided') }}</span>
                        @elseif($isTransferRow && $r->status === 'approved' && $ts === 'finance_rejected')
                            <span class="badge badge-red">{{ __('approval.tstatus.finance_rejected') }}</span>
                        @else
                            <span class="badge {{ $r->status_badge }}">{{ $r->status_label }}</span>
                        @endif
                    </td>
                    <td class="py-3 pr-4 text-gray-500">
                        {{ $r->approver?->name ?? '-' }}
                        @if($r->decided_at)
                        <div class="text-[10px] text-gray-400">{{ $r->decided_at->format('m-d H:i') }}</div>
                        @endif
                    </td>
                    <td class="py-3 text-right">
                        @if($r->status === 'pending')
                        <div class="flex justify-end gap-1">
                            <button wire:click="openApproveModal({{ $r->id }})"
                                    class="rounded bg-green-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-600">
                                {{ __('approval.approve') }}
                            </button>
                            <button wire:click="openRejectModal({{ $r->id }})"
                                    class="rounded bg-red-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-600">
                                {{ __('approval.reject') }}
                            </button>
                        </div>
                        @else
                        <span class="text-xs text-gray-400">{{ __('approval.handled') }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">{{ __('approval.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->requests as $r)
        @php
            $tsMobile = $r->getAttributeValue('related_transfer_status');
            $isTransferRowMobile = in_array($r->action_type, [
                ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER,
                ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID,
                ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING,
            ], true);
        @endphp
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <div class="font-medium text-gray-800">{{ $r->action_label }}</div>
                @php $voidRejectedMobile = $r->getAttributeValue('related_transfer_void_rejected'); @endphp
                @if($isTransferRowMobile && $r->status === 'approved' && $tsMobile === 'approved_awaiting_finance')
                    <span class="badge badge-blue">{{ __('approval.tstatus.approved_awaiting') }}</span>
                @elseif($isTransferRowMobile && $r->status === 'approved' && $tsMobile === 'voided_awaiting_finance')
                    <span class="badge badge-amber">{{ __('approval.tstatus.voided_awaiting') }}</span>
                @elseif($isTransferRowMobile && $r->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID && $r->status === 'approved' && $tsMobile === 'executed' && $voidRejectedMobile)
                    <span class="badge badge-red">{{ __('approval.tstatus.void_rejected') }}</span>
                @elseif($isTransferRowMobile && $r->status === 'approved' && $tsMobile === 'executed')
                    <span class="badge badge-green">{{ __('approval.tstatus.executed') }}</span>
                @elseif($isTransferRowMobile && $r->status === 'approved' && $tsMobile === 'voided')
                    <span class="badge badge-gray">{{ __('approval.tstatus.voided') }}</span>
                @elseif($isTransferRowMobile && $r->status === 'approved' && $tsMobile === 'finance_rejected')
                    <span class="badge badge-red">{{ __('approval.tstatus.finance_rejected') }}</span>
                @else
                    <span class="badge {{ $r->status_badge }}">{{ $r->status_label }}</span>
                @endif
            </div>
            <div class="mt-1 text-xs text-gray-500">
                {{ $r->requester?->name ?? '-' }} · {{ $r->created_at->format('Y-m-d H:i') }}
            </div>
            @if($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_BUYER_OVERLAP)
                @php $p = $r->payload ?? []; @endphp
                <div class="mt-1 text-xs text-gray-700">
                    <span class="font-semibold">{{ $p['buyer_name'] ?? __('approval.t.buyer', ['id' => $r->target_id]) }}</span>
                    · {{ __('approval.t.vehicle') }} <span class="font-mono">{{ $p['new_vehicle_number'] ?? __('approval.t.unassigned') }}</span>
                </div>
                @if(isset($p['overlap_count'], $p['overlap_amount_krw']))
                <div class="text-[11px] text-gray-500">{{ __('approval.t.overlap', ['count' => $p['overlap_count'], 'amount' => number_format($p['overlap_amount_krw'])]) }}</div>
                @endif
            @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)
                @php $p = $r->payload ?? []; @endphp
                <div class="mt-1 text-xs text-gray-700">
                    <span class="text-gray-400">{{ __('approval.t.source') }}</span> <span class="font-mono">{{ $p['source_vehicle_number'] ?? '#'.($p['source_vehicle_id'] ?? '?') }}</span>
                    →
                    <span class="text-gray-400">{{ __('approval.t.target') }}</span> <span class="font-mono">{{ $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?') }}</span>
                </div>
                <div class="mt-0.5 text-xs font-semibold text-violet-700">
                    {{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? 'KRW' }}
                </div>
            @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
                @php $p = $r->payload ?? []; @endphp
                <div class="mt-1 text-xs text-red-700 font-semibold">{{ __('approval.t.void', ['id' => $p['transfer_id'] ?? '?']) }}</div>
                <div class="mt-0.5 text-xs text-gray-700">
                    <span class="font-mono">{{ $p['source_vehicle_number'] ?? '?' }}</span> →
                    <span class="font-mono">{{ $p['target_vehicle_number'] ?? '?' }}</span>
                </div>
                <div class="mt-0.5 text-xs font-semibold text-red-600">
                    {{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? 'KRW' }} {{ __('approval.t.restore') }}
                </div>
            @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING)
                @php $p = $r->payload ?? []; @endphp
                <div class="mt-1 text-xs text-gray-700">
                    <span class="text-gray-400">{{ __('approval.t.source') }}</span> <span class="font-mono">{{ $p['source_vehicle_number'] ?? '#'.($p['source_vehicle_id'] ?? '?') }}</span>
                    →
                    <span class="text-gray-400">{{ __('approval.t.target') }}</span> <span class="font-mono">{{ $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?') }}</span>
                </div>
                <div class="mt-0.5 text-xs font-semibold text-indigo-700">
                    ₩{{ number_format($p['amount_krw'] ?? 0) }}
                    <span class="text-[10px] font-normal text-gray-400">({{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? '' }})</span>
                </div>
            @endif
            @if($r->reason)
            <div class="mt-1 text-xs text-gray-600">{{ $r->reason }}</div>
            @endif
            @if($r->status === 'pending')
            <div class="mt-2 flex gap-2">
                <button wire:click="openApproveModal({{ $r->id }})"
                        class="flex-1 rounded bg-green-500 px-3 py-1.5 text-xs font-medium text-white">{{ __('approval.approve') }}</button>
                <button wire:click="openRejectModal({{ $r->id }})"
                        class="flex-1 rounded bg-red-500 px-3 py-1.5 text-xs font-medium text-white">{{ __('approval.reject') }}</button>
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('approval.empty') }}</div>
        @endforelse
    </div>

    <div>{{ $this->requests->links() }}</div>

</div>

{{-- 결정 모달 --}}
@if($showDecisionModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     wire:click.self="closeDecisionModal">
    <div class="card max-w-md mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">
            {{ $decisionMode === 'approve' ? __('approval.modal.approve_title') : __('approval.modal.reject_title') }}
        </h3>

        {{-- 요청 요약 — 내용 먼저 보고 결정 (jin 2026-07-21) --}}
        @php $dr = $this->decisionRequest; $dp = $dr?->payload ?? []; @endphp
        @if($dr)
        <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-xs space-y-1">
            <div class="font-semibold text-gray-800">{{ $dr->action_label }}</div>
            @if(isset($dp['source_vehicle_number']) || isset($dp['target_vehicle_number']))
            <div class="text-gray-700">
                <span class="text-gray-400">{{ __('approval.t.source') }}</span> <span class="font-mono">{{ $dp['source_vehicle_number'] ?? '?' }}</span>
                → <span class="text-gray-400">{{ __('approval.t.target') }}</span> <span class="font-mono">{{ $dp['target_vehicle_number'] ?? '?' }}</span>
            </div>
            @endif
            @if($dr->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_PURCHASE_FUNDING)
            <div class="font-semibold text-indigo-700">₩{{ number_format($dp['amount_krw'] ?? 0) }} <span class="text-[10px] font-normal text-gray-400">({{ number_format($dp['amount'] ?? 0) }} {{ $dp['currency'] ?? '' }} · {{ __('approval.t.purchase_funding') }})</span></div>
            @elseif(isset($dp['amount']))
            <div class="font-semibold text-gray-800">{{ number_format($dp['amount']) }} {{ $dp['currency'] ?? '' }}</div>
            @endif
            <div class="text-gray-500">{{ $dr->requester?->name ?? '-' }}@if($dr->reason) · {{ $dr->reason }}@endif</div>
        </div>
        @endif

        <p class="mt-2 text-sm text-gray-600">
            @if($decisionMode === 'approve')
                {{ __('approval.modal.approve_desc') }}
            @else
                {{ __('approval.modal.reject_desc') }}
            @endif
        </p>
        <div class="mt-3">
            <textarea wire:model="decisionNote" rows="3"
                      class="input-base"
                      placeholder="{{ $decisionMode === 'approve' ? __('approval.modal.memo_ph') : __('approval.modal.reject_ph') }}"></textarea>
            @error('decisionNote')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeDecisionModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="decide"
                    wire:loading.attr="disabled"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white
                           {{ $decisionMode === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700' }}">
                {{ $decisionMode === 'approve' ? __('approval.approve') : __('approval.reject') }}
            </button>
        </div>
    </div>
</div>
@endif

</div>
