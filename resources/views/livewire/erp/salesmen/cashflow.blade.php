<?php

use App\Models\Salesman;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    // Review.md #2 (2026-06-09) — IDOR 차단. mount() 인가 후 클라이언트가
    // $salesmanId 를 변조해 타 영업 자금현황을 조회하던 경로를 #[Locked] 로 봉인.
    #[Locked]
    public int    $salesmanId    = 0;
    public string $salesmanName  = '';
    public string $salesmanPhone = '';
    public string $salesmanEmail = '';

    public string $dateFrom = '';
    public string $dateTo   = '';

    public function mount(int $id): void
    {
        $salesman = Salesman::findOrFail($id);
        $user     = auth()->user();

        // 비관리자는 자신의 캐시플로우만 접근 가능
        if (! $user->isAdmin() && $salesman->user_id !== $user->id) {
            abort(403);
        }

        $this->salesmanId    = $salesman->id;
        $this->salesmanName  = $salesman->name;
        $this->salesmanPhone = $salesman->phone ?? '';
        $this->salesmanEmail = $salesman->email ?? '';
        $this->dateFrom = now()->subMonths(3)->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');
    }

    public function search(): void {}

    #[Computed]
    public function vehicles()
    {
        return Vehicle::query()
            ->where('salesman_id', $this->salesmanId)
            ->with(['finalPayments', 'purchaseBalancePayments'])
            ->when($this->dateFrom, fn($q) => $q->where('purchase_date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->where('purchase_date', '<=', $this->dateTo))
            ->latest('purchase_date')
            ->get();
    }

    #[Computed]
    public function summary(): array
    {
        $vehicles = $this->vehicles;

        $saleUnpaidByCurrency = $vehicles
            ->where('sale_price', '>', 0)
            ->groupBy('currency')
            ->map(fn($g) => $g->sum('sale_unpaid_amount'))
            ->filter(fn($v) => $v > 0)
            ->toArray();

        return [
            'count'                  => $vehicles->count(),
            'purchase_total'         => (int) $vehicles->sum('purchase_price'),
            'purchase_unpaid'        => (int) $vehicles->sum('purchase_unpaid_amount'),
            'sale_unpaid_by_currency' => $saleUnpaidByCurrency,
            // 미청산 이월 — Salesman accessor(단일 출처, 흡수 훅과 동일 공식). 날짜필터 무관 현재 잔액.
            'unconsumed_carryover'   => (int) (Salesman::find($this->salesmanId)?->unconsumed_carryover ?? 0),
        ];
    }
}; ?>

<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-start gap-3">
    <a href="{{ route('erp.salesmen.index') }}" wire:navigate
       class="mt-1 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    </a>
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('cashflow.title', ['name' => $salesmanName]) }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">
            {{ $salesmanPhone }}{{ $salesmanPhone && $salesmanEmail ? ' · ' : '' }}{{ $salesmanEmail }}
        </p>
    </div>
</div>

{{-- 날짜 필터 --}}
<div class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <div>
        <label class="label-base">{{ __('cashflow.date_from') }}</label>
        <input wire:model="dateFrom" type="date" class="input-filter" />
    </div>
    <div>
        <label class="label-base">{{ __('cashflow.date_to') }}</label>
        <input wire:model="dateTo" type="date" class="input-filter" />
    </div>
    <button wire:click="search" class="btn-search">{{ __('common.search') }}</button>
</div>

{{-- KPI 카드 --}}
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card">
        <div class="text-xs text-gray-500">{{ __('cashflow.kpi_vehicles') }}</div>
        <div class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->summary['count'] }}<span class="text-sm font-normal text-gray-500">{{ __('cashflow.unit') }}</span>
        </div>
    </div>
    <div class="card">
        <div class="text-xs text-gray-500">{{ __('cashflow.kpi_purchase_total') }}</div>
        <div class="mt-1 text-lg font-bold text-gray-800">₩{{ number_format($this->summary['purchase_total']) }}</div>
    </div>
    <div class="card">
        <div class="text-xs text-gray-500">{{ __('cashflow.kpi_purchase_unpaid') }}</div>
        <div class="mt-1 text-lg font-bold {{ $this->summary['purchase_unpaid'] > 0 ? 'text-red-600' : 'text-green-600' }}">
            {{ $this->summary['purchase_unpaid'] > 0 ? '₩'.number_format($this->summary['purchase_unpaid']) : __('cashflow.none') }}
        </div>
    </div>
    <div class="card">
        <div class="text-xs text-gray-500">{{ __('cashflow.kpi_sale_unpaid') }}</div>
        @if(empty($this->summary['sale_unpaid_by_currency']))
            <div class="mt-1 text-lg font-bold text-green-600">{{ __('cashflow.none') }}</div>
        @else
            <div class="mt-1 space-y-0.5">
                @foreach($this->summary['sale_unpaid_by_currency'] as $currency => $amount)
                <div class="text-sm font-bold text-red-600">{{ $currency }} {{ number_format($amount, 2) }}</div>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- 미청산 이월 (stranded carryover) — 흡수 안 된 잔액 상시 표시. 0이면 회색 '없음'. --}}
