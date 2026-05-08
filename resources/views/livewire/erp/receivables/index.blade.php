<?php

use App\Models\Buyer;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
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

    // ── 슬라이드 패널 (회수 이력) ──────────────────────────
    public bool $showPanel = false;
    public ?int $selectedVehicleId = null;

    // 채권담당자 지정
    public string $managerIdInput = '';

    // 회수 이력 입력 폼
    public ?int $historyEditId = null;
    public string $hCollectedAt = '';
    public string $hCollectorId = '';
    public string $hMethod = 'deposit';
    public string $hAmount = '';
    public string $hNote = '';

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

    #[Computed]
    public function staff()
    {
        // 회수 담당자 / 채권담당자 셀렉트용 — 모든 활성 사용자
        return User::orderBy('name')->get(['id', 'name', 'permission']);
    }

    #[Computed]
    public function selectedVehicle(): ?Vehicle
    {
        if (! $this->selectedVehicleId) {
            return null;
        }

        return Vehicle::with(['receivableHistories.collector', 'receivableManager', 'salesman', 'buyer', 'exportBuyer'])
            ->find($this->selectedVehicleId);
    }

    public function openPanel(int $vehicleId): void
    {
        $vehicle = Vehicle::find($vehicleId);
        if (! $vehicle) {
            return;
        }

        $this->selectedVehicleId = $vehicleId;
        $this->managerIdInput = $vehicle->receivable_manager_id ? (string) $vehicle->receivable_manager_id : '';

        $this->resetHistoryForm();
        $this->showPanel = true;
    }

    public function closePanel(): void
    {
        $this->showPanel = false;
        $this->selectedVehicleId = null;
        $this->resetHistoryForm();
        unset($this->selectedVehicle);
    }

    public function assignManager(): void
    {
        $vehicle = $this->selectedVehicle;
        if (! $vehicle) {
            return;
        }

        $vehicle->update([
            'receivable_manager_id' => $this->managerIdInput !== '' ? (int) $this->managerIdInput : null,
        ]);

        unset($this->selectedVehicle, $this->vehicles, $this->summary);
        session()->flash('panel_success', '채권담당자가 지정됐습니다.');
    }

    public function editHistory(int $historyId): void
    {
        $h = ReceivableHistory::find($historyId);
        if (! $h || $h->vehicle_id !== $this->selectedVehicleId) {
            return;
        }

        $this->historyEditId = $h->id;
        $this->hCollectedAt = $h->collected_at?->format('Y-m-d') ?? '';
        $this->hCollectorId = (string) $h->collector_id;
        $this->hMethod = $h->method;
        $this->hAmount = (string) $h->amount;
        $this->hNote = $h->note ?? '';
    }

    public function saveHistory(): void
    {
        $this->validate([
            'hCollectedAt' => ['required', 'date'],
            'hCollectorId' => ['required', 'exists:users,id'],
            'hMethod' => ['required', 'in:deposit,cash,offset,other'],
            'hAmount' => ['required', 'numeric', 'min:0'],
            'hNote' => ['nullable', 'string', 'max:500'],
        ], [], [
            'hCollectedAt' => '회수일자',
            'hCollectorId' => '회수 담당자',
            'hMethod' => '회수 방법',
            'hAmount' => '회수 금액',
            'hNote' => '메모',
        ]);

        $vehicle = $this->selectedVehicle;
        if (! $vehicle) {
            return;
        }

        $payload = [
            'vehicle_id' => $vehicle->id,
            'collected_at' => $this->hCollectedAt,
            'collector_id' => (int) $this->hCollectorId,
            'method' => $this->hMethod,
            'amount' => (float) $this->hAmount,
            'note' => $this->hNote ?: null,
        ];

        if ($this->historyEditId) {
            ReceivableHistory::find($this->historyEditId)?->update($payload);
            session()->flash('panel_success', '회수 이력이 수정됐습니다.');
        } else {
            ReceivableHistory::create($payload);
            session()->flash('panel_success', '회수 이력이 추가됐습니다.');
        }

        $this->resetHistoryForm();
        unset($this->selectedVehicle, $this->vehicles, $this->summary);
    }

    public function deleteHistory(int $historyId): void
    {
        $h = ReceivableHistory::find($historyId);
        if (! $h || $h->vehicle_id !== $this->selectedVehicleId) {
            return;
        }

        $h->delete();   // saved/deleted 이벤트가 final_payment 미러링 + 캐시 갱신 처리
        unset($this->selectedVehicle, $this->vehicles, $this->summary);
        session()->flash('panel_success', '회수 이력이 삭제됐습니다.');
    }

    private function resetHistoryForm(): void
    {
        $this->historyEditId = null;
        $this->hCollectedAt = now()->format('Y-m-d');
        $this->hCollectorId = (string) (auth()->id() ?? '');
        $this->hMethod = 'deposit';
        $this->hAmount = '';
        $this->hNote = '';
        $this->resetValidation();
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
                <tr class="cursor-pointer border-b border-gray-100 {{ $rowBg }} hover:bg-violet-50"
                    wire:click="openPanel({{ $v->id }})">
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

{{-- ── 슬라이드 패널: 회수 이력 ────────────────────────── --}}
@if ($showPanel && $this->selectedVehicle)
@php $sv = $this->selectedVehicle; @endphp
<div class="fixed inset-0 z-50 flex justify-end" wire:keydown.escape="closePanel">
    {{-- backdrop --}}
    <div class="absolute inset-0 bg-black/40" wire:click="closePanel"></div>

    {{-- panel --}}
    <div class="relative ml-auto flex h-full w-full max-w-[640px] flex-col overflow-y-auto bg-white shadow-xl">
        {{-- 헤더 --}}
        <div class="sticky top-0 z-10 border-b border-gray-200 bg-white px-5 py-4">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-400">회수 이력</div>
                    <div class="mt-0.5 text-lg font-bold text-gray-800">{{ $sv->vehicle_number }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">
                        {{ $sv->brand }} {{ $sv->model_type }} · 담당자 {{ $sv->salesman?->name ?? '-' }}
                    </div>
                </div>
                <button type="button" wire:click="closePanel" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- 미납 요약 --}}
            @php
                $rb = match($sv->receivable_risk) {
                    'safe'     => 'badge-blue',
                    'caution'  => 'badge-amber',
                    'danger'   => 'badge-amber',
                    'critical' => 'badge-red',
                    default    => 'badge-gray',
                };
            @endphp
            <div class="mt-3 grid grid-cols-3 gap-2">
                <div class="card-sm">
                    <div class="text-xs text-gray-500">판매합계</div>
                    <div class="mt-0.5 text-sm font-semibold text-gray-800">{{ $sv->currency }} {{ number_format($sv->sale_total_amount, $sv->currency === 'KRW' ? 0 : 2) }}</div>
                </div>
                <div class="card-sm">
                    <div class="text-xs text-gray-500">미납금</div>
                    <div class="mt-0.5 text-sm font-semibold text-red-600">{{ $sv->currency }} {{ number_format($sv->sale_unpaid_amount, $sv->currency === 'KRW' ? 0 : 2) }}</div>
                </div>
                <div class="card-sm">
                    <div class="text-xs text-gray-500">위험도</div>
                    <div class="mt-0.5"><span class="badge {{ $rb }}">{{ $sv->receivable_risk_label }}</span></div>
                </div>
            </div>
        </div>

        @if (session('panel_success'))
        <div class="mx-5 mt-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-700">{{ session('panel_success') }}</div>
        @endif

        {{-- 채권담당자 지정 --}}
        <div class="px-5 py-4">
            <div class="section-header">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">채권담당자</span>
            </div>
            <div class="mt-2 flex items-center gap-2">
                <select wire:model="managerIdInput" class="input-base flex-1">
                    <option value="">미지정</option>
                    @foreach ($this->staff as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->permission }})</option>@endforeach
                </select>
                <button type="button" wire:click="assignManager" class="btn-primary px-3 py-1.5 text-sm">지정</button>
            </div>
        </div>

        <hr class="section-divider mx-5">

        {{-- 회수 이력 추가/수정 폼 --}}
        <div class="px-5 py-4">
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ $historyEditId ? '회수 이력 수정' : '회수 이력 추가' }}</span>
            </div>

            <div class="mt-2 grid grid-cols-2 gap-2">
                <div>
                    <label class="label-base">회수일자 *</label>
                    <input type="date" wire:model="hCollectedAt" class="input-base" />
                    @error('hCollectedAt')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="label-base">회수 담당자 *</label>
                    <select wire:model="hCollectorId" class="input-base">
                        <option value="">선택</option>
                        @foreach ($this->staff as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                    @error('hCollectorId')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="label-base">회수 방법 *</label>
                    <select wire:model="hMethod" class="input-base">
                        <option value="deposit">입금 (final_payment 자동 생성)</option>
                        <option value="cash">현금</option>
                        <option value="offset">상계</option>
                        <option value="other">기타</option>
                    </select>
                    @error('hMethod')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="label-base">금액 ({{ $sv->currency }}) *</label>
                    <input type="number" step="0.01" wire:model="hAmount" class="input-base" placeholder="0" />
                    @error('hAmount')<div class="mt-1 text-xs text-red-500">{{ $message }}</div>@enderror
                </div>
                <div class="col-span-2">
                    <label class="label-base">메모</label>
                    <textarea wire:model="hNote" rows="2" class="input-base" placeholder="회수 경위·연락 이력 등"></textarea>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-end gap-2">
                @if ($historyEditId)
                <button type="button" wire:click="resetHistoryForm" class="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">취소</button>
                @endif
                <button type="button" wire:click="saveHistory" class="btn-primary px-4 py-1.5 text-sm">{{ $historyEditId ? '수정 저장' : '추가' }}</button>
            </div>
        </div>

        <hr class="section-divider mx-5">

        {{-- 회수 이력 목록 --}}
        <div class="px-5 py-4 pb-8">
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">회수 이력 목록 (최신순)</span>
            </div>

            @php $histories = $sv->receivableHistories->sortByDesc('collected_at'); @endphp

            @if ($histories->isEmpty())
            <div class="mt-3 rounded border border-dashed border-gray-200 px-3 py-6 text-center text-xs text-gray-400">
                회수 이력이 없습니다.
            </div>
            @else
            <div class="mt-2 space-y-2">
                @foreach ($histories as $h)
                @php
                    $methodLabel = match($h->method) {
                        'deposit' => '입금',
                        'cash'    => '현금',
                        'offset'  => '상계',
                        'other'   => '기타',
                    };
                    $methodBadge = $h->method === 'deposit' ? 'badge-blue' : 'badge-gray';
                @endphp
                <div class="rounded border border-gray-200 px-3 py-2">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="font-medium text-gray-800">{{ $h->collected_at->format('Y-m-d') }}</span>
                                <span class="badge {{ $methodBadge }}">{{ $methodLabel }}</span>
                                <span class="text-xs text-gray-500">{{ $h->collector?->name ?? '-' }}</span>
                                @if ($h->final_payment_id)
                                <span class="text-xs text-blue-500" title="final_payments와 미러링됨">↔ #{{ $h->final_payment_id }}</span>
                                @endif
                            </div>
                            <div class="mt-1 text-base font-semibold text-gray-800">{{ $sv->currency }} {{ number_format($h->amount, $sv->currency === 'KRW' ? 0 : 2) }}</div>
                            @if ($h->note)
                            <div class="mt-0.5 text-xs text-gray-500">{{ $h->note }}</div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="editHistory({{ $h->id }})" class="text-xs text-violet-600 hover:underline">수정</button>
                            <button type="button" wire:click="deleteHistory({{ $h->id }})" wire:confirm="이 회수 이력을 삭제하시겠습니까? 미러링된 입금 기록도 함께 삭제됩니다." class="text-xs text-red-500 hover:underline">삭제</button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
@endif
</div>
