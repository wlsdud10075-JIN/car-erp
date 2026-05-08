<?php

use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = $this->dateFrom ?: now()->subMonths(2)->format('Y-m-d');
        $this->dateTo = $this->dateTo ?: now()->format('Y-m-d');
    }

    /**
     * 매출/KPI 전용 — 채권 위젯은 의도적으로 제외 (CLAUDE.md 9단계 결정사항 #4).
     * 채권 정보는 /erp/receivables에서 별도 관리.
     */
    #[Computed]
    public function kpis(): array
    {
        $base = Vehicle::query()
            ->when($this->dateFrom, fn ($q) => $q->where('purchase_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('purchase_date', '<=', $this->dateTo));

        $vehiclesInRange = (clone $base)->count();
        $purchaseTotal = (int) (clone $base)->sum('purchase_price');

        // 판매가 KRW 환산 합계
        $saleKrw = (clone $base)->where('sale_price', '>', 0)->get()->sum(function ($v) {
            return $v->currency === 'KRW' ? $v->sale_price : $v->sale_price * ($v->exchange_rate ?: 0);
        });
        $saleCount = (clone $base)->where('sale_price', '>', 0)->count();

        $byChannel = (clone $base)
            ->selectRaw('sales_channel, COUNT(*) as cnt')
            ->groupBy('sales_channel')
            ->pluck('cnt', 'sales_channel')
            ->toArray();

        $byProgress = (clone $base)
            ->selectRaw('progress_status_cache, COUNT(*) as cnt')
            ->groupBy('progress_status_cache')
            ->pluck('cnt', 'progress_status_cache')
            ->toArray();

        return [
            'vehicles' => $vehiclesInRange,
            'purchase_total' => $purchaseTotal,
            'sale_total_krw' => (int) $saleKrw,
            'sale_count' => $saleCount,
            'by_channel' => [
                'export' => $byChannel['export'] ?? 0,
                'heyman' => $byChannel['heyman'] ?? 0,
                'carpul' => $byChannel['carpul'] ?? 0,
            ],
            'by_progress' => $byProgress,
        ];
    }

    /**
     * 카드/뱃지 클릭 시 vehicles 목록으로 이동할 URL 빌더.
     * 모든 링크가 같은 dateFrom/dateTo + dateType=purchase 컨텍스트를 공유.
     */
    public function vehiclesUrl(array $extra = []): string
    {
        $params = array_merge([
            'dateType' => 'purchase',
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
        ], $extra);

        return route('erp.vehicles.index').'?'.http_build_query($params);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    {{-- 헤더 --}}
    <div class="flex items-end justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">관리자 대시보드</h1>
            <p class="mt-1 text-sm text-gray-500">매출 / 차량 진행 KPI · 기간별 비즈니스 지표</p>
        </div>
        <div class="flex items-center gap-2">
            <input type="date" wire:model.live="dateFrom" class="input-base" />
            <span class="text-xs text-gray-400">~</span>
            <input type="date" wire:model.live="dateTo" class="input-base" />
        </div>
    </div>

    {{-- 매출 KPI 카드 4개 (모두 클릭 가능) --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <a href="{{ $this->vehiclesUrl() }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">기간 차량 수</span>
                <span class="text-xs text-violet-500">상세 →</span>
            </div>
            <div class="mt-1 text-2xl font-bold text-gray-800">{{ number_format($this->kpis['vehicles']) }}<span class="ml-1 text-sm font-normal text-gray-500">대</span></div>
        </a>
        <a href="{{ $this->vehiclesUrl(['action' => 'has_purchase']) }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">기간 매입가 합계</span>
                <span class="text-xs text-violet-500">상세 →</span>
            </div>
            <div class="mt-1 text-2xl font-bold text-gray-800">{{ number_format($this->kpis['purchase_total']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
        </a>
        <a href="{{ $this->vehiclesUrl(['action' => 'has_sale']) }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">기간 판매가 합계 (KRW 환산)</span>
                <span class="text-xs text-violet-500">{{ $this->kpis['sale_count'] }}대 →</span>
            </div>
            <div class="mt-1 text-2xl font-bold text-blue-600">{{ number_format($this->kpis['sale_total_krw']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
        </a>
        <a href="{{ route('erp.receivables.index') }}" wire:navigate class="card transition hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">채권 관리</span>
                <span class="text-xs text-violet-500">이동 →</span>
            </div>
            <div class="mt-1 text-sm font-medium text-violet-600">미수금 / 회수 이력</div>
            <div class="mt-1 text-xs text-gray-400">채권은 별도 화면에서 관리</div>
        </a>
    </div>

    {{-- 채널별 분포 --}}
    <div class="card">
        <div class="section-header">
            <span class="section-dot bg-violet-500"></span>
            <span class="section-title">채널별 차량 수 (클릭 시 해당 채널 목록)</span>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-3">
            @foreach (['export' => '수출', 'heyman' => '헤이맨', 'carpul' => '카풀'] as $code => $label)
            @php $cnt = $this->kpis['by_channel'][$code]; @endphp
            <a href="{{ $this->vehiclesUrl(['channelFilter' => $code]) }}" wire:navigate
               class="card-sm flex items-center justify-between transition hover:bg-violet-50 {{ $cnt === 0 ? 'opacity-50' : '' }}">
                <span class="text-sm text-gray-700">{{ $label }}</span>
                <span class="text-lg font-bold text-gray-800">{{ number_format($cnt) }}</span>
            </a>
            @endforeach
        </div>
    </div>

    {{-- 진행 단계별 분포 --}}
    <div class="card">
        <div class="section-header">
            <span class="section-dot bg-blue-500"></span>
            <span class="section-title">진행 단계별 차량 수 (클릭 시 해당 단계 목록)</span>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-6">
            @foreach (['매입중','매입완료','말소완료','판매중','판매완료','수출통관중','수출통관완료','선적중','선적완료','거래완료','폐기'] as $status)
            @php $cnt = $this->kpis['by_progress'][$status] ?? 0; @endphp
            <a href="{{ $this->vehiclesUrl(['progressFilter' => $status]) }}" wire:navigate
               class="card-sm flex items-center justify-between transition hover:bg-violet-50 {{ $cnt === 0 ? 'opacity-40' : '' }}">
                <span class="text-xs text-gray-600">{{ $status }}</span>
                <span class="text-base font-bold text-gray-800">{{ number_format($cnt) }}</span>
            </a>
            @endforeach
        </div>
    </div>

    <p class="text-xs text-gray-400">
        ⓘ 미수금·회수 이력·채권 위험도는 <a href="{{ route('erp.receivables.index') }}" wire:navigate class="text-violet-600 hover:underline">채권관리 화면</a>에서 확인하세요.
    </p>
</div>