@php $carryover = $this->summary['unconsumed_carryover']; @endphp
<div class="card flex items-center justify-between {{ $carryover > 0 ? 'border-emerald-300 bg-emerald-50/40' : ($carryover < 0 ? 'border-red-300 bg-red-50/40' : '') }}">
    <div>
        <div class="text-xs text-gray-500">{{ __('cashflow.kpi_carryover') }}</div>
        <div class="text-[11px] text-gray-400">{{ __('cashflow.carryover_sub') }}</div>
    </div>
    @if($carryover > 0)
        <div class="text-right">
            <div class="text-xl font-bold text-emerald-600">+₩{{ number_format($carryover) }}</div>
            <div class="text-[11px] font-medium text-emerald-600">{{ __('cashflow.carryover_to_pay') }}</div>
        </div>
    @elseif($carryover < 0)
        <div class="text-right">
            <div class="text-xl font-bold text-red-600">−₩{{ number_format(abs($carryover)) }}</div>
            <div class="text-[11px] font-medium text-red-600">{{ __('cashflow.carryover_to_collect') }}</div>
        </div>
    @else
        <div class="text-lg font-bold text-gray-400">{{ __('cashflow.none') }}</div>
    @endif
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.number') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.status') }}</th>
                {{-- 큐 16 — 채널 컬럼 제거 (단일 채널). --}}
                <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.purchase_date') }}</th>
                <th class="pb-2 pr-4 font-medium text-right">{{ __('vehicle.col.purchase_price') }}</th>
                <th class="pb-2 pr-4 font-medium text-right">{{ __('vehicle.col.unpaid_purchase') }}</th>
                <th class="pb-2 pr-4 font-medium text-right">{{ __('vehicle.col.sale_price') }}</th>
                <th class="pb-2 font-medium text-right">{{ __('vehicle.col.unpaid_sale') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->vehicles as $v)
            @php
                $statusBadge = match(true) {
                    in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                    in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                    in_array($v->progress_status, ['선적중','선적완료'])            => 'badge-amber',
                    in_array($v->progress_status, ['통관중','통관완료'])             => 'badge-green',
                    in_array($v->progress_status, ['수출통관중','수출통관완료'])    => 'badge-amber',
                    $v->progress_status === '거래완료'                             => 'badge-gray',
                    default => 'badge-gray',
                };
                // 큐 16 — channelLabel/Badge 제거 (단일 채널).
                $purchaseUnpaid = $v->purchase_unpaid_amount;
                $saleUnpaid     = $v->sale_unpaid_amount;
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $statusBadge }}">{{ __('domain.progress.'.$v->progress_status) }}</span>
                </td>
                {{-- 큐 16 — 채널 td 제거 --}}
                <td class="py-3 pr-4 text-gray-500">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-right text-gray-700">
                    {{ $v->purchase_price > 0 ? '₩'.number_format($v->purchase_price) : '-' }}
                </td>
                <td class="py-3 pr-4 text-right {{ $purchaseUnpaid > 0 ? 'font-medium text-red-500' : 'text-gray-400' }}">
                    {{ $purchaseUnpaid > 0 ? '₩'.number_format($purchaseUnpaid) : __('cashflow.paid') }}
                </td>
                <td class="py-3 pr-4 text-right text-gray-700">
                    {{ $v->sale_price > 0 ? ($v->currency.' '.number_format($v->sale_price, 2)) : '-' }}
                </td>
                <td class="py-3 text-right {{ $v->sale_price > 0 && $saleUnpaid > 0 ? 'font-medium text-red-500' : 'text-gray-400' }}">
                    @if($v->sale_price <= 0)
                        -
                    @elseif($saleUnpaid > 0)
                        {{ $v->currency }} {{ number_format($saleUnpaid, 2) }}
                    @else
                        {{ __('cashflow.paid') }}
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="py-12 text-center text-sm text-gray-400">{{ __('cashflow.empty') }}</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->vehicles as $v)
    @php
        $statusBadge = match(true) {
            in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
            in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
            in_array($v->progress_status, ['선적중','선적완료'])            => 'badge-amber',
            in_array($v->progress_status, ['통관중','통관완료'])             => 'badge-green',
            in_array($v->progress_status, ['수출통관중','수출통관완료'])    => 'badge-amber',
            $v->progress_status === '거래완료'                             => 'badge-gray',
            default => 'badge-gray',
        };
        $purchaseUnpaid = $v->purchase_unpaid_amount;
        $saleUnpaid     = $v->sale_unpaid_amount;
    @endphp
    <div class="card-tight">
        <div class="flex items-center justify-between">
            <div class="font-medium text-gray-800">{{ $v->vehicle_number }}</div>
            <span class="badge {{ $statusBadge }}">{{ __('domain.progress.'.$v->progress_status) }}</span>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-x-4 text-xs text-gray-500">
            <div>{{ __('cashflow.m_purchase_date') }} {{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</div>
            <div>{{ __('cashflow.m_purchase_price') }} {{ $v->purchase_price > 0 ? '₩'.number_format($v->purchase_price) : '-' }}</div>
            <div class="{{ $purchaseUnpaid > 0 ? 'text-red-500 font-medium' : '' }}">
                {{ __('cashflow.m_unpaid') }} {{ $purchaseUnpaid > 0 ? '₩'.number_format($purchaseUnpaid) : __('cashflow.paid') }}
            </div>
            <div class="{{ $v->sale_price > 0 && $saleUnpaid > 0 ? 'text-red-500 font-medium' : '' }}">
                {{ __('cashflow.m_sale_unpaid') }}
                @if($v->sale_price <= 0) -
                @elseif($saleUnpaid > 0) {{ $v->currency }} {{ number_format($saleUnpaid, 2) }}
                @else {{ __('cashflow.paid') }}
                @endif
            </div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('cashflow.empty') }}</div>
    @endforelse
</div>

</div>
</div>
