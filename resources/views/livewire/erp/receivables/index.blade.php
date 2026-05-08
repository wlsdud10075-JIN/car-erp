<?php

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    // ── URL 파라미터 ───────────────────────────────────────
    #[Url] public string $channel = 'export';   // export / heyman / carpul
    #[Url] public string $search = '';
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public string $salesmanFilter = '';
    #[Url] public string $buyerFilter = '';
    #[Url] public string $progressFilter = '';
    #[Url] public string $riskFilter = '';        // safe/caution/danger/critical
    #[Url] public string $unpaidRatioMin = '';    // 30 / 50 / 70

    public int $perPage = 30;

    public function mount(): void
    {
        // TODO: receivable role 신설 시 canAccessReceivable() 검증으로 전환.
        //       현재는 admin 단독 접근 정책 (CLAUDE.md 9단계 결정사항 #5).
        if (! auth()->user()?->canAccessAdmin()) {
            abort(403, '채권관리에 접근할 권한이 없습니다.');
        }

        $this->dateFrom = $this->dateFrom ?: now()->subMonths(3)->format('Y-m-d');
        $this->dateTo = $this->dateTo ?: now()->format('Y-m-d');
    }

    public function search(): void
    {
        $this->resetPage();
    }

    public function setChannel(string $channel): void
    {
        if (! in_array($channel, ['export', 'heyman', 'carpul'], true)) {
            return;
        }
        $this->channel = $channel;
        $this->resetPage();
    }

    #[Computed]
    public function vehicles()
    {
        return $this->buildQuery()
            ->with(['exportBuyer', 'buyer', 'salesman', 'receivableManager'])
            ->orderByDesc('sale_unpaid_amount_krw_cache')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function summary(): array
    {
        $base = $this->buildQuery();

        // 다중통화: 행 단위 KRW 환산 후 합산.
        $totalSaleKrw = (clone $base)->get()->sum(function ($v) {
            $total = $v->sale_total_amount;

            return $v->currency === 'KRW' ? $total : $total * ($v->exchange_rate ?: 0);
        });
        $totalUnpaidKrw = (int) (clone $base)->sum('sale_unpaid_amount_krw_cache');
        $totalPaidKrw = max(0, (int) $totalSaleKrw - $totalUnpaidKrw);
        $riskCount = (clone $base)->whereIn('receivable_risk', ['danger', 'critical'])->count();

        return [
            'total_sale_krw' => (int) $totalSaleKrw,
            'total_paid_krw' => (int) $totalPaidKrw,
            'total_unpaid_krw' => $totalUnpaidKrw,
            'risk_count' => $riskCount,
        ];
    }

    #[Computed]
    public function buyers()
    {
        return Buyer::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function salesmen()
    {
        return Salesman::where('is_active', true)->orderBy('name')->get();
    }

    /**
     * 공통 쿼리 빌더 — KPI / 목록 / 필터링에 모두 사용.
     */
    private function buildQuery()
    {
        return Vehicle::query()
            ->where('sales_channel', $this->channel)
            // 채권관리는 판매단계 이후 차량만 (sale_price > 0)
            ->where('sale_price', '>', 0)
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
            ))
            ->when($this->dateFrom, fn ($q) => $q->where('purchase_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('purchase_date', '<=', $this->dateTo))
            ->when($this->salesmanFilter, fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->buyerFilter, fn ($q) => $q->where(function ($q2) {
                $q2->where('buyer_id', $this->buyerFilter)
                   ->orWhere('export_buyer_id', $this->buyerFilter);
            }))
            ->when($this->progressFilter, fn ($q) => $q->where('progress_status_cache', $this->progressFilter))
            ->when($this->riskFilter, fn ($q) => $q->where('receivable_risk', $this->riskFilter))
            // 미납률 30/50/70%↑ 필터는 receivable_risk 캐시 컬럼 매핑으로 대신.
            // 정확한 ratio 슬라이더 필요 시 raw SQL로 확장 가능 (현재는 카테고리 매핑으로 충분).
            ->when($this->unpaidRatioMin === '30', fn ($q) => $q->whereIn('receivable_risk', ['caution', 'danger', 'critical']))
            ->when($this->unpaidRatioMin === '50', fn ($q) => $q->whereIn('receivable_risk', ['danger', 'critical']))
            ->when($this->unpaidRatioMin === '70', fn ($q) => $q->where('receivable_risk', 'critical'));
    }
}; ?>

<div>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    {{-- 헤더 --}}
    <div class="flex items-end justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">채권관리</h2>
            <p class="text-xs text-gray-500 mt-1">미수금 현황 · 회수 이력 · 위험도 모니터링</p>
        </div>
        <div class="text-xs text-gray-400">권한: 관리자 전용</div>
    </div>

    {{-- 채널 탭 --}}
    <div class="flex items-center gap-2">
        @foreach (['export' => '수출', 'carpul' => '카풀', 'heyman' => '헤이맨'] as $code => $label)
        <button type="button" wire:click="setChannel('{{ $code }}')"
                class="tab-pill {{ $channel === $code ? 'is-active' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- KPI 4개 --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="card">
            <div class="text-xs text-gray-500">총 매출 (KRW 환산)</div>
            <div class="mt-1 text-2xl font-bold text-gray-800">{{ number_format($this->summary['total_sale_krw']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">총 입금</div>
            <div class="mt-1 text-2xl font-bold text-blue-600">{{ number_format($this->summary['total_paid_krw']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">총 미수금</div>
            <div class="mt-1 text-2xl font-bold text-red-600">{{ number_format($this->summary['total_unpaid_krw']) }}<span class="ml-1 text-sm font-normal text-gray-500">원</span></div>
        </div>
        <div class="card">
            <div class="text-xs text-gray-500">위험 건수 (위험 + 심각)</div>
            <div class="mt-1 text-2xl font-bold text-orange-600">{{ $this->summary['risk_count'] }}<span class="ml-1 text-sm font-normal text-gray-500">대</span></div>
        </div>
    </div>

    {{-- 필터 바 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <input type="text" wire:model.live.debounce.500ms="search" placeholder="차량번호·브랜드 검색"
               class="input-base min-w-[180px] flex-1" />
        <input type="date" wire:model.live="dateFrom" class="input-base" />
        <span class="text-xs text-gray-400">~</span>
        <input type="date" wire:model.live="dateTo" class="input-base" />
        <select wire:model.live="salesmanFilter" class="input-base">
            <option value="">담당자 전체</option>
            @foreach ($this->salesmen as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </select>
        <select wire:model.live="buyerFilter" class="input-base">
            <option value="">바이어 전체</option>
            @foreach ($this->buyers as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
        </select>
        <select wire:model.live="progressFilter" class="input-base">
            <option value="">진행상태 전체</option>
            @foreach (['판매중','판매완료','수출통관중','수출통관완료','선적중','선적완료','거래완료'] as $s)
            <option value="{{ $s }}">{{ $s }}</option>
            @endforeach
        </select>
        <select wire:model.live="riskFilter" class="input-base">
            <option value="">위험도 전체</option>
            <option value="safe">안전</option>
            <option value="caution">주의</option>
            <option value="danger">위험</option>
            <option value="critical">심각</option>
        </select>
        <select wire:model.live="unpaidRatioMin" class="input-base">
            <option value="">미납률 ALL</option>
            <option value="30">30%↑</option>
            <option value="50">50%↑</option>
            <option value="70">70%↑</option>
        </select>
    </div>

    {{-- 테이블 --}}
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 text-xs uppercase text-gray-500">
                <tr>
                    <th class="py-2 pr-3 text-left">번호</th>
                    <th class="py-2 pr-3 text-left">차량번호</th>
                    <th class="py-2 pr-3 text-left">담당자</th>
                    <th class="py-2 pr-3 text-left">바이어</th>
                    <th class="py-2 pr-3 text-right">판매합계</th>
                    <th class="py-2 pr-3 text-right">미납금</th>
                    <th class="py-2 pr-3 text-right">미납률</th>
                    <th class="py-2 pr-3 text-left">진행상태</th>
                    <th class="py-2 pr-3 text-left">BL</th>
                    <th class="py-2 pr-3 text-left">위험도</th>
                    <th class="py-2 pr-3 text-left">채권담당</th>
                    @if (in_array($channel, ['carpul','heyman']))
                    <th class="py-2 pr-3 text-right">계산서1</th>
                    <th class="py-2 pr-3 text-right">계산서2</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->vehicles as $v)
                @php
                    $rowBg = match($v->receivable_risk) {
                        'critical' => 'bg-red-50',
                        'danger'   => 'bg-orange-50',
                        'caution'  => 'bg-yellow-50',
                        'safe'     => 'bg-blue-50',
                        default    => '',
                    };
                    $riskBadge = match($v->receivable_risk) {
                        'safe'     => 'badge-blue',
                        'caution'  => 'badge-amber',
                        'danger'   => 'badge-amber',
                        'critical' => 'badge-red',
                        default    => 'badge-gray',
                    };
                    $unpaidRatio = $v->sale_total_amount > 0
                        ? round(($v->sale_unpaid_amount / $v->sale_total_amount) * 100, 1)
                        : 0;
                    $primaryBuyer = $channel === 'export' ? ($v->exportBuyer ?? $v->buyer) : $v->buyer;
                @endphp
                <tr class="border-b border-gray-100 {{ $rowBg }}">
                    <td class="py-2 pr-3 text-gray-500">{{ $v->id }}</td>
                    <td class="py-2 pr-3 font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                    <td class="py-2 pr-3 text-gray-600">{{ $v->salesman?->name ?? '-' }}</td>
                    <td class="py-2 pr-3 text-gray-600">{{ $primaryBuyer?->name ?? '-' }}</td>
                    <td class="py-2 pr-3 text-right text-gray-700">{{ $v->currency }} {{ number_format($v->sale_total_amount, $v->currency === 'KRW' ? 0 : 2) }}</td>
                    <td class="py-2 pr-3 text-right font-medium text-red-600">{{ $v->currency }} {{ number_format($v->sale_unpaid_amount, $v->currency === 'KRW' ? 0 : 2) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-700">{{ $unpaidRatio }}%</td>
                    <td class="py-2 pr-3"><span class="badge badge-gray">{{ $v->progress_status_cache ?? '-' }}</span></td>
                    <td class="py-2 pr-3 text-center text-xs">{{ $v->bl_document ? '✓' : '-' }}</td>
                    <td class="py-2 pr-3"><span class="badge {{ $riskBadge }}">{{ $v->receivable_risk_label }}</span></td>
                    <td class="py-2 pr-3 text-gray-600">{{ $v->receivableManager?->name ?? '-' }}</td>
                    @if (in_array($channel, ['carpul','heyman']))
                    <td class="py-2 pr-3 text-right text-gray-600">{{ $v->tax_invoice_1_amount ? number_format($v->tax_invoice_1_amount) : '-' }}</td>
                    <td class="py-2 pr-3 text-right text-gray-600">{{ $v->tax_invoice_2_amount ? number_format($v->tax_invoice_2_amount) : '-' }}</td>
                    @endif
                </tr>
                @empty
                <tr>
                    <td colspan="{{ in_array($channel, ['carpul','heyman']) ? 13 : 11 }}"
                        class="py-8 text-center text-gray-400">조회된 차량이 없습니다.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 페이지네이션 --}}
    <div class="mt-2">{{ $this->vehicles->links() }}</div>
</div>
</div>
