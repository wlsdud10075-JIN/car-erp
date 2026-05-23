<?php

use App\Models\Salesman;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * 회의확장씬 큐 15 / G5 (2026-05-23) — 영업담당자별 재고관리.
 *
 * 회의록 2026-05-14-3way-workflow-policy.md §G5:
 *   "영업담당자별. 말소완료까지 = 재고. 선적중 시 재고 제거."
 *
 * 재고 정의:
 *   - progress_status_cache IN ('매입중', '매입완료', '말소완료')
 *   - 즉 판매중 진입 전 차량만
 *
 * 권한:
 *   - admin/super: 전체 재고
 *   - 관리: 본인 부하 영업의 재고만 (subordinates)
 *   - 영업: 본인 재고만
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    #[Url] public string $salesmanFilter = '';

    #[Url] public string $statusFilter = '';

    public string $search = '';

    #[Url] public int $perPage = 20;

    private const STOCK_STATUSES = ['매입중', '매입완료', '말소완료'];

    #[Computed]
    public function inventoryVehicles()
    {
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && $user->role === '영업' && $user->salesman;
        $restrictToManagerScope = $user && ! $user->isAdmin() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        return Vehicle::query()
            ->with(['salesman', 'buyer'])
            ->whereIn('progress_status_cache', self::STOCK_STATUSES)
            ->when($restrictToOwnSalesman, fn ($q) => $q->where('salesman_id', $user->salesman->id))
            ->when($restrictToManagerScope, fn ($q) => $q->whereIn('salesman_id', $managerScopeSalesmanIds))
            ->when($this->salesmanFilter !== '', fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('progress_status_cache', $this->statusFilter))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
                ->orWhere('model_type', 'like', "%{$this->search}%")
                ->orWhere('nice_reg_owner_name', 'like', "%{$this->search}%")
            ))
            ->orderByRaw('salesman_id IS NULL ASC')
            ->orderBy('salesman_id')
            ->orderBy('purchase_date')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function salesmen()
    {
        $q = Salesman::where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && $user->role === '관리') {
            $q->whereIn('id', $user->getSubordinateSalesmanIds());
        }

        return $q->get();
    }

    /**
     * 영업담당자별 재고 카운트 (스트립 표시).
     */
    #[Computed]
    public function stockCountsBySalesman(): array
    {
        $user = auth()->user();
        $q = Vehicle::query()->whereIn('progress_status_cache', self::STOCK_STATUSES);
        if ($user && ! $user->isAdmin() && $user->role === '관리') {
            $q->whereIn('salesman_id', $user->getSubordinateSalesmanIds());
        }

        return $q->selectRaw('salesman_id, COUNT(*) as cnt')
            ->groupBy('salesman_id')
            ->pluck('cnt', 'salesman_id')
            ->toArray();
    }

    public function search(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['salesmanFilter', 'statusFilter', 'search']);
        $this->resetPage();
    }
}; ?>

