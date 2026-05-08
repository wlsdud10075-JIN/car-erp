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
    public function summary(): array
    {
        $sid = $this->selectedSalesmanId ?: null;
        $ms  = now()->startOfMonth()->toDateString();
        $me  = now()->endOfMonth()->toDateString();

        $base = Vehicle::query()->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid));

        $active        = (clone $base)->where('is_disposed', false)->where('dhl_request', false)->count();
        $thisMonthBuy  = (clone $base)->whereBetween('purchase_date', [$ms, $me])->count();
        $thisMonthSale = (clone $base)->where('sale_price', '>', 0)->whereBetween('sale_date', [$ms, $me])->count();
        $thisMonthDone = (clone $base)->where('dhl_request', true)->whereBetween('updated_at', [$ms.' 00:00:00', $me.' 23:59:59'])->count();

        return compact('active', 'thisMonthBuy', 'thisMonthSale', 'thisMonthDone');
    }

    // 미지급/미입금/통관/선적/DHL/정산 액션 건수 — 차량 한 번 로드 후 collection 필터
    #[Computed]
    public function actionCounts(): array
    {
        $sid = $this->selectedSalesmanId ?: null;

        $vehicles = Vehicle::query()
            ->whereNull('deleted_at')
            ->where('is_disposed', false)
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->with(['finalPayments', 'purchaseBalancePayments'])
            ->get();

        $active = $vehicles->where('dhl_request', false);

        $pendingSettlements = Settlement::query()
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->where('settlement_status', 'pending')
            ->count();

        return [
            'purchaseUnpaid'     => $active->filter(fn ($v) => $v->purchase_price > 0 && $v->purchase_unpaid_amount > 0)->count(),
            'saleUnpaid'         => $active->filter(fn ($v) => $v->sale_price > 0 && $v->sale_unpaid_amount > 0)->count(),
            'clearanceNeeded'    => $active->filter(fn ($v) => $v->sale_price > 0 && $v->sale_unpaid_amount <= 0 && ! $v->export_declaration_document)->count(),
            'shippingNeeded'     => $active->filter(fn ($v) => $v->export_declaration_document && ! $v->bl_document)->count(),
            'dhlNeeded'          => $active->filter(fn ($v) => (bool) $v->bl_document)->count(),
            'pendingSettlements' => $pendingSettlements,
        ];
    }

    #[Computed]
    public function activeVehicles()
    {
        $sid = $this->selectedSalesmanId ?: null;

        return Vehicle::query()
            ->whereNull('deleted_at')
            ->where('is_disposed', false)
            ->where('dhl_request', false)
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->with(['salesman', 'finalPayments', 'purchaseBalancePayments'])
            ->orderBy('purchase_date', 'desc')
            ->limit(12)
            ->get();
    }
}; ?>

<div>
<div class="flex h-full flex-col gap-5 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold text-gray-800">내 업무 현황</h1>
        @php
            $smName = $selectedSalesmanId
                ? $this->salesmen->firstWhere('id', $selectedSalesmanId)?->name
                : null;
        @endphp
        <p class="mt-0.5 text-xs text-gray-500">
            {{ $smName ? $smName.' 담당' : '전체' }} · {{ now()->format('Y년 m월 d일') }}
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

