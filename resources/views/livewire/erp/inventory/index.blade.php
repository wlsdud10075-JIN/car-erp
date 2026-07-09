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
 * 회의록 2026-05-14-3way-workflow-policy.md §G5 원문: "말소완료까지 = 재고".
 * 사용자 정정 (2026-05-23): "선적전까지, 판매완료까지는 재고로 잡아줘."
 *
 * 재고 정의 (사용자 정정):
 *   - progress_status_cache IN ('매입중', '매입완료', '말소완료', '판매중', '판매완료')
 *   - 즉 선적 진입 전 차량 모두 (판매 등록·완료해도 출고 전이면 재고)
 *   - 선적중 부터 비재고 (이미 출고 시작)
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

    /** 출고일 draft (vehicle_id => 'Y-m-d' | ''). 즉시저장 아님 — 여러 차량 지정 후 「적용」으로 일괄 저장. */
    public array $warehouseOut = [];

    #[Computed]
    public function inventoryVehicles()
    {
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업' && $user->salesman;
        $restrictToManagerScope = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        $result = Vehicle::query()
            ->with(['salesman', 'buyer'])
            ->inStock()
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

        // 출고일 draft 초기화 — 현재 페이지 차량 중 draft 없는 것만 DB값으로 채움(사용자 편집 보존).
        foreach ($result as $v) {
            if (! array_key_exists($v->id, $this->warehouseOut)) {
                $this->warehouseOut[$v->id] = $v->warehouse_out_date?->format('Y-m-d') ?? '';
            }
        }

        return $result;
    }

    #[Computed]
    public function salesmen()
    {
        $q = Salesman::where('is_active', true)->orderBy('name');
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
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
        $q = Vehicle::query()->inStock();
        if ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
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

    /**
     * 출고일 일괄 적용 (jin 2026-07-09) — 여러 차량 출고일을 지정한 뒤 「적용」 버튼으로 한 번에 저장.
     * 즉시저장(오클릭 위험) 대신 draft(warehouseOut) 편집 → 적용. DB와 다른 것만 저장.
     * 스코프 인증(영업=본인/관리=팀/그외=전체). 출고일 있으면 재고 제외, 비우면 복귀.
     */
    public function applyWarehouseOut(): void
    {
        $user = auth()->user();
        $ids = array_values(array_filter(array_map('intval', array_keys($this->warehouseOut))));
        if (empty($ids)) {
            return;
        }

        $applied = 0;
        foreach (Vehicle::whereIn('id', $ids)->get() as $v) {
            if (! $user->canScopeVehicle($v)) {
                continue;   // 스코프 밖 (IDOR 방지)
            }
            $draft = trim((string) ($this->warehouseOut[$v->id] ?? ''));
            $new = $draft !== '' ? $draft : null;
            if ($new === $v->warehouse_out_date?->format('Y-m-d')) {
                continue;   // 변경 없음
            }
            $v->warehouse_out_date = $new;
            $v->save();
            $applied++;
        }

        unset($this->inventoryVehicles, $this->stockCountsBySalesman);
        $this->dispatch('notify',
            message: $applied > 0 ? __('inventory.out_applied', ['count' => $applied]) : __('inventory.out_nochange'),
            type: $applied > 0 ? 'success' : 'info');
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
            <h2 class="text-xl font-bold text-gray-800">{{ __('inventory.title') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">
                {{ __('inventory.subtitle') }}
            </p>
        </div>
        <span class="text-xs text-gray-400">{{ __('inventory.total', ['count' => number_format($this->inventoryVehicles->total())]) }}</span>
    </div>

    {{-- 영업담당자별 재고 카운트 스트립 --}}
    @if(count($this->stockCountsBySalesman))
    <div class="card-tight overflow-x-auto">
        <div class="flex items-center gap-2 text-xs">
            <span class="font-semibold text-gray-500 whitespace-nowrap">{{ __('inventory.by_salesman') }}</span>
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
                    {{ __('inventory.unassigned_strip', ['count' => $unassignedCnt]) }}
                </span>
            @endif
        </div>
    </div>
    @endif

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <input wire:model="search" wire:keydown.enter="search" type="text"
               placeholder="{{ __('inventory.search_ph') }}"
               class="input-filter w-64" />
        <select wire:model.live="salesmanFilter" class="input-filter">
            <option value="">{{ __('inventory.all_salesmen') }}</option>
            @foreach($this->salesmen as $sm)
                <option value="{{ $sm->id }}">{{ $sm->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter" class="input-filter">
            <option value="">{{ __('inventory.all_status') }}</option>
            @foreach(['매입중','매입완료','말소완료','판매중','판매완료','선적중','선적완료','통관중','통관완료','거래완료'] as $st)
            <option value="{{ $st }}">{{ __('domain.progress.'.$st) }}</option>
            @endforeach
        </select>
        <button wire:click="search" class="btn-search">{{ __('common.search') }}</button>
        <button wire:click="resetFilters" class="text-xs text-violet-600 hover:underline">{{ __('common.reset_filters') }}</button>
        <select wire:model.live="perPage" class="input-filter ml-auto">
            <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
            <option value="20">{{ __('common.per_page', ['count' => 20]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
    </div>

    {{-- 출고일 일괄 적용 (jin 2026-07-09) — 여러 차량 출고일을 지정한 뒤 한 번에 저장(오클릭 방지). --}}
    <div class="mt-2 flex items-center justify-end gap-2">
        <span class="text-xs text-gray-400">{{ __('inventory.out_apply_hint') }}</span>
        <button type="button" wire:click="applyWarehouseOut" class="btn-primary text-xs">{{ __('inventory.out_apply_btn') }}</button>
    </div>

    {{-- 데스크탑 테이블 --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.number') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.salesman') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.status') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.brand_model') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('inventory.col_warehouse_in') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('inventory.col_warehouse_out') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right">{{ __('vehicle.col.purchase_price') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('inventory.col_owner') }}</th>
                    <th class="pb-2 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->inventoryVehicles as $v)
                @php
                    $statusBadge = match($v->progress_status_cache) {
                        '매입중', '매입완료', '말소완료' => 'badge-blue',
                        '판매중', '판매완료' => 'badge-purple',
                        '선적중', '선적완료' => 'badge-amber',
                        '통관중', '통관완료' => 'badge-green',
                        default => 'badge-gray',
                    };
                @endphp
                <tr wire:key="inv-row-{{ $v->id }}" class="hover:bg-gray-50 cursor-pointer"
                    wire:click="$dispatch('navigate-to-vehicle', { id: {{ $v->id }} })">
                    <td class="py-3 pr-4 font-mono font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                    <td class="py-3 pr-4 text-gray-500">
                        @if($v->salesman)
                            <span class="badge badge-blue">{{ $v->salesman->name }}</span>
                        @else
                            <span class="text-gray-300">{{ __('common.unassigned') }}</span>
                        @endif
                    </td>
                    <td class="py-3 pr-4">
                        <span class="badge {{ $statusBadge }}">{{ __('domain.progress.'.$v->progress_status_cache) }}</span>
                    </td>
                    <td class="py-3 pr-4 text-gray-700">
                        {{ $v->brand }} {{ $v->model_type }}
                        @if($v->year)<span class="text-xs text-gray-400">({{ $v->year }})</span>@endif
                    </td>
                    <td class="py-3 pr-4 text-gray-500">{{ $v->warehouse_in_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4" @click.stop>
                        <input type="text" data-date wire:key="inv-out-{{ $v->id }}" wire:model="warehouseOut.{{ $v->id }}"
                               placeholder="YYYY-MM-DD"
                               class="w-28 rounded border border-gray-300 px-1.5 py-0.5 text-xs text-gray-700 focus:border-primary" />
                    </td>
                    <td class="py-3 pr-4 text-right text-gray-700">
                        @if($v->purchase_price > 0)₩{{ number_format($v->purchase_price) }}@else -@endif
                    </td>
                    <td class="py-3 pr-4 text-gray-500 text-xs">{{ $v->nice_reg_owner_name ?? '-' }}</td>
                    <td class="py-3 text-right">
                        <a href="{{ route('erp.vehicles.index') }}?openVehicle={{ $v->id }}"
                           wire:navigate
                           class="text-xs text-violet-600 hover:underline">{{ __('inventory.edit_vehicle') }}</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="py-12 text-center text-sm text-gray-400">
                    {{ __('inventory.empty') }}
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->inventoryVehicles as $v)
        @php
            $statusBadgeM = match($v->progress_status_cache) {
                '매입중', '매입완료', '말소완료' => 'badge-blue',
                '판매중', '판매완료' => 'badge-purple',
                default => 'badge-gray',
            };
        @endphp
        <a wire:key="inv-card-{{ $v->id }}" href="{{ route('erp.vehicles.index') }}?openVehicle={{ $v->id }}" wire:navigate class="card-tight block">
            <div class="flex items-center justify-between">
                <span class="font-mono font-medium text-gray-800">{{ $v->vehicle_number }}</span>
                <span class="badge {{ $statusBadgeM }}">{{ __('domain.progress.'.$v->progress_status_cache) }}</span>
            </div>
            <div class="mt-1 grid grid-cols-2 gap-x-3 text-xs text-gray-500">
                <div>{{ __('inventory.m_salesman') }} {{ $v->salesman?->name ?? __('common.unassigned') }}</div>
                <div>{{ $v->brand }} {{ $v->model_type }}</div>
                <div>{{ __('inventory.m_warehouse_in') }} {{ $v->warehouse_in_date?->format('Y-m-d') ?? '-' }}</div>
                <div class="text-right">@if($v->purchase_price > 0)₩{{ number_format($v->purchase_price) }}@else -@endif</div>
            </div>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs text-gray-500" @click.stop.prevent>
                <span>{{ __('inventory.col_warehouse_out') }}</span>
                <input type="text" data-date wire:key="inv-out-m-{{ $v->id }}" wire:model="warehouseOut.{{ $v->id }}"
                       placeholder="YYYY-MM-DD"
                       class="w-28 rounded border border-gray-300 px-1.5 py-0.5 text-xs" />
            </div>
        </a>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('inventory.empty_mobile') }}</div>
        @endforelse
    </div>

    <div>{{ $this->inventoryVehicles->links() }}</div>
</div>
