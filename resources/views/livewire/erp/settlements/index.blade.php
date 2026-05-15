<?php

use App\Models\ApprovalRequest;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    // ── 목록 필터 ──────────────────────────────────────────────────
    public string $search = '';

    public string $statusFilter = '';

    public int $salesmanFilter = 0;

    public string $dateFrom = '';

    public string $dateTo = '';

    #[Url] public int $perPage = 10;

    // ── 슬라이드 패널 ─────────────────────────────────────────────
    public bool $showPanel = false;

    public ?int $editingId = null;

    // ── 폼 필드 ───────────────────────────────────────────────────
    public ?int $vehicle_id = null;

    public string $vehicleSearch = '';

    public ?int $salesman_id = null;

    public string $settlement_type = 'ratio';

    public ?float $settlement_ratio = null;

    public ?float $per_unit_amount = null;

    public float $other_deduction = 0;

    public string $settlement_status = 'pending';

    public string $note = '';

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

    // ── 목록 ──────────────────────────────────────────────────────

    #[Computed]
    public function settlements()
    {
        return Settlement::query()
            ->with(['vehicle.finalPayments', 'vehicle.purchaseBalancePayments', 'salesman', 'latestPayApproval.approver'])
            ->when($this->search, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('vehicle_number', 'like', "%{$this->search}%")
            ))
            ->when($this->statusFilter, fn ($q) => $q->where('settlement_status', $this->statusFilter))
            ->when($this->salesmanFilter, fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '>=', $this->dateFrom)
            ))
            ->when($this->dateTo, fn ($q) => $q->whereHas('vehicle', fn ($q2) => $q2->where('purchase_date', '<=', $this->dateTo)
            ))
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function salesmen()
    {
        return Salesman::orderBy('name')->get(['id', 'name']);
    }

    // ── 패널 차량 검색 ────────────────────────────────────────────

    #[Computed]
    public function vehicleSearchResults()
    {
        if (strlen($this->vehicleSearch) < 2) {
            return collect();
        }

        return Vehicle::query()
            ->where('vehicle_number', 'like', "%{$this->vehicleSearch}%")
            ->with('salesman:id,name')
            ->limit(8)
            ->get(['id', 'vehicle_number', 'salesman_id']);
    }

    #[Computed]
    public function selectedVehicle(): ?Vehicle
    {
        if (! $this->vehicle_id) {
            return null;
        }

        return Vehicle::find($this->vehicle_id);
    }

    // ── 패널 마진 실시간 계산 ────────────────────────────────────

    #[Computed]
    public function marginData(): array
    {
        $v = $this->selectedVehicle;
        if (! $v) {
            return [];
        }

        $exportUsd = (float) ($v->export_declaration_amount ?? 0);
        $transportUsd = (float) ($v->transport_fee ?? 0);
        $rate = (float) ($v->exchange_rate ?? 0);
        $salesAmountKrw = (int) (($exportUsd - $transportUsd) * $rate);
        $settlementSalesKrw = $salesAmountKrw - $v->cost_total;
        $salesMargin = $settlementSalesKrw - (int) ($v->purchase_price ?? 0);
        $vatMargin = (int) (($v->purchase_price ?? 0) * 0.09);
        $totalMargin = $salesMargin + $vatMargin;

        $settlementAmount = 0;
        if ($this->settlement_type === 'ratio' && ($this->settlement_ratio ?? 0) > 0) {
            $settlementAmount = (int) ($totalMargin * ($this->settlement_ratio / 100));
        } elseif ($this->settlement_type === 'per_unit') {
            $settlementAmount = (int) ($this->per_unit_amount ?? 0);
        }

        $actualPayout = $settlementAmount - (int) ($this->other_deduction ?? 0);

        return compact(
            'salesAmountKrw', 'settlementSalesKrw', 'salesMargin',
            'vatMargin', 'totalMargin', 'settlementAmount', 'actualPayout'
        );
    }

    // ── 액션 ──────────────────────────────────────────────────────

    public function selectVehicle(int $vehicleId): void
    {
        $this->vehicle_id = $vehicleId;
        $vehicle = Vehicle::find($vehicleId);
        $this->salesman_id = $vehicle?->salesman_id;
        $this->vehicleSearch = '';
        unset($this->selectedVehicle, $this->vehicleSearchResults, $this->marginData);
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $s = Settlement::findOrFail($id);
        $this->editingId = $id;
        $this->vehicle_id = $s->vehicle_id;
        $this->salesman_id = $s->salesman_id;
        $this->settlement_type = $s->settlement_type;
        $this->settlement_ratio = $s->settlement_ratio;
        $this->per_unit_amount = $s->per_unit_amount;
        $this->other_deduction = (float) ($s->other_deduction ?? 0);
        $this->settlement_status = $s->settlement_status;
        $this->note = $s->note ?? '';
        $this->vehicleSearch = '';
        $this->showPanel = true;
        unset($this->selectedVehicle, $this->marginData);
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
        unset($this->selectedVehicle, $this->vehicleSearchResults, $this->marginData);
    }

    public function save(): void
    {
        $rules = [
            'vehicle_id' => 'required|exists:vehicles,id',
            'settlement_type' => 'required|in:ratio,per_unit',
            'other_deduction' => 'nullable|numeric|min:0',
            'settlement_status' => 'required|in:pending,calculating,confirmed,paid',
        ];

        if ($this->settlement_type === 'ratio') {
            $rules['settlement_ratio'] = 'required|numeric|min:0|max:100';
        } else {
            $rules['per_unit_amount'] = 'required|numeric|min:0';
        }

        $this->validate($rules);

        $data = [
            'vehicle_id' => $this->vehicle_id,
            'salesman_id' => $this->salesman_id ?: null,
            'settlement_type' => $this->settlement_type,
            'settlement_ratio' => $this->settlement_type === 'ratio' ? $this->settlement_ratio : null,
            'per_unit_amount' => $this->settlement_type === 'per_unit' ? $this->per_unit_amount : null,
            'other_deduction' => (float) ($this->other_deduction ?? 0),
            'settlement_status' => $this->settlement_status,
            'note' => $this->note ?: null,
        ];

        $now = now();

        if ($this->editingId) {
            $existing = Settlement::findOrFail($this->editingId);
            if ($this->settlement_status === 'confirmed' && ! $existing->confirmed_at) {
                $data['confirmed_at'] = $now;
            }
            if ($this->settlement_status === 'paid' && ! $existing->paid_at) {
                $data['paid_at'] = $now;
            }
            $existing->update($data);
        } else {
            if ($this->settlement_status === 'confirmed') {
                $data['confirmed_at'] = $now;
            }
            if ($this->settlement_status === 'paid') {
                $data['paid_at'] = $now;
            }
            Settlement::create($data);
        }

        unset($this->settlements);
        $this->close();
        session()->flash('success', '정산 정보가 저장됐습니다.');
    }

    public function delete(int $id): void
    {
        Settlement::findOrFail($id)->delete();
        unset($this->settlements);
        session()->flash('success', '정산이 삭제됐습니다.');
    }

    /**
     * 큐 14-4-2 — confirmed 정산을 paid로 전환 요청.
     * canApprove user는 직접 paid 변경 가능 (Settlement::saving 가드 통과).
     * 그 외 user는 이 메서드로 ApprovalRequest 생성 → /erp/approvals 큐로 진입.
     */
    public function requestPayApproval(int $id): void
    {
        $settlement = Settlement::findOrFail($id);
        if ($settlement->settlement_status !== 'confirmed') {
            $this->dispatch('notify', message: 'confirmed 상태에서만 지급 승인 요청 가능합니다.', type: 'warning');

            return;
        }

        // 동일 정산에 대기중 요청 있으면 중복 차단
        $existing = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_SETTLEMENT_PAY)
            ->where('target_type', Settlement::class)
            ->where('target_id', $settlement->id)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->exists();
        if ($existing) {
            $this->dispatch('notify', message: '이미 대기중인 승인 요청이 있습니다.', type: 'warning');

            return;
        }

        ApprovalRequest::create([
            'requester_id' => auth()->id(),
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class,
            'target_id' => $settlement->id,
            'payload' => [
                'vehicle_number' => $settlement->vehicle?->vehicle_number,
                'actual_payout' => $settlement->actual_payout,
            ],
            'reason' => '정산 #'.$settlement->id.' ('.($settlement->vehicle?->vehicle_number ?? '?').') 지급 처리',
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);

        $this->dispatch('notify', message: '지급 승인 요청을 보냈습니다.', type: 'success');
    }

    private function resetForm(): void
    {
        $this->vehicle_id = null;
        $this->vehicleSearch = '';
        $this->salesman_id = null;
        $this->settlement_type = 'ratio';
        $this->settlement_ratio = null;
        $this->per_unit_amount = null;
        $this->other_deduction = 0;
        $this->settlement_status = 'pending';
        $this->note = '';
    }
}; ?>

