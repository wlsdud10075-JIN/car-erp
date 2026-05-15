<?php

use App\Models\InterVehicleTransfer;
use App\Services\InterVehicleTransferService;
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

    #[Url]
    public string $statusFilter = 'awaiting';   // awaiting / all / executed / voided

    #[Url]
    public int $perPage = 10;

    public bool $showModal = false;

    public ?int $modalTransferId = null;

    public string $financeNote = '';

    public function mount(): void
    {
        if (! auth()->user()?->canConfirmFinanceTransfer()) {
            abort(403, '재무 확정 권한이 없습니다. (관리 role 은 자기 승인을 직접 처리할 수 없음 — SoD)');
        }
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

    public function openModal(int $id): void
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
        $this->modalTransferId = $id;
        $this->financeNote = '';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->modalTransferId = null;
        $this->financeNote = '';
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
        }
    }
}; ?>

<div>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    {{-- 헤더 --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">재무 처리</h2>
            <p class="mt-1 text-xs text-gray-500">
                관리 승인 완료 — 실물 자금 처리 대기
                <span class="ml-1 font-semibold text-amber-600">{{ $this->awaitingCount }}</span>건.
                재무가 통장 확인 후 [재무 처리 완료] 클릭 시 시스템 ledger 기록 (final_payment 페어).
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

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <div class="flex gap-1">
            @foreach(['awaiting' => '재무 대기', 'executed' => '실행 완료', 'voided' => '취소', 'all' => '전체'] as $val => $label)
            <button wire:click="$set('statusFilter', '{{ $val }}')"
                    class="rounded-full px-3 py-1 text-xs font-medium transition
                           {{ $statusFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
                @if($val === 'awaiting' && $this->awaitingCount > 0)
                <span class="ml-1 rounded-full bg-amber-500 px-1.5 text-[10px] font-bold text-white">{{ $this->awaitingCount }}</span>
                @endif
            </button>
            @endforeach
        </div>
    </div>

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
                            <button wire:click="openModal({{ $t->id }})"
                                    class="rounded bg-emerald-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-600">
                                재무 처리 완료
                            </button>
                            @endif
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
            <div class="mt-2">
                @if($selfConfirm)
                <button disabled class="w-full rounded bg-gray-200 px-3 py-1.5 text-xs font-medium text-gray-400 cursor-not-allowed">
                    SoD 차단 — 본인 승인 건 처리 불가
                </button>
                @else
                <button wire:click="openModal({{ $t->id }})"
                        class="w-full rounded bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white">
                    재무 처리 완료
                </button>
                @endif
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">조건에 맞는 자금 이체가 없습니다.</div>
        @endforelse
    </div>

    <div>{{ $this->transfers->links() }}</div>

</div>

{{-- 재무 확정 모달 --}}
@if($showModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     wire:click.self="closeModal">
    <div class="card max-w-md mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">재무 처리 완료 확인</h3>
        <p class="mt-2 text-sm text-gray-600">
            통장 거래가 정상적으로 처리되었는지 확인 후 [재무 처리 완료] 를 클릭하세요.
            이 시점에 시스템 ledger (final_payment) 가 기록되고 양 차량 미수 캐시가 갱신됩니다.
        </p>
        <div class="mt-3">
            <label class="block text-xs text-gray-500 mb-1">은행 거래 번호 또는 처리 메모 (선택)</label>
            <textarea wire:model="financeNote" rows="3"
                      class="input-base"
                      placeholder="예: KB 12345-6789 / 신한 거래번호 등 — 입력 시 audit_logs 에 기록"></textarea>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button wire:click="confirm"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                재무 처리 완료
            </button>
        </div>
    </div>
</div>
@endif

</div>