{{-- Row 1: 요약 카드 --}}
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="card">
        <p class="text-xs text-gray-500">현재 진행중</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->summary['active'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
        <p class="mt-1 text-xs text-gray-400">거래완료·폐기 제외</p>
    </div>
    <div class="card">
        <p class="text-xs text-gray-500">이달 매입</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->summary['thisMonthBuy'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
        <p class="mt-1 text-xs text-gray-400">{{ now()->format('m') }}월 매입 기준</p>
    </div>
    <div class="card">
        <p class="text-xs text-gray-500">이달 판매</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->summary['thisMonthSale'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
        <p class="mt-1 text-xs text-gray-400">{{ now()->format('m') }}월 판매 기준</p>
    </div>
    <div class="card">
        <p class="text-xs text-gray-500">이달 거래완료</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">
            {{ $this->summary['thisMonthDone'] }}<span class="ml-0.5 text-sm font-normal text-gray-400">대</span>
        </p>
        <p class="mt-1 text-xs text-gray-400">{{ now()->format('m') }}월 완료 기준</p>
    </div>
</div>

{{-- Row 2: 할일 목록 --}}
<div class="card">
    <h2 class="mb-4 text-sm font-semibold text-gray-700">처리 필요 항목</h2>
    @php
    $ac = $this->actionCounts;
    $vehiclesUrl = function (string $action) use ($selectedSalesmanId) {
        $url = route('erp.vehicles.index').'?action='.$action;
        if ($selectedSalesmanId) {
            $url .= '&salesmanId='.$selectedSalesmanId;
        }
        return $url;
    };
    $actions = [
        [
            'label'  => '매입 미지급',
            'desc'   => '매입가 입력 후 잔금 미지급',
            'count'  => $ac['purchaseUnpaid'],
            'dot'    => 'bg-red-500',
            'href'   => $vehiclesUrl('purchase_unpaid'),
            'urgent' => true,
        ],
        [
            'label'  => '판매 미입금',
            'desc'   => '판매 후 미회수 금액 존재',
            'count'  => $ac['saleUnpaid'],
            'dot'    => 'bg-amber-500',
            'href'   => $vehiclesUrl('sale_unpaid'),
            'urgent' => true,
        ],
        [
            'label'  => '수출통관 신청 필요',
            'desc'   => '판매 완납 → 면장서류 미업로드',
            'count'  => $ac['clearanceNeeded'],
            'dot'    => 'bg-blue-500',
            'href'   => $vehiclesUrl('clearance_needed'),
            'urgent' => false,
        ],
        [
            'label'  => '선적 처리 필요',
            'desc'   => '수출통관 완료 → B/L 미처리',
            'count'  => $ac['shippingNeeded'],
            'dot'    => 'bg-green-500',
            'href'   => $vehiclesUrl('shipping_needed'),
            'urgent' => false,
        ],
        [
            'label'  => 'DHL 발송 필요',
            'desc'   => '선적 완료 → DHL 미신청',
            'count'  => $ac['dhlNeeded'],
            'dot'    => 'bg-teal-500',
            'href'   => $vehiclesUrl('dhl_needed'),
            'urgent' => false,
        ],
        [
            'label'  => '정산 대기',
            'desc'   => '정산 방식 미입력 또는 확인 필요',
            'count'  => $ac['pendingSettlements'],
            'dot'    => 'bg-violet-500',
            'href'   => route('erp.settlements.index'),
            'urgent' => false,
        ],
    ];
    $totalActions = collect($actions)->sum('count');
    @endphp

    @if($totalActions === 0)
    <div class="flex flex-col items-center justify-center py-8 text-center">
        <div class="mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
            <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <p class="text-sm font-medium text-green-700">처리할 항목이 없습니다</p>
        <p class="mt-0.5 text-xs text-gray-400">모든 작업이 최신 상태입니다</p>
    </div>
    @else
    <div class="divide-y divide-gray-100">
        @foreach($actions as $action)
        @if($action['count'] > 0)
        <a href="{{ $action['href'] }}" wire:navigate
           class="flex items-center gap-3 py-3 transition hover:bg-gray-50 -mx-4 px-4 first:-mt-1 last:-mb-1">
            <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full {{ $action['dot'] }}"></span>
            <div class="flex-1 min-w-0">
                <span class="text-sm font-medium text-gray-800">{{ $action['label'] }}</span>
                <span class="ml-2 text-xs text-gray-400 hidden sm:inline">{{ $action['desc'] }}</span>
            </div>
            <span class="flex-shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold
                         {{ $action['urgent'] && $action['count'] > 0 ? 'bg-red-100 text-red-700' : 'text-gray-700' }}">
                {{ $action['count'] }}건
            </span>
            <svg class="h-4 w-4 flex-shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        @endif
        @endforeach
    </div>
    @endif
</div>

{{-- Row 3: 진행중 차량 목록 --}}
<div class="card">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">
            진행중 차량
            <span class="ml-1 text-xs font-normal text-gray-400">최근 12대</span>
        </h2>
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
                    <th class="pb-2 pr-4 font-medium">다음 할일</th>
                    <th class="pb-2 pr-4 font-medium">매입일</th>
                    <th class="pb-2 pr-4 font-medium">미지급</th>
                    <th class="pb-2 font-medium">미입금</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($this->activeVehicles as $v)
                @php
                    $pb = match(true) {
                        in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                        in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                        in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                        in_array($v->progress_status, ['선적중','선적완료'])           => 'badge-green',
                        default                                                         => 'badge-gray',
                    };
                    $nextAction = match($v->progress_status) {
                        '매입중'       => '매입 정보 입력',
                        '매입완료'     => '말소 처리',
                        '말소완료'     => '판매 등록',
                        '판매중'       => '입금 확인',
                        '판매완료'     => '수출통관 신청',
                        '수출통관중'   => '면장서류 업로드',
                        '수출통관완료' => '선적 처리',
                        '선적중'       => 'B/L 업로드',
                        '선적완료'     => 'DHL 발송',
                        default        => '-',
                    };
                    $puAmt = $v->purchase_unpaid_amount;
                    $suAmt = $v->sale_unpaid_amount;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="py-2.5 pr-4 font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                    <td class="py-2.5 pr-4 text-gray-500">{{ $v->salesman?->name ?? '-' }}</td>
                    <td class="py-2.5 pr-4"><span class="badge {{ $pb }}">{{ $v->progress_status }}</span></td>
                    <td class="py-2.5 pr-4 text-xs text-gray-500">{{ $nextAction }}</td>
                    <td class="py-2.5 pr-4 text-gray-500">{{ $v->purchase_date?->format('m-d') ?? '-' }}</td>
                    <td class="py-2.5 pr-4 text-xs {{ $puAmt > 0 ? 'font-medium text-red-600' : 'text-gray-300' }}">
                        {{ $puAmt > 0 ? '₩'.number_format($puAmt) : '없음' }}
                    </td>
                    <td class="py-2.5 text-xs {{ $suAmt > 0 ? 'font-medium text-amber-600' : 'text-gray-300' }}">
                        {{ $suAmt > 0 ? number_format($suAmt, 0).' ('.$v->currency.')' : '없음' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="py-8 text-center text-sm text-gray-400">진행중인 차량이 없습니다.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block space-y-2 sm:hidden">
        @forelse($this->activeVehicles as $v)
        @php
            $pb = match(true) {
                in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                in_array($v->progress_status, ['선적중','선적완료'])           => 'badge-green',
                default                                                         => 'badge-gray',
            };
            $nextAction = match($v->progress_status) {
                '매입중'       => '매입 정보 입력',
                '매입완료'     => '말소 처리',
                '말소완료'     => '판매 등록',
                '판매중'       => '입금 확인',
                '판매완료'     => '수출통관 신청',
                '수출통관중'   => '면장서류 업로드',
                '수출통관완료' => '선적 처리',
                '선적중'       => 'B/L 업로드',
                '선적완료'     => 'DHL 발송',
                default        => '-',
            };
        @endphp
        <div class="rounded-lg border border-gray-100 px-3 py-2.5">
            <div class="flex items-center justify-between">
                <span class="font-medium text-gray-800">{{ $v->vehicle_number }}</span>
                <span class="badge {{ $pb }}">{{ $v->progress_status }}</span>
            </div>
            <div class="mt-1 flex items-center justify-between">
                <span class="text-xs text-gray-500">{{ $nextAction }}</span>
                <span class="text-xs text-gray-400">
                    {{ $v->purchase_date?->format('m-d') ?? '-' }}
                    @if($v->salesman) · {{ $v->salesman->name }} @endif
                </span>
            </div>
        </div>
        @empty
        <div class="py-8 text-center text-sm text-gray-400">진행중인 차량이 없습니다.</div>
        @endforelse
    </div>
</div>

</div>
</div>
