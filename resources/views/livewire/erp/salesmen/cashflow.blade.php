<?php

use App\Models\Salesman;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

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
        ];
    }
}; ?>

<div>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-start gap-3">
    <a href="{{ route('erp.salesmen.index') }}" wire:navigate
       class="mt-1 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    </a>
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ $salesmanName }} — 캐시플로우</h1>
        <p class="mt-0.5 text-xs text-gray-500">
            {{ $salesmanPhone }}{{ $salesmanPhone && $salesmanEmail ? ' · ' : '' }}{{ $salesmanEmail }}
        </p>
    </div>
</div>

{{-- 날짜 필터 --}}
<div class="card-tight flex flex-wrap items-end gap-3">
    <div>
        <label class="label-base">매입일 시작</label>
        <input wire:model.live="dateFrom" type="date" class="input-base" />
    </div>
    <div>
        <label class="label-base">매입일 종료</label>
        <input wire:model.live="dateTo" type="date" class="input-base" />
    </div>
</div>

{{-- KPI 카드 --}}
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card">
        <div class="text-xs text-gray-500">담당 차량</div>
        <div class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->summary['count'] }}<span class="text-sm font-normal text-gray-500">대</span>
        </div>
    </div>
    <div class="card">
        <div class="text-xs text-gray-500">매입가 합계</div>
        <div class="mt-1 text-lg font-bold text-gray-800">₩{{ number_format($this->summary['purchase_total']) }}</div>
    </div>
    <div class="card">
        <div class="text-xs text-gray-500">매입 미지급</div>
        <div class="mt-1 text-lg font-bold {{ $this->summary['purchase_unpaid'] > 0 ? 'text-red-600' : 'text-green-600' }}">
            {{ $this->summary['purchase_unpaid'] > 0 ? '₩'.number_format($this->summary['purchase_unpaid']) : '없음' }}
        </div>
    </div>
    <div class="card">
        <div class="text-xs text-gray-500">판매 미입금</div>
        @if(empty($this->summary['sale_unpaid_by_currency']))
            <div class="mt-1 text-lg font-bold text-green-600">없음</div>
        @else
            <div class="mt-1 space-y-0.5">
                @foreach($this->summary['sale_unpaid_by_currency'] as $currency => $amount)
                <div class="text-sm font-bold text-red-600">{{ $currency }} {{ number_format($amount, 2) }}</div>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">차량번호</th>
                <th class="pb-2 pr-4 font-medium">상태</th>
                <th class="pb-2 pr-4 font-medium">채널</th>
                <th class="pb-2 pr-4 font-medium">매입일</th>
                <th class="pb-2 pr-4 font-medium text-right">매입가</th>
                <th class="pb-2 pr-4 font-medium text-right">미지급</th>
                <th class="pb-2 pr-4 font-medium text-right">판매가</th>
                <th class="pb-2 font-medium text-right">미입금</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->vehicles as $v)
            @php
                $statusBadge = match(true) {
                    in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                    in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                    in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                    in_array($v->progress_status, ['선적중','선적완료'])           => 'badge-green',
                    $v->progress_status === '거래완료'                             => 'badge-gray',
                    $v->progress_status === '폐기'                                 => 'badge-red',
                    default => 'badge-gray',
                };
                $channelLabel = match($v->sales_channel ?? '') {
                    'export' => '수출', 'heyman' => '헤이맨', 'carpul' => '카풀', default => '-',
                };
                $channelBadge = match($v->sales_channel ?? '') {
                    'export' => 'badge-blue', 'heyman' => 'badge-teal', 'carpul' => 'badge-purple', default => 'badge-gray',
                };
                $purchaseUnpaid = $v->purchase_unpaid_amount;
                $saleUnpaid     = $v->sale_unpaid_amount;
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $statusBadge }}">{{ $v->progress_status }}</span>
                </td>
                <td class="py-3 pr-4">
                    @if($v->sales_channel)
                    <span class="badge {{ $channelBadge }}">{{ $channelLabel }}</span>
                    @else
                    <span class="text-gray-300">-</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-500">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-right text-gray-700">
                    {{ $v->purchase_price > 0 ? '₩'.number_format($v->purchase_price) : '-' }}
                </td>
                <td class="py-3 pr-4 text-right {{ $purchaseUnpaid > 0 ? 'font-medium text-red-500' : 'text-gray-400' }}">
                    {{ $purchaseUnpaid > 0 ? '₩'.number_format($purchaseUnpaid) : '완납' }}
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
                        완납
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="py-12 text-center text-sm text-gray-400">해당 기간에 담당 차량이 없습니다.</td>
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
            in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
            in_array($v->progress_status, ['선적중','선적완료'])           => 'badge-green',
            $v->progress_status === '거래완료'                             => 'badge-gray',
            $v->progress_status === '폐기'                                 => 'badge-red',
            default => 'badge-gray',
        };
        $purchaseUnpaid = $v->purchase_unpaid_amount;
        $saleUnpaid     = $v->sale_unpaid_amount;
    @endphp
    <div class="card-tight">
        <div class="flex items-center justify-between">
            <div class="font-medium text-gray-800">{{ $v->vehicle_number }}</div>
            <span class="badge {{ $statusBadge }}">{{ $v->progress_status }}</span>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-x-4 text-xs text-gray-500">
            <div>매입일: {{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</div>
            <div>매입가: {{ $v->purchase_price > 0 ? '₩'.number_format($v->purchase_price) : '-' }}</div>
            <div class="{{ $purchaseUnpaid > 0 ? 'text-red-500 font-medium' : '' }}">
                미지급: {{ $purchaseUnpaid > 0 ? '₩'.number_format($purchaseUnpaid) : '완납' }}
            </div>
            <div class="{{ $v->sale_price > 0 && $saleUnpaid > 0 ? 'text-red-500 font-medium' : '' }}">
                미입금:
                @if($v->sale_price <= 0) -
                @elseif($saleUnpaid > 0) {{ $v->currency }} {{ number_format($saleUnpaid, 2) }}
                @else 완납
                @endif
            </div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">해당 기간에 담당 차량이 없습니다.</div>
    @endforelse
</div>

</div>
</div>
