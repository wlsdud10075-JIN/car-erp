<?php

use App\Models\Buyer;
use App\Models\Consignee;
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

    /** 바이어/컨사이니 필터 (jin 2026-07-24) — 출고일 지정 시점엔 당사자가 정해져 있어 검색 편의. combobox(검색 드롭다운). */
    #[Url] public string $buyerFilter = '';

    #[Url] public string $consigneeFilter = '';

    /** 재고 카테고리 필터 (jin 2026-07-18): '' 전체 / general 일반재고(미판매) / pre_ship 선적전 재고(판매됨·출고전). */
    #[Url] public string $category = '';

    public string $search = '';

    #[Url] public int $perPage = 20;

    /**
     * 보관 위치 필터 (jin 2026-07-28) — 담당자 + 위치로 좁혀 본다.
     * 다중 선택 — 홈플+화물 처럼 여러 곳을 동시에 볼 수 있다. 빈 배열 = 전체.
     * '__none' 은 위치 미지정 차량(다른 위치와 함께 고를 수 있다).
     */
    #[Url(as: 'loc')] public array $locationFilters = [];

    /** 출고일 draft (vehicle_id => 'Y-m-d' | ''). 즉시저장 아님 — 여러 차량 지정 후 「적용」으로 일괄 저장. */
    public array $warehouseOut = [];

    /** 보관 위치 draft (vehicle_id => '홈플'|'화물'|'야드'|''). 출고일과 같은 「적용」으로 함께 저장. */
    public array $stockLocation = [];

    /** 위치 비고 draft (vehicle_id => 자유 텍스트). */
    public array $stockLocationNote = [];

    #[Computed]
    public function inventoryVehicles()
    {
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업' && $user->salesman;
        $restrictToManagerScope = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        // 출고완료(jin 2026-07-28) — 출고일이 찍히면 재고에서 빠져 어디서도 못 보던 문제.
        //   inStock() 은 whereNull(warehouse_out_date) 라 이 카테고리와 배타적 → 스코프를 분기한다.
        //   재고 이력 조회용이라 매입완납 조건은 걸지 않는다(출고된 차는 이미 재고를 떠난 것).
        $isShippedOut = $this->category === 'shipped_out';
        $isAwaitingPayment = $this->category === 'awaiting_payment';

        $result = Vehicle::query()
            // 컨사이니 3칸(통관·선적·판매) = effective_consignee 폴백용 eager load.
            ->with(['salesman', 'buyer', 'consignee', 'blConsignee', 'exportConsignee', 'purchaseBalancePayments'])   // purchaseBalancePayments: warehouse_in_date·purchase_unpaid_amount accessor N+1 방지
            // 지급대기(jin 2026-08-09) = 매입 대금이 남은 차 = 입고 전. inStock() 과 배타적이라 스코프를 분기한다.
            ->when($isAwaitingPayment, fn ($q) => $q->awaitingPurchasePayment())
            ->when(! $isAwaitingPayment, fn ($q) => $q->when($isShippedOut,
                fn ($q2) => $q2->whereNotNull('warehouse_out_date'),
                fn ($q2) => $q2->inStock()
            ))
            ->when($this->category === 'general', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('sale_price')->orWhere('sale_price', '<=', 0)))
            ->when($this->category === 'pre_ship', fn ($q) => $q->where('sale_price', '>', 0))
            ->when($restrictToOwnSalesman, fn ($q) => $q->where('salesman_id', $user->salesman->id))
            ->when($restrictToManagerScope, fn ($q) => $q->whereIn('salesman_id', $managerScopeSalesmanIds))
            ->when($this->salesmanFilter !== '', fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->buyerFilter !== '', fn ($q) => $q->where('buyer_id', $this->buyerFilter))
            // 컨사이니 필터 — 3칸 어디에 들어 있든 잡는다. consignee_id 만 보면 07-09 이후 차량이 전부 0건.
            ->when($this->consigneeFilter !== '', fn ($q) => $q->whereEffectiveConsignee($this->consigneeFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('progress_status_cache', $this->statusFilter))
            ->when($this->locationFilters !== [], function ($q) {
                $picked = array_values(array_diff($this->locationFilters, ['__none']));
                $wantNone = in_array('__none', $this->locationFilters, true);
                $q->where(function ($q2) use ($picked, $wantNone) {
                    if ($picked !== []) {
                        $q2->whereIn('stock_location', $picked);
                    }
                    if ($wantNone) {
                        $q2->orWhereNull('stock_location')->orWhere('stock_location', '');
                    }
                });
            })
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
                ->orWhere('model_type', 'like', "%{$this->search}%")
                ->orWhere('nice_reg_vin', 'like', "%{$this->search}%")           // 차대번호 — 끝 6자리 등 부분 검색
                ->orWhere('export_declaration_number', 'like', "%{$this->search}%") // 수출신고번호
                ->orWhere('vessel_name', 'like', "%{$this->search}%")            // 선박명(VSL)
                ->orWhere('container_number', 'like', "%{$this->search}%")        // 컨테이너번호
                // 바이어명 (board 인계 2026-08-12) — "이 바이어한테 나갈 차 뭐뭐 있지" 를 재고에서 바로.
                //   ⚠️ **화면에 표시되는 그 바이어**(`buyer_id`)만 훑는다. 통관·선적 바이어까지 넣으면
                //      A 로 표시된 행이 B 로 검색되는 어긋남이 생긴다. 일반재고는 바이어가 없어 0건이 정상.
                ->orWhereHas('buyer', fn ($q3) => $q3->where('name', 'like', "%{$this->search}%"))
            ))
            // 출고완료는 "언제 나갔나"가 관심사 → 최근 출고순. 재고는 기존 담당자·매입일 순 유지.
            ->when($isShippedOut,
                fn ($q) => $q->orderByDesc('warehouse_out_date')->orderByDesc('id'),
                fn ($q) => $q->orderByRaw('salesman_id IS NULL ASC')->orderBy('salesman_id')->orderBy('purchase_date')
            )
            ->paginate($this->perPage);

        // 출고일 draft 초기화 — 현재 페이지 차량 중 draft 없는 것만 DB값으로 채움(사용자 편집 보존).
        foreach ($result as $v) {
            if (! array_key_exists($v->id, $this->warehouseOut)) {
                $this->warehouseOut[$v->id] = $v->warehouse_out_date?->format('Y-m-d') ?? '';
            }
            if (! array_key_exists($v->id, $this->stockLocation)) {
                $this->stockLocation[$v->id] = (string) ($v->stock_location ?? '');
            }
            if (! array_key_exists($v->id, $this->stockLocationNote)) {
                $this->stockLocationNote[$v->id] = (string) ($v->stock_location_note ?? '');
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

    /** 바이어 옵션 (스코프별 — buyersForFilter 규칙: admin=전체 / 영업=본인 / 관리=부하). combobox 검색용. */
    #[Computed]
    public function buyers()
    {
        $q = Buyer::orderBy('name');
        $user = auth()->user();
        if (! $user || $user->isAdmin() || $user->isManager()) {
            return $q->get();
        }
        if ($user->role === '관리') {
            $subIds = $user->getSubordinateSalesmanIds();
            $q->where(fn ($q2) => $q2->whereIn('salesman_id', $subIds)
                ->orWhereHas('vehicles', fn ($q3) => $q3->whereIn('salesman_id', $subIds)));
        } elseif ($user->role === '영업' && $user->salesman) {
            $ownId = $user->salesman->id;
            $q->where(fn ($q2) => $q2->where('salesman_id', $ownId)
                ->orWhereHas('vehicles', fn ($q3) => $q3->where('salesman_id', $ownId)));
        }

        return $q->get();
    }

    /** 컨사이니 옵션 — 선택 바이어 종속(Consignee.buyer_id). 바이어 미선택 시 빈 목록. */
    #[Computed]
    public function consignees()
    {
        if ($this->buyerFilter === '') {
            return collect();
        }

        return Consignee::where('buyer_id', $this->buyerFilter)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** 바이어 변경 → 컨사이니 리셋 + 재조회. combobox $wire.set 이 발화. */
    public function updatedBuyerFilter(): void
    {
        $this->consigneeFilter = '';
        unset($this->inventoryVehicles, $this->consignees);
        $this->resetPage();
    }

    public function updatedConsigneeFilter(): void
    {
        unset($this->inventoryVehicles);
        $this->resetPage();
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

    /** 재고 2분류 카운트 (스코프 반영, 담당자 스코프 존중). */
    #[Computed]
    public function categoryCounts(): array
    {
        $user = auth()->user();
        $scoped = function ($q) use ($user) {
            if ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업' && $user->salesman) {
                $q->where('salesman_id', $user->salesman->id);
            } elseif ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
                $q->whereIn('salesman_id', $user->getSubordinateSalesmanIds());
            }

            return $q;
        };

        return [
            'general' => $scoped(Vehicle::query()->generalStock())->count(),
            'pre_ship' => $scoped(Vehicle::query()->preShippingStock())->count(),
            // 출고완료는 재고가 아니라 "나간 이력" — 전체 합계(cat_all)에는 더하지 않는다.
            'shipped_out' => $scoped(Vehicle::query()->whereNotNull('warehouse_out_date'))->count(),
            // 지급대기 = 입고 전이라 재고 합계(cat_all)에도 안 더한다.
            'awaiting_payment' => $scoped(Vehicle::query()->awaitingPurchasePayment())->count(),
        ];
    }

    public function setCategory(string $cat): void
    {
        $this->category = $cat;
        unset($this->inventoryVehicles);
        $this->resetPage();
    }

    public function searchNow(): void
    {
        $this->resetPage();
    }

    /**
     * 보관 위치 선택 (jin 2026-07-28) — 버튼 클릭으로 draft 에 찍는다. 같은 값을 다시 누르면 해제.
     * 저장은 출고일과 함께 「적용」에서 (즉시저장 아님 — 오클릭 방지 원칙 유지).
     */
    public function setLocation(int $vehicleId, string $location): void
    {
        if ($location !== '' && ! in_array($location, Vehicle::stockLocations(), true)) {
            return;   // 화이트리스트 밖 값은 무시
        }
        // draft 는 목록 렌더(inventoryVehicles) 때 DB 값으로 채워진다. 아직 안 채워진 차량(페이지 이동 직후 등)은
        // DB 값을 기준으로 판단한다 — 렌더 순서에 기대면 "이미 그 위치인 차를 다시 눌러도 해제가 안 되는" 버그가 난다.
        $current = array_key_exists($vehicleId, $this->stockLocation)
            ? (string) $this->stockLocation[$vehicleId]
            : (string) (Vehicle::whereKey($vehicleId)->value('stock_location') ?? '');
        $this->stockLocation[$vehicleId] = $current === $location ? '' : $location;
    }

    /**
     * 출고일·보관위치 일괄 적용 (jin 2026-07-09 / 위치 2026-07-28) — 여러 차량을 지정한 뒤 「적용」으로 한 번에 저장.
     * 즉시저장(오클릭 위험) 대신 draft 편집 → 적용. DB와 다른 것만 저장.
     * 스코프 인증(영업=본인/관리=팀/그외=전체). 출고일 있으면 재고 제외, 비우면 복귀.
     */
    public function applyWarehouseOut(): void
    {
        $user = auth()->user();
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge(
            array_keys($this->warehouseOut), array_keys($this->stockLocation), array_keys($this->stockLocationNote)
        )))));
        if (empty($ids)) {
            return;
        }

        $applied = 0;
        foreach (Vehicle::whereIn('id', $ids)->get() as $v) {
            if (! $user->canScopeVehicle($v)) {
                continue;   // 스코프 밖 (IDOR 방지)
            }
            $dirty = false;

            if (array_key_exists($v->id, $this->warehouseOut)) {
                $draft = trim((string) $this->warehouseOut[$v->id]);
                $new = $draft !== '' ? $draft : null;
                if ($new !== $v->warehouse_out_date?->format('Y-m-d')) {
                    $v->warehouse_out_date = $new;
                    $dirty = true;
                }
            }

            if (array_key_exists($v->id, $this->stockLocation)) {
                $loc = trim((string) $this->stockLocation[$v->id]);
                $loc = in_array($loc, Vehicle::stockLocations(), true) ? $loc : null;
                if ($loc !== $v->stock_location) {
                    $v->stock_location = $loc;
                    $dirty = true;
                }
            }

            if (array_key_exists($v->id, $this->stockLocationNote)) {
                $note = trim((string) $this->stockLocationNote[$v->id]);
                $note = $note !== '' ? mb_substr($note, 0, 255) : null;
                if ($note !== $v->stock_location_note) {
                    $v->stock_location_note = $note;
                    $dirty = true;
                }
            }

            if (! $dirty) {
                continue;   // 변경 없음
            }
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
        $this->reset(['salesmanFilter', 'statusFilter', 'search', 'buyerFilter', 'consigneeFilter', 'locationFilters']);
        $this->resetPage();
    }

    /** 위치 필터 pill 클릭 — 누를 때마다 추가/해제(다중). 전부 해제하면 전체. */
    public function toggleLocationFilter(string $location): void
    {
        if (in_array($location, $this->locationFilters, true)) {
            $this->locationFilters = array_values(array_filter(
                $this->locationFilters, fn ($l) => $l !== $location
            ));
        } else {
            $this->locationFilters[] = $location;
        }
        unset($this->inventoryVehicles);
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
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-400">{{ __('inventory.total', ['count' => number_format($this->inventoryVehicles->total())]) }}</span>
            <a href="{{ route('erp.vehicles.index') }}?create=1" wire:navigate class="btn-primary text-xs">
                {{ __('inventory.new_purchase') }}
            </a>
        </div>
    </div>

    {{-- 재고 2분류 탭 (jin 2026-07-18) — 일반재고(미판매) / 선적전 재고(판매됨·출고전) --}}
    @php $cc = $this->categoryCounts; @endphp
    <div class="flex flex-wrap items-center gap-2">
        <button wire:click="setCategory('')"
                class="tab-pill {{ $category === '' ? 'is-active' : '' }}">
            {{ __('inventory.cat_all') }}
            <span class="pill-count">{{ number_format($cc['general'] + $cc['pre_ship']) }}</span>
        </button>
        {{-- 지급대기 (jin 2026-08-09) — 매입 대금이 남은 차. 지급을 마치면 자동으로 재고 탭으로 넘어간다. --}}
        <button wire:click="setCategory('awaiting_payment')"
                class="tab-pill {{ $category === 'awaiting_payment' ? 'is-active' : '' }}">
            {{ __('inventory.cat_awaiting_payment') }}
            <span class="pill-count">{{ number_format($cc['awaiting_payment']) }}</span>
        </button>
        <button wire:click="setCategory('general')"
                class="tab-pill {{ $category === 'general' ? 'is-active' : '' }}">
            {{ __('inventory.cat_general') }}
            <span class="pill-count">{{ number_format($cc['general']) }}</span>
        </button>
        <button wire:click="setCategory('pre_ship')"
                class="tab-pill {{ $category === 'pre_ship' ? 'is-active' : '' }}">
            {{ __('inventory.cat_pre_ship') }}
            <span class="pill-count">{{ number_format($cc['pre_ship']) }}</span>
        </button>
        {{-- 출고완료 (jin 2026-07-28) — 출고일이 찍히면 재고에서 빠져 어디서도 못 보던 차량 조회용. --}}
        <button wire:click="setCategory('shipped_out')"
                class="tab-pill {{ $category === 'shipped_out' ? 'is-active' : '' }}">
            {{ __('inventory.cat_shipped_out') }}
            <span class="pill-count">{{ number_format($cc['shipped_out']) }}</span>
        </button>
        @if($category === 'general')
            <span class="ml-2 text-xs text-gray-400">{{ __('inventory.cat_general_hint') }}</span>
        @elseif($category === 'shipped_out')
            <span class="ml-2 text-xs text-gray-400">{{ __('inventory.cat_shipped_out_hint') }}</span>
        @elseif($category === 'awaiting_payment')
            <span class="ml-2 text-xs text-gray-400">{{ __('inventory.cat_awaiting_payment_hint') }}</span>
        @endif
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
        <input wire:model="search" wire:keydown.enter="searchNow" type="text"
               placeholder="{{ __('inventory.search_ph') }}"
               class="input-filter w-64" />
        <select wire:model.live="salesmanFilter" class="input-filter">
            <option value="">{{ __('inventory.all_salesmen') }}</option>
            @foreach($this->salesmen as $sm)
                <option value="{{ $sm->id }}">{{ $sm->name }}</option>
            @endforeach
        </select>
        {{-- 바이어/컨사이니 검색 드롭다운 (jin 2026-07-24) — 출고일 지정 시점엔 당사자 확정. combobox 타이핑 검색. --}}
        <x-erp.combobox wire:key="inv-buyer-{{ $buyerFilter }}" model="buyerFilter" :options="$this->buyers"
            :selected="$buyerFilter" placeholder="{{ __('inventory.buyer_ph') }}" class="w-44" />
        <x-erp.combobox wire:key="inv-consignee-{{ $buyerFilter }}-{{ $consigneeFilter }}" model="consigneeFilter"
            :options="$this->consignees" :selected="$consigneeFilter" placeholder="{{ __('inventory.consignee_ph') }}" class="w-44" />
        <select wire:model.live="statusFilter" class="input-filter">
            <option value="">{{ __('inventory.all_status') }}</option>
            @foreach(['매입중','매입완료','말소완료','판매중','판매완료','선적중','선적완료','통관중','통관완료','거래완료'] as $st)
            <option value="{{ $st }}">{{ __('domain.progress.'.$st) }}</option>
            @endforeach
        </select>
        {{-- 보관 위치 필터 (jin 2026-07-28) — 담당자 선택 후 위치를 눌러 좁힌다. 다시 누르면 해제. --}}
        <div class="flex items-center gap-1">
            @foreach(\App\Models\Vehicle::stockLocations() as $loc)
                <button type="button" wire:click="toggleLocationFilter('{{ $loc }}')"
                        class="rounded-full border px-2.5 py-1 text-xs {{ in_array($loc, $locationFilters, true) ? 'border-primary bg-primary-light font-semibold text-primary-text' : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50' }}">
                    {{ $loc }}
                </button>
            @endforeach
            <button type="button" wire:click="toggleLocationFilter('__none')"
                    class="rounded-full border px-2.5 py-1 text-xs {{ in_array('__none', $locationFilters, true) ? 'border-primary bg-primary-light font-semibold text-primary-text' : 'border-gray-300 bg-white text-gray-400 hover:bg-gray-50' }}">
                {{ __('inventory.location_none') }}
            </button>
        </div>
        <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
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

    {{-- 데스크탑 테이블 — 표시컬럼 토글(차량관리와 같은 Alpine + localStorage 방식, jin 2026-07-28) --}}
    <div class="hidden sm:block" x-data="inventoryColumnsToggle()" x-init="init()">
        <div class="mb-2 flex justify-end relative">
            <button type="button" @click="open = !open"
                    class="rounded border border-gray-300 bg-white px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">
                {{ __('vehicle.columns') }} <span x-text="open ? '▲' : '▼'"></span>
            </button>
            <div x-show="open" x-cloak @click.outside="open = false"
                 class="absolute right-0 top-8 z-20 max-h-80 w-56 overflow-y-auto rounded-lg border border-gray-200 bg-white py-2 shadow-lg">
                <div class="px-3 pb-1 text-[10px] font-semibold uppercase text-gray-400">{{ __('vehicle.show_columns') }}</div>
                <template x-for="col in togglableColumns" :key="col.key">
                    <label class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" :checked="visible[col.key]" @change="toggle(col.key)" class="rounded" />
                        <span x-text="col.label"></span>
                    </label>
                </template>
                <div class="border-t border-gray-100 mt-1 px-3 py-1">
                    <button @click="resetDefaults()" class="text-[11px] text-violet-600 hover:underline">{{ __('vehicle.reset_defaults') }}</button>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.number') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.salesman') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('vehicle.col.status') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['brand_model']">{{ __('vehicle.col.brand_model') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['vin']">{{ __('vehicle.col.vin') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['warehouse_in']">{{ __('inventory.col_warehouse_in') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['purchase_date']">{{ __('vehicle.col.purchase_date') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['deregistration_date']">{{ __('vehicle.col.deregistration_date') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['sale_date']">{{ __('vehicle.col.sale_date') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['shipping_date']">{{ __('inventory.col_shipping') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['eta_date']">{{ __('vehicle.col.eta_date') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('inventory.col_warehouse_out') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('inventory.col_location') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['vessel_name']">{{ __('inventory.col_vessel') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['container_number']">{{ __('vehicle.col.container_number') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['export_declaration_number']">{{ __('vehicle.col.export_declaration_number') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['bl_number']">{{ __('vehicle.col.bl_number') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['purchase_from']">{{ __('vehicle.col.purchase_from') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right" x-show="visible['purchase_price']">{{ __('vehicle.col.purchase_price') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right" x-show="visible['sale_price']">{{ __('vehicle.col.sale_price') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right" x-show="visible['currency_rate']">{{ __('vehicle.col.currency_rate') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['sales_channel']">{{ __('vehicle.col.channel') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['buyer']">{{ __('inventory.col_buyer') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['consignee']">{{ __('inventory.col_consignee') }}</th>
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
                    $isGeneral = ($v->sale_price ?? 0) <= 0;
                    $overCap = $isGeneral && $v->purchase_price > \App\Models\Vehicle::GENERAL_STOCK_PRICE_CAP;
                    $overAge = $isGeneral && $v->warehouse_in_date
                        && $v->warehouse_in_date->copy()->addMonths(\App\Models\Vehicle::GENERAL_STOCK_SELL_MONTHS)->isPast();
                @endphp
                <tr wire:key="inv-row-{{ $v->id }}" class="hover:bg-gray-50 cursor-pointer"
                    wire:click="$dispatch('navigate-to-vehicle', { id: {{ $v->id }} })">
                    <td class="py-3 pr-4 font-mono font-medium text-gray-800">
                        {{ $v->vehicle_number }}
                        @if($overCap)<span class="badge badge-red ml-1 text-[10px]">{{ __('inventory.badge_over_cap') }}</span>@endif
                        @if($overAge)<span class="badge badge-amber ml-1 text-[10px]">{{ __('inventory.badge_over_age') }}</span>@endif
                    </td>
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
                    <td class="py-3 pr-4 text-gray-700" x-show="visible['brand_model']">
                        {{ $v->brand }} {{ $v->model_type }}
                        @if($v->year)<span class="text-xs text-gray-400">({{ $v->year }})</span>@endif
                    </td>
                    <td class="py-3 pr-4 font-mono text-xs text-gray-600" x-show="visible['vin']">{{ $v->nice_reg_vin ?: '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['warehouse_in']">{{ $v->warehouse_in_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['purchase_date']">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['deregistration_date']">{{ $v->deregistration_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['sale_date']">{{ $v->sale_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['shipping_date']">{{ $v->shipping_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['eta_date']">{{ $v->eta_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 pr-4" @click.stop>
                        <input type="text" data-date wire:key="inv-out-{{ $v->id }}" wire:model="warehouseOut.{{ $v->id }}"
                               placeholder="YYYY-MM-DD"
                               class="w-28 rounded border border-gray-300 px-1.5 py-0.5 text-xs text-gray-700 focus:border-primary" />
                    </td>
                    {{-- 보관 위치 (jin 2026-07-28) — 버튼으로 찍고 옆칸에 비고. 저장은 출고일과 같은 「적용」. --}}
                    <td class="py-3 pr-4" @click.stop>
                        <div class="flex items-center gap-1">
                            @foreach(\App\Models\Vehicle::stockLocations() as $loc)
                                @php $on = ($this->stockLocation[$v->id] ?? '') === $loc; @endphp
                                <button type="button" wire:key="inv-loc-{{ $v->id }}-{{ $loc }}"
                                        wire:click="setLocation({{ $v->id }}, '{{ $loc }}')"
                                        class="rounded border px-1.5 py-0.5 text-xs {{ $on ? 'border-primary bg-primary-light font-semibold text-primary-text' : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50' }}">
                                    {{ $loc }}
                                </button>
                            @endforeach
                            <input type="text" wire:key="inv-locnote-{{ $v->id }}" wire:model="stockLocationNote.{{ $v->id }}"
                                   placeholder="{{ __('inventory.location_note_ph') }}" maxlength="255"
                                   class="w-24 rounded border border-gray-300 px-1.5 py-0.5 text-xs text-gray-700 focus:border-primary" />
                        </div>
                    </td>
                    <td class="py-3 pr-4 text-xs text-gray-600" x-show="visible['vessel_name']">{{ $v->vessel_name ?: '-' }}</td>
                    <td class="py-3 pr-4 font-mono text-xs text-gray-600" x-show="visible['container_number']">{{ $v->container_number ?: '-' }}</td>
                    <td class="py-3 pr-4 font-mono text-xs text-gray-600" x-show="visible['export_declaration_number']">{{ $v->export_declaration_number ?: '-' }}</td>
                    <td class="py-3 pr-4 font-mono text-xs text-gray-600" x-show="visible['bl_number']">{{ $v->bl_number ?: '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['purchase_from']">{{ $v->purchase_from ?: '-' }}</td>
                    <td class="py-3 pr-4 text-right text-gray-700" x-show="visible['purchase_price']">
                        @if($v->purchase_price > 0)₩{{ number_format($v->purchase_price) }}@else -@endif
                    </td>
                    <td class="py-3 pr-4 text-right text-gray-700" x-show="visible['sale_price']">
                        @if($v->sale_price > 0){{ number_format($v->sale_price) }} {{ $v->currency }}@else -@endif
                    </td>
                    <td class="py-3 pr-4 text-right text-xs text-gray-500" x-show="visible['currency_rate']">
                        {{ $v->currency }} @if($v->exchange_rate > 0)/ {{ number_format($v->exchange_rate) }}@endif
                    </td>
                    <td class="py-3 pr-4" x-show="visible['sales_channel']">
                        <span class="badge {{ $v->sales_channel === 'export' ? 'badge-blue' : ($v->sales_channel === 'heyman' ? 'badge-teal' : 'badge-purple') }}">{{ $v->sales_channel }}</span>
                    </td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['buyer']">{{ $v->buyer?->name ?? '-' }}</td>
                    <td class="py-3 pr-4 text-gray-500" x-show="visible['consignee']">{{ $v->effective_consignee?->name ?? '-' }}</td>
                    <td class="py-3 text-right">
                        <a href="{{ route('erp.vehicles.index') }}?openVehicle={{ $v->id }}"
                           wire:navigate
                           class="text-xs text-violet-600 hover:underline">{{ __('inventory.edit_vehicle') }}</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="24" class="py-12 text-center text-sm text-gray-400">
                    {{ __('inventory.empty') }}
                </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- 표시컬럼 토글 (차량관리와 같은 방식) — 선택은 localStorage 에 남아 다음에도 유지된다. --}}
    <script>
    function inventoryColumnsToggle() {
        const STORAGE_KEY = 'car_erp_inventory_columns_v1';
        const defaultVisible = {
            brand_model: true, vin: true, warehouse_in: true, shipping_date: true,
            purchase_price: true, buyer: true,
            purchase_date: false, deregistration_date: false, sale_date: false, eta_date: false,
            vessel_name: false, container_number: false, export_declaration_number: false, bl_number: false,
            purchase_from: false, sale_price: false, currency_rate: false, sales_channel: false, consignee: false,
        };
        return {
            open: false,
            visible: {},
            togglableColumns: [
                { key: 'brand_model',   label: @json(__('vehicle.col.brand_model')) },
                { key: 'vin',           label: @json(__('vehicle.col.vin')) },
                { key: 'warehouse_in',  label: @json(__('inventory.col_warehouse_in')) },
                { key: 'purchase_date', label: @json(__('vehicle.col.purchase_date')) },
                { key: 'deregistration_date', label: @json(__('vehicle.col.deregistration_date')) },
                { key: 'sale_date',     label: @json(__('vehicle.col.sale_date')) },
                { key: 'shipping_date', label: @json(__('inventory.col_shipping')) },
                { key: 'eta_date',      label: @json(__('vehicle.col.eta_date')) },
                { key: 'vessel_name',   label: @json(__('inventory.col_vessel')) },
                { key: 'container_number', label: @json(__('vehicle.col.container_number')) },
                { key: 'export_declaration_number', label: @json(__('vehicle.col.export_declaration_number')) },
                { key: 'bl_number',     label: @json(__('vehicle.col.bl_number')) },
                { key: 'purchase_from', label: @json(__('vehicle.col.purchase_from')) },
                { key: 'purchase_price', label: @json(__('vehicle.col.purchase_price')) },
                { key: 'sale_price',    label: @json(__('vehicle.col.sale_price')) },
                { key: 'currency_rate', label: @json(__('vehicle.col.currency_rate')) },
                { key: 'sales_channel', label: @json(__('vehicle.col.channel')) },
                { key: 'buyer',         label: @json(__('inventory.col_buyer')) },
                { key: 'consignee',     label: @json(__('inventory.col_consignee')) },
            ],
            init() {
                const saved = localStorage.getItem(STORAGE_KEY);
                const parsed = saved ? JSON.parse(saved) : {};
                for (const key in defaultVisible) {
                    this.visible[key] = parsed[key] !== undefined ? parsed[key] : defaultVisible[key];
                }
            },
            toggle(key) {
                this.visible[key] = !this.visible[key];
                localStorage.setItem(STORAGE_KEY, JSON.stringify(this.visible));
            },
            resetDefaults() {
                this.visible = { ...defaultVisible };
                localStorage.setItem(STORAGE_KEY, JSON.stringify(this.visible));
            },
        };
    }
    </script>

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
                <div class="col-span-2 font-mono text-[11px] text-gray-400">{{ __('vehicle.col.vin') }}: {{ $v->nice_reg_vin ?: '-' }}</div>
            </div>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs text-gray-500" @click.stop.prevent>
                <span>{{ __('inventory.col_warehouse_out') }}</span>
                <input type="text" data-date wire:key="inv-out-m-{{ $v->id }}" wire:model="warehouseOut.{{ $v->id }}"
                       placeholder="YYYY-MM-DD"
                       class="w-28 rounded border border-gray-300 px-1.5 py-0.5 text-xs" />
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-1 text-xs" @click.stop.prevent>
                <span class="text-gray-500">{{ __('inventory.col_location') }}</span>
                @foreach(\App\Models\Vehicle::stockLocations() as $loc)
                    @php $onM = ($this->stockLocation[$v->id] ?? '') === $loc; @endphp
                    <button type="button" wire:key="inv-loc-m-{{ $v->id }}-{{ $loc }}"
                            wire:click="setLocation({{ $v->id }}, '{{ $loc }}')"
                            class="rounded border px-1.5 py-0.5 {{ $onM ? 'border-primary bg-primary-light font-semibold text-primary-text' : 'border-gray-300 bg-white text-gray-500' }}">
                        {{ $loc }}
                    </button>
                @endforeach
                <input type="text" wire:key="inv-locnote-m-{{ $v->id }}" wire:model="stockLocationNote.{{ $v->id }}"
                       placeholder="{{ __('inventory.location_note_ph') }}" maxlength="255"
                       class="w-24 rounded border border-gray-300 px-1.5 py-0.5 text-xs" />
            </div>
        </a>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('inventory.empty_mobile') }}</div>
        @endforelse
    </div>

    <div>{{ $this->inventoryVehicles->links() }}</div>
</div>
