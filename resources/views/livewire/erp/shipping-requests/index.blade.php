<?php

use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    #[Url]
    public string $statusFilter = '';

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);
    }

    public function setStatus(string $s): void
    {
        $this->statusFilter = in_array($s, [
            ShippingRequest::STATUS_REQUESTED,
            ShippingRequest::STATUS_IN_PROGRESS,
            ShippingRequest::STATUS_DONE,
        ], true) ? $s : '';
    }

    /**
     * 배치 단위 상태 전환 — mutating endpoint 이므로 매번 재인가(SKILLS §8 #26).
     * done 전환 시 연동된 shipping_requested 알람을 resolve(벨/알림 카운트 정합).
     */
    public function changeStatus(string $batchId, string $to): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        if (! in_array($to, [ShippingRequest::STATUS_IN_PROGRESS, ShippingRequest::STATUS_DONE], true)) {
            return;
        }

        $rows = ShippingRequest::where('batch_id', $batchId)->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $r) {
            $r->status = $to;
            if ($to === ShippingRequest::STATUS_DONE) {
                $r->processed_at = now();
            }
            $r->save();
        }

        if ($to === ShippingRequest::STATUS_DONE) {
            TaskAlarm::where('type', 'shipping_requested')
                ->whereIn('vehicle_id', $rows->pluck('vehicle_id'))
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolved_reason' => 'shipping_done']);
        }

        $this->dispatch('notify', message: __('shipping.toast.updated'), type: 'success');
    }

    /**
     * 배치 취소 — 영업이 board에서 올린 요청을 통관/관리가 car-erp 에서 무름.
     * status='cancelled'(open 집계 제외 → 차 재요청 가능) + 연동 알람 resolve. done 은 취소 불가.
     */
    public function cancel(string $batchId): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        $rows = ShippingRequest::where('batch_id', $batchId)
            ->whereIn('status', [ShippingRequest::STATUS_REQUESTED, ShippingRequest::STATUS_IN_PROGRESS])
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $r) {
            $r->status = ShippingRequest::STATUS_CANCELLED;
            $r->processed_at = now();
            $r->save();
        }

        TaskAlarm::where('type', 'shipping_requested')
            ->whereIn('vehicle_id', $rows->pluck('vehicle_id'))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_reason' => 'shipping_cancelled']);

        $this->dispatch('notify', message: __('shipping.toast.cancelled'), type: 'success');
    }

    public function with(): array
    {
        $rows = ShippingRequest::query()
            ->with(['vehicle', 'buyer', 'consignee'])
            ->where('status', '!=', ShippingRequest::STATUS_CANCELLED)   // 취소건 = 목록서 제외(무른 것)
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('requested_at')
            ->get();

        $batches = $rows->groupBy('batch_id')->map(function ($items) {
            $f = $items->first();

            return [
                'batch_id' => (string) $f->batch_id,
                'buyer' => $f->buyer?->name,
                'consignee' => $f->consignee?->name,
                'shipping_method' => $f->shipping_method,
                'requested_by' => $f->requested_by_email,
                'requested_at' => $f->requested_at,
                'status' => $f->status,
                'vehicles' => $items->map(fn ($r) => [
                    'id' => $r->vehicle_id,
                    'number' => $r->vehicle?->vehicle_number ?? ('#'.$r->vehicle_id),
                ])->values()->all(),
                'count' => $items->count(),
            ];
        })->sortByDesc(fn ($b) => optional($b['requested_at'])->timestamp)->values();

        // 상태별 배치 수 (필터 칩 카운트) — 취소건 제외
        $counts = ShippingRequest::query()
            ->where('status', '!=', ShippingRequest::STATUS_CANCELLED)
            ->selectRaw('status, COUNT(DISTINCT batch_id) as c')
            ->groupBy('status')->pluck('c', 'status');

        return ['batches' => $batches, 'counts' => $counts];
    }
}; ?>

