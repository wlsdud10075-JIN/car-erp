<?php

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Support\ListFilters;
use App\Support\ListSearch;
use App\Support\StackedHeaderSort;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
    /** 🪟 다른 탭이 차량을 저장했다(app.js BroadcastChannel) — 다시 그리기만 하면 목록이 최신이다 (jin 2026-10-08). */
    #[On('vehicles-changed')]
    public function vehiclesChangedElsewhere(): void
    {
    }

    use WithPagination;

    use StackedHeaderSort;
    use ListSearch;    // 🔎 검색공용
    use ListFilters;   // 🏷️ 필터칩공용

    /**
     * 🔼🔽 헤더 정렬 (jin 2026-10-26 «각 컬럼 헤더에 오름차순/내림차순 · 차량관리에 적용된 내용대로») — 규칙은 `StackedHeaderSort`.
     *   헤더 키 → 정렬 칼럼. 관계 칸(담당자·바이어·컨사이니)은 이름 서브쿼리(컨사이니는 화면과 같은 폴백 순서).
     *   입고일(계산값)·채널(단일값)은 뺐다. 빈 값은 방향과 무관하게 뒤로 — 날짜·번호 칸은 빈 행이 많아 앞에 몰리면 정렬한 뜻이 없다.
     */
    private const SORTABLE = [
        'vehicle_number' => 'vehicle_number', 'salesman' => 'salesman', 'status' => 'progress_status_cache',
        'brand_model' => 'brand', 'vin' => 'nice_reg_vin', 'purchase_date' => 'purchase_date',
        'deregistration_date' => 'deregistration_date', 'sale_date' => 'sale_date', 'shipping_date' => 'shipping_date',
        'eta_date' => 'eta_date', 'warehouse_out_date' => 'warehouse_out_date', 'stock_location' => 'stock_location',
        'vessel_name' => 'vessel_name', 'container_number' => 'container_number',
        'export_declaration_number' => 'export_declaration_number', 'bl_number' => 'bl_number',
        'purchase_from' => 'purchase_from', 'purchase_price' => 'purchase_price', 'sale_price' => 'sale_price',
        'currency_rate' => 'currency', 'buyer' => 'buyer', 'consignee' => 'consignee',
    ];

    /** 문자열 칸 — 빈 문자열도 「없음」으로 뒤로 보낸다. */
    private const SORTABLE_TEXT = ['vehicle_number', 'progress_status_cache', 'brand', 'nice_reg_vin', 'stock_location',
        'vessel_name', 'container_number', 'export_declaration_number', 'bl_number', 'purchase_from', 'currency'];

    protected function sortableKeys(): array
    {
        return array_keys(self::SORTABLE);
    }

    protected function afterSortChanged(): void
    {
        unset($this->inventoryVehicles);
        $this->resetPage();
    }

    protected function applySortKey($q, string $col, string $dir)
    {
        $col = self::SORTABLE[$col];
        if (in_array($col, ['salesman', 'buyer', 'consignee'], true)) {
            $fk = match ($col) {
                'salesman' => 'vehicles.salesman_id',
                'buyer' => 'vehicles.buyer_id',
                'consignee' => 'COALESCE('.implode(', ', array_map(fn ($c) => 'vehicles.'.$c, Vehicle::CONSIGNEE_FALLBACK_COLUMNS)).')',
            };
            $table = $col === 'salesman' ? 'salesmen' : ($col === 'buyer' ? 'buyers' : 'consignees');
            $name = "(SELECT name FROM {$table} WHERE {$table}.id = {$fk} LIMIT 1)";

            return $q->orderByRaw("{$name} IS NULL ASC")->orderByRaw("{$name} {$dir}");
        }
        $q = $q->orderByRaw($this->emptyLastExpression($col, in_array($col, self::SORTABLE_TEXT, true)))->orderBy($col, $dir);

        return $col === 'brand' ? $q->orderBy('model_type', $dir) : $q;
    }


    #[Url] public string $salesmanFilter = '';

    #[Url] public string $statusFilter = '';

    /** 바이어/컨사이니 필터 (jin 2026-07-24) — 출고일 지정 시점엔 당사자가 정해져 있어 검색 편의. combobox(검색 드롭다운). */
    #[Url] public string $buyerFilter = '';

    #[Url] public string $consigneeFilter = '';

    /** 재고 카테고리 필터 (jin 2026-07-18): '' 전체 / general 일반재고(미판매) / pre_ship 선적전 재고(판매됨·출고전). */
    #[Url] public string $category = '';

    /**
     * 🔎 검색 대상(검색공용). 바이어명은 **화면에 표시되는 그 바이어**(`buyer_id`)만 — 통관·선적 바이어까지 넣으면
     *    A 로 표시된 행이 B 로 검색된다(board 인계 2026-08-12). 일반재고는 바이어가 없어 0건이 정상.
     */
    protected function searchColumns(): array
    {
        return ['vehicle_number', 'brand', 'model_type', 'nice_reg_vin', 'export_declaration_number', 'vessel_name', 'container_number', 'buyer.name'];
    }

    /** 🏷️ 필터칩(필터칩공용) — 행 뱃지 「2억 초과」와 같은 기준·같은 모양(jin 2026-11-22). 일반재고에만 해당하므로 전체·일반재고 탭에서만. */
    protected function filterChips(): array
    {
        return [
            'over_cap' => ['type' => 'bool', 'label' => __('inventory.badge_over_cap'), 'class' => 'badge badge-red text-[10px]',
                'show' => in_array($this->category, ['', 'general'], true),
                'apply' => fn ($q) => $q->generalStock()->where('purchase_price', '>', Vehicle::GENERAL_STOCK_PRICE_CAP)],
        ];
    }

    protected function afterSearch(): void
    {
        unset($this->inventoryVehicles);
        $this->resetPage();
    }

    protected function afterChipChanged(): void
    {
        unset($this->inventoryVehicles);
        $this->resetPage();
    }

    #[Url] public int $perPage = 20;

    /**
     * 보관 위치 필터 (jin 2026-07-28) — 담당자 + 위치로 좁혀 본다.
     * 다중 선택 — 홈플+화물 처럼 여러 곳을 동시에 볼 수 있다. 빈 배열 = 전체.
     * '__none' 은 위치 미지정 차량(다른 위치와 함께 고를 수 있다).
     */
    #[Url(as: 'loc')] public array $locationFilters = [];

    /** 「3개월 경과」 필터칩 (jin 2026-11-20) — 일반재고 중 입고 3개월이 지난 차만. 행 뱃지와 같은 기준(Vehicle::scopeGeneralStockOverAge). */
    #[Url(as: 'aged')] public bool $overAgeOnly = false;

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
        //   inStock() 은 stillInYard() 라 이 카테고리(leftStock = 출고일 있음 OR 선적일 지남, 2026-10-29 (가))와 배타적 → 스코프를 분기한다.
        //   재고 이력 조회용이라 매입완납 조건은 걸지 않는다(출고된 차는 이미 재고를 떠난 것).
        $isShippedOut = $this->category === 'shipped_out';
        $isAwaitingPayment = $this->category === 'awaiting_payment';

        $result = Vehicle::query()
            // 컨사이니 3칸(통관·선적·판매) = effective_consignee 폴백용 eager load.
            ->with(['salesman', 'buyer', 'consignee', 'blConsignee', 'exportConsignee', 'purchaseBalancePayments'])   // purchaseBalancePayments: warehouse_in_date·purchase_unpaid_amount accessor N+1 방지
            // 지급대기(jin 2026-08-09) = 매입 대금이 남은 차 = 입고 전. inStock() 과 배타적이라 스코프를 분기한다.
            ->when($isAwaitingPayment, fn ($q) => $q->awaitingPurchasePayment())
            ->when(! $isAwaitingPayment, fn ($q) => $q->when($isShippedOut,
                fn ($q2) => $q2->leftStock(),
                fn ($q2) => $q2->inStock()
            ))
            // 일반재고 = 바이어 미정 + 판매가 없음 / 선적전 재고 = 바이어 지정 또는 판매가 (jin 2026-11-20 — scopeGeneralStock 과 같은 기준)
            ->when($this->category === 'general', fn ($q) => $q->whereNull('vehicles.buyer_id')->where(fn ($q2) => $q2->whereNull('vehicles.sale_price')->orWhere('vehicles.sale_price', '<=', 0)))
            ->when($this->category === 'pre_ship', fn ($q) => $q->where(fn ($q2) => $q2->whereNotNull('vehicles.buyer_id')->orWhere('vehicles.sale_price', '>', 0)))
            ->when($restrictToOwnSalesman, fn ($q) => $q->where('salesman_id', $user->salesman->id))
            ->when($restrictToManagerScope, fn ($q) => $q->whereIn('salesman_id', $managerScopeSalesmanIds))
            ->when($this->salesmanFilter !== '', fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when($this->buyerFilter !== '', fn ($q) => $q->where('buyer_id', $this->buyerFilter))
            // 컨사이니 필터 — 3칸 어디에 들어 있든 잡는다. consignee_id 만 보면 07-09 이후 차량이 전부 0건.
            ->when($this->consigneeFilter !== '', fn ($q) => $q->whereEffectiveConsignee($this->consigneeFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('progress_status_cache', $this->statusFilter))
            ->when($this->overAgeOnly && in_array($this->category, ['', 'general'], true), fn ($q) => $q->generalStockOverAge())
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
            ->tap(fn ($q) => $this->applySearch($q))
            ->tap(fn ($q) => $this->applyChipFilters($q))
            // 출고완료는 "언제 나갔나"가 관심사 → 최근 출고순. 재고는 기존 담당자·매입일 순 유지.
            // 🔼🔽 헤더 정렬(겹침)이 있으면 그걸로, 기본이면 종전 순서.
            ->when(! $this->isDefaultSort(), fn ($q) => $this->applyStackedSort($q), fn ($q) => $q->when($isShippedOut,
                fn ($q2) => $q2->orderByDesc('warehouse_out_date')->orderByDesc('id'),
                // 동점 tie-break (jin 2026-09-11) — 매입일이 같은 차가 많아 페이지가 흔들린다(SKILLS §8 #92).
                //   출고완료 분기는 이미 id 를 쓰고 있다.
                fn ($q2) => $q2->orderByRaw('salesman_id IS NULL ASC')->orderBy('salesman_id')->orderBy('purchase_date')->orderBy('id')
            ))
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
        $q = Salesman::where('is_active', true)->sales()->orderBy('name');   // sales() = 검차직원 제외(월정산 v3)
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
     * 담당자별 재고 — 대수·매입가 합 (jin 2026-11-20 «재고탭에서 총금액 / 인원별 재고 금액»).
     * all = 재고 전체(일반 + 선적전) / general = 일반재고만(인원당 상한 GENERAL_STOCK_PRICE_CAP 2억 비교 대상)
     * / awaiting = 지급대기(매입 대금이 남은 차 — 입고 전이라 재고 총금액에는 안 들어간다).
     * 금액 = 매입가(purchase_price) 합. 고른 탭·필터와 무관하게 「지금 재고」를 센다 — 카드가 여기서 고른 담당자 몫만 꺼내 쓴다.
     *
     * @return array{all: array<string, array{cnt:int, amount:int}>, general: array<string, array{cnt:int, amount:int}>, awaiting: array<string, array{cnt:int, amount:int}>} 키 = salesman_id('' = 미배정)
     */
    #[Computed]
    public function stockBySalesman(): array
    {
        $user = auth()->user();
        $group = function ($q) use ($user) {
            if ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
                $q->whereIn('salesman_id', $user->getSubordinateSalesmanIds());
            }

            return $q->selectRaw('salesman_id, COUNT(*) as cnt, COALESCE(SUM(purchase_price), 0) as amount')
                ->groupBy('salesman_id')
                ->get()
                ->mapWithKeys(fn ($r) => [(string) $r->salesman_id => ['cnt' => (int) $r->cnt, 'amount' => (int) round((float) $r->amount)]])
                ->all();
        };

        return [
            'all' => $group(Vehicle::query()->inStock()),
            'general' => $group(Vehicle::query()->generalStock()),
            'awaiting' => $group(Vehicle::query()->awaitingPurchasePayment()),
        ];
    }

    /**
     * 금액 카드 4장(재고 총금액 · 지급대기 · 일반재고 · 선적전 재고) — 담당자 칩을 고르면 그 사람 몫, 아니면 볼 수 있는 전원 합.
     * 선적전 = 전체 − 일반(두 스코프가 여집합이라 닫힌다).
     *
     * @return array{total: array{cnt:int, amount:int}, awaiting: array{cnt:int, amount:int}, general: array{cnt:int, amount:int}, pre_ship: array{cnt:int, amount:int}, over_cap_people:int}
     */
    #[Computed]
    public function stockSummary(): array
    {
        $by = $this->stockBySalesman;
        $pick = function (array $rows): array {
            if ($this->salesmanFilter !== '') {
                return $rows[(string) $this->salesmanFilter] ?? ['cnt' => 0, 'amount' => 0];
            }

            return ['cnt' => array_sum(array_column($rows, 'cnt')), 'amount' => array_sum(array_column($rows, 'amount'))];
        };
        $total = $pick($by['all']);
        $general = $pick($by['general']);

        return [
            'total' => $total,
            'awaiting' => $pick($by['awaiting']),
            'general' => $general,
            'pre_ship' => ['cnt' => $total['cnt'] - $general['cnt'], 'amount' => $total['amount'] - $general['amount']],
            // 미배정('')은 사람이 아니라 상한 대상이 아니다
            'over_cap_people' => count(array_filter($by['general'], fn ($r, $k) => $k !== '' && $r['amount'] > Vehicle::GENERAL_STOCK_PRICE_CAP, ARRAY_FILTER_USE_BOTH)),
        ];
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
            'shipped_out' => $scoped(Vehicle::query()->leftStock())->count(),   // 출고일 있음 OR 선적일 지남 (2026-10-29 (가))
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

        unset($this->inventoryVehicles, $this->stockBySalesman, $this->stockSummary);
        $this->dispatch('notify',
            message: $applied > 0 ? __('inventory.out_applied', ['count' => $applied]) : __('inventory.out_nochange'),
            type: $applied > 0 ? 'success' : 'info');
    }

    public function resetFilters(): void
    {
        $this->reset(['salesmanFilter', 'statusFilter', 'search', 'buyerFilter', 'consigneeFilter', 'locationFilters', 'overAgeOnly', 'chipFilters']);
        $this->resetPage();
    }

    /** 위치 필터 pill 클릭 — 누를 때마다 추가/해제(다중). 전부 해제하면 전체. */
    public function toggleOverAge(): void
    {
        $this->overAgeOnly = ! $this->overAgeOnly;
        unset($this->inventoryVehicles);
        $this->resetPage();
    }

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

    {{-- 재고 금액 카드 + 담당자 칩 (jin 2026-11-20). 칩은 이름만 — 누르면 아래 목록과 카드 금액이 그 담당자 것으로 바뀐다.
         일반재고는 인원당 2억(GENERAL_STOCK_PRICE_CAP)까지 — 넘은 담당자는 칩과 카드가 빨갛게. --}}
    @php
        $stockBy = $this->stockBySalesman;
        $sum = $this->stockSummary;
        $stockCap = \App\Models\Vehicle::GENERAL_STOCK_PRICE_CAP;
        $pickedSm = $salesmanFilter !== '' ? $this->salesmen->firstWhere('id', (int) $salesmanFilter) : null;
        $cardOver = $salesmanFilter !== '' ? $sum['general']['amount'] > $stockCap : $sum['over_cap_people'] > 0;
        $unassigned = $stockBy['all'][''] ?? null;
        // 억원 단위로 줄여 적는다(jin 2026-11-20) — 원 단위 전체는 title 로
        $eok = fn (int $won) => rtrim(rtrim(number_format($won / 100_000_000, 2), '0'), '.').__('inventory.unit_eok_won');
    @endphp
    @if($stockBy['all'] !== [] || $stockBy['awaiting'] !== [])
    <div class="space-y-2" data-stock-by-salesman>
        <div class="flex flex-wrap items-center gap-1.5 text-xs">
            <span class="whitespace-nowrap font-semibold text-gray-500">{{ __('inventory.by_salesman') }}</span>
            <button type="button" wire:click="$set('salesmanFilter', '')"
                    class="shrink-0 whitespace-nowrap rounded-md border px-2.5 py-1 font-medium transition
                           {{ $salesmanFilter === '' ? 'border-violet-600 bg-violet-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}">{{ __('inventory.chip_all') }}</button>
            @foreach($this->salesmen as $sm)
                @php
                    $picked = $salesmanFilter == $sm->id;
                    $over = (($stockBy['general'][(string) $sm->id]['amount'] ?? 0) > $stockCap);
                @endphp
                @continue(! isset($stockBy['all'][(string) $sm->id]) && ! isset($stockBy['awaiting'][(string) $sm->id]) && ! $picked)
                <button type="button" wire:click="$set('salesmanFilter', '{{ $picked ? '' : $sm->id }}')"
                        @if($over) data-stock-over-cap title="{{ __('inventory.chip_over_cap_title') }}" @endif
                        class="shrink-0 whitespace-nowrap rounded-md border px-2.5 py-1 font-medium transition
                               {{ $picked ? 'border-violet-600 bg-violet-600 text-white' : ($over ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50') }}">{{ $sm->name }}</button>
            @endforeach
            @if($unassigned && $unassigned['cnt'] > 0)
                <span class="shrink-0 whitespace-nowrap rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-800" title="{{ number_format($unassigned['amount']) }}">
                    {{ __('inventory.unassigned_strip', ['count' => $unassigned['cnt']]) }}
                </span>
            @endif
        </div>
        {{-- 카드 4칸 — PC 는 화면 폭을 4등분, 폰은 2칸씩 두 줄 (jin 2026-11-20) --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" data-stock-cards>
            <div class="card-sm sm:px-4">
                <div class="whitespace-nowrap text-xs text-gray-500">{{ __('inventory.card_total') }} <span class="text-gray-400">· {{ $pickedSm?->name ?? __('inventory.chip_all') }}</span></div>
                <div class="mt-0.5 whitespace-nowrap text-xl font-bold text-gray-800" data-stock-total title="{{ number_format($sum['total']['amount']) }}">{{ $eok($sum['total']['amount']) }}</div>
                <div class="whitespace-nowrap text-[11px] text-gray-400">{{ __('inventory.total', ['count' => number_format($sum['total']['cnt'])]) }}</div>
            </div>
            <div class="card-sm sm:px-4">
                <div class="whitespace-nowrap text-xs text-gray-500">{{ __('inventory.card_awaiting') }}</div>
                <div class="mt-0.5 whitespace-nowrap text-xl font-bold text-gray-800" data-stock-awaiting title="{{ number_format($sum['awaiting']['amount']) }}">{{ $eok($sum['awaiting']['amount']) }}</div>
                <div class="whitespace-nowrap text-[11px] text-gray-400">{{ __('inventory.total', ['count' => number_format($sum['awaiting']['cnt'])]) }}</div>
            </div>
            <div class="card-sm sm:px-4 {{ $cardOver ? 'border-red-300 bg-red-50' : '' }}">
                <div class="whitespace-nowrap text-xs text-gray-500">{{ __('inventory.cat_general') }}</div>
                <div class="mt-0.5 whitespace-nowrap text-xl font-bold {{ $cardOver && $salesmanFilter !== '' ? 'text-red-600' : 'text-gray-800' }}" data-stock-general title="{{ number_format($sum['general']['amount']) }}">{{ $eok($sum['general']['amount']) }}</div>
                <div class="text-[11px] {{ $cardOver ? 'font-semibold text-red-600' : 'text-gray-400' }}">
                    {{ __('inventory.total', ['count' => number_format($sum['general']['cnt'])]) }} ·
                    @if($salesmanFilter !== '')
                        @if($cardOver)
                            {{ __('inventory.cap_over_by', ['amount' => $eok($sum['general']['amount'] - $stockCap)]) }}
                        @else
                            {{ __('inventory.cap_room', ['amount' => $eok($stockCap - $sum['general']['amount'])]) }}
                        @endif
                    @elseif($sum['over_cap_people'] > 0)
                        {{ __('inventory.cap_over_people', ['n' => $sum['over_cap_people']]) }}
                    @else
                        {{ __('inventory.stock_cap_hint') }}
                    @endif
                </div>
            </div>
            <div class="card-sm sm:px-4">
                <div class="whitespace-nowrap text-xs text-gray-500">{{ __('inventory.cat_pre_ship') }}</div>
                <div class="mt-0.5 whitespace-nowrap text-xl font-bold text-gray-800" data-stock-preship title="{{ number_format($sum['pre_ship']['amount']) }}">{{ $eok($sum['pre_ship']['amount']) }}</div>
                <div class="whitespace-nowrap text-[11px] text-gray-400">{{ __('inventory.total', ['count' => number_format($sum['pre_ship']['cnt'])]) }}</div>
            </div>
        </div>
    </div>
    @endif

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <x-erp.search-bar :placeholder="__('inventory.search_ph')" width="w-64">
        {{-- 🔽 드롭박스콤보공용 (jin 2026-11-22 «지금 할 때 하는 게 낫지 않나») — 담당자도 치면서 찾는다. 비우면(×) 전체. --}}
        <x-erp.combobox wire:key="inv-sm-filter-{{ $salesmanFilter }}" model="salesmanFilter" :options="$this->salesmen"
            :selected="$salesmanFilter" placeholder="{{ __('inventory.all_salesmen') }}" class="w-40" deferred />
        {{-- 바이어/컨사이니 검색 드롭다운 (jin 2026-07-24) — 출고일 지정 시점엔 당사자 확정. combobox 타이핑 검색. --}}
        <x-erp.combobox wire:key="inv-buyer-{{ $buyerFilter }}" model="buyerFilter" :options="$this->buyers"
            :selected="$buyerFilter" placeholder="{{ __('inventory.buyer_ph') }}" class="w-44" deferred />
        <x-erp.combobox wire:key="inv-consignee-{{ $buyerFilter }}-{{ $consigneeFilter }}" model="consigneeFilter"
            :options="$this->consignees" :selected="$consigneeFilter" placeholder="{{ __('inventory.consignee_ph') }}" class="w-44" deferred />
        <select wire:model="statusFilter" class="input-filter">
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
        {{-- 「3개월 경과」 필터칩 (jin 2026-11-20) — 행 뱃지와 같은 기준·**같은 모양**(jin «뱃지로 표시되는 건 그 뱃지 그대로 필터가»): 꺼짐 = 흐리게, 켜짐 = 테두리. 일반재고에만 해당하므로 전체·일반재고 탭에서만 보인다. --}}
        @if(in_array($category, ['', 'general'], true))
            <button type="button" wire:click="toggleOverAge" data-filter-over-age
                    class="badge badge-amber cursor-pointer whitespace-nowrap text-[10px] {{ $overAgeOnly ? 'ring-2' : 'opacity-60 hover:opacity-100' }}">
                {{ __('inventory.badge_over_age') }}
            </button>
        @endif
        <x-erp.filter-chips :axes="$this->chipAxes()" />
        </x-erp.search-bar>
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
                {{-- 🔼🔽 정렬 헤더 = 헤더정렬공용(`sortTh` → <x-erp.sort-th>). 규칙 = StackedHeaderSort. --}}
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ $this->sortTh('vehicle_number', __('vehicle.col.number'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ $this->sortTh('salesman', __('vehicle.col.salesman'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ $this->sortTh('status', __('vehicle.col.status'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['brand_model']">{{ $this->sortTh('brand_model', __('vehicle.col.brand_model'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['vin']">{{ $this->sortTh('vin', __('vehicle.col.vin'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['warehouse_in']">{{ __('inventory.col_warehouse_in') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['purchase_date']">{{ $this->sortTh('purchase_date', __('vehicle.col.purchase_date'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['deregistration_date']">{{ $this->sortTh('deregistration_date', __('vehicle.col.deregistration_date'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['sale_date']">{{ $this->sortTh('sale_date', __('vehicle.col.sale_date'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['shipping_date']">{{ $this->sortTh('shipping_date', __('inventory.col_shipping'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['eta_date']">{{ $this->sortTh('eta_date', __('vehicle.col.eta_date'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ $this->sortTh('warehouse_out_date', __('inventory.col_warehouse_out'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ $this->sortTh('stock_location', __('inventory.col_location'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['vessel_name']">{{ $this->sortTh('vessel_name', __('inventory.col_vessel'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['container_number']">{{ $this->sortTh('container_number', __('vehicle.col.container_number'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['export_declaration_number']">{{ $this->sortTh('export_declaration_number', __('vehicle.col.export_declaration_number'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['bl_number']">{{ $this->sortTh('bl_number', __('vehicle.col.bl_number'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['purchase_from']">{{ $this->sortTh('purchase_from', __('vehicle.col.purchase_from'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right" x-show="visible['purchase_price']">{{ $this->sortTh('purchase_price', __('vehicle.col.purchase_price'), 'right') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right" x-show="visible['sale_price']">{{ $this->sortTh('sale_price', __('vehicle.col.sale_price'), 'right') }}</th>
                    <th class="pb-2 pr-4 font-medium text-right" x-show="visible['currency_rate']">{{ $this->sortTh('currency_rate', __('vehicle.col.currency_rate'), 'right') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['sales_channel']">{{ __('vehicle.col.channel') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['buyer']">{{ $this->sortTh('buyer', __('inventory.col_buyer'), 'left') }}</th>
                    <th class="pb-2 pr-4 font-medium" x-show="visible['consignee']">{{ $this->sortTh('consignee', __('inventory.col_consignee'), 'left') }}</th>
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
                    $isGeneral = $v->isGeneralStock();
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