<div wire:poll.30s class="flex h-full flex-col gap-4 p-3 md:p-6">
    {{-- 헤더 --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">재고관리</h2>
            <p class="mt-0.5 text-xs text-gray-500">
                매입중 · 매입완료 · 말소완료 차량 (판매 진입 전). 영업담당자별 그룹 정렬.
            </p>
        </div>
        <span class="text-xs text-gray-400">총 {{ number_format($this->inventoryVehicles->total()) }} 대</span>
    </div>

    {{-- 영업담당자별 재고 카운트 스트립 --}}
    @if(count($this->stockCountsBySalesman))
    <div class="card-tight overflow-x-auto">
        <div class="flex items-center gap-2 text-xs">
            <span class="font-semibold text-gray-500 whitespace-nowrap">담당자별:</span>
            @foreach($this->salesmen as $sm)
                @php $cnt = $this->stockCountsBySalesman[$sm->id] ?? 0; @endphp
                <button wire:click="$set('salesmanFilter', '{{ $sm->id }}')"
                        class="flex items-center gap-1 rounded-full px-2.5 py-0.5 transition
                               {{ $salesmanFilter == $sm->id ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    <span>{{ $sm->name }}</span>
                    <span class="rounded-full bg-white/20 px-1.5 text-[10px] font-bold">{{ $cnt }}</span>
                </button>
            @endforeach
            @php $unassignedCnt = $this->stockCountsBySalesman[''] ?? $this->stockCountsBySalesman[null] ?? 0; @endphp
            @if($unassignedCnt > 0)
                <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-amber-800">
                    미배정 {{ $unassignedCnt }}
                </span>
            @endif
        </div>
    </div>
    @endif

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <input wire:model="search" wire:keydown.enter="search" type="text"
               placeholder="차량번호 · 브랜드 · 차종 · 소유자"
               class="input-filter w-64" />
        <select wire:model.live="salesmanFilter" class="input-filter">
            <option value="">담당자 전체</option>
            @foreach($this->salesmen as $sm)
                <option value="{{ $sm->id }}">{{ $sm->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" class="input-filter">
            <option value="">상태 전체</option>
            <option value="매입중">매입중</option>
            <option value="매입완료">매입완료</option>
            <option value="말소완료">말소완료</option>
        </select>
        <button wire:click="search" class="btn-search">조회</button>
        <button wire:click="resetFilters" class="text-xs text-violet-600 hover:underline">필터 초기화</button>
        <select wire:model.live="perPage" class="input-filter ml-auto">
            <option value="10">10개</option>
            <option value="20">20개</option>
            <option value="50">50개</option>
            <option value="100">100개</option>
        </select>
    </div>

    {{-- 데스크탑 테이블 --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">차량번호</th>
                    <th class="pb-2 pr-4 font-medium">담당자</th>
                    <th class="pb-2 pr-4 font-medium">진행상태</th>
                    <th class="pb-2 pr-4 font-medium">브랜드/차종</th>
                    <th class="pb-2 pr-4 font-medium">매입일</th>
                    <th class="pb-2 pr-4 font-medium text-right">매입가</th>
                    <th class="pb-2 pr-4 font-medium">소유자</th>
                    <th class="pb-2 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->inventoryVehicles as $v)
                @php
                    $statusBadge = match($v->progress_status_cache) {
                        '매입중', '매입완료', '말소완료' => 'badge-blue',
                        default => 'badge-gray',
                    };
                @endphp
                <tr class="hover:bg-gray-50 cursor-pointer"
                    wire:click="$dispatch('navigate-to-vehicle', { id: {{ $v->id }} })">
                    <td class="py-3 pr-4 font-mono font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                    <td class="py-3 pr-4 text-gray-500">
                        @if($v->salesman)
                            <span class="badge badge-blue">{{ $v->salesman->name }}</span>
                        @else
                            <span class="text-gray-300">미배정</span>
                        @endif
                    </td>
                    <td class="py-3 pr-4">
                        <span class="badge {{ $statusBadge }}">{{ $v->progress_status_cache }}</span>
                    </td>
                    <td class="py-3 pr-4 text-gray-700">
                        {{ $v->brand }} {{ $v->model_type }}
                        @if($v->year)<span class="text-xs text-gray-400">({{ $v->year }})</span>@endif
                    </td>
                    <td class="py-3 pr-4 text-gray-500">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-right text-gray-700">
                        @if($v->purchase_price > 0)₩{{ number_format($v->purchase_price) }}@else -@endif
                    </td>
                    <td class="py-3 pr-4 text-gray-500 text-xs">{{ $v->nice_reg_owner_name ?? '-' }}</td>
                    <td class="py-3 text-right">
                        <a href="{{ route('erp.vehicles.index') }}?openVehicle={{ $v->id }}"
                           wire:navigate
                           class="text-xs text-violet-600 hover:underline">차량 편집</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">
                    조회 조건에 일치하는 재고 차량이 없습니다.
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->inventoryVehicles as $v)
        <a href="{{ route('erp.vehicles.index') }}?openVehicle={{ $v->id }}" wire:navigate class="card-tight block">
            <div class="flex items-center justify-between">
                <span class="font-mono font-medium text-gray-800">{{ $v->vehicle_number }}</span>
                <span class="badge badge-blue">{{ $v->progress_status_cache }}</span>
            </div>
            <div class="mt-1 grid grid-cols-2 gap-x-3 text-xs text-gray-500">
                <div>담당: {{ $v->salesman?->name ?? '미배정' }}</div>
                <div>{{ $v->brand }} {{ $v->model_type }}</div>
                <div>매입일: {{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</div>
                <div class="text-right">@if($v->purchase_price > 0)₩{{ number_format($v->purchase_price) }}@else -@endif</div>
            </div>
        </a>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">재고 차량이 없습니다.</div>
        @endforelse
    </div>

    <div>{{ $this->inventoryVehicles->links() }}</div>
</div>