<div class="p-3 md:p-6">
    {{-- 헤더 --}}
    <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('shipping.title') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('shipping.subtitle') }}</p>
        </div>
    </div>

    {{-- 상태 필터 칩 --}}
    @php
        $statusMeta = [
            '' => ['label' => __('shipping.filter.all'), 'count' => $counts->sum()],
            ShippingRequest::STATUS_REQUESTED => ['label' => __('shipping.filter.requested'), 'count' => $counts[ShippingRequest::STATUS_REQUESTED] ?? 0],
            ShippingRequest::STATUS_IN_PROGRESS => ['label' => __('shipping.filter.in_progress'), 'count' => $counts[ShippingRequest::STATUS_IN_PROGRESS] ?? 0],
            ShippingRequest::STATUS_DONE => ['label' => __('shipping.filter.done'), 'count' => $counts[ShippingRequest::STATUS_DONE] ?? 0],
        ];
    @endphp
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($statusMeta as $key => $m)
            <button type="button" wire:click="setStatus('{{ $key }}')"
                    class="tab-pill {{ $statusFilter === $key ? 'is-active' : '' }}">
                {{ $m['label'] }}
                <span class="pill-count">{{ $m['count'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- 배치 카드 --}}
    @if ($batches->isEmpty())
        <div class="card text-center text-sm text-gray-400">{{ __('shipping.empty') }}</div>
    @else
        <div class="space-y-3">
            @foreach ($batches as $b)
                @php
                    $statusBadge = match ($b['status']) {
                        'requested' => 'badge-blue',
                        'in_progress' => 'badge-amber',
                        'done' => 'badge-gray',
                        default => 'badge-gray',
                    };
                @endphp
                <div class="card">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge {{ $statusBadge }}">{{ __('shipping.status.'.$b['status']) }}</span>
                                <span class="badge badge-teal">{{ $b['shipping_method'] }}</span>
                                <span class="text-sm font-bold text-gray-800">{{ $b['buyer'] ?? '—' }}</span>
                                @if ($b['consignee'])
                                    <span class="text-xs text-gray-400">→ {{ $b['consignee'] }}</span>
                                @endif
                                <span class="pill-count">{{ __('shipping.vehicles_n', ['n' => $b['count']]) }}</span>
                            </div>
                            <div class="mt-1 text-[11px] text-gray-400">
                                {{ __('shipping.requested_by') }}: {{ $b['requested_by'] }}
                                @if ($b['requested_at']) · {{ $b['requested_at']->format('Y-m-d H:i') }} @endif
                            </div>
                        </div>

                        {{-- 상태 전환 액션 --}}
                        @php $idsCsv = implode(',', array_column($b['vehicles'], 'id')); @endphp
                        <div class="flex shrink-0 flex-wrap gap-1.5">
                            {{-- 배치 N대를 차량관리에 그 차량만 조회 — 입금률·게이트 보며 묶음 처리 --}}
                            <a href="{{ route('erp.vehicles.index', ['ids' => $idsCsv]) }}" wire:navigate
                               class="rounded-md border border-primary bg-primary-light px-2.5 py-1 text-[11px] font-semibold text-primary-text hover:opacity-90">
                                {{ __('shipping.action.open_in_vehicles', ['count' => $b['count']]) }}
                            </a>
                            @if ($b['status'] === 'requested')
                                <button type="button" wire:click="changeStatus('{{ $b['batch_id'] }}', 'in_progress')"
                                        class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 hover:bg-amber-100">
                                    {{ __('shipping.action.start') }}
                                </button>
                            @endif
                            @if (in_array($b['status'], ['requested', 'in_progress'], true))
                                <button type="button" wire:click="changeStatus('{{ $b['batch_id'] }}', 'done')"
                                        class="rounded-md border border-gray-300 bg-gray-50 px-2.5 py-1 text-[11px] font-semibold text-gray-700 hover:bg-gray-100">
                                    {{ __('shipping.action.done') }}
                                </button>
                                <button type="button" wire:click="cancel('{{ $b['batch_id'] }}')"
                                        wire:confirm="{{ __('shipping.confirm.cancel', ['n' => $b['count']]) }}"
                                        class="rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-100">
                                    {{ __('shipping.action.cancel') }}
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- 묶인 차량 칩 (클릭 → 차량 편집 패널) --}}
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($b['vehicles'] as $veh)
                            <a href="{{ route('erp.vehicles.index', ['openVehicle' => $veh['id']]) }}" wire:navigate
                               class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-700 hover:border-primary hover:text-primary-text">
                                {{ $veh['number'] }}
                            </a>
                        @endforeach
                    </div>

                    {{-- 묶음 서류 — 배치 N대를 1서류로 (기존 다중차량 선적서류 재사용). 방식별 2종. --}}
                    @php
                        $idsCsv = implode(',', array_column($b['vehicles'], 'id'));
                        $docTypes = $b['shipping_method'] === 'CONTAINER'
                            ? ['container_invoice_packing' => __('shipping.doc.invoice_packing'), 'container_contract' => __('shipping.doc.contract')]
                            : ['roro_invoice_packing' => __('shipping.doc.invoice_packing'), 'roro_contract' => __('shipping.doc.contract')];
                    @endphp
                    <div class="mt-2 flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-2">
                        <span class="text-[11px] font-semibold text-gray-400">{{ __('shipping.doc.label') }}</span>
                        @foreach ($docTypes as $type => $label)
                            <a href="{{ route('erp.vehicles.documents.multi', ['type' => $type, 'ids' => $idsCsv]) }}" target="_blank" rel="noopener"
                               class="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100">
                                ⬇ {{ $b['shipping_method'] }} {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
