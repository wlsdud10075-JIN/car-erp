<?php

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    public int $selectedSalesmanId = 0;

    public function mount(): void
    {
        if (! auth()->user()->isAdmin()) {
            $salesman = auth()->user()->salesman;
            if ($salesman) {
                $this->selectedSalesmanId = $salesman->id;
            }
        }
    }

    #[Computed]
    public function salesmen()
    {
        return Salesman::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function kpi(): array
    {
        $sid = $this->selectedSalesmanId ?: null;
        $ms  = now()->startOfMonth()->toDateString();
        $me  = now()->endOfMonth()->toDateString();

        $base = Vehicle::query()->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid));

        $total         = (clone $base)->count();
        $thisMonthBuy  = (clone $base)->whereBetween('purchase_date', [$ms, $me])->count();
        $thisMonthSale = (clone $base)->where('sale_price', '>', 0)->whereBetween('sale_date', [$ms, $me])->count();
        $totalDone     = (clone $base)->where('dhl_request', true)->where('is_disposed', false)->count();

        $thisMonthPurchaseAmt = (clone $base)->whereBetween('purchase_date', [$ms, $me])->sum('purchase_price');
        $thisMonthSaleAmt     = (clone $base)->where('sale_price', '>', 0)->whereBetween('sale_date', [$ms, $me])->sum('sale_price');

        $disposed  = (clone $base)->where('is_disposed', true)->count();
        $done      = (clone $base)->where('dhl_request', true)->where('is_disposed', false)->count();
        $shipping  = (clone $base)->whereNotNull('bl_document')
            ->where('dhl_request', false)->where('is_disposed', false)->count();
        $clearance = (clone $base)->whereNotNull('export_declaration_document')
            ->whereNull('bl_document')->where('dhl_request', false)->where('is_disposed', false)->count();
        $selling   = (clone $base)->where('sale_price', '>', 0)
            ->whereNull('export_declaration_document')
            ->where('dhl_request', false)->where('is_disposed', false)->count();
        $buying    = (clone $base)->where('sale_price', '=', 0)
            ->where('is_disposed', false)->where('dhl_request', false)->count();

        $settlementCounts = Settlement::query()
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->selectRaw('settlement_status, COUNT(*) as cnt')
            ->groupBy('settlement_status')
            ->pluck('cnt', 'settlement_status')
            ->toArray();

        return compact(
            'total', 'thisMonthBuy', 'thisMonthSale', 'totalDone',
            'thisMonthPurchaseAmt', 'thisMonthSaleAmt',
            'buying', 'selling', 'clearance', 'shipping', 'done', 'disposed',
            'settlementCounts'
        );
    }

    #[Computed]
    public function channelCounts(): array
    {
        $sid = $this->selectedSalesmanId ?: null;

        return Vehicle::query()
            ->whereNull('deleted_at')
            ->where('is_disposed', false)
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->whereNotNull('sales_channel')
            ->selectRaw('sales_channel, COUNT(*) as cnt')
            ->groupBy('sales_channel')
            ->pluck('cnt', 'sales_channel')
            ->toArray();
    }

    #[Computed]
    public function monthlyTrend(): array
    {
        $sid       = $this->selectedSalesmanId ?: null;
        $startDate = now()->subMonths(5)->startOfMonth()->toDateString();
        $months    = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'))->all();

        $buys = Vehicle::query()->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->whereNotNull('purchase_date')
            ->where('purchase_date', '>=', $startDate)
            ->selectRaw("DATE_FORMAT(purchase_date, '%Y-%m') as ym, COUNT(*) as cnt")
            ->groupBy('ym')
            ->pluck('cnt', 'ym');

        $sales = Vehicle::query()->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->where('sale_price', '>', 0)
            ->whereNotNull('sale_date')
            ->where('sale_date', '>=', $startDate)
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') as ym, COUNT(*) as cnt")
            ->groupBy('ym')
            ->pluck('cnt', 'ym');

        return collect($months)->map(fn ($m) => [
            'month' => $m,
            'label' => ltrim(substr($m, 5), '0') . '월',
            'buy'   => (int) ($buys[$m] ?? 0),
            'sale'  => (int) ($sales[$m] ?? 0),
        ])->all();
    }

    #[Computed]
    public function unpaid(): array
    {
        $sid = $this->selectedSalesmanId ?: null;

        $vehicles = Vehicle::query()
            ->whereNull('deleted_at')
            ->where('is_disposed', false)
            ->where('dhl_request', false)
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->where(fn ($q) => $q->where('purchase_price', '>', 0)->orWhere('sale_price', '>', 0))
            ->with(['finalPayments', 'purchaseBalancePayments'])
            ->get();

        $purchaseUnpaid = (int) $vehicles->sum('purchase_unpaid_amount');

        $saleUnpaid = $vehicles
            ->where('sale_price', '>', 0)
            ->groupBy('currency')
            ->map(fn ($g) => $g->sum('sale_unpaid_amount'))
            ->filter(fn ($v) => $v > 0)
            ->toArray();

        return compact('purchaseUnpaid', 'saleUnpaid');
    }

    #[Computed]
    public function recentVehicles()
    {
        $sid = $this->selectedSalesmanId ?: null;

        return Vehicle::query()
            ->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->with(['salesman', 'finalPayments', 'purchaseBalancePayments'])
            ->latest()
            ->limit(8)
            ->get();
    }
}; ?>