<div>
{{-- 성공 토스트 --}}
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
        <h1 class="text-xl font-bold text-gray-800">정산 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->settlements->total() }}건</p>
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
            정산 추가
        </button>
    </div>
</div>

{{-- 필터 바 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="차량번호"
           class="input-filter w-36" />
    <select wire:model="statusFilter" class="input-filter">
        <option value="">전체 상태</option>
        <option value="pending">대기</option>
        <option value="calculating">계산중</option>
        <option value="confirmed">확정</option>
        <option value="paid">지급완료</option>
    </select>
    <select wire:model="salesmanFilter" class="input-filter">
        <option value="0">전체 담당자</option>
        @foreach($this->salesmen as $sm)
        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
        @endforeach
    </select>
    <input wire:model="dateFrom" type="date" class="input-filter" />
    <span class="text-gray-400 text-sm">~</span>
    <input wire:model="dateTo" type="date" class="input-filter" />
    <button wire:click="search" class="btn-search">조회</button>
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">차량번호</th>
                <th class="pb-2 pr-4 font-medium">담당자</th>
                <th class="pb-2 pr-4 font-medium">진행상태</th>
                <th class="pb-2 pr-4 font-medium">정산방식</th>
                <th class="pb-2 pr-4 font-medium text-right">총마진</th>
                <th class="pb-2 pr-4 font-medium text-right">정산액</th>
                <th class="pb-2 pr-4 font-medium text-right">실지급액</th>
                <th class="pb-2 pr-4 font-medium">상태</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->settlements as $s)
            @php
                $statusBadge = match($s->settlement_status) {
                    'pending'     => 'badge-blue',
                    'calculating' => 'badge-amber',
                    'confirmed'   => 'badge-green',
                    'paid'        => 'badge-gray',
                    default       => 'badge-gray',
                };
                $statusLabel = match($s->settlement_status) {
                    'pending'     => '대기',
                    'calculating' => '계산중',
                    'confirmed'   => '확정',
                    'paid'        => '지급완료',
                    default       => $s->settlement_status,
                };
                $progressBadge = match(true) {
                    in_array($s->vehicle?->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                    in_array($s->vehicle?->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                    in_array($s->vehicle?->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                    in_array($s->vehicle?->progress_status, ['선적중','선적완료'])           => 'badge-green',
                    $s->vehicle?->progress_status === '거래완료'                             => 'badge-gray',
                    default => 'badge-gray',
                };
            @endphp
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $s->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $s->vehicle?->vehicle_number ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $s->salesman?->name ?? '-' }}</td>
                <td class="py-3 pr-4">
                    @if($s->vehicle)
                    <span class="badge {{ $progressBadge }}">{{ $s->vehicle->progress_status }}</span>
                    @else
                    <span class="text-gray-300">-</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-600">
                    @if($s->settlement_type === 'ratio')
                        비율 {{ number_format($s->settlement_ratio, 1) }}%
                    @else
                        건당 ₩{{ number_format($s->per_unit_amount) }}
                    @endif
                </td>
                <td class="py-3 pr-4 text-right {{ $s->total_margin < 0 ? 'text-red-500' : 'text-gray-700' }}">
                    ₩{{ number_format($s->total_margin) }}
                </td>
                <td class="py-3 pr-4 text-right text-gray-700">
                    ₩{{ number_format($s->settlement_amount) }}
                </td>
                <td class="py-3 pr-4 text-right font-semibold {{ $s->actual_payout < 0 ? 'text-red-600' : 'text-gray-800' }}">
                    ₩{{ number_format($s->actual_payout) }}
                </td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                    {{-- 큐 14-4-2 — 지급 승인 요청 상태 인라인 표시 --}}
                    @php $pa = $s->latestPayApproval; @endphp
                    @if($pa && $pa->status === 'pending')
                    <span class="badge badge-amber ml-1" title="승인 대기중 — 관리자 결정 대기">승인 대기중</span>
                    @elseif($pa && $pa->status === 'rejected')
                    <span class="badge badge-red ml-1" title="{{ $pa->approver?->name ?? '?' }} 거부 사유: {{ $pa->decision_note ?? '(사유 없음)' }}">
                        거부됨
                    </span>
                    @endif
                </td>
                <td class="py-3 text-right">
                    <div class="flex justify-end gap-2">
                        {{-- 큐 14-4-2 — confirmed + 대기중 요청 없음 + 비-canApprove user만 버튼 노출 --}}
                        @if($s->settlement_status === 'confirmed' && ! auth()->user()->canApprove()
                            && (! $pa || $pa->status !== 'pending'))
                        <button wire:click.stop="requestPayApproval({{ $s->id }})"
                                wire:confirm="지급 승인 요청을 보내시겠습니까?"
                                class="text-xs text-violet-600 hover:text-violet-800">지급 승인 요청</button>
                        @endif
                        <button wire:click.stop="delete({{ $s->id }})"
                                wire:confirm="정산을 삭제하시겠습니까?"
                                class="text-xs text-red-400 hover:text-red-600">삭제</button>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="py-12 text-center text-sm text-gray-400">정산 내역이 없습니다.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->settlements as $s)
    @php
        $statusBadge = match($s->settlement_status) {
            'pending' => 'badge-blue', 'calculating' => 'badge-amber',
            'confirmed' => 'badge-green', 'paid' => 'badge-gray', default => 'badge-gray',
        };
        $statusLabel = match($s->settlement_status) {
            'pending' => '대기', 'calculating' => '계산중',
            'confirmed' => '확정', 'paid' => '지급완료', default => $s->settlement_status,
        };
    @endphp
    <div class="card-tight cursor-pointer" wire:click="openEdit({{ $s->id }})">
        <div class="flex items-center justify-between">
            <div class="font-medium text-gray-800">{{ $s->vehicle?->vehicle_number ?? '-' }}</div>
            <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-x-4 text-xs text-gray-500">
            <div>담당자: {{ $s->salesman?->name ?? '-' }}</div>
            <div>방식: {{ $s->settlement_type === 'ratio' ? number_format($s->settlement_ratio, 1).'%' : '건당' }}</div>
            <div>총마진: ₩{{ number_format($s->total_margin) }}</div>
            <div class="font-semibold text-gray-700">실지급: ₩{{ number_format($s->actual_payout) }}</div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">정산 내역이 없습니다.</div>
    @endforelse
</div>

<div>{{ $this->settlements->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[520px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? '정산 수정' : '정산 추가' }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- Validation 에러 박스 (큐 14-4-2 — Settlement::saving 가드 throw 잡힘) --}}
    @if($errors->any())
    <div class="border-b border-red-200 bg-red-50 px-5 py-3">
        <div class="flex items-start gap-2">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1">
                <p class="text-xs font-semibold text-red-700">저장할 수 없습니다 — 아래 항목을 확인하세요</p>
                <ul class="mt-1 space-y-0.5 text-xs text-red-600">
                    @foreach($errors->all() as $msg)
                    <li>· {{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endif

    {{-- 폼 본문 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-5">

        {{-- 차량 선택 (신규) / 차량 정보 표시 (수정) --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">차량</span>
            </div>
            @if(! $editingId)
            <div class="relative">
                <input wire:model.live.debounce.300ms="vehicleSearch"
                       type="text"
                       placeholder="차량번호 입력 (2자 이상)..."
                       class="input-base w-full" />
                @if($this->vehicleSearchResults->isNotEmpty())
                <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border bg-white shadow-lg">
                    @foreach($this->vehicleSearchResults as $v)
                    <button wire:click="selectVehicle({{ $v->id }})"
                            class="flex w-full items-center justify-between border-b px-3 py-2 text-left text-sm last:border-0 hover:bg-gray-50">
                        <span class="font-medium">{{ $v->vehicle_number }}</span>
                        @if($v->salesman)
                        <span class="text-xs text-gray-400">{{ $v->salesman->name }}</span>
                        @endif
                    </button>
                    @endforeach
                </div>
                @endif
            </div>
            @if($vehicle_id && $this->selectedVehicle)
            <div class="mt-2 flex items-center gap-2 rounded-lg bg-blue-50 px-3 py-2 text-sm">
                <svg class="h-4 w-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span class="font-medium text-blue-800">{{ $this->selectedVehicle->vehicle_number }}</span>
                <span class="text-blue-500">{{ $this->selectedVehicle->progress_status }}</span>
            </div>
            @endif
            @error('vehicle_id')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            @else
            @if($this->selectedVehicle)
            <div class="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                <span class="font-medium text-gray-800">{{ $this->selectedVehicle->vehicle_number }}</span>
                @php
                    $pb = match(true) {
                        in_array($this->selectedVehicle->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                        in_array($this->selectedVehicle->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                        in_array($this->selectedVehicle->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                        in_array($this->selectedVehicle->progress_status, ['선적중','선적완료'])           => 'badge-green',
                        $this->selectedVehicle->progress_status === '거래완료'                             => 'badge-gray',
                        default => 'badge-red',
                    };
                @endphp
                <span class="badge {{ $pb }}">{{ $this->selectedVehicle->progress_status }}</span>
            </div>
            @endif
            @endif
        </div>

        {{-- 마진 산출내역 --}}
        @if(! empty($this->marginData))
        <div>
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">마진 산출내역</span>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 text-sm space-y-1.5">
                <div class="flex justify-between text-gray-600">
                    <span>판매금원화 <span class="text-xs text-gray-400">(면장금액-운임비)×환율</span></span>
                    <span>₩{{ number_format($this->marginData['salesAmountKrw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>정산판매금원화 <span class="text-xs text-gray-400">-비용합계</span></span>
                    <span>₩{{ number_format($this->marginData['settlementSalesKrw']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>판매마진 <span class="text-xs text-gray-400">-매입가</span></span>
                    <span class="{{ $this->marginData['salesMargin'] < 0 ? 'text-red-500' : '' }}">₩{{ number_format($this->marginData['salesMargin']) }}</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>부가세마진 <span class="text-xs text-gray-400">매입가×9%</span></span>
                    <span>₩{{ number_format($this->marginData['vatMargin']) }}</span>
                </div>
                <hr class="border-gray-200" />
                <div class="flex justify-between font-semibold text-gray-800">
                    <span>총마진</span>
                    <span class="{{ $this->marginData['totalMargin'] < 0 ? 'text-red-600' : '' }}">₩{{ number_format($this->marginData['totalMargin']) }}</span>
                </div>
            </div>
        </div>
        @endif

        {{-- 정산 설정 --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-purple-500"></span>
                <span class="section-title">정산 설정</span>
            </div>

            {{-- 담당자 --}}
            <div class="mb-3">
                <label class="label-base">영업담당자</label>
                <select wire:model="salesman_id" class="input-base">
                    <option value="">담당자 없음</option>
                    @foreach($this->salesmen as $sm)
                    <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- 정산방식 --}}
            <div class="mb-3">
                <label class="label-base">정산방식 <span class="text-red-500">*</span></label>
                <div class="mt-1.5 flex gap-5">
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                        <input wire:model.live="settlement_type" type="radio" value="ratio" class="accent-primary" />
                        비율제
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                        <input wire:model.live="settlement_type" type="radio" value="per_unit" class="accent-primary" />
                        건당제
                    </label>
                </div>
            </div>

            @if($settlement_type === 'ratio')
            <div class="mb-3">
                <label class="label-base">비율 (%) <span class="text-red-500">*</span></label>
                <input wire:model.live.debounce.500ms="settlement_ratio"
                       type="number" step="0.01" min="0" max="100"
                       class="input-base" placeholder="예: 30.00" />
                @error('settlement_ratio')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @else
            <div class="mb-3">
                <label class="label-base">건당 금액 (원) <span class="text-red-500">*</span></label>
                <input wire:model.live.debounce.500ms="per_unit_amount"
                       type="number" step="1" min="0"
                       class="input-base" placeholder="예: 500000" />
                @error('per_unit_amount')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif

            <div class="mb-3">
                <label class="label-base">기타공제 (원)</label>
                <input wire:model.live.debounce.500ms="other_deduction"
                       type="number" step="1" min="0"
                       class="input-base" placeholder="0" />
            </div>
        </div>

        {{-- 정산 결과 --}}
        @if(! empty($this->marginData))
        <div class="rounded-lg bg-purple-50 p-3 text-sm space-y-1.5">
            <div class="flex justify-between text-gray-600">
                <span>정산액</span>
                <span class="font-medium">₩{{ number_format($this->marginData['settlementAmount']) }}</span>
            </div>
            <div class="flex justify-between text-gray-500">
                <span>기타공제</span>
                <span class="text-red-500">- ₩{{ number_format((int) ($other_deduction ?? 0)) }}</span>
            </div>
            <hr class="border-purple-200" />
            <div class="flex justify-between text-base font-bold">
                <span class="text-gray-800">실지급액</span>
                <span class="{{ $this->marginData['actualPayout'] < 0 ? 'text-red-600' : 'text-purple-700' }}">
                    ₩{{ number_format($this->marginData['actualPayout']) }}
                </span>
            </div>
        </div>
        @endif

        {{-- 진행상태 --}}
        <div>
            <div class="section-header">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">진행상태</span>
            </div>
            <select wire:model="settlement_status" class="input-base">
                <option value="pending">대기</option>
                <option value="calculating">계산중</option>
                <option value="confirmed">확정</option>
                <option value="paid">지급완료</option>
            </select>
            @if($editingId)
            @php
                $existing = \App\Models\Settlement::find($editingId);
            @endphp
            @if($existing?->confirmed_at)
            <p class="mt-1 text-xs text-gray-400">확정일: {{ $existing->confirmed_at->format('Y-m-d H:i') }}</p>
            @endif
            @if($existing?->paid_at)
            <p class="mt-0.5 text-xs text-gray-400">지급일: {{ $existing->paid_at->format('Y-m-d H:i') }}</p>
            @endif
            @endif
        </div>

        {{-- 메모 --}}
        <div>
            <label class="label-base">메모</label>
            <textarea wire:model="note" class="input-base" rows="2" placeholder="특이사항 등"></textarea>
        </div>

    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button wire:click="close"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
            취소
        </button>
        <button wire:click="save" class="btn-primary"
                wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">저장</span>
            <span wire:loading wire:target="save">저장 중...</span>
        </button>
    </div>

</div>
@endif

</div>
