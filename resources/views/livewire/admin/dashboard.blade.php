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
     * 조회 버튼 — deferred wire:model로 받은 dateFrom/dateTo를 적용해
     * Computed `kpis` 캐시를 무효화. wire:model.live였을 때 매 keystroke마다
     * Vehicle 풀스캔 + KRW 환산 SUM이 돌던 부담 제거.
     */
    public function applyFilters(): void
    {
        unset($this->kpis);
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

<div x-data="adminDashboard()" class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    {{-- 헤더 — 모바일 세로 스택, 데스크탑 좌우 분리 --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">관리자 대시보드</h1>
            <p class="mt-1 text-sm text-gray-500">매출 / 차량 진행 KPI · 기간별 비즈니스 지표</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <input type="date" wire:model="dateFrom" class="input-filter" />
            <span class="text-xs text-gray-400">~</span>
            <input type="date" wire:model="dateTo" class="input-filter" />
            <button wire:click="applyFilters" type="button"
                class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-violet-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-violet-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                조회
            </button>
            <button @click="settingsOpen = true" type="button"
                class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                위젯 설정
            </button>
        </div>
    </div>

    {{-- 매출 KPI 카드 4개 (모두 클릭 가능) --}}
    <div id="w-kpi" x-show="widgets['w-kpi']" class="grid grid-cols-2 gap-3 xl:grid-cols-4">
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
    <div id="w-channel" x-show="widgets['w-channel']" class="card">
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

    {{-- 진행 단계별 분포 — 큐 2번 파이프라인 카운트 스트립 (기간 내 매입 차량 기준) --}}
    <div id="w-progress" x-show="widgets['w-progress']">
        <x-erp.pipeline-strip
            :counts="$this->kpis['by_progress']"
            :url-builder="fn (string $s) => $this->vehiclesUrl(['progressFilter' => $s])"
            title="진행 단계별 차량 수"
            :subtitle="'매입일 '.$dateFrom.' ~ '.$dateTo.' 한정'" />
    </div>

    <p class="text-xs text-gray-400">
        ⓘ 미수금·회수 이력·채권 위험도는 <a href="{{ route('erp.receivables.index') }}" wire:navigate class="text-violet-600 hover:underline">채권관리 화면</a>에서 확인하세요.
    </p>

    {{-- 위젯 설정 슬라이드 패널 --}}
    <div x-show="settingsOpen" x-cloak class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-black/30" @click="settingsOpen = false"></div>
        <div
            x-show="settingsOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="relative z-10 h-full w-80 overflow-y-auto bg-white p-6 shadow-xl">

            <div class="mb-6 flex items-center justify-between">
                <h3 class="font-bold text-gray-800">위젯 설정</h3>
                <button @click="settingsOpen = false" class="text-gray-400 hover:text-gray-600" type="button">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <p class="mb-4 text-xs text-gray-500">표시할 위젯을 선택하세요. 설정은 이 브라우저에 저장됩니다.</p>

            <div class="space-y-4">
                <template x-for="w in widgetList" :key="w.key">
                    <label class="flex items-center justify-between">
                        <span class="text-sm text-gray-700" x-text="w.label"></span>
                        <button @click="toggleWidget(w.key)" type="button"
                            :class="widgets[w.key] ? 'bg-[var(--color-primary)]' : 'bg-gray-300'"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors">
                            <span :class="widgets[w.key] ? 'translate-x-5' : 'translate-x-1'"
                                class="inline-block h-3 w-3 transform rounded-full bg-white transition-transform"></span>
                        </button>
                    </label>
                </template>
            </div>
        </div>
    </div>

    <script>
        function adminDashboard() {
            return {
                settingsOpen: false,
                widgets: {},
                widgetList: [
                    { key: 'w-kpi',      label: 'KPI 카드 (4개)' },
                    { key: 'w-channel',  label: '채널별 차량 수' },
                    { key: 'w-progress', label: '진행 단계별 차량 수' },
                ],
                init() {
                    const saved = localStorage.getItem('car_erp_admin_dashboard_widgets');
                    const parsed = saved ? JSON.parse(saved) : {};
                    this.widgetList.forEach(w => {
                        this.widgets[w.key] = parsed[w.key] !== undefined ? parsed[w.key] : true;
                    });
                },
                toggleWidget(key) {
                    this.widgets[key] = !this.widgets[key];
                    localStorage.setItem('car_erp_admin_dashboard_widgets', JSON.stringify(this.widgets));
                },
            };
        }
    </script>
</div>