<div>
<div class="flex h-full flex-col gap-5 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold text-gray-800">ERP 대시보드</h1>
        @php
            $smName = $selectedSalesmanId
                ? $this->salesmen->firstWhere('id', $selectedSalesmanId)?->name
                : null;
        @endphp
        <p class="mt-0.5 text-xs text-gray-500">
            {{ $smName ? $smName.' 담당 현황' : '전체 현황' }} · {{ now()->format('Y년 m월') }}
        </p>
    </div>
    @if(auth()->user()->isAdmin())
    <div class="flex items-center gap-2">
        <span class="text-xs text-gray-500">담당자</span>
        <select wire:model.live="selectedSalesmanId" class="input-filter">
            <option value="0">전체</option>
            @foreach($this->salesmen as $sm)
            <option value="{{ $sm->id }}">{{ $sm->name }}</option>
            @endforeach
        </select>
    </div>
    @endif
</div>

{{-- Row 1: 요약 KPI 카드 --}}
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card">
        <p class="text-xs text-gray-500">전체 차량</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->kpi['total'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
    </div>
    <div class="card">
        <p class="text-xs text-gray-500">이달 거래완료</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->kpi['totalDone'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
    </div>
    <div class="card">
        <p class="text-xs text-gray-500">이달 매입</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->kpi['thisMonthBuy'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
        @if($this->kpi['thisMonthPurchaseAmt'] > 0)
        <p class="mt-1 text-xs text-gray-400">₩{{ number_format($this->kpi['thisMonthPurchaseAmt']) }}</p>
        @endif
    </div>
    <div class="card">
        <p class="text-xs text-gray-500">이달 판매</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->kpi['thisMonthSale'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
        @if($this->kpi['thisMonthSaleAmt'] > 0)
        <p class="mt-1 text-xs text-gray-400">₩{{ number_format($this->kpi['thisMonthSaleAmt']) }}</p>
        @endif
    </div>
</div>

{{-- Row 2: 채널별 현황 + 월별 추이 --}}
<div class="grid grid-cols-1 gap-3 lg:grid-cols-2">

    {{-- 채널별 현황 --}}
    <div class="card">
        <h2 class="mb-3 text-sm font-semibold text-gray-700">
            채널별 현황
            <span class="ml-1 text-xs font-normal text-gray-400">진행중 차량</span>
        </h2>
        @php
        $channels = [
            ['key' => 'export',  'label' => '수출',   'badge' => 'badge-blue'],
            ['key' => 'heyman',  'label' => '헤이맨', 'badge' => 'badge-teal'],
            ['key' => 'carpul',  'label' => '카풀',   'badge' => 'badge-purple'],
        ];
        $cc = $this->channelCounts;
        @endphp
        <div class="grid grid-cols-3 gap-2">
            @foreach($channels as $ch)
            <a href="{{ route('erp.vehicles.index') }}?channelFilter={{ $ch['key'] }}" wire:navigate
               class="flex flex-col items-center rounded-lg border border-gray-100 bg-gray-50 px-2 py-4 text-center transition hover:bg-gray-100">
                <span class="badge {{ $ch['badge'] }} mb-2">{{ $ch['label'] }}</span>
                <span class="text-2xl font-bold text-gray-800">{{ $cc[$ch['key']] ?? 0 }}</span>
                <span class="text-xs text-gray-400">대</span>
            </a>
            @endforeach
        </div>
    </div>

    {{-- 월별 매입/판매 추이 --}}
    <div class="card">
        <h2 class="mb-3 text-sm font-semibold text-gray-700">월별 매입 / 판매 추이</h2>
        @php
        $trend    = $this->monthlyTrend;
        $maxVal   = max(1, collect($trend)->max(fn ($m) => max($m['buy'], $m['sale'])));
        @endphp
        <div class="flex items-end gap-2" style="height: 100px;">
            @foreach($trend as $m)
            @php
                $buyPct  = round($m['buy']  / $maxVal * 100);
                $salePct = round($m['sale'] / $maxVal * 100);
            @endphp
            <div class="flex flex-1 flex-col items-center gap-0.5">
                <div class="flex w-full items-end justify-center gap-0.5" style="height: 72px;">
                    <div class="flex-1 rounded-sm bg-blue-300 transition-all"
                         style="height: {{ max(2, $buyPct) }}%"
                         title="매입 {{ $m['buy'] }}대"></div>
                    <div class="flex-1 rounded-sm bg-violet-400 transition-all"
                         style="height: {{ max(2, $salePct) }}%"
                         title="판매 {{ $m['sale'] }}대"></div>
                </div>
                <span class="text-[9px] text-gray-400">{{ $m['label'] }}</span>
                <div class="flex gap-1 text-[9px] font-medium">
                    <span class="text-blue-500">{{ $m['buy'] }}</span>
                    <span class="text-gray-300">/</span>
                    <span class="text-violet-500">{{ $m['sale'] }}</span>
                </div>
            </div>
            @endforeach
        </div>
        <div class="mt-2 flex items-center gap-3 text-[10px] text-gray-400">
            <span class="flex items-center gap-1">
                <span class="inline-block h-2 w-3 rounded-sm bg-blue-300"></span> 매입
            </span>
            <span class="flex items-center gap-1">
                <span class="inline-block h-2 w-3 rounded-sm bg-violet-400"></span> 판매
            </span>
        </div>
    </div>
</div>

{{-- Row 3: 진행단계 현황 --}}
<div class="card">
    <h2 class="mb-3 text-sm font-semibold text-gray-700">진행단계 현황</h2>
    @php
    $stages = [
        ['label' => '매입단계', 'count' => $this->kpi['buying'],    'badge' => 'badge-blue',   'filter' => '매입중'],
        ['label' => '판매단계', 'count' => $this->kpi['selling'],   'badge' => 'badge-purple', 'filter' => '판매중'],
        ['label' => '통관단계', 'count' => $this->kpi['clearance'], 'badge' => 'badge-amber',  'filter' => '수출통관중'],
        ['label' => '선적단계', 'count' => $this->kpi['shipping'],  'badge' => 'badge-green',  'filter' => '선적중'],
        ['label' => '거래완료', 'count' => $this->kpi['done'],      'badge' => 'badge-gray',   'filter' => '거래완료'],
        ['label' => '폐기',     'count' => $this->kpi['disposed'],  'badge' => 'badge-red',    'filter' => '폐기'],
    ];
    @endphp
    <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
        @foreach($stages as $stage)
        <a href="{{ route('erp.vehicles.index') }}?progressFilter={{ urlencode($stage['filter']) }}" wire:navigate
           class="flex flex-col items-center rounded-lg border border-gray-100 bg-gray-50 px-2 py-3 text-center transition hover:bg-gray-100">
            <span class="badge {{ $stage['badge'] }} mb-2">{{ $stage['label'] }}</span>
            <span class="text-xl font-bold text-gray-800">{{ $stage['count'] }}</span>
            <span class="text-xs text-gray-400">대</span>
        </a>
        @endforeach
    </div>
</div>

{{-- Row 4: 미지급/미입금 + 정산 현황 --}}
<div class="grid grid-cols-1 gap-3 lg:grid-cols-2">

    {{-- 미지급/미입금 --}}
    <div class="card">
        <h2 class="mb-3 text-sm font-semibold text-gray-700">
            미지급 / 미입금
            <span class="ml-1 text-xs font-normal text-gray-400">진행중 차량 기준</span>
        </h2>
        <div class="space-y-2">
            <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2.5">
                <span class="text-sm text-gray-600">매입 미지급</span>
                <span class="font-semibold {{ $this->unpaid['purchaseUnpaid'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                    {{ $this->unpaid['purchaseUnpaid'] > 0 ? '₩'.number_format($this->unpaid['purchaseUnpaid']) : '없음' }}
                </span>
            </div>
            <div class="rounded-lg bg-gray-50 px-3 py-2.5">
                <div class="mb-2 text-sm text-gray-600">판매 미입금</div>
                @if(empty($this->unpaid['saleUnpaid']))
                    <span class="text-sm font-semibold text-green-600">없음</span>
                @else
                    <div class="space-y-1">
                        @foreach($this->unpaid['saleUnpaid'] as $currency => $amount)
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-400">{{ $currency }}</span>
                            <span class="text-sm font-semibold text-red-600">{{ number_format($amount, 2) }}</span>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- 정산 현황 --}}
    <div class="card">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-700">정산 현황</h2>
            <a href="{{ route('erp.settlements.index') }}" wire:navigate class="text-xs text-violet-600 hover:underline">자세히 →</a>
        </div>
        @php
        $sc = $this->kpi['settlementCounts'];
        $settlementStages = [
            ['key' => 'pending',     'label' => '대기',     'badge' => 'badge-blue'],
            ['key' => 'calculating', 'label' => '계산중',   'badge' => 'badge-amber'],
            ['key' => 'confirmed',   'label' => '확정',     'badge' => 'badge-green'],
            ['key' => 'paid',        'label' => '지급완료', 'badge' => 'badge-gray'],
        ];
        @endphp
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach($settlementStages as $ss)
            <a href="{{ route('erp.settlements.index') }}" wire:navigate
               class="flex flex-col items-center rounded-lg border border-gray-100 bg-gray-50 px-2 py-3 text-center transition hover:bg-gray-100">
                <span class="badge {{ $ss['badge'] }} mb-2">{{ $ss['label'] }}</span>
                <span class="text-xl font-bold text-gray-800">{{ $sc[$ss['key']] ?? 0 }}</span>
                <span class="text-xs text-gray-400">건</span>
            </a>
            @endforeach
        </div>
    </div>
</div>

{{-- Row 5: 최근 차량 --}}
<div class="card">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">최근 등록 차량</h2>
        <a href="{{ route('erp.vehicles.index') }}" wire:navigate class="text-xs text-violet-600 hover:underline">전체 보기 →</a>
    </div>

    {{-- 데스크탑 테이블 --}}
    <div class="hidden overflow-x-auto sm:block">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-left text-xs text-gray-400">
                    <th class="pb-2 pr-4 font-medium">차량번호</th>
                    <th class="pb-2 pr-4 font-medium">담당자</th>
                    <th class="pb-2 pr-4 font-medium">진행상태</th>
                    <th class="pb-2 pr-4 font-medium">채널</th>
                    <th class="pb-2 pr-4 font-medium">매입일</th>
                    <th class="pb-2 text-right font-medium">매입가</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($this->recentVehicles as $v)
                @php
                    $pb = match(true) {
                        in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                        in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                        in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                        in_array($v->progress_status, ['선적중','선적완료'])           => 'badge-green',
                        $v->progress_status === '거래완료'                             => 'badge-gray',
                        default                                                         => 'badge-red',
                    };
                    $cb = match($v->sales_channel ?? '') {
                        'export' => 'badge-blue', 'heyman' => 'badge-teal',
                        'carpul' => 'badge-purple', default => 'badge-gray',
                    };
                    $cl = match($v->sales_channel ?? '') {
                        'export' => '수출', 'heyman' => '헤이맨', 'carpul' => '카풀', default => '-',
                    };
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="py-2.5 pr-4 font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                    <td class="py-2.5 pr-4 text-gray-500">{{ $v->salesman?->name ?? '-' }}</td>
                    <td class="py-2.5 pr-4"><span class="badge {{ $pb }}">{{ $v->progress_status }}</span></td>
                    <td class="py-2.5 pr-4">
                        @if($v->sales_channel)
                        <span class="badge {{ $cb }}">{{ $cl }}</span>
                        @else
                        <span class="text-gray-300">-</span>
                        @endif
                    </td>
                    <td class="py-2.5 pr-4 text-gray-500">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-2.5 text-right text-gray-700">
                        {{ $v->purchase_price > 0 ? '₩'.number_format($v->purchase_price) : '-' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="py-8 text-center text-sm text-gray-400">차량이 없습니다.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block space-y-2 sm:hidden">
        @forelse($this->recentVehicles as $v)
        @php
            $pb = match(true) {
                in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                in_array($v->progress_status, ['선적중','선적완료'])           => 'badge-green',
                $v->progress_status === '거래완료'                             => 'badge-gray',
                default                                                         => 'badge-red',
            };
        @endphp
        <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2.5">
            <div>
                <div class="font-medium text-gray-800">{{ $v->vehicle_number }}</div>
                <div class="text-xs text-gray-400">
                    {{ $v->purchase_date?->format('Y-m-d') ?? '-' }}
                    @if($v->salesman) · {{ $v->salesman->name }} @endif
                </div>
            </div>
            <span class="badge {{ $pb }}">{{ $v->progress_status }}</span>
        </div>
        @empty
        <div class="py-8 text-center text-sm text-gray-400">차량이 없습니다.</div>
        @endforelse
    </div>
</div>

</div>
</div>
