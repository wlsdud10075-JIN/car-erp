<?php

use App\Models\ApprovalRequest;
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
            abort(403, '승인 권한이 없습니다.');
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
        return ApprovalRequest::query()
            ->with(['requester', 'approver', 'target'])
            ->when($this->statusFilter !== 'all',
                fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->actionFilter,
                fn ($q) => $q->where('action_type', $this->actionFilter))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function pendingCount(): int
    {
        return ApprovalRequest::where('status', 'pending')->count();
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
            $this->dispatch('notify', message: '이미 처리되었거나 존재하지 않는 요청입니다.', type: 'warning');
            $this->closeDecisionModal();

            return;
        }

        if ($this->decisionMode === 'reject') {
            $this->validate(['decisionNote' => ['required', 'string', 'min:5']],
                ['decisionNote.required' => '거부 사유를 5자 이상 입력하세요.']);
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
            $this->dispatch('notify', message: '처리 실패: '.$e->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('notify',
            message: ($this->decisionMode === 'approve' ? '승인 + 액션 실행' : '거부').' 완료.',
            type: 'success');
        $this->closeDecisionModal();
    }
}; ?>

<div>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    {{-- 헤더 --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">승인 큐</h2>
            <p class="mt-1 text-xs text-gray-500">
                대기 <span class="font-semibold text-amber-600">{{ $this->pendingCount }}</span>건
                · 5 액션 통합 (같은 바이어 미수·정산 지급·민감 액션·50% 룰 예외·차량 간 자금 이체)
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
            @foreach(['pending' => '대기', 'approved' => '승인', 'rejected' => '거부', 'all' => '전체'] as $val => $label)
            <button wire:click="$set('statusFilter', '{{ $val }}')"
                    class="rounded-full px-3 py-1 text-xs font-medium transition
                           {{ $statusFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
        <div class="h-4 w-px bg-gray-200 hidden sm:block"></div>
        <select wire:model.live="actionFilter" class="input-filter">
            <option value="">액션 전체</option>
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
                    <th class="pb-2 pr-4 font-medium">생성일</th>
                    <th class="pb-2 pr-4 font-medium">액션</th>
                    <th class="pb-2 pr-4 font-medium">요청자</th>
                    <th class="pb-2 pr-4 font-medium">대상</th>
                    <th class="pb-2 pr-4 font-medium">사유</th>
                    <th class="pb-2 pr-4 font-medium">상태</th>
                    <th class="pb-2 pr-4 font-medium">결정자</th>
                    <th class="pb-2 font-medium text-right">처리</th>
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
                            <div class="font-semibold text-gray-800">{{ $p['buyer_name'] ?? '바이어 #'.$r->target_id }}</div>
                            <div class="text-gray-500">
                                차량 <span class="font-mono text-gray-700">{{ $p['new_vehicle_number'] ?? '(미지정)' }}</span>
                                @if(isset($p['overlap_count'], $p['overlap_amount_krw']))
                                · 미수 {{ $p['overlap_count'] }}대 ₩{{ number_format($p['overlap_amount_krw']) }}
                                @endif
                            </div>
                            @if(! empty($p['overlap_vehicle_numbers']))
                            <div class="text-[10px] text-gray-400 truncate max-w-[260px]">미수 차량: {{ implode(', ', $p['overlap_vehicle_numbers']) }}</div>
                            @endif
                        @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)
                            @php $p = $r->payload ?? []; @endphp
                            <div class="space-y-0.5">
                                <div>
                                    <span class="text-gray-400">출처</span>
                                    <span class="font-mono text-gray-800">{{ $p['source_vehicle_number'] ?? '#'.($p['source_vehicle_id'] ?? '?') }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-400">대상</span>
                                    <span class="font-mono text-gray-800">{{ $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?') }}</span>
                                </div>
                                <div class="font-semibold text-violet-700">
                                    {{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? 'KRW' }}
                                </div>
                            </div>
                        @elseif($r->target_type && $r->target_id)
                            <span class="font-mono">{{ class_basename($r->target_type) }} #{{ $r->target_id }}</span>
                        @else - @endif
                    </td>
                    <td class="py-3 pr-4 text-gray-500 max-w-[200px] truncate" title="{{ $r->reason }}">{{ $r->reason ?? '-' }}</td>
                    <td class="py-3 pr-4">
                        <span class="badge {{ $r->status_badge }}">{{ $r->status_label }}</span>
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
                                승인
                            </button>
                            <button wire:click="openRejectModal({{ $r->id }})"
                                    class="rounded bg-red-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-600">
                                거부
                            </button>
                        </div>
                        @else
                        <span class="text-xs text-gray-400">처리됨</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">조건에 맞는 승인 요청이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->requests as $r)
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <div class="font-medium text-gray-800">{{ $r->action_label }}</div>
                <span class="badge {{ $r->status_badge }}">{{ $r->status_label }}</span>
            </div>
            <div class="mt-1 text-xs text-gray-500">
                {{ $r->requester?->name ?? '-' }} · {{ $r->created_at->format('Y-m-d H:i') }}
            </div>
            @if($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_BUYER_OVERLAP)
                @php $p = $r->payload ?? []; @endphp
                <div class="mt-1 text-xs text-gray-700">
                    <span class="font-semibold">{{ $p['buyer_name'] ?? '바이어 #'.$r->target_id }}</span>
                    · 차량 <span class="font-mono">{{ $p['new_vehicle_number'] ?? '(미지정)' }}</span>
                </div>
                @if(isset($p['overlap_count'], $p['overlap_amount_krw']))
                <div class="text-[11px] text-gray-500">미수 {{ $p['overlap_count'] }}대 · ₩{{ number_format($p['overlap_amount_krw']) }}</div>
                @endif
            @elseif($r->action_type === \App\Models\ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)
                @php $p = $r->payload ?? []; @endphp
                <div class="mt-1 text-xs text-gray-700">
                    <span class="text-gray-400">출처</span> <span class="font-mono">{{ $p['source_vehicle_number'] ?? '#'.($p['source_vehicle_id'] ?? '?') }}</span>
                    →
                    <span class="text-gray-400">대상</span> <span class="font-mono">{{ $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?') }}</span>
                </div>
                <div class="mt-0.5 text-xs font-semibold text-violet-700">
                    {{ number_format($p['amount'] ?? 0) }} {{ $p['currency'] ?? 'KRW' }}
                </div>
            @endif
            @if($r->reason)
            <div class="mt-1 text-xs text-gray-600">{{ $r->reason }}</div>
            @endif
            @if($r->status === 'pending')
            <div class="mt-2 flex gap-2">
                <button wire:click="openApproveModal({{ $r->id }})"
                        class="flex-1 rounded bg-green-500 px-3 py-1.5 text-xs font-medium text-white">승인</button>
                <button wire:click="openRejectModal({{ $r->id }})"
                        class="flex-1 rounded bg-red-500 px-3 py-1.5 text-xs font-medium text-white">거부</button>
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">조건에 맞는 승인 요청이 없습니다.</div>
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
            {{ $decisionMode === 'approve' ? '승인 확인' : '거부 사유 입력' }}
        </h3>
        <p class="mt-2 text-sm text-gray-600">
            @if($decisionMode === 'approve')
                이 요청을 승인하시겠습니까? 사유는 선택입니다.
            @else
                거부 사유를 5자 이상 입력해야 합니다.
            @endif
        </p>
        <div class="mt-3">
            <textarea wire:model="decisionNote" rows="3"
                      class="input-base"
                      placeholder="{{ $decisionMode === 'approve' ? '메모 (선택)' : '거부 사유 (필수)' }}"></textarea>
            @error('decisionNote')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeDecisionModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button wire:click="decide"
                    wire:loading.attr="disabled"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white
                           {{ $decisionMode === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700' }}">
                {{ $decisionMode === 'approve' ? '승인' : '거부' }}
            </button>
        </div>
    </div>
</div>
@endif

</div>
