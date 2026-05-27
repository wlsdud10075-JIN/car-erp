<?php

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\ForwardingCompany;
use App\Models\InterVehicleTransfer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use App\Services\NiceApiService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination, WithFileUploads;

    // ── 목록 필터 ─────────────────────────────────────────────────
    #[Url] public string $search = '';
    #[Url] public string $dateType = 'purchase';
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    // 큐 16 — channelFilter Url 파라미터 제거 (채널 단일).
    #[Url] public string $progressFilter = '';
    // 대시보드 처리 필요 액션 카드에서 진입 시 동일 산정 로직으로 필터링.
    // 값: purchase_unpaid / sale_unpaid / clearance_needed / shipping_needed / dhl_needed
    #[Url] public string $action = '';
    #[Url] public string $salesmanId = '';
    // 정산 등 외부 화면에서 ?openVehicle=ID 로 진입 → 해당 차량 편집 패널 자동 오픈.
    #[Url] public ?int $openVehicle = null;
    // 회의확장씬 #3 Phase 2-4 (2026-05-23) — 필터바 바이어 select.
    #[Url] public string $buyerId = '';
    #[Url] public int $perPage = 10;

    // #3 다중차량 선적 서류 — 체크박스로 선택한 차량 id (export 차량만). 선택 N대 → 1서류.
    public array $shipDocIds = [];

    public function clearShipDocSelection(): void
    {
        $this->shipDocIds = [];
    }

    // 회의확장씬 #10 Phase 2-3 (2026-05-23) — 헤더 클릭 정렬.
    // localStorage 가 캐시 (Alpine), 진실은 Livewire property (server-side orderBy).
    public string $sortColumn = 'created_at';

    public string $sortDirection = 'desc';

    // DB 컬럼만 정렬 허용 (accessor 정렬 X — 페이지네이션 + accessor 호환 X).
    private const SORTABLE_COLUMNS = [
        'vehicle_number', 'brand', 'progress_status_cache',
        'purchase_date', 'sale_date', 'shipping_date', 'bl_issue_date',
        'salesman_id', 'sale_price', 'purchase_price',
        'currency', 'exchange_rate', 'sales_channel', 'buyer_id', 'created_at',
    ];

    public function setSort(string $col): void
    {
        if (! in_array($col, self::SORTABLE_COLUMNS, true)) {
            return;
        }
        if ($this->sortColumn === $col) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $col;
            $this->sortDirection = 'asc';
        }
    }

    // ── 슬라이드 패널 상태 ────────────────────────────────────────
    public bool $showPanel = false;
    public ?int $editingId = null;
    // 큐 14-4-4 — 신규 등록 직후 같은 패널이 편집 모드로 재로드될 때(H14 next-step 동선),
    // 헤더 배지·차량번호 readonly 강조로 "이미 저장됨"을 시각적으로 알리기 위한 마커.
    // close()/openEdit() 진입 시 reset.
    public ?int $justCreatedId = null;

    // ── 큐 21 — Ledger 잠금 상태 (회의록 2026-05-18) ───────────────
    // confirmed FinalPayment OR PurchaseBalancePayment 1건 이상 → isLedgerLocked = true.
    // admin/super가 [잠금 해제] 모달에서 사유 입력 → unlock 토큰 발급 → hasLedgerUnlockToken = true.
    // 저장 1회 후 토큰 소비되어 다시 false로. openEdit / save 후 갱신.
    public bool $isLedgerLocked = false;
    public bool $hasLedgerUnlockToken = false;
    public bool $showLedgerUnlockModal = false;
    public string $ledgerUnlockReason = '';

    // ── 큐 21 후속 — 말소·수출통관 체크/문서 mismatch 확인 모달 (사용자 결정 2026-05-18) ──
    // 운영 흐름상 체크와 서류 업로드 순서가 비순차적. 강제 차단 대신 모달로 인지 강제.
    public bool $showDocCheckModal = false;
    public bool $userConfirmedDocCheckMismatch = false;
    public array $docCheckMismatches = [];

    // ── 기본정보 ──────────────────────────────────────────────────
    public string $vehicle_number = '';
    public string $sales_channel = 'export';
    public string $brand      = '';
    public string $model_type = '';
    public string $year_str   = '';
    public string $cc_str     = '';
    public string $weight_kg_str = '';
    public string $mileage_str   = '';
    public string $color = '';

    // ── NICE API 등록정보 12 ──────────────────────────────────────
    public string $nice_reg_vin          = '';
    public string $nice_reg_engine_no    = '';
    public string $nice_reg_fuel_type    = '';
    public string $nice_reg_use_type     = '';
    public string $nice_reg_vehicle_form = '';
    public string $nice_reg_first_date   = '';
    public string $nice_reg_date         = '';
    public string $nice_reg_owner_name   = '';
    public string $nice_reg_owner_addr   = '';
    public string $nice_reg_owner_rrn    = '';
    public string $nice_reg_max_load_str = '';
    public string $nice_reg_passengers_str = '';
    public string $nice_reg_color        = '';

    // ── NICE API 제원정보 12 ──────────────────────────────────────
    public string $nice_spec_maker           = '';
    public string $nice_spec_model           = '';
    public string $nice_spec_year            = '';
    public string $nice_spec_displacement_str = '';
    public string $nice_spec_transmission    = '';
    public string $nice_spec_drive_type      = '';
    public string $nice_spec_length_str      = '';
    public string $nice_spec_width_str       = '';
    public string $nice_spec_height_str      = '';
    public string $nice_spec_wheelbase_str   = '';
    public string $nice_spec_curb_weight_str = '';
    public string $nice_spec_fuel_efficiency = '';

    // NICE 조회 응답 원본(ssancar data) — 저장 시 nice_raw 컬럼에 보존 (미매핑 필드 재조회 없이 활용).
    public array $niceRaw = [];

    // ── 매입 ──────────────────────────────────────────────────────
    public string $purchase_date = '';
    public string $salesman_id_str = '';
    public string $purchase_from  = '';
    // 큐 20-A/C — 매입처 계좌 4컬럼 (account는 모델 cast로 자동 암호화)
    public string $purchase_seller_bank    = '';
    public string $purchase_seller_account = '';
    public string $purchase_seller_holder  = '';
    public string $purchase_bank_memo      = '';
    public string $purchase_price_str    = '';
    public string $selling_fee_str       = '';
    public string $cost_deregistration_str = '';
    public string $cost_license_str   = '';
    public string $cost_towing_str    = '';
    public string $cost_carry_str     = '';
    public string $cost_shoring_str   = '';
    public string $cost_insurance_str = '';
    public string $cost_transfer_str  = '';
    public string $cost_extra1_str    = '';
    public string $cost_extra2_str    = '';
    public string $down_payment_str        = '';
    public string $selling_fee_payment_str = '';
    public string $purchase_remittance_memo = '';
    public bool   $is_deregistered = false;
    public array  $purchaseBalancePayments = [];

    // ── 판매 ──────────────────────────────────────────────────────
    public string $sale_date    = '';
    public string $currency     = 'USD';
    public string $exchange_rate_str = '';
    public string $buyer_id_str     = '';
    public string $consignee_id_str = '';
    public string $sale_price_str       = '';
    public string $tax_dc_str           = '';
    public string $commission_str       = '';
    public string $transport_fee_str    = '';
    public string $auto_loading_str     = '';
    public string $sale_other_costs_str = '';
    // 22-A-3a 사용자 정정 (2026-05-20) — 4 항목 _str 복귀.
    // 4 input 은 입금 분류 메타데이터 (계약금/중도금/선수금) — 재무·관리자만 입력.
    // 실 저장은 final_payments.type 별 confirmed row. 화면 표시는 type별 합산.
    public string $deposit_down_payment_str = '';
    public string $interim_payment_str = '';
    public string $advance_payment1_str = '';
    public string $advance_payment2_str = '';
    public string $savings_used_str     = '';
    // 회의확장씬 #12 (2026-05-22) — 적립금 적립 입력 (한 번 저장 → SavingsStatus EARNED 거래 → reset).
    // 누적 표시는 buyerSavingsBalance computed (SavingsStatus 단일 출처).
    public string $savings_deposit_str  = '';
    public array  $finalPayments = [];

    // 판매탭 미납률 표시 (수정 불가, openEdit 시 갱신)
    //   null  = 판매 전 (sale_total_amount=0) → "—"
    //   0     = 완납
    //   > 0   = 미납 (0~1)
    public ?float $panelUnpaidRatio = null;

    // 큐 19-C — 차량 간 자금 이체 요청 모달 상태
    public bool $showTransferRequestModal = false;

    public string $transferTargetVehicleId = '';

    public string $transferAmountStr = '';

    public string $transferReason = '';

    public string $transferNotes = '';

    // 큐 19-E — 이체 취소(void) 요청 모달 상태
    public bool $showTransferVoidModal = false;

    public ?int $voidTransferId = null;

    public string $voidReason = '';

    // UX #3 (2026-05-20) — 영업 저장 확인 모달.
    // 사용자 정정: 영업 [저장] → 모달에 매입+판매 필수 항목 미리보기 → [확인] 시 실제 save.
    // 다른 role (admin/재무/관리/통관) 은 모달 없이 직접 save (빠른 처리).
    public bool $showSaveConfirmModal = false;
    // 회의확장씬 (2026-05-22) — 저장 확인 모달 탭별 미리보기 분기.
    //   'purchase' / 'sale' / 'bl' / 'clearance' — 그 외(basic/dhl/docs) 즉시 save (모달 X).
    public string $activeTabForSave = 'purchase';

    // 큐 16 — 카풀/헤이맨 계산서 5 properties 제거 (DB 5컬럼 drop과 동기).

    // ── 수출통관 ──────────────────────────────────────────────────
    public string $export_buyer_id_str     = '';
    public string $export_consignee_id_str = '';
    public string $forwarding_company_id_str = '';
    public string $export_declaration_amount_str  = '';
    public string $export_declaration_number      = '';
    public string $shipping_date     = '';
    public string $eta_date          = '';
    public string $shipping_method   = '';
    public string $port_of_loading   = '';
    // 2026-05-21 — CIPL 이식 — 인코텀즈 + 도착항 마스터 FK
    public string $incoterms              = '';
    public string $discharge_port_id_str  = '';
    public bool   $is_export_cleared = false;

    // ── 작업2 (2026-05-27) — 바이어·컨사이니 인라인 quick-add (패널 안 닫고 즉석 등록) ──
    public bool   $quickAddOpen     = false;
    public string $quickAddType     = '';   // 'buyer' | 'consignee'
    public string $quickAddContext  = '';   // 'sale' | 'export' | 'bl'
    public string $quickAddBuyerName = '';   // consignee 등록 시 종속 바이어명 표시용
    public string $qaName         = '';
    public string $qaCountryId    = '';
    public string $qaSalesmanId   = '';
    public string $qaContactName  = '';
    public string $qaContactPhone = '';

    // ── 선적 (B/L) ────────────────────────────────────────────────
    public string $bl_buyer_id_str     = '';
    public string $bl_consignee_id_str = '';
    public string $bl_number           = '';
    public string $container_number    = '';
    public string $bl_loading_location = '';
    public string $vessel_name         = '';
    public string $bl_issue_date       = '';

    // ── DHL ───────────────────────────────────────────────────────
    public string $dhl_recipient_name    = '';
    public string $dhl_recipient_address = '';
    public string $dhl_recipient_phone   = '';
    public string $dhl_sender_name       = '';
    public string $dhl_sender_address    = '';
    public string $dhl_weight_str        = '';
    public string $dhl_dimensions        = '';
    public bool   $dhl_request           = false;

    public string $memo = '';

    // ── 파일 업로드 ───────────────────────────────────────────────
    public $deregistrationDocFile    = null;
    public $exportDeclarationDocFile = null;
    public $blDocFile                = null;

    // 기존 파일 경로 (수정 시 표시용)
    public string $deregistration_document_path     = '';
    public string $export_declaration_document_path = '';
    public string $bl_document_path                 = '';

    // "기존 파일 삭제" 액션 플래그 (UI 버튼 → save 시 컬럼 null + 디스크 삭제)
    public bool $clearDeregistrationDoc    = false;
    public bool $clearExportDeclarationDoc = false;
    public bool $clearBlDoc                = false;

    // ── 차량 사진 (jpg/png, 최대 10장 — vehicle_photos 테이블) ──────
    public const MAX_PHOTOS = 10;

    public array $photoFiles = [];      // 신규 업로드 (multiple temp)
    public array $existingPhotos = [];  // 저장된 사진 [['id'=>, 'url'=>], ...] (편집 시 표시)
    public array $deletePhotoIds = [];  // 개별 삭제 staged → save 시 DB row + 디스크 제거

    public function mount(): void
    {
        // 정산 등에서 ?openVehicle=ID 진입 → 해당 차량 편집 패널 자동 오픈 + 매입 탭(기타비용 수정 동선).
        if ($this->openVehicle) {
            try {
                $this->openEdit($this->openVehicle);
                $this->dispatch('switch-tab', tab: 'purchase');
            } catch (\Throwable $e) {
                // 접근 불가(스코프/미존재) — 조용히 목록만 표시
            }
        }

        // 대시보드에서 진입한 경우 — action(처리 필요 카드) 또는 progressFilter(파이프라인 스트립):
        // 날짜 기본 필터를 적용하지 않는다. 카드 카운트와 목록 카운트의 정합성을 위해
        // 산정 로직(전체 기간)과 동일 범위에서 목록을 보여줘야 함.
        if ($this->action !== '' || $this->progressFilter !== '') {
            return;
        }

        $this->dateFrom = $this->dateFrom ?: now()->subMonths(2)->format('Y-m-d');
        $this->dateTo = $this->dateTo ?: now()->format('Y-m-d');
    }

    public function applyFilters(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    public function updatedChannelFilter(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    public function updatedProgressFilter(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    public function updatedSalesmanId(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        unset($this->vehicles);
        $this->resetPage();
    }

    #[Computed]
    public function vehicles()
    {
        $dateColumn = match ($this->dateType) {
            'sale'     => 'sale_date',
            'shipping' => 'shipping_date',
            'bl'       => 'bl_issue_date',
            default    => 'purchase_date',
        };

        // 2026-05-20 #3 — 영업 role 본인 차량 한정. admin/super/관리/통관/재무는 전체.
        // 편집 권한 (L590~) 과 정합 — 본 사용자 차량만 노출 → 다른 영업 차량 클릭 시도 자체 차단.
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && $user->role === '영업' && $user->salesman;

        // 회의확장씬 #11 (2026-05-22) — [관리] 본인 담당 영업의 차량만.
        // admin/super 전체. 영업은 위 restrictToOwnSalesman 우선. [관리]만 subordinates 의 salesman.
        // subordinates 0명 → whereIn([]) → 빈 결과 (의도. /admin/users 에서 배정 안내 필요).
        $restrictToManagerScope = $user && ! $user->isAdmin() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        return Vehicle::query()
            ->with(['buyer', 'salesman', 'finalPayments', 'purchaseBalancePayments', 'receivableHistories'])
            ->when($restrictToOwnSalesman, fn ($q) => $q->where('salesman_id', $user->salesman->id))
            ->when($restrictToManagerScope, fn ($q) => $q->whereIn('salesman_id', $managerScopeSalesmanIds))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
                ->orWhere('model_type', 'like', "%{$this->search}%")
                ->orWhere('nice_reg_owner_name', 'like', "%{$this->search}%")
            ))
            ->when($this->progressFilter, fn ($q) => $q->where('progress_status_cache', $this->progressFilter))
            ->when($this->salesmanId !== '', fn ($q) => $q->where('salesman_id', $this->salesmanId))
            ->when($this->buyerId !== '', fn ($q) => $q->where('buyer_id', $this->buyerId))
            ->when($this->action !== '', fn ($q) => $this->applyActionFilter($q))
            ->when($this->dateFrom, fn ($q) => $q->where($dateColumn, '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where($dateColumn, '<=', $this->dateTo))
            ->orderBy($this->sortColumn, $this->sortDirection)
            ->paginate($this->perPage);
    }

    /**
     * 대시보드 카드 카운트와 vehicles 목록 SQL where 100% 일치.
     * 14 액션(영업 5 / 통관 7 / 정산 5) + 관리자 2 = 16 케이스를
     * Vehicle::scopeAction()에서 통합 정의. SKILLS.md §9 action 파라미터 패턴.
     */
    private function applyActionFilter($q)
    {
        return $q->action($this->action);
    }

    #[Computed]
    public function buyers() { return Buyer::where('is_active', true)->orderBy('name')->get(); }

    // 작업2 (2026-05-27) — quick-add 폼 국가 드롭다운용.
    #[Computed]
    public function countries() { return Country::orderBy('name')->get(); }

    // 2026-05-21 — CIPL 드롭다운 (Port 마스터 type 별 활성 목록)
    #[Computed]
    public function loadingPorts() { return \App\Models\Port::ofType('loading')->get(); }

    #[Computed]
    public function unloadingPorts() { return \App\Models\Port::ofType('unloading')->get(); }

    #[Computed]
    public function dischargePorts() { return \App\Models\Port::ofType('discharge')->get(); }

    #[Computed]
    public function consigneesForSale()
    {
        if ($this->buyer_id_str === '') return collect();
        return Consignee::where('buyer_id', $this->buyer_id_str)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function consigneesForExport()
    {
        if ($this->export_buyer_id_str === '') return collect();
        return Consignee::where('buyer_id', $this->export_buyer_id_str)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function consigneesForBl()
    {
        if ($this->bl_buyer_id_str === '') return collect();
        return Consignee::where('buyer_id', $this->bl_buyer_id_str)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function forwardingCompanies() { return ForwardingCompany::where('is_active', true)->orderBy('name')->get(); }

    // 회의확장씬 #12 (2026-05-22) — 현재 편집 중 차량의 buyer × currency 누적 적립금.
    // SavingsStatus 단일 출처 (vehicles 판매 탭 적립금 + 바이어 페이지 적립금 둘 다 누적).
    // null = 차량 미선택 또는 buyer 미지정.
    #[Computed]
    public function buyerSavingsBalance(): ?float
    {
        if (! $this->editingId) {
            return null;
        }
        $v = Vehicle::find($this->editingId);
        if (! $v || ! $v->buyer_id) {
            return null;
        }
        $latest = \App\Models\SavingsStatus::where('buyer_id', $v->buyer_id)
            ->where('currency', $v->currency)
            ->orderByDesc('id')
            ->first();

        return (float) ($latest?->balance ?? 0);
    }

    // 회의확장씬 #11 (2026-05-22) — [관리]는 본인 담당 영업만 select 노출.
    // admin/super 전체 / 영업 전체 (본인 차량 한정은 query 측 restrictToOwnSalesman 분리).
    // 필터바 select (L2392) + 편집 패널 select (L2802) 양쪽 자동 반영.
    #[Computed]
    public function salesmen()
    {
        $q = Salesman::where('is_active', true)->orderBy('name');

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && $user->role === '관리') {
            $q->whereIn('id', $user->getSubordinateSalesmanIds());
        }

        return $q->get();
    }

    /**
     * 회의확장씬 #3 Phase 2-4 (2026-05-23) — 필터바 바이어 select 옵션.
     * - admin/super/통관/재무: 전체 바이어
     * - 관리: 본인 담당 영업의 바이어만 (direct salesman_id + indirect vehicles.salesman_id)
     * - 영업: 본인 영업의 바이어만 (#11 패턴 동일)
     */
    #[Computed]
    public function buyersForFilter()
    {
        $q = Buyer::orderBy('name');
        $user = auth()->user();

        if (! $user || $user->isAdmin()) {
            return $q->get();
        }

        if ($user->role === '관리') {
            $subIds = $user->getSubordinateSalesmanIds();
            $q->where(function ($q2) use ($subIds) {
                $q2->whereIn('salesman_id', $subIds)
                    ->orWhereHas('vehicles', fn ($q3) => $q3->whereIn('salesman_id', $subIds));
            });
        } elseif ($user->role === '영업' && $user->salesman) {
            $ownId = $user->salesman->id;
            $q->where(function ($q2) use ($ownId) {
                $q2->where('salesman_id', $ownId)
                    ->orWhereHas('vehicles', fn ($q3) => $q3->where('salesman_id', $ownId));
            });
        }

        return $q->get();
    }

    /**
     * 큐 2번 — 편집 패널 1대용 흐름도 7노드.
     * 매입 / 말소 / 판매 / 입금 / 통관 / 선적 / DHL.
     * 상태: done(✓) / warn(!) / progress(진행중) / pending(-).
     * 큐 17 — 폐기 컨셉 제거. disabled 상태 사용처 없어짐.
     * 큐 6 잔여 H13 — warn/pending/progress 노드에 reason 텍스트 부착 (tooltip 안내).
     */
    #[Computed]
    public function progressFlow(): ?array
    {
        if (! $this->editingId) {
            return null;
        }
        $v = Vehicle::with(['finalPayments', 'purchaseBalancePayments', 'receivableHistories'])
            ->find($this->editingId);
        if (! $v) {
            return null;
        }

        // 매입
        $purchaseStatus = $v->purchase_price > 0
            ? ($v->purchase_unpaid_amount <= 0 ? 'done' : 'warn')
            : 'pending';
        $purchaseReason = match (true) {
            $purchaseStatus === 'warn' => '매입 미지급 잔액 '.number_format($v->purchase_unpaid_amount).'원 존재',
            $purchaseStatus === 'pending' => '매입가 미입력',
            default => null,
        };

        // 말소
        $deregStatus = $v->is_deregistered && $v->deregistration_document
            ? 'done'
            : ($v->is_deregistered ? 'warn' : 'pending');
        $deregReason = match (true) {
            $deregStatus === 'warn' => '말소 체크는 됐지만 말소등록증 미업로드',
            $deregStatus === 'pending' => '말소 미처리 — 말소완료 체크 + 말소등록증 업로드 필요',
            default => null,
        };

        // 판매
        $saleStatus = $v->sale_price > 0 ? 'done' : 'pending';
        $saleReason = $saleStatus === 'pending' ? '판매가 미입력' : null;

        // 입금
        $paymentStatus = $v->sale_price > 0
            ? ($v->sale_unpaid_amount <= 0 ? 'done' : 'warn')
            : 'pending';
        $paymentReason = match (true) {
            $paymentStatus === 'warn' => '판매 미입금 '.number_format($v->sale_unpaid_amount).'원 존재',
            $paymentStatus === 'pending' => '판매가 미입력 — 입금 추적 불가',
            default => null,
        };

        // 통관 — 큐 2.6 잔여 통합: 체크박스 + 문서 둘 다 누락 시 명시 안내
        $clearanceStatus = $v->export_declaration_document ? 'done'
            : ($v->export_buyer_id && $v->shipping_date ? 'progress' : 'pending');
        $clearanceReason = match (true) {
            $clearanceStatus === 'progress' => '수출신고서 업로드 필요 (체크박스만으론 단계 진행 안 됨)',
            $clearanceStatus === 'pending' => '수출통관 정보 미입력 — 통관 바이어/선적일·포워딩사 입력 후 수출신고서 업로드',
            default => null,
        };

        // 선적
        $blStatus = $v->bl_document ? 'done'
            : ($v->bl_loading_location ? 'progress' : 'pending');
        $blReason = match (true) {
            $blStatus === 'progress' => 'B/L 문서 업로드 필요 (반입지 입력 완료)',
            $blStatus === 'pending' => '선적 미진행 — B/L 반입지 입력 후 문서 업로드',
            default => null,
        };

        // DHL
        $dhlStatus = $v->dhl_request ? 'done' : 'pending';
        $dhlReason = $dhlStatus === 'pending' ? 'DHL 발송신청 미체크' : null;

        return [
            ['key' => 'purchase',       'label' => '매입',   'tab' => 'purchase',  'status' => $purchaseStatus,  'reason' => $purchaseReason],
            ['key' => 'deregistration', 'label' => '말소',   'tab' => 'purchase',  'status' => $deregStatus,     'reason' => $deregReason],
            ['key' => 'sale',           'label' => '판매',   'tab' => 'sale',      'status' => $saleStatus,      'reason' => $saleReason],
            ['key' => 'payment',        'label' => '입금',   'tab' => 'sale',      'status' => $paymentStatus,   'reason' => $paymentReason],
            // 회의확장씬 #1 v4 (2026-05-21) — 워크플로우 순서 swap: 선적 → 통관.
            // 라벨 '통관' 보존 (단계 약자) — role 명칭 '수출통관'과 분리. 7노드 흐름도 박스 폭 좁아짐 방지.
            ['key' => 'bl',             'label' => '선적',   'tab' => 'bl',        'status' => $blStatus,        'reason' => $blReason],
            ['key' => 'clearance',      'label' => '통관',   'tab' => 'clearance', 'status' => $clearanceStatus, 'reason' => $clearanceReason],
            ['key' => 'dhl',            'label' => 'DHL',    'tab' => 'dhl',       'status' => $dhlStatus,       'reason' => $dhlReason],
        ];
    }

    public function updatedBuyerIdStr(): void { $this->consignee_id_str = ''; unset($this->consigneesForSale); }
    public function updatedExportBuyerIdStr(): void { $this->export_consignee_id_str = ''; unset($this->consigneesForExport); }
    public function updatedBlBuyerIdStr(): void { $this->bl_consignee_id_str = ''; unset($this->consigneesForBl); }

    // ── 작업2 (2026-05-27) — 차량 패널 내 바이어·컨사이니 즉석 등록 ──────────
    //   context = 'sale' | 'export' | 'bl' (어느 드롭다운에서 호출됐는지).
    //   consignee 는 해당 context 의 바이어가 선택돼 있어야 함 (buyer_id 종속).
    //   ⚠️ 저장 후 *_id_str 를 서버에서 직접 할당 → updatedXxx 훅 안 뜸 →
    //      종속 consignee 리셋·computed unset 을 saveQuickAdd 에서 수동 처리.
    private function contextBuyerId(string $context): string
    {
        return match ($context) {
            'sale'   => $this->buyer_id_str,
            'export' => $this->export_buyer_id_str,
            'bl'     => $this->bl_buyer_id_str,
            default  => '',
        };
    }

    public function openQuickAdd(string $type, string $context): void
    {
        if (! in_array($type, ['buyer', 'consignee'], true) || ! in_array($context, ['sale', 'export', 'bl'], true)) {
            return;
        }

        if ($type === 'consignee') {
            $buyerId = $this->contextBuyerId($context);
            if ($buyerId === '') {
                $this->dispatch('notify', message: '바이어 먼저 선택', type: 'warning');

                return;
            }
            $this->quickAddBuyerName = (string) (Buyer::find($buyerId)?->name ?? '');
        }

        $this->quickAddType = $type;
        $this->quickAddContext = $context;
        $this->resetQuickAddForm();

        // 바이어 quick-add: 차량 패널의 현재 선택 영업담당자를 기본값으로 prefill (수정 가능)
        if ($type === 'buyer') {
            $this->qaSalesmanId = $this->salesman_id_str;
        }

        $this->quickAddOpen = true;
    }

    public function saveQuickAdd(): void
    {
        $this->validate([
            'qaName'       => 'required|string|max:100',
            'qaCountryId'  => 'nullable|integer|exists:countries,id',
            'qaSalesmanId' => 'nullable|integer|exists:salesmen,id',
        ]);

        $countryId = $this->qaCountryId !== '' ? (int) $this->qaCountryId : null;

        if ($this->quickAddType === 'buyer') {
            $buyer = Buyer::create([
                'name'          => $this->qaName,
                'country_id'    => $countryId,
                'salesman_id'   => $this->qaSalesmanId !== '' ? (int) $this->qaSalesmanId : null,
                'contact_name'  => $this->qaContactName ?: null,
                'contact_phone' => $this->qaContactPhone ?: null,
                'is_active'     => true,
            ]);
            $this->selectNewBuyer($this->quickAddContext, (string) $buyer->id);
            unset($this->buyers);
            $this->dispatch('notify', message: '신규 바이어 등록 완료', type: 'success');
        } else {
            $buyerId = $this->contextBuyerId($this->quickAddContext);
            if ($buyerId === '') {
                $this->dispatch('notify', message: '바이어 먼저 선택', type: 'warning');

                return;
            }
            $consignee = Consignee::create([
                'name'          => $this->qaName,
                'buyer_id'      => (int) $buyerId,
                'country_id'    => $countryId,
                'contact_name'  => $this->qaContactName ?: null,
                'contact_phone' => $this->qaContactPhone ?: null,
                'is_active'     => true,
            ]);
            $this->selectNewConsignee($this->quickAddContext, (string) $consignee->id);
            $this->dispatch('notify', message: '신규 컨사이니 등록 완료', type: 'success');
        }

        $this->quickAddOpen = false;
        $this->resetQuickAddForm();

        // 패널 dirty 마킹 — morphdom 프로그램적 select 변경은 input/change 이벤트 안 쏨 →
        // 자동선택 후 패널이 clean 으로 보여 ESC/backdrop 닫힘 시 새 항목이 차량에 안 붙는 footgun 방지.
        $this->dispatch('panel-mark-dirty');
    }

    private function selectNewBuyer(string $context, string $id): void
    {
        if ($context === 'sale') {
            $this->buyer_id_str = $id;
            $this->consignee_id_str = '';
            unset($this->consigneesForSale);
        } elseif ($context === 'export') {
            $this->export_buyer_id_str = $id;
            $this->export_consignee_id_str = '';
            unset($this->consigneesForExport);
        } elseif ($context === 'bl') {
            $this->bl_buyer_id_str = $id;
            $this->bl_consignee_id_str = '';
            unset($this->consigneesForBl);
        }
    }

    private function selectNewConsignee(string $context, string $id): void
    {
        // unset 먼저 → 재렌더 시 computed 가 신규 컨사이니 포함하도록 재계산.
        if ($context === 'sale') {
            unset($this->consigneesForSale);
            $this->consignee_id_str = $id;
        } elseif ($context === 'export') {
            unset($this->consigneesForExport);
            $this->export_consignee_id_str = $id;
        } elseif ($context === 'bl') {
            unset($this->consigneesForBl);
            $this->bl_consignee_id_str = $id;
        }
    }

    public function cancelQuickAdd(): void
    {
        $this->quickAddOpen = false;
        $this->resetQuickAddForm();
    }

    private function resetQuickAddForm(): void
    {
        $this->qaName = '';
        $this->qaCountryId = '';
        $this->qaSalesmanId = '';
        $this->qaContactName = '';
        $this->qaContactPhone = '';
        $this->resetErrorBag(['qaName', 'qaCountryId', 'qaSalesmanId']);
    }

    // C1 — 통화가 KRW로 변경되면 환율 reset (KRW 차량에 환율값 dirty 방지).
    public function updatedCurrency(): void
    {
        if ($this->currency === 'KRW') {
            $this->exchange_rate_str = '';
        }
    }

    // 큐 16 — updatedSalesChannel hook 제거 (sales_channel은 enum 'export' 단일값으로 변경 불가).

    // ── 패널 열기/닫기 ────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        // 회의확장씬 #9 (2026-05-22) — 신규 차량 기타비용 기본기재 (사용자 명세).
        // 운영자가 수정 가능 (또는 0 으로 비움). 2차 정산 단계에서 [관리]/[재무] 가
        // 한 달 뒤 측정된 실제 비용으로 정정 (Phase 1-3 흐름).
        $this->cost_deregistration_str = '24,000';
        $this->cost_license_str = '11,000';
        $this->cost_towing_str = '30,000';
        $this->showPanel = true;
    }

    // ── 큐 7 확장 C7-a — 회계 민감 컬럼 silent restore ─────────────────
    // 정산/통관/관리 role은 아래 컬럼 변경 시 원값 자동 복원.
    // 입금/지급 컬럼(down_payment, deposit_down_payment, final_payments, purchase_balance_payments)은
    // 정산 role의 정상 업무라 제외.
    private const FINANCIAL_FIELD_MAP = [
        'purchase_price_str' => 'purchase_price',
        'selling_fee_str' => 'selling_fee',
        'sale_price_str' => 'sale_price',
        'tax_dc_str' => 'tax_dc',
        'commission_str' => 'commission',
        'transport_fee_str' => 'transport_fee',
        'auto_loading_str' => 'auto_loading',
        'sale_other_costs_str' => 'sale_other_costs',
        'exchange_rate_str' => 'exchange_rate',
        'export_declaration_amount_str' => 'export_declaration_amount',
        'cost_deregistration_str' => 'cost_deregistration',
        'cost_license_str' => 'cost_license',
        'cost_towing_str' => 'cost_towing',
        'cost_carry_str' => 'cost_carry',
        'cost_shoring_str' => 'cost_shoring',
        'cost_insurance_str' => 'cost_insurance',
        'cost_transfer_str' => 'cost_transfer',
        'cost_extra1_str' => 'cost_extra1',
        'cost_extra2_str' => 'cost_extra2',
    ];

    private function restoreFinancialFieldsFromOriginal(): void
    {
        $original = Vehicle::find($this->editingId);
        if (! $original) {
            return;
        }
        $restored = 0;
        foreach (self::FINANCIAL_FIELD_MAP as $formField => $dbField) {
            $current = (float) str_replace(',', '', (string) ($this->{$formField} ?? '0'));
            $originalValue = (float) ($original->{$dbField} ?? 0);
            if (abs($current - $originalValue) > 0.001) {
                $this->{$formField} = (string) $originalValue;
                $restored++;
            }
        }

        // 2026-05-19 풀회의 P0-1 — RRN(주민/법인등록번호) silent restore.
        // string 컬럼이라 FINANCIAL_FIELD_MAP의 float 비교 패턴 불가 → 별도 분기.
        // accessor가 자동 복호화하므로 평문 비교 가능.
        //
        // Day 5 보강 (안건 C — 말소 [everyone]) — canHandleDeregistration() 사용자는
        // 말소 처리 시 RRN 입력 필수(H10) → restore 대상에서 제외.
        if (! auth()->user()?->canHandleDeregistration()) {
            $currentRrn = (string) ($this->nice_reg_owner_rrn ?? '');
            $originalRrn = (string) ($original->nice_reg_owner_rrn ?? '');
            if ($currentRrn !== $originalRrn) {
                $this->nice_reg_owner_rrn = $originalRrn;
                $restored++;
            }
        }

        if ($restored > 0) {
            $this->dispatch(
                'notify',
                message: "회계 민감 필드 {$restored}건은 변경 권한이 없어 원값으로 복원됨 (admin/영업만 변경 가능)",
                type: 'warning'
            );
        }
    }

    /**
     * 큐 10 H4 — paid 정산이 있는 차량의 회계 민감 컬럼 변경 차단.
     * 회계 마감된 정산의 표시값(snapshot 기반)과 vehicle 컬럼 사이 drift 방지.
     */
    private function guardFinancialDriftAfterPaid(): void
    {
        $existing = Vehicle::find($this->editingId);
        if (! $existing) {
            return;
        }
        $hasPaid = $existing->settlements()->where('settlement_status', 'paid')->exists();
        if (! $hasPaid) {
            return;
        }

        // 회의확장씬 #8 (2026-05-22) — 2차 정산 대기 동안 [재무]/[관리]/admin 잠금 해제.
        // paid 후 한 달 뒤 측정되는 기타비용(말소·면허·탁송·보험·이전비·기타1,2) 수정 대기.
        // secondary_status='closed' 후 다시 잠금 (회계 무결성 복구).
        $user = auth()->user();
        $isFinanceOrManager = $user && ($user->isAdmin() || in_array($user->role, ['재무', '관리'], true));
        if ($isFinanceOrManager) {
            $hasSecondaryPending = $existing->settlements()
                ->where('settlement_status', 'paid')
                ->where('secondary_status', 'pending')
                ->exists();
            if ($hasSecondaryPending) {
                return;   // 2차 정산 대기 동안 수정 허용 (회의확장씬 #8)
            }
        }

        $changed = [];
        foreach (self::FINANCIAL_FIELD_MAP as $formField => $dbField) {
            $newVal = (float) str_replace(',', '', (string) ($this->{$formField} ?? '0'));
            $oldVal = (float) ($existing->{$dbField} ?? 0);
            if (abs($newVal - $oldVal) > 0.001) {
                $changed[] = $dbField;
            }
        }
        if (! empty($changed)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'purchase_price_str' => "정산이 'paid' 상태인 차량의 회계 컬럼은 변경할 수 없습니다 (시도: ".implode(', ', $changed).'). 정산 취소 후 재시도하세요.',
            ]);
        }
    }

    // ── 큐 2.6 — admin 미입금 우회 승인 (per-stage append-only) ────────
    public string $overrideStage = '';
    public string $overrideReason = '';

    public function approveUnpaidOverride(): void
    {
        abort_unless(auth()->user()?->canApproveUnpaidExport(), 403, '미입금 우회 승인 권한이 없습니다.');
        abort_unless($this->editingId, 422, '차량을 먼저 저장한 뒤 승인할 수 있습니다.');

        $this->validate(
            [
                'overrideStage' => ['required', Rule::in(['clearance', 'shipping', 'dhl'])],
                'overrideReason' => ['required', 'string', 'min:20'],
            ],
            [],
            ['overrideStage' => '단계', 'overrideReason' => '사유']
        );

        $v = Vehicle::findOrFail($this->editingId);

        \App\Models\UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => $this->overrideStage,
            'approved_by' => auth()->id(),
            'reason' => $this->overrideReason,
            'approved_at' => now(),
            'ip_address' => request()->ip(),
            'sale_unpaid_amount_snapshot' => $v->sale_unpaid_amount_krw_cache,
        ]);

        $this->overrideStage = '';
        $this->overrideReason = '';

        $this->dispatch('notify', message: '미입금 우회 승인 완료 — 해당 단계 진입 가능', type: 'success');
    }

    public function openEdit(int $id): void
    {
        $v = Vehicle::with(['finalPayments', 'purchaseBalancePayments'])->findOrFail($id);

        // C7-b — 영업 role은 본인 담당 차량만 편집 가능.
        // 관리 role은 본인 팀(부하 영업담당) 차량만 — 목록 스코프(managerScopeSalesmanIds)와 동일 기준.
        // admin/super, 통관/재무는 우회. (claudereview B — openEdit 관리 미스코핑 보정)
        $user = auth()->user();
        if (! $user->isAdmin() && $user->role === '영업') {
            abort_unless($v->salesman_id === $user->salesman?->id, 403, '본인 담당 차량만 편집 가능합니다.');
        } elseif (! $user->isAdmin() && $user->role === '관리') {
            abort_unless(in_array($v->salesman_id, $user->getSubordinateSalesmanIds(), true), 403, '본인 팀 담당 차량만 편집 가능합니다.');
        }

        // save() 신규 등록 직후 호출인지(justCreatedId == $id) 보존,
        // 다른 차량 열기 시엔 reset.
        if ($this->justCreatedId !== $id) {
            $this->justCreatedId = null;
        }
        $this->editingId = $id;

        $lockedFinalIds = \App\Models\ReceivableHistory::where('vehicle_id', $id)
            ->whereNotNull('final_payment_id')
            ->pluck('final_payment_id')
            ->toArray();

        // 큐 19-C 보강 — 자금 이체로 생성된 final_payment는 append-only (locked + transfer 메타)
        $transferLinkedPayments = FinalPayment::where('vehicle_id', $id)
            ->whereNotNull('transfer_id')
            ->with('transfer.sourceVehicle:id,vehicle_number', 'transfer.targetVehicle:id,vehicle_number')
            ->get()
            ->keyBy('id');
        $lockedFinalIds = array_unique(array_merge($lockedFinalIds, $transferLinkedPayments->keys()->all()));

        // 큐 19-E — 각 transfer에 pending void ApprovalRequest 있는지 확인 (취소 요청 중 표시용)
        $transferIds = $transferLinkedPayments->pluck('transfer_id')->unique()->filter()->all();
        $pendingVoidTransferIds = empty($transferIds) ? [] : ApprovalRequest::query()
            ->where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->where('target_type', InterVehicleTransfer::class)
            ->whereIn('target_id', $transferIds)
            ->pluck('target_id')
            ->all();

        $this->vehicle_number = $v->vehicle_number;
        $this->sales_channel  = $v->sales_channel;
        $this->brand      = $v->brand ?? '';
        $this->model_type = $v->model_type ?? '';
        $this->year_str      = $v->year      ? (string)$v->year      : '';
        $this->cc_str        = $v->cc        ? (string)$v->cc        : '';
        $this->weight_kg_str = $v->weight_kg ? (string)$v->weight_kg : '';
        $this->mileage_str   = $v->mileage   ? (string)$v->mileage   : '';
        $this->color = $v->color ?? '';

        // NICE 등록
        $this->nice_reg_vin          = $v->nice_reg_vin          ?? '';
        $this->nice_reg_engine_no    = $v->nice_reg_engine_no    ?? '';
        $this->nice_reg_fuel_type    = $v->nice_reg_fuel_type    ?? '';
        $this->nice_reg_use_type     = $v->nice_reg_use_type     ?? '';
        $this->nice_reg_vehicle_form = $v->nice_reg_vehicle_form ?? '';
        $this->nice_reg_first_date   = $v->nice_reg_first_date   ? $v->nice_reg_first_date->format('Y-m-d') : '';
        $this->nice_reg_date         = $v->nice_reg_date         ? $v->nice_reg_date->format('Y-m-d') : '';
        $this->nice_reg_owner_name   = $v->nice_reg_owner_name   ?? '';
        $this->nice_reg_owner_addr   = $v->nice_reg_owner_addr   ?? '';
        $this->nice_reg_owner_rrn    = $v->nice_reg_owner_rrn    ?? '';
        $this->nice_reg_max_load_str    = $v->nice_reg_max_load    ? (string)$v->nice_reg_max_load    : '';
        $this->nice_reg_passengers_str  = $v->nice_reg_passengers  ? (string)$v->nice_reg_passengers  : '';
        $this->nice_reg_color           = $v->nice_reg_color        ?? '';

        // NICE 제원
        $this->nice_spec_maker           = $v->nice_spec_maker           ?? '';
        $this->nice_spec_model           = $v->nice_spec_model           ?? '';
        $this->nice_spec_year            = $v->nice_spec_year            ?? '';
        $this->nice_spec_displacement_str = $v->nice_spec_displacement    ? (string)$v->nice_spec_displacement : '';
        $this->nice_spec_transmission    = $v->nice_spec_transmission    ?? '';
        $this->nice_spec_drive_type      = $v->nice_spec_drive_type      ?? '';
        $this->nice_spec_length_str      = $v->nice_spec_length      ? (string)$v->nice_spec_length      : '';
        $this->nice_spec_width_str       = $v->nice_spec_width       ? (string)$v->nice_spec_width       : '';
        $this->nice_spec_height_str      = $v->nice_spec_height      ? (string)$v->nice_spec_height      : '';
        $this->nice_spec_wheelbase_str   = $v->nice_spec_wheelbase   ? (string)$v->nice_spec_wheelbase   : '';
        $this->nice_spec_curb_weight_str = $v->nice_spec_curb_weight ? (string)$v->nice_spec_curb_weight : '';
        $this->nice_spec_fuel_efficiency = $v->nice_spec_fuel_efficiency ?? '';

        // 매입
        $this->purchase_date       = $v->purchase_date ? $v->purchase_date->format('Y-m-d') : '';
        $this->salesman_id_str     = $v->salesman_id   ? (string)$v->salesman_id : '';
        $this->purchase_from       = $v->purchase_from ?? '';
        // 큐 20-A/C — 매입처 계좌 4컬럼 (account는 모델 decrypt accessor에서 평문)
        $this->purchase_seller_bank    = $v->purchase_seller_bank    ?? '';
        $this->purchase_seller_account = $v->purchase_seller_account ?? '';
        $this->purchase_seller_holder  = $v->purchase_seller_holder  ?? '';
        $this->purchase_bank_memo      = $v->purchase_bank_memo      ?? '';
        $this->purchase_price_str  = $v->purchase_price ? number_format($v->purchase_price) : '';
        $this->selling_fee_str     = $v->selling_fee    ? number_format($v->selling_fee)    : '';
        $this->cost_deregistration_str = $v->cost_deregistration ? number_format($v->cost_deregistration) : '';
        $this->cost_license_str    = $v->cost_license   ? number_format($v->cost_license)   : '';
        $this->cost_towing_str     = $v->cost_towing    ? number_format($v->cost_towing)    : '';
        $this->cost_carry_str      = $v->cost_carry     ? number_format($v->cost_carry)     : '';
        $this->cost_shoring_str    = $v->cost_shoring   ? number_format($v->cost_shoring)   : '';
        $this->cost_insurance_str  = $v->cost_insurance ? number_format($v->cost_insurance) : '';
        $this->cost_transfer_str   = $v->cost_transfer  ? number_format($v->cost_transfer)  : '';
        $this->cost_extra1_str     = $v->cost_extra1    ? number_format($v->cost_extra1)    : '';
        $this->cost_extra2_str     = $v->cost_extra2    ? number_format($v->cost_extra2)    : '';
        // 22-C-E (2026-05-20) — 2 _str = type별 confirmed PBP 합산 (down/selling_fee).
        $confirmedPbp = $v->purchaseBalancePayments->whereNotNull('confirmed_at');
        $sumPbpByType = function (string $type) use ($confirmedPbp): string {
            $sum = $confirmedPbp->where('type', $type)->sum('amount');

            return $sum > 0 ? number_format((int) $sum) : '';
        };
        $this->down_payment_str        = $sumPbpByType('down');
        $this->selling_fee_payment_str = $sumPbpByType('selling_fee');
        $this->purchase_remittance_memo = $v->purchase_remittance_memo ?? '';
        $this->is_deregistered = $v->is_deregistered;
        $this->purchaseBalancePayments = $v->purchaseBalancePayments->map(fn($p) => [
            'id' => $p->id, 'amount' => (string)$p->amount,
            'payment_date' => $p->payment_date?->format('Y-m-d') ?? '', 'note' => $p->note ?? '',
            // 큐 20-C — 분자 A안 시각화
            'confirmed_at' => $p->confirmed_at?->format('Y-m-d H:i'),
            'finance_confirmer' => $p->financeConfirmer?->name,
        ])->toArray();

        // 판매
        $this->sale_date         = $v->sale_date ? $v->sale_date->format('Y-m-d') : '';
        $this->currency          = $v->currency ?? 'USD';
        $this->exchange_rate_str = $v->exchange_rate ? (string)$v->exchange_rate : '';
        $this->buyer_id_str      = $v->buyer_id     ? (string)$v->buyer_id     : '';
        $this->consignee_id_str  = $v->consignee_id ? (string)$v->consignee_id : '';
        $this->sale_price_str       = $v->sale_price       ? (string)$v->sale_price       : '';
        $this->tax_dc_str           = $v->tax_dc           ? (string)$v->tax_dc           : '';
        $this->commission_str       = $v->commission       ? (string)$v->commission       : '';
        $this->transport_fee_str    = $v->transport_fee    ? (string)$v->transport_fee    : '';
        $this->auto_loading_str     = $v->auto_loading     ? (string)$v->auto_loading     : '';
        $this->sale_other_costs_str = $v->sale_other_costs ? (string)$v->sale_other_costs : '';
        // 22-A-3a 사용자 정정 (2026-05-20) — 4 _str = type별 confirmed FP 합산.
        $confirmedFp = $v->finalPayments->whereNotNull('confirmed_at');
        $sumByType = function (string $type) use ($confirmedFp): string {
            $sum = $confirmedFp->where('type', $type)->sum('amount');

            return $sum > 0 ? (string) (int) $sum : '';
        };
        $this->deposit_down_payment_str = $sumByType('deposit_down');
        $this->interim_payment_str = $sumByType('interim');
        $this->advance_payment1_str = $sumByType('advance_1');
        $this->advance_payment2_str = $sumByType('advance_2');
        $this->savings_used_str     = $v->savings_used     ? (string)$v->savings_used     : '';
        $this->savings_deposit_str  = '';   // 입력란은 항상 빈 값 (누적은 buyerSavingsBalance computed)
        $this->finalPayments = $v->finalPayments->map(function ($p) use ($lockedFinalIds, $transferLinkedPayments, $pendingVoidTransferIds) {
            $row = [
                'id' => $p->id, 'amount' => (string) $p->amount,
                // 회의확장씬 #7 (2026-05-22) — 잔금 row 별 입금 시점 환율
                'exchange_rate' => $p->exchange_rate ? (string) $p->exchange_rate : '',
                'payment_date' => $p->payment_date?->format('Y-m-d') ?? '', 'note' => $p->note ?? '',
                'locked' => in_array($p->id, $lockedFinalIds),
                'transfer' => null,
                // 큐 20-C — 분자 A안 시각화: confirmed_at 유무로 row 색 분기
                'confirmed_at' => $p->confirmed_at?->format('Y-m-d H:i'),
                'finance_confirmer' => $p->financeConfirmer?->name,
            ];
            if ($linked = $transferLinkedPayments->get($p->id)) {
                $t = $linked->transfer;
                $isExecuted = $t->status === \App\Models\InterVehicleTransfer::STATUS_EXECUTED;
                $pendingVoid = in_array($t->id, $pendingVoidTransferIds, true);
                // 큐 19-E — void 가능 조건: status=executed AND 영업/관리/admin 권한 AND pending void 없음
                $user = auth()->user();
                $canVoid = $isExecuted && ! $pendingVoid && $user && ($user->canApprove() || $user->role === '영업');
                $row['transfer'] = [
                    'id' => $t->id,
                    'amount' => (float) $t->amount,
                    'currency' => $t->currency,
                    'status' => $t->status,
                    'approval_request_id' => $t->approval_request_id,
                    'direction' => $p->amount < 0 ? 'outgoing' : 'incoming',  // 이 차량 기준 출/입
                    'counterpart_id' => $p->amount < 0 ? $t->target_vehicle_id : $t->source_vehicle_id,
                    'counterpart_number' => $p->amount < 0
                        ? $t->targetVehicle?->vehicle_number
                        : $t->sourceVehicle?->vehicle_number,
                    'can_void' => $canVoid,
                    'pending_void' => $pendingVoid,
                ];
            }

            return $row;
        })->toArray();

        // 카풀/헤이맨 계산서
        // 큐 16 — tax_invoice_*·agency_fee 로드 제거 (DB drop됨).

        // 수출통관
        $this->export_buyer_id_str       = $v->export_buyer_id       ? (string)$v->export_buyer_id       : '';
        $this->export_consignee_id_str   = $v->export_consignee_id   ? (string)$v->export_consignee_id   : '';
        $this->forwarding_company_id_str = $v->forwarding_company_id ? (string)$v->forwarding_company_id : '';
        $this->export_declaration_amount_str = $v->export_declaration_amount ? (string)$v->export_declaration_amount : '';
        $this->export_declaration_number     = $v->export_declaration_number ?? '';
        $this->shipping_date   = $v->shipping_date ? $v->shipping_date->format('Y-m-d') : '';
        $this->eta_date        = $v->eta_date      ? $v->eta_date->format('Y-m-d')      : '';
        $this->shipping_method = $v->shipping_method ?? '';
        $this->port_of_loading = $v->port_of_loading ?? '';
        $this->incoterms = $v->incoterms ?? '';
        $this->discharge_port_id_str = $v->discharge_port_id ? (string) $v->discharge_port_id : '';
        $this->is_export_cleared = $v->is_export_cleared;

        // 선적
        $this->bl_buyer_id_str     = $v->bl_buyer_id     ? (string)$v->bl_buyer_id     : '';
        $this->bl_consignee_id_str = $v->bl_consignee_id ? (string)$v->bl_consignee_id : '';
        $this->bl_number           = $v->bl_number           ?? '';
        $this->container_number    = $v->container_number    ?? '';
        $this->bl_loading_location = $v->bl_loading_location ?? '';
        $this->vessel_name         = $v->vessel_name         ?? '';
        $this->bl_issue_date       = $v->bl_issue_date ? $v->bl_issue_date->format('Y-m-d') : '';

        // DHL
        $this->dhl_recipient_name    = $v->dhl_recipient_name    ?? '';
        $this->dhl_recipient_address = $v->dhl_recipient_address ?? '';
        $this->dhl_recipient_phone   = $v->dhl_recipient_phone   ?? '';
        $this->dhl_sender_name       = $v->dhl_sender_name       ?? '';
        $this->dhl_sender_address    = $v->dhl_sender_address    ?? '';
        $this->dhl_weight_str        = $v->dhl_weight ? (string)$v->dhl_weight : '';
        $this->dhl_dimensions        = $v->dhl_dimensions ?? '';
        $this->dhl_request           = $v->dhl_request;

        $this->memo = $v->memo ?? '';

        $this->deregistration_document_path     = $v->deregistration_document     ?? '';
        $this->export_declaration_document_path = $v->export_declaration_document ?? '';
        $this->bl_document_path                 = $v->bl_document                 ?? '';
        $this->deregistrationDocFile = $this->exportDeclarationDocFile = $this->blDocFile = null;
        $this->clearDeregistrationDoc = $this->clearExportDeclarationDoc = $this->clearBlDoc = false;

        // 차량 사진 로드 (편집 패널 갤러리)
        $this->photoFiles = [];
        $this->deletePhotoIds = [];
        $this->existingPhotos = $v->photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->url])->all();

        $this->panelUnpaidRatio = $v->unpaid_ratio;

        // 큐 21 — Ledger 잠금 상태 갱신
        $this->refreshLedgerLockState($v);

        $this->showPanel = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
        $this->justCreatedId = null;
        $this->panelUnpaidRatio = null;
        // 큐 14-4-4 — 패널 닫힐 때 overlap 모달도 함께 정리 (열린 채 남아 있으면 어색)
        $this->showOverlapRequestModal = false;
        $this->overlapRequestReason = '';
        // 큐 19-C — 자금 이체 모달도 동일 정리
        $this->resetTransferRequestForm();
        // 큐 21 — Ledger 잠금 모달도 정리
        $this->showLedgerUnlockModal = false;
        $this->ledgerUnlockReason = '';
        $this->isLedgerLocked = false;
        $this->hasLedgerUnlockToken = false;
        // 큐 21 후속 — 말소·수출통관 mismatch 모달 정리
        $this->showDocCheckModal = false;
        $this->userConfirmedDocCheckMismatch = false;
        $this->docCheckMismatches = [];
    }

    // ── 큐 21 후속 — 말소·수출통관 mismatch 감지 + 모달 (사용자 결정 2026-05-18) ────────
    private function detectDocCheckMismatches(): array
    {
        $mismatches = [];

        // 말소: is_deregistered ↔ deregistration_document
        $hasDeregDoc = ($this->deregistrationDocFile !== null)
            || ($this->deregistration_document_path !== '' && ! $this->clearDeregistrationDoc);
        if ($this->is_deregistered xor $hasDeregDoc) {
            $mismatches[] = [
                'stage' => 'deregistration',
                'label' => '말소',
                'tab' => 'purchase',
                'checked' => $this->is_deregistered,
                'has_doc' => $hasDeregDoc,
            ];
        }

        // 수출통관: is_export_cleared ↔ export_declaration_document
        $hasExportDoc = ($this->exportDeclarationDocFile !== null)
            || ($this->export_declaration_document_path !== '' && ! $this->clearExportDeclarationDoc);
        if ($this->is_export_cleared xor $hasExportDoc) {
            $mismatches[] = [
                'stage' => 'export_clearance',
                'label' => '수출통관',
                'tab' => 'export',
                'checked' => $this->is_export_cleared,
                'has_doc' => $hasExportDoc,
            ];
        }

        return $mismatches;
    }

    public function confirmSaveWithDocMismatch(): void
    {
        $this->userConfirmedDocCheckMismatch = true;
        $this->showDocCheckModal = false;
        $this->save();
    }

    public function dismissDocCheckModal(string $jumpToTab = ''): void
    {
        $this->showDocCheckModal = false;
        $this->docCheckMismatches = [];
        $this->userConfirmedDocCheckMismatch = false;
        if ($jumpToTab !== '') {
            $this->dispatch('switch-tab', tab: $jumpToTab);
        }
    }

    // ── 큐 21 — Ledger 잠금 상태 메서드 (회의록 2026-05-18) ────────────
    private function refreshLedgerLockState(?Vehicle $vehicle = null): void
    {
        if (! $this->editingId) {
            $this->isLedgerLocked = false;
            $this->hasLedgerUnlockToken = false;

            return;
        }
        $v = $vehicle ?: Vehicle::find($this->editingId);
        if (! $v) {
            $this->isLedgerLocked = false;
            $this->hasLedgerUnlockToken = false;

            return;
        }
        $this->isLedgerLocked = $v->hasConfirmedPaymentLock();
        $this->hasLedgerUnlockToken = \Illuminate\Support\Facades\Cache::has(
            Vehicle::ledgerUnlockCacheKey($v->id)
        );
    }

    public function openLedgerUnlockModal(): void
    {
        if (! auth()->user()?->canAccessAdmin()) {
            $this->dispatch('notify', message: '잠금 해제 권한 없음 (admin/super 전용)', type: 'error');

            return;
        }
        if (! $this->editingId) {
            return;
        }
        $this->ledgerUnlockReason = '';
        $this->showLedgerUnlockModal = true;
    }

    public function closeLedgerUnlockModal(): void
    {
        $this->showLedgerUnlockModal = false;
        $this->ledgerUnlockReason = '';
    }

    public function submitLedgerUnlock(): void
    {
        $this->validate(
            ['ledgerUnlockReason' => ['required', 'string', 'min:10']],
            ['ledgerUnlockReason.required' => '잠금 해제 사유를 10자 이상 입력하세요.',
                'ledgerUnlockReason.min' => '잠금 해제 사유는 10자 이상 필수입니다.'],
            ['ledgerUnlockReason' => '잠금 해제 사유']
        );

        try {
            $v = Vehicle::findOrFail($this->editingId);
            app(\App\Services\VehicleLedgerUnlockService::class)->unlock(
                $v,
                auth()->user(),
                $this->ledgerUnlockReason
            );
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: '잠금 해제 실패: '.$e->getMessage(), type: 'error');

            return;
        }

        $this->refreshLedgerLockState();
        $this->closeLedgerUnlockModal();
        $this->dispatch('notify',
            message: '잠금 해제 완료. 저장 1회 후 자동 재잠금됩니다.',
            type: 'success');
    }

    private function resetTransferRequestForm(): void
    {
        $this->showTransferRequestModal = false;
        $this->transferTargetVehicleId = '';
        $this->transferAmountStr = '';
        $this->transferReason = '';
        $this->transferNotes = '';
        $this->resetErrorBag(['transferTargetVehicleId', 'transferAmountStr', 'transferReason']);
        $this->resetTransferVoidForm();
    }

    private function resetTransferVoidForm(): void
    {
        $this->showTransferVoidModal = false;
        $this->voidTransferId = null;
        $this->voidReason = '';
        $this->resetErrorBag('voidReason');
    }

    public function removeDeregistrationDoc(): void
    {
        $this->clearDeregistrationDoc = true;
        $this->deregistrationDocFile = null;
        $this->deregistration_document_path = '';
    }

    public function removeExportDeclarationDoc(): void
    {
        $this->clearExportDeclarationDoc = true;
        $this->exportDeclarationDocFile = null;
        $this->export_declaration_document_path = '';
    }

    public function removeBlDoc(): void
    {
        $this->clearBlDoc = true;
        $this->blDocFile = null;
        $this->bl_document_path = '';
    }

    /** 신규 업로드 미리보기에서 한 장 제거 (저장 전). */
    public function removeNewPhoto(int $idx): void
    {
        unset($this->photoFiles[$idx]);
        $this->photoFiles = array_values($this->photoFiles);
    }

    /** 기존 사진 한 장 삭제 staged — save 시 DB row + 디스크에서 제거. */
    public function removeExistingPhoto(int $id): void
    {
        if (! in_array($id, $this->deletePhotoIds, true)) {
            $this->deletePhotoIds[] = $id;
        }
        $this->existingPhotos = array_values(array_filter(
            $this->existingPhotos,
            fn ($p) => $p['id'] !== $id,
        ));
    }

    // 버그 2 fix (2026-05-18) — 새 파일 업로드 시 clear flag reset (safety net).
    // [삭제] → 새 파일 업로드 흐름에서 clearBlDoc stale 상태로 인한 save 사이클 충돌 방지.
    public function updatedDeregistrationDocFile(): void
    {
        if ($this->deregistrationDocFile !== null) {
            $this->clearDeregistrationDoc = false;
        }
    }

    public function updatedExportDeclarationDocFile(): void
    {
        if ($this->exportDeclarationDocFile !== null) {
            $this->clearExportDeclarationDoc = false;
        }
    }

    public function updatedBlDocFile(): void
    {
        if ($this->blDocFile !== null) {
            $this->clearBlDoc = false;
        }
    }

    private function validateVehicleForm(): void
    {
        $nonNegativeNumeric = function (string $attribute, mixed $value, \Closure $fail) {
            // 앞뒤 공백 방어 — NICE/붙여넣기로 들어온 공백-only 값은 trim 후 빈 값 취급(미입력 허용).
            // (behavior change: ' ' 가 이전엔 '숫자 아님' 에러였으나 이제 빈 칸으로 통과)
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === null) {
                return;
            }
            // 큐 19-F/20-C 보강 — finalPayments.*.amount 의 경우 transfer_id 있는 row는 skip.
            // 자금 이체 페어(음수 row)는 UI에서 readonly로 노출되고 영업이 입력한 게 아님.
            if (preg_match('/^finalPayments\.(\d+)\.amount$/', $attribute, $m)) {
                $idx = (int) $m[1];
                $row = $this->finalPayments[$idx] ?? null;
                if (is_array($row) && ! empty($row['transfer'])) {
                    return;
                }
            }
            $cleaned = str_replace(',', '', (string) $value);
            if (! is_numeric($cleaned) || (float) $cleaned < 0) {
                $fail(':attribute은(는) 0 이상의 숫자여야 합니다.');
            }
        };

        $numericFields = [
            'year_str', 'cc_str', 'weight_kg_str', 'mileage_str',
            'nice_reg_max_load_str', 'nice_reg_passengers_str',
            'nice_spec_displacement_str', 'nice_spec_length_str',
            'nice_spec_width_str', 'nice_spec_height_str',
            'nice_spec_wheelbase_str', 'nice_spec_curb_weight_str',
            'purchase_price_str', 'selling_fee_str',
            'cost_deregistration_str', 'cost_license_str', 'cost_towing_str',
            'cost_carry_str', 'cost_shoring_str', 'cost_insurance_str',
            'cost_transfer_str', 'cost_extra1_str', 'cost_extra2_str',
            'down_payment_str', 'selling_fee_payment_str',
            'exchange_rate_str', 'sale_price_str', 'tax_dc_str',
            'commission_str', 'transport_fee_str', 'auto_loading_str',
            'sale_other_costs_str', 'savings_used_str',
            // 22-A-3a 사용자 정정 — 4 _str 복귀 (재무·관리자 입력)
            'deposit_down_payment_str', 'interim_payment_str',
            'advance_payment1_str', 'advance_payment2_str',
            'export_declaration_amount_str', 'dhl_weight_str',
        ];

        $rules = [
            'vehicle_number' => [
                'required', 'string', 'max:20',
                // C6 — soft-delete된 row 제외. closure로 동적 메시지 제공 (방금 저장된 차량인지 힌트 포함).
                function (string $attribute, mixed $value, \Closure $fail) {
                    $q = \App\Models\Vehicle::where('vehicle_number', $value)->whereNull('deleted_at');
                    if ($this->editingId) {
                        $q->where('id', '!=', $this->editingId);
                    }
                    $existing = $q->first(['id', 'vehicle_number', 'created_at']);
                    if (! $existing) {
                        return;
                    }
                    $minutesAgo = (int) $existing->created_at->diffInMinutes(now());
                    $hint = $minutesAgo <= 5
                        ? ' (방금 저장하신 차량일 수 있습니다 — 차량 목록에서 확인하세요)'
                        : '';
                    $fail("같은 차량번호({$value})가 차량 #{$existing->id}로 이미 등록되어 있습니다.{$hint}");
                },
            ],
            // 큐 16 — sales_channel은 enum 'export' 단일값. DB 레벨 강제 + 폼은 hidden 유지.
            'sales_channel'   => ['required', 'in:export'],
            'currency'        => ['required', 'in:USD,JPY,EUR,GBP,CNY,KRW'],
            'shipping_method' => ['nullable', 'in:RORO,CONTAINER'],

            'purchase_date'       => ['nullable', 'date'],
            'sale_date'           => ['nullable', 'date'],
            'shipping_date'       => ['nullable', 'date'],
            'eta_date'            => ['nullable', 'date'],
            'bl_issue_date'       => ['nullable', 'date'],
            'nice_reg_first_date' => ['nullable', 'date'],
            'nice_reg_date'       => ['nullable', 'date'],

            'salesman_id_str'           => [Rule::when($this->salesman_id_str !== '', ['exists:salesmen,id'])],
            'buyer_id_str'              => [Rule::when($this->buyer_id_str !== '', ['exists:buyers,id'])],
            'consignee_id_str'          => [Rule::when($this->consignee_id_str !== '', ['exists:consignees,id'])],
            'export_buyer_id_str'       => [Rule::when($this->export_buyer_id_str !== '', ['exists:buyers,id'])],
            'export_consignee_id_str'   => [Rule::when($this->export_consignee_id_str !== '', ['exists:consignees,id'])],
            'forwarding_company_id_str' => [Rule::when($this->forwarding_company_id_str !== '', ['exists:forwarding_companies,id'])],
            'bl_buyer_id_str'           => [Rule::when($this->bl_buyer_id_str !== '', ['exists:buyers,id'])],
            'bl_consignee_id_str'       => [Rule::when($this->bl_consignee_id_str !== '', ['exists:consignees,id'])],

            'deregistrationDocFile'    => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'exportDeclarationDocFile' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'blDocFile'                => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            // 차량 사진 — jpg/png 만, 장당 8MB. 총 장수 제한은 아래 별도 가드.
            'photoFiles.*' => ['image', 'mimes:jpeg,jpg,png', 'max:8192'],

            // H9 — RRN(주민/법인등록번호) 형식 검증. 000000-0000000 패턴 강제.
            // 말소신청서·등록증재발급·양도증명서 PDF에 RRN 정확한 입력 필수.
            'nice_reg_owner_rrn' => ['nullable', 'string', 'regex:/^\d{6}-\d{7}$/'],

            'finalPayments.*.amount'           => [$nonNegativeNumeric],
            'finalPayments.*.payment_date'     => ['nullable', 'date'],
            'purchaseBalancePayments.*.amount' => [$nonNegativeNumeric],
            // C2 — 매입 잔금 payment_date 필수 (NULL이면 미지급 계산에서 제외되어 매입완료 오인)
            'purchaseBalancePayments.*.payment_date' => ['required', 'date'],
        ];

        foreach ($numericFields as $field) {
            $rules[$field] = [$nonNegativeNumeric];
        }

        // C1 + 2026-05-19 풀회의 안건 E — 판매 정보 입력(sale_price > 0) 시 필수 필드 강화.
        //   - 회의 명세: "판매일, 바이어, 통화, 판매가, 환율은 반드시 기입"
        //   - currency는 select 강제(default 'USD') → 별도 추가 불필요
        //   - 2026-05-20 사용자 정정: KRW는 환율 입력 불필요 (한국돈). Vehicle::saving 훅이 자동 1 normalize.
        //   - 외화만 사용자 명시 입력 강제 (C1 원형 보존).
        //   - 2026-05-26: buyer_id_str 'required' 는 이제 이 규칙의 단일 enforcement.
        //     (DB CHECK chk_sale_required 가 MySQL 8 error 3823 — FK 컬럼 CHECK 금지 —
        //      때문에 buyer_id 를 제외함. 마이그레이션 2026_05_20_000002 docblock 참조.)
        $salePrice = (float) str_replace(',', '', $this->sale_price_str ?: '0');
        if ($salePrice > 0) {
            $rules['sale_date'] = ['required', 'date'];
            $rules['buyer_id_str'] = ['required', 'exists:buyers,id'];
            if ($this->currency !== 'KRW') {
                $rules['exchange_rate_str'] = ['required', 'numeric', 'gt:0'];
            }
        }

        $attributes = [
            'vehicle_number' => '차량번호',
            'sales_channel'  => '판매채널',
            'currency'       => '통화',
            'shipping_method' => '선적방식',
            'purchase_date'  => '매입일',
            'sale_date'      => '판매일',
            'shipping_date'  => '선적일',
            'eta_date'       => 'ETA',
            'bl_issue_date'  => 'B/L 발행일',
            'nice_reg_first_date' => '최초등록일',
            'nice_reg_date'  => '등록일',
            'salesman_id_str' => '영업담당자',
            'buyer_id_str'    => '판매 바이어',
            'consignee_id_str' => '판매 컨사이니',
            'export_buyer_id_str'     => '수출 바이어',
            'export_consignee_id_str' => '수출 컨사이니',
            'forwarding_company_id_str' => '포워딩사',
            'bl_buyer_id_str'    => 'B/L 바이어',
            'bl_consignee_id_str' => 'B/L 컨사이니',
            'deregistrationDocFile'    => '말소서류',
            'exportDeclarationDocFile' => '수출신고서',
            'blDocFile'                => 'B/L 문서',
            'year_str' => '연식', 'cc_str' => '배기량',
            'weight_kg_str' => '중량', 'mileage_str' => '주행거리',
            'purchase_price_str' => '매입가', 'selling_fee_str' => '매도비',
            'sale_price_str' => '판매가', 'exchange_rate_str' => '환율',
            'export_declaration_amount_str' => '면장금액',
            'dhl_weight_str' => 'DHL 중량',
            'nice_reg_owner_rrn' => '소유자 주민(법인)등록번호',
        ];

        $messages = [
            'nice_reg_owner_rrn.regex' => '주민(법인)등록번호는 000000-0000000 형식이어야 합니다.',
        ];

        $this->validate($rules, $messages, $attributes);

        // 차량 사진 총 장수 가드 — 유지될 기존(existingPhotos) + 신규(photoFiles) ≤ MAX_PHOTOS.
        if (count($this->existingPhotos) + count($this->photoFiles) > self::MAX_PHOTOS) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'photoFiles' => '차량 사진은 최대 '.self::MAX_PHOTOS.'장까지 등록할 수 있습니다.',
            ]);
        }
    }

    /**
     * UX #3 (2026-05-20) — 영업 저장 확인 모달 트리거.
     *
     * 영업 role: 모달 열기 → 매입+판매 필수 항목 미리보기 → [확인] 시 confirmAndSave() 호출 → save() 실행.
     * 다른 role (admin/재무/관리/통관): 모달 없이 즉시 save() (빠른 처리).
     */
    public function requestSave(string $tab = 'purchase'): void
    {
        // 회의확장씬 (2026-05-22) — 영업·관리 모달 노출. 4 탭(매입/판매/선적/통관) 만 모달.
        // 그 외(basic/dhl/docs) 즉시 save.
        // admin/super/재무/수출통관: 즉시 save (빠른 처리).
        $user = auth()->user();
        $modalTabs = ['purchase', 'sale', 'bl', 'clearance'];
        if ($user && in_array($user->role, ['영업', '관리'], true) && in_array($tab, $modalTabs, true)) {
            $this->activeTabForSave = $tab;
            $this->showSaveConfirmModal = true;

            return;
        }

        $this->save();
    }

    public function confirmAndSave(): void
    {
        $this->showSaveConfirmModal = false;
        $this->save();
    }

    public function closeSaveConfirmModal(): void
    {
        $this->showSaveConfirmModal = false;
    }

    public function save(): void
    {
        // C7-b 회의확장씬 (2026-05-22) — 신규 등록 권한: 영업·관리 role 또는 admin/super.
        // 사용자 헤더 명세 "[관리]가 차량등록부터 거래완료까지 모든 씬 진행" 완전 충족.
        // 수출통관·재무 role 은 신규 등록 차단 (admin/관리 가 만든 차량의 자기 영역만 편집).
        $user = auth()->user();
        if ($this->editingId === null && ! $user->isAdmin()
            && ! in_array($user->role, ['영업', '관리'], true)) {
            abort(403, '차량 신규 등록은 영업·관리 role 또는 admin/super 만 가능합니다.');
        }

        // 큐 14-4-4 후속 — 신규 등록 시 누락된 핵심 메타 자동 채움.
        // A) 영업 role이 본인 담당자 미지정으로 등록 → 자동으로 본인 salesman 적용.
        //    (영업 외 role/admin은 명시적 선택 필요 — 다른 영업 대신 등록할 수 있어 자동 set X)
        // B) 매입일 미지정 → 오늘로 채움. 차량목록 디폴트 날짜필터(매입일 2개월~오늘)에서 누락 방지.
        //    명시적으로 다른 날짜 원하면 사용자가 입력 후 저장.
        if ($this->editingId === null) {
            if ($this->salesman_id_str === '' && $user->role === '영업' && $user->salesman) {
                $this->salesman_id_str = (string) $user->salesman->id;
            }
            if ($this->purchase_date === '') {
                $this->purchase_date = now()->format('Y-m-d');
            }
        }

        // C7-a — 회계 민감 컬럼 (매입가·판매가·환율·면장금액·비용9개) 변경 권한.
        // 정산/통관/관리 role이 변경 시도하면 silent restore + 토스트 안내.
        if ($this->editingId && ! $user->canEditVehicleFinancialFields()) {
            $this->restoreFinancialFieldsFromOriginal();
        }

        // H4 — paid 정산이 있는 차량의 회계 민감 컬럼 변경 차단 (retroactive drift 잠금).
        // admin도 변경 불가. 회계 마감된 정산의 표시값 보호.
        if ($this->editingId) {
            $this->guardFinancialDriftAfterPaid();
        }

        $this->validateVehicleForm();

        // C4·C5 — UI 저장 시점에 단계 의존성 검증 (시드/raw create는 우회).
        // 임시 Vehicle 인스턴스에 현재 form 값을 채워 guardStageOrderForExport 호출.
        $previewVehicle = $this->editingId
            ? \App\Models\Vehicle::find($this->editingId)?->replicate() ?? new \App\Models\Vehicle
            : new \App\Models\Vehicle;
        $previewVehicle->sales_channel = $this->sales_channel;
        $previewVehicle->is_deregistered = $this->is_deregistered;
        $previewVehicle->deregistration_document = $this->deregistration_document_path ?: ($this->deregistrationDocFile ? 'pending' : null);
        $previewVehicle->sale_price = (float) str_replace(',', '', $this->sale_price_str ?: '0');
        $previewVehicle->export_buyer_id = $this->export_buyer_id_str !== '' ? (int) $this->export_buyer_id_str : null;
        $previewVehicle->shipping_date = $this->shipping_date ?: null;
        $previewVehicle->export_declaration_document = $this->export_declaration_document_path ?: ($this->exportDeclarationDocFile ? 'pending' : null);
        $previewVehicle->bl_loading_location = $this->bl_loading_location ?: null;
        $previewVehicle->bl_document = $this->bl_document_path ?: ($this->blDocFile ? 'pending' : null);
        $previewVehicle->dhl_request = $this->dhl_request;
        $previewVehicle->is_export_cleared = $this->is_export_cleared;
        // sale_unpaid_amount accessor는 finalPayments/receivableHistories를 보지만, save 단계에선
        // 현재 form의 deposit/잔금 입력값으로 임시 계산. 단순화 — 미입금 잔존은 DB 저장 후 정확.
        // 여기선 ID 있는 차량의 기존 sale_unpaid 캐시를 활용해 1차 검증만.
        if ($this->editingId) {
            // replicate()는 exists=false·id=null로 만들어주는데,
            // 이 상태에서 hasUnpaidOverride() 쿼리가 빈 결과를 반환해 admin이 발급한
            // 미입금 우회 승인이 무시되던 버그. 원본 차량 식별자를 복원해서 관계 쿼리 가능하게.
            $previewVehicle->id = $this->editingId;
            $previewVehicle->exists = true;

            $existing = \App\Models\Vehicle::find($this->editingId);
            if ($existing && $existing->sale_unpaid_amount_krw_cache !== null) {
                // 기존 차량의 미입금 캐시로 C5 평가
                $previewVehicle->setRawAttributes(array_merge(
                    $previewVehicle->getAttributes(),
                    ['sale_unpaid_amount_krw_cache' => $existing->sale_unpaid_amount_krw_cache]
                ));
            }
        }
        $previewVehicle->guardStageOrderForExport();
        $previewVehicle->guardAttachmentDeps();

        // H10 — 말소 처리(is_deregistered=true) 시 RRN 필수.
        // 말소신청서·등록증재발급·양도증명서 PDF가 RRN 필드 사용. 빈칸 발급 차단.
        if ($this->is_deregistered && empty($this->nice_reg_owner_rrn)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'nice_reg_owner_rrn' => '말소 처리에는 소유자 주민(법인)등록번호 입력이 필수입니다.',
            ]);
        }

        // 큐 21 후속 — 말소·수출통관 체크↔서류 mismatch 모달 (사용자 결정 2026-05-18).
        // 모든 validation 통과 후 마지막 단계 — 한쪽만 있으면 단계 진입 안 되므로 사용자 인지 강제.
        if (! $this->userConfirmedDocCheckMismatch) {
            $mismatches = $this->detectDocCheckMismatches();
            if (! empty($mismatches)) {
                $this->docCheckMismatches = $mismatches;
                $this->showDocCheckModal = true;

                return;   // save 보류 — 모달 후 confirmSaveWithDocMismatch에서 재호출
            }
        }

        $toInt = fn(?string $v): int => (int) str_replace(',', '', $v ?? '');
        $toFloat = fn(?string $v): float => (float) str_replace(',', '', $v ?? '');
        $toDate = fn(string $v): ?string => $v !== '' ? $v : null;
        $toId = fn(string $v): ?int => $v !== '' ? (int)$v : null;

        $data = [
            'vehicle_number' => $this->vehicle_number,
            'sales_channel'  => $this->sales_channel,
            'brand'      => $this->brand      ?: null,
            'model_type' => $this->model_type ?: null,
            'year'       => $this->year_str      !== '' ? (int)$this->year_str      : null,
            'cc'         => $this->cc_str        !== '' ? (int)$this->cc_str        : null,
            'weight_kg'  => $this->weight_kg_str !== '' ? (int)$this->weight_kg_str : null,
            'mileage'    => $this->mileage_str   !== '' ? (int)$this->mileage_str   : null,
            'color'      => $this->color ?: null,
            // NICE 등록
            'nice_reg_vin'          => $this->nice_reg_vin          ?: null,
            'nice_reg_engine_no'    => $this->nice_reg_engine_no    ?: null,
            'nice_reg_fuel_type'    => $this->nice_reg_fuel_type    ?: null,
            'nice_reg_use_type'     => $this->nice_reg_use_type     ?: null,
            'nice_reg_vehicle_form' => $this->nice_reg_vehicle_form ?: null,
            'nice_reg_first_date'   => $toDate($this->nice_reg_first_date),
            'nice_reg_date'         => $toDate($this->nice_reg_date),
            'nice_reg_owner_name'   => $this->nice_reg_owner_name   ?: null,
            'nice_reg_owner_addr'   => $this->nice_reg_owner_addr   ?: null,
            'nice_reg_owner_rrn'    => $this->nice_reg_owner_rrn    ?: null,
            'nice_reg_max_load'     => $this->nice_reg_max_load_str   !== '' ? (int)$this->nice_reg_max_load_str   : null,
            'nice_reg_passengers'   => $this->nice_reg_passengers_str !== '' ? (int)$this->nice_reg_passengers_str : null,
            'nice_reg_color'        => $this->nice_reg_color ?: null,
            // NICE 제원
            'nice_spec_maker'           => $this->nice_spec_maker           ?: null,
            'nice_spec_model'           => $this->nice_spec_model           ?: null,
            'nice_spec_year'            => $this->nice_spec_year            ?: null,
            'nice_spec_displacement'    => $this->nice_spec_displacement_str !== '' ? (int)$this->nice_spec_displacement_str : null,
            'nice_spec_transmission'    => $this->nice_spec_transmission    ?: null,
            'nice_spec_drive_type'      => $this->nice_spec_drive_type      ?: null,
            'nice_spec_length'          => $this->nice_spec_length_str      !== '' ? (int)$this->nice_spec_length_str      : null,
            'nice_spec_width'           => $this->nice_spec_width_str       !== '' ? (int)$this->nice_spec_width_str       : null,
            'nice_spec_height'          => $this->nice_spec_height_str      !== '' ? (int)$this->nice_spec_height_str      : null,
            'nice_spec_wheelbase'       => $this->nice_spec_wheelbase_str   !== '' ? (int)$this->nice_spec_wheelbase_str   : null,
            'nice_spec_curb_weight'     => $this->nice_spec_curb_weight_str !== '' ? (int)$this->nice_spec_curb_weight_str : null,
            'nice_spec_fuel_efficiency' => $this->nice_spec_fuel_efficiency ?: null,
            // 매입
            'purchase_date'    => $toDate($this->purchase_date),
            'salesman_id'      => $toId($this->salesman_id_str),
            'purchase_from'    => $this->purchase_from ?: null,
            // 큐 20-A/C — 매입처 계좌 4컬럼
            'purchase_seller_bank'    => $this->purchase_seller_bank    ?: null,
            'purchase_seller_account' => $this->purchase_seller_account ?: null,
            'purchase_seller_holder'  => $this->purchase_seller_holder  ?: null,
            'purchase_bank_memo'      => $this->purchase_bank_memo      ?: null,
            'purchase_price'   => $toInt($this->purchase_price_str),
            'selling_fee'      => $toInt($this->selling_fee_str),
            'cost_deregistration' => $toInt($this->cost_deregistration_str),
            'cost_license'     => $toInt($this->cost_license_str),
            'cost_towing'      => $toInt($this->cost_towing_str),
            'cost_carry'       => $toInt($this->cost_carry_str),
            'cost_shoring'     => $toInt($this->cost_shoring_str),
            'cost_insurance'   => $toInt($this->cost_insurance_str),
            'cost_transfer'    => $toInt($this->cost_transfer_str),
            'cost_extra1'      => $toInt($this->cost_extra1_str),
            'cost_extra2'      => $toInt($this->cost_extra2_str),
            // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP.
            // _str ↔ PBP type='down'/'selling_fee' confirmed row 동기화는 vehicle save 이후 별도 처리.
            'purchase_remittance_memo' => $this->purchase_remittance_memo ?: null,
            'is_deregistered'  => $this->is_deregistered,
            // 판매
            'sale_date'    => $toDate($this->sale_date),
            'currency'     => $this->currency,
            'exchange_rate' => $toFloat($this->exchange_rate_str),
            'buyer_id'     => $toId($this->buyer_id_str),
            'consignee_id' => $toId($this->consignee_id_str),
            'sale_price'       => $toFloat($this->sale_price_str),
            'tax_dc'           => $toFloat($this->tax_dc_str),
            'commission'       => $toFloat($this->commission_str),
            'transport_fee'    => $toFloat($this->transport_fee_str),
            'auto_loading'     => $toFloat($this->auto_loading_str),
            'sale_other_costs' => $toFloat($this->sale_other_costs_str),
            // 큐 22-A-3 — 4컬럼 save 라인 제거. final_payments rows 로 통합.
            'savings_used'     => $toFloat($this->savings_used_str),
            // 수출통관
            'export_buyer_id'       => $toId($this->export_buyer_id_str),
            'export_consignee_id'   => $toId($this->export_consignee_id_str),
            'forwarding_company_id' => $toId($this->forwarding_company_id_str),
            'export_declaration_amount' => $this->export_declaration_amount_str !== '' ? $toFloat($this->export_declaration_amount_str) : null,
            'export_declaration_number' => $this->export_declaration_number ?: null,
            'shipping_date'    => $toDate($this->shipping_date),
            'eta_date'         => $toDate($this->eta_date),
            'shipping_method'  => $this->shipping_method  ?: null,
            'port_of_loading'  => $this->port_of_loading  ?: null,
            'incoterms'        => $this->incoterms ?: null,
            'discharge_port_id' => $this->discharge_port_id_str !== '' ? (int) $this->discharge_port_id_str : null,
            'is_export_cleared' => $this->is_export_cleared,
            // 선적
            'bl_buyer_id'     => $toId($this->bl_buyer_id_str),
            'bl_consignee_id' => $toId($this->bl_consignee_id_str),
            'bl_number'           => $this->bl_number           ?: null,
            'container_number'    => $this->container_number    ?: null,
            'bl_loading_location' => $this->bl_loading_location ?: null,
            'vessel_name'         => $this->vessel_name         ?: null,
            'bl_issue_date'       => $toDate($this->bl_issue_date),
            // DHL
            'dhl_recipient_name'    => $this->dhl_recipient_name    ?: null,
            'dhl_recipient_address' => $this->dhl_recipient_address ?: null,
            'dhl_recipient_phone'   => $this->dhl_recipient_phone   ?: null,
            'dhl_sender_name'       => $this->dhl_sender_name       ?: null,
            'dhl_sender_address'    => $this->dhl_sender_address    ?: null,
            'dhl_weight'     => $this->dhl_weight_str !== '' ? $toFloat($this->dhl_weight_str) : null,
            'dhl_dimensions' => $this->dhl_dimensions ?: null,
            'dhl_request'    => $this->dhl_request,
            'memo' => $this->memo ?: null,
            // 카풀/헤이맨 계산서 (channel != carpul/heyman 이어도 컬럼은 nullable이라 OK)
            // 큐 16 — tax_invoice_*·agency_fee persist 제거.
        ];

        // NICE 조회 원본 보존 — 이번에 조회한 경우에만 기입(편집 시 빈 niceRaw 가 기존 값을 덮어쓰지 않도록 조건부).
        if (! empty($this->niceRaw)) {
            $data['nice_raw'] = $this->niceRaw;
        }

        // 파일 정리 추적용 (트랜잭션 성공·실패 분기)
        $newlyStoredPaths = [];   // 트랜잭션 실패 시 디스크에서 제거
        $pathsToDelete    = [];   // 트랜잭션 성공 시 디스크에서 제거 (옛 파일 / 사용자가 삭제 클릭)

        $fileFields = [
            ['col' => 'deregistration_document',     'fileProp' => 'deregistrationDocFile',    'clearProp' => 'clearDeregistrationDoc'],
            ['col' => 'export_declaration_document', 'fileProp' => 'exportDeclarationDocFile', 'clearProp' => 'clearExportDeclarationDoc'],
            ['col' => 'bl_document',                 'fileProp' => 'blDocFile',                'clearProp' => 'clearBlDoc'],
        ];

        $wasCreating = $this->editingId === null;
        $vehicle = null;
        // 22-C-light 후속 fix (2026-05-20) — Vehicle save 전에 PBP existing id 캡처.
        // Vehicle::saved 훅이 자동 PBP Draft 생성하는데, 그 후 sync 에서 existing - submitted = 자동 Draft 가 delete 대상에 포함되어 사라지는 버그.
        // 캡처 시점을 Vehicle 저장 전으로 옮기면 자동 PBP 보호.
        $existingPurchaseIdsBefore = $this->editingId
            ? PurchaseBalancePayment::where('vehicle_id', $this->editingId)->pluck('id')->toArray()
            : [];
        try {
            \DB::transaction(function () use ($data, $toInt, $toFloat, $toDate, $fileFields, $existingPurchaseIdsBefore, &$newlyStoredPaths, &$pathsToDelete, &$vehicle) {
                if ($this->editingId) {
                    $vehicle = Vehicle::findOrFail($this->editingId);
                    $vehicle->update($data);
                } else {
                    $vehicle = Vehicle::create($data);
                }

                // 파일: 업로드 / 삭제 / 교체 통합 처리
                $fileUpdates = [];
                foreach ($fileFields as $f) {
                    $oldPath = $vehicle->{$f['col']};
                    if ($this->{$f['fileProp']}) {
                        $newPath = $this->{$f['fileProp']}->store("vehicles/{$vehicle->id}", config('filesystems.vehicle_docs_disk'));
                        $newlyStoredPaths[] = $newPath;
                        $fileUpdates[$f['col']] = $newPath;
                        if ($oldPath && $oldPath !== $newPath) {
                            $pathsToDelete[] = $oldPath;
                        }
                    } elseif ($this->{$f['clearProp']} && $oldPath) {
                        $fileUpdates[$f['col']] = null;
                        $pathsToDelete[] = $oldPath;
                    }
                }
                if ($fileUpdates) {
                    $vehicle->update($fileUpdates);
                }

                // 차량 사진 — 개별 삭제(staged) + 신규 업로드. 디스크는 vehicle_docs_disk(로컬 public / 운영 s3).
                // orphan 정리: 삭제 path → $pathsToDelete(tx 성공 시), 신규 path → $newlyStoredPaths(tx 실패 시).
                if ($this->deletePhotoIds) {
                    $delPhotos = \App\Models\VehiclePhoto::where('vehicle_id', $vehicle->id)
                        ->whereIn('id', $this->deletePhotoIds)->get();
                    foreach ($delPhotos as $photo) {
                        $pathsToDelete[] = $photo->path;
                    }
                    \App\Models\VehiclePhoto::whereIn('id', $delPhotos->pluck('id'))->delete();
                }
                if ($this->photoFiles) {
                    $nextOrder = (int) \App\Models\VehiclePhoto::where('vehicle_id', $vehicle->id)->max('sort_order');
                    foreach ($this->photoFiles as $photo) {
                        $photoPath = $photo->store("vehicles/{$vehicle->id}/photos", config('filesystems.vehicle_docs_disk'));
                        $newlyStoredPaths[] = $photoPath;
                        \App\Models\VehiclePhoto::create([
                            'vehicle_id' => $vehicle->id,
                            'path' => $photoPath,
                            'sort_order' => ++$nextOrder,
                        ]);
                    }
                }

            // 판매 잔금 동기화 (id-diff)
            // 채권 화면에서 생성된 잔금(locked)은 이 패널에서 수정/삭제 불가 — 채권관리가 원천
            $lockedFinalIds = \App\Models\ReceivableHistory::where('vehicle_id', $vehicle->id)
                ->whereNotNull('final_payment_id')->pluck('final_payment_id')->toArray();
            $existingFinalIds = $vehicle->finalPayments->pluck('id')->toArray();
            $submittedFinalIds = collect($this->finalPayments)->pluck('id')->filter()->toArray();
            $toDeleteIds = array_diff($existingFinalIds, $submittedFinalIds);
            FinalPayment::whereIn('id', array_diff($toDeleteIds, $lockedFinalIds))->delete();

            // 회의확장씬 #6 (2026-05-22) — canConfirmFinance(재무·관리·admin) 직접 추가 시 즉시 확정.
            // 사용자 명세: "[관리]가 직접 할 경우 요청씬 없이 추가되고 즉시 승인 가능"
            // 영업 추가 → Draft (confirmed_at=NULL) → 재무가 transfers 에서 별도 확정
            // 신규 row 만 자동 확정 — 기존 Draft 수정은 transfers 흐름 유지 (의도)
            $authUser = auth()->user();
            $autoConfirmFields = $authUser?->canConfirmFinance() ? [
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $authUser->id,
            ] : [];

            foreach ($this->finalPayments as $row) {
                if (!empty($row['locked'])) continue;
                if ($row['amount'] === '' && $row['payment_date'] === '') continue;
                $amt = $toFloat($row['amount'] ?? '');
                $dt  = $toDate($row['payment_date'] ?? '');
                // 회의확장씬 #7 (2026-05-22) — 잔금 row 별 환율 저장.
                $rateVal = ($row['exchange_rate'] ?? '') !== '' ? (float) str_replace(',', '', $row['exchange_rate']) : null;
                if (isset($row['id']) && $row['id']) {
                    if (in_array($row['id'], $lockedFinalIds)) continue;
                    FinalPayment::where('id', $row['id'])->update([
                        'amount' => $amt,
                        'exchange_rate' => $rateVal,
                        'payment_date' => $dt,
                        'note' => $row['note'] ?? null,
                    ]);
                } else {
                    $vehicle->finalPayments()->create(array_merge([
                        'amount' => $amt,
                        'exchange_rate' => $rateVal,
                        'payment_date' => $dt,
                        'note' => $row['note'] ?? null,
                    ], $autoConfirmFields));
                }
            }

            // 22-A-3a 사용자 정정 (2026-05-20) — 4 항목 (계약금/중도금/선수금) FP type별 sync.
            // 재무·관리자 입력 — 각 _str 값과 type별 confirmed 합산 비교 후 변경분만 row 재생성.
            // confirmed_at SET row 잠금 우회 ($allowConfirmedMutation flag) — 4 항목은 분류 메타데이터.
            if (auth()->user()?->canManagePaymentBreakdown()) {
                $typeStrMap = [
                    'deposit_down' => $this->deposit_down_payment_str,
                    'interim' => $this->interim_payment_str,
                    'advance_1' => $this->advance_payment1_str,
                    'advance_2' => $this->advance_payment2_str,
                ];
                foreach ($typeStrMap as $type => $str) {
                    $newAmount = $str === '' ? 0.0 : (float) $str;
                    $existingSum = (float) $vehicle->finalPayments()
                        ->where('type', $type)
                        ->whereNotNull('confirmed_at')
                        ->sum('amount');
                    if (abs($newAmount - $existingSum) < 0.01) {
                        continue;   // 변경 없음
                    }
                    FinalPayment::$allowConfirmedMutation = true;
                    try {
                        $vehicle->finalPayments()
                            ->where('type', $type)
                            ->whereNotNull('confirmed_at')
                            ->delete();
                    } finally {
                        FinalPayment::$allowConfirmedMutation = false;
                    }
                    if ($newAmount > 0) {
                        $vehicle->finalPayments()->create([
                            'amount' => $newAmount,
                            'type' => $type,
                            'payment_date' => today(),
                            'confirmed_at' => now(),
                            'confirmed_by_user_id' => auth()->id(),
                            'note' => match ($type) {
                                'deposit_down' => '계약금',
                                'interim' => '중도금',
                                'advance_1' => '선수금1',
                                'advance_2' => '선수금2',
                            },
                        ]);
                    }
                }
            }

            // 회의확장씬 #12 (2026-05-22) — canConfirmFinance 입력 시 SavingsStatus EARNED 거래 추가.
            // 한 번 입력 → 거래 추가 → 입력란 reset. 누적은 SavingsStatus 단일 출처 (buyerSavingsBalance 자동 반영).
            // 영업·통관 입력은 disabled — 만약 ui 우회로 값 들어와도 canConfirmFinance 가드로 차단.
            if (auth()->user()?->canConfirmFinance()) {
                $depositAmt = $this->savings_deposit_str === ''
                    ? 0.0
                    : (float) str_replace(',', '', $this->savings_deposit_str);
                if ($depositAmt > 0 && $vehicle->buyer_id) {
                    $vehicle->syncSavingsDeposit($depositAmt);
                    $this->savings_deposit_str = '';
                    unset($this->buyerSavingsBalance);   // computed 캐시 무효화 → 즉시 반영
                }
            }

            // 22-C-E 사용자 정정 (2026-05-20) — 2 항목 (계약금/매도비) PBP type별 sync.
            // 재무·admin 입력 (canConfirmFinance) — 각 _str 값과 type별 confirmed 합산 비교 후 변경분만 row 재생성.
            // confirmed_at SET row 잠금 우회 ($allowConfirmedMutation flag) — 2 항목은 분류 메타데이터.
            if (auth()->user()?->canConfirmFinance()) {
                $pbpTypeStrMap = [
                    'down' => $this->down_payment_str,
                    'selling_fee' => $this->selling_fee_payment_str,
                ];
                foreach ($pbpTypeStrMap as $type => $str) {
                    $newAmount = $str === '' ? 0.0 : (float) str_replace(',', '', $str);
                    $existingSum = (float) $vehicle->purchaseBalancePayments()
                        ->where('type', $type)
                        ->whereNotNull('confirmed_at')
                        ->sum('amount');
                    if (abs($newAmount - $existingSum) < 0.01) {
                        continue;
                    }
                    PurchaseBalancePayment::$allowConfirmedMutation = true;
                    try {
                        $vehicle->purchaseBalancePayments()
                            ->where('type', $type)
                            ->whereNotNull('confirmed_at')
                            ->delete();
                    } finally {
                        PurchaseBalancePayment::$allowConfirmedMutation = false;
                    }
                    if ($newAmount > 0) {
                        $vehicle->purchaseBalancePayments()->create([
                            'amount' => $newAmount,
                            'type' => $type,
                            'payment_date' => today(),
                            'confirmed_at' => now(),
                            'confirmed_by_user_id' => auth()->id(),
                            'note' => match ($type) {
                                'down' => '계약금',
                                'selling_fee' => '매도비',
                            },
                        ]);
                    }
                }
            }

            // 매입 잔금 동기화 (type='balance' default)
            // 22-C-light 후속 fix — Vehicle save 전 캡처한 $existingPurchaseIdsBefore 사용.
            // Vehicle::saved 자동 PBP Draft 는 existing 에 없으므로 삭제 대상 X (보호).
            $submittedPurchaseIds = collect($this->purchaseBalancePayments)->pluck('id')->filter()->toArray();
            PurchaseBalancePayment::whereIn('id', array_diff($existingPurchaseIdsBefore, $submittedPurchaseIds))->delete();
            foreach ($this->purchaseBalancePayments as $row) {
                if (($row['amount'] ?? '') === '' && ($row['payment_date'] ?? '') === '') continue;
                $amt = $toInt($row['amount'] ?? '');
                $dt  = $toDate($row['payment_date'] ?? '');
                if (isset($row['id']) && $row['id']) {
                    PurchaseBalancePayment::where('id', $row['id'])->update(['amount' => $amt, 'payment_date' => $dt, 'note' => $row['note'] ?? null]);
                } else {
                    // 회의확장씬 #6 (2026-05-22) — canConfirmFinance 직접 추가 시 즉시 확정 (판매 잔금과 동일 정책).
                    $vehicle->purchaseBalancePayments()->create(array_merge([
                        'amount' => $amt,
                        'payment_date' => $dt,
                        'note' => $row['note'] ?? null,
                    ], $autoConfirmFields));
                }
            }

            // 잔금 bulk delete/update는 모델 이벤트가 안 뜸 → 명시적으로 캐시 갱신
            $vehicle->refreshCaches();
            });
        } catch (\DomainException $e) {
            // #1 (2026-05-20) — paid Settlement·SoD·회계 무결성 등 비즈니스 룰 위반.
            // 화이트스크린 대신 토스트로 사용자에게 사유 노출. 트랜잭션은 이미 rollback됨.
            foreach ($newlyStoredPaths as $p) {
                Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($p);
            }
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        } catch (\Throwable $e) {
            // 트랜잭션 실패: 새로 저장된 파일 정리 후 재예외
            foreach ($newlyStoredPaths as $p) {
                Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($p);
            }
            throw $e;
        }

        // 트랜잭션 성공: 옛 파일(교체·삭제 대상) 디스크에서 제거
        foreach ($pathsToDelete as $p) {
            Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($p);
        }

        $this->clearDeregistrationDoc = false;
        $this->clearExportDeclarationDoc = false;
        $this->clearBlDoc = false;

        // 큐 21 후속 — mismatch confirm flag 리셋 (다음 save 진입 시 재검사)
        $this->userConfirmedDocCheckMismatch = false;

        $this->unsetComputedProperties();

        // 큐 6 H14 — 신규 차량 등록 시 다음 단계로 동선 안내.
        // 패널을 닫지 않고 방금 만든 차량을 다시 로드(openEdit)한 뒤,
        // 흐름도에서 첫 warn/pending/progress 노드의 탭으로 자동 전환 + 토스트로 사유 안내.
        // 수정 저장은 기존 close() 흐름 유지 (탭 자동 전환은 편집 중 사용자 흐름을 끊음).
        $nextStep = $wasCreating && $vehicle ? $this->resolveNextStep($vehicle->id) : null;

        if ($wasCreating && $nextStep) {
            $newId = $vehicle->id;
            // 큐 14-4-4 — openEdit() 진입 시 justCreatedId가 일치하면 보존되도록 먼저 set.
            $this->justCreatedId = $newId;
            $this->openEdit($newId);
            $this->dispatch('switch-tab', tab: $nextStep['tab']);
            $this->dispatch(
                'notify',
                message: "차량이 등록됐습니다. 다음 단계: {$nextStep['label']} — {$nextStep['reason']}",
                type: 'success',
            );
            return;
        }

        $this->close();
        session()->flash('success', $wasCreating ? '차량이 등록됐습니다.' : '차량 정보가 수정됐습니다.');
    }

    /**
     * 큐 6 H14 — 신규 차량 등록 후 다음 단계 노드 산정.
     * progressFlow 순서대로 첫 번째 warn/pending/progress 노드 반환. disabled/done은 스킵.
     * 모든 비-done 노드가 disabled면 (헤이맨/카풀 채널 + 매입·말소·판매·입금 모두 done 등) null 반환.
     */
    private function resolveNextStep(int $vehicleId): ?array
    {
        $savedEditingId = $this->editingId;
        $this->editingId = $vehicleId;
        unset($this->progressFlow);
        $flow = $this->progressFlow;
        $this->editingId = $savedEditingId;
        unset($this->progressFlow);

        if (! $flow) {
            return null;
        }
        foreach ($flow as $node) {
            if (in_array($node['status'], ['warn', 'pending', 'progress'], true)) {
                return $node;
            }
        }
        return null;
    }

    public function delete(int $id): void
    {
        try {
            Vehicle::findOrFail($id)->delete();
        } catch (\DomainException $e) {
            // [관리] 등 권한 부족(재무확정 잔금 있는 차량은 admin/super만 삭제) → 500 Ignition 대신 토스트.
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->unsetComputedProperties();
        session()->flash('success', '차량이 삭제됐습니다.');
    }

    // 큐 14-4-4 G2 — 승인 요청 모달 상태
    public bool $showOverlapRequestModal = false;

    public string $overlapRequestReason = '';

    public function openOverlapRequestModal(): void
    {
        $vehicleNumber = trim((string) $this->vehicle_number);
        if ($vehicleNumber === '') {
            $this->dispatch('notify', message: '먼저 차량번호를 입력하세요. (승인은 차량번호 단위로 잠깁니다)', type: 'warning');

            return;
        }
        if ($this->buyer_id_str === '') {
            $this->dispatch('notify', message: '먼저 바이어를 선택하세요.', type: 'warning');

            return;
        }
        $this->overlapRequestReason = '';
        $this->resetErrorBag('overlapRequestReason');
        $this->showOverlapRequestModal = true;
    }

    public function closeOverlapRequestModal(): void
    {
        $this->showOverlapRequestModal = false;
        $this->overlapRequestReason = '';
        $this->resetErrorBag('overlapRequestReason');
    }

    /**
     * 큐 14-4-4 — 같은 바이어 미수 + 신규 거래 승인 요청 (G2).
     * 영업이 buyer 선택 + 차량번호 입력 후 모달에서 사유 작성 → ApprovalRequest 생성.
     * 승인은 (buyer_id × vehicle_number) 쌍에 바인딩 — 1 승인 = 1 차량 보장.
     */
    public function requestSameBuyerOverlapApproval(): void
    {
        $vehicleNumber = trim((string) $this->vehicle_number);
        if ($vehicleNumber === '') {
            $this->dispatch('notify', message: '먼저 차량번호를 입력하세요.', type: 'warning');

            return;
        }
        if ($this->buyer_id_str === '') {
            $this->dispatch('notify', message: '먼저 바이어를 선택하세요.', type: 'warning');

            return;
        }

        $this->validate([
            'overlapRequestReason' => ['required', 'string', 'min:5'],
        ], [
            'overlapRequestReason.required' => '승인 요청 사유를 입력하세요.',
            'overlapRequestReason.min' => '사유는 최소 5자 이상이어야 합니다.',
        ]);

        $buyerId = (int) $this->buyer_id_str;
        $buyer = Buyer::find($buyerId);
        if (! $buyer) {
            return;
        }

        // 같은 (buyer × vehicle_number)에 대해 이미 대기중인 요청 있는지 — 차량번호까지 매칭
        $existing = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_BUYER_OVERLAP)
            ->where('target_type', Buyer::class)
            ->where('target_id', $buyerId)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->get()
            ->first(fn (ApprovalRequest $req) => trim((string) ($req->payload['new_vehicle_number'] ?? '')) === $vehicleNumber);

        if ($existing) {
            $this->dispatch('notify', message: '이미 대기중인 요청이 있습니다.', type: 'warning');
            $this->closeOverlapRequestModal();

            return;
        }

        $overlap = Vehicle::where('buyer_id', $buyerId)
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->whereNull('deleted_at')
            ->get(['id', 'vehicle_number', 'sale_unpaid_amount_krw_cache']);

        ApprovalRequest::create([
            'requester_id' => auth()->id(),
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyerId,
            'payload' => [
                'buyer_name' => $buyer->name,
                'new_vehicle_number' => $vehicleNumber,
                'overlap_count' => $overlap->count(),
                'overlap_amount_krw' => (int) $overlap->sum('sale_unpaid_amount_krw_cache'),
                'overlap_vehicle_numbers' => $overlap->pluck('vehicle_number')->take(5)->values()->all(),
            ],
            'reason' => trim($this->overlapRequestReason),
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);

        $this->dispatch('notify', message: '신규 거래 승인 요청을 보냈습니다.', type: 'success');
        $this->closeOverlapRequestModal();
    }

    // 큐 19-C — 차량 간 자금 이체 요청 ────────────────────────────────

    /**
     * 자금 이체 요청 모달 열기. editingId 차량을 source로 가정.
     */
    public function openTransferRequestModal(): void
    {
        if ($this->editingId === null) {
            $this->dispatch('notify', message: '차량을 먼저 저장한 뒤 이체 요청할 수 있습니다.', type: 'warning');

            return;
        }
        $ctx = $this->transferContext;
        if (! empty($ctx['pending'])) {
            $this->dispatch('notify', message: '이미 대기중인 자금 이체 요청이 있습니다. 관리 승인/거부 후 재시도하세요.', type: 'warning');

            return;
        }
        if (! $ctx['eligible']) {
            $this->dispatch('notify', message: $ctx['reason'], type: 'warning');

            return;
        }
        $this->transferTargetVehicleId = '';
        $this->transferAmountStr = '';
        $this->transferReason = '';
        $this->transferNotes = '';
        $this->resetErrorBag(['transferTargetVehicleId', 'transferAmountStr', 'transferReason']);
        $this->showTransferRequestModal = true;
    }

    public function closeTransferRequestModal(): void
    {
        $this->resetTransferRequestForm();
    }

    /**
     * 자금 이체 요청 제출 → InterVehicleTransferService::request() 호출.
     * 안전 가드 5종 (동일 바이어·동일 currency·ratio≤0.5·금액 한도·paid Settlement 없음)은
     * service 내부에서 재검증. UI는 사용자 입력만 검증.
     */
    public function submitTransferRequest(InterVehicleTransferService $service): void
    {
        if ($this->editingId === null) {
            return;
        }
        $this->validate([
            'transferTargetVehicleId' => ['required', 'integer'],
            'transferAmountStr' => ['required', 'string'],
            'transferReason' => ['required', 'string', 'min:5'],
        ], [
            'transferTargetVehicleId.required' => '이체 대상 차량을 선택하세요.',
            'transferAmountStr.required' => '이체 금액을 입력하세요.',
            'transferReason.required' => '승인 요청 사유를 입력하세요.',
            'transferReason.min' => '사유는 최소 5자 이상이어야 합니다.',
        ]);

        $source = Vehicle::find($this->editingId);
        $target = Vehicle::find((int) $this->transferTargetVehicleId);
        $amount = (float) str_replace(',', '', $this->transferAmountStr);

        if (! $source || ! $target) {
            $this->dispatch('notify', message: '이체 출처 또는 대상 차량을 찾을 수 없습니다.', type: 'warning');

            return;
        }

        try {
            $service->request(
                source: $source,
                target: $target,
                amount: $amount,
                requester: auth()->user(),
                reason: trim($this->transferReason),
                notes: trim($this->transferNotes) ?: null,
            );
        } catch (\DomainException $e) {
            $this->addError('transferAmountStr', $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: '차량 간 자금 이체 승인 요청을 보냈습니다.', type: 'success');
        $this->resetTransferRequestForm();
    }

    // 큐 19-E — 이체 취소(void) 요청 ────────────────────────────────

    public function openTransferVoidModal(int $transferId): void
    {
        $transfer = InterVehicleTransfer::find($transferId);
        if (! $transfer || $transfer->status !== InterVehicleTransfer::STATUS_EXECUTED) {
            $this->dispatch('notify', message: '실행 완료된 이체만 취소 요청할 수 있습니다.', type: 'warning');

            return;
        }
        $this->voidTransferId = $transferId;
        $this->voidReason = '';
        $this->resetErrorBag('voidReason');
        $this->showTransferVoidModal = true;
    }

    public function closeTransferVoidModal(): void
    {
        $this->resetTransferVoidForm();
    }

    public function submitTransferVoidRequest(InterVehicleTransferService $service): void
    {
        if ($this->voidTransferId === null) {
            return;
        }
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5'],
        ], [
            'voidReason.required' => '이체 취소 사유를 입력하세요.',
            'voidReason.min' => '사유는 최소 5자 이상이어야 합니다.',
        ]);

        $transfer = InterVehicleTransfer::find($this->voidTransferId);
        if (! $transfer) {
            $this->dispatch('notify', message: '이체 거래를 찾을 수 없습니다.', type: 'warning');

            return;
        }

        $submittedTransferId = $this->voidTransferId;

        try {
            $service->voidRequest($transfer, auth()->user(), trim($this->voidReason));
        } catch (\DomainException $e) {
            $this->addError('voidReason', $e->getMessage());

            return;
        }

        // 잔금 row의 transfer 메타를 즉시 갱신 (DB 재쿼리 없이 메모리에서)
        // → 모달 닫힘과 동시에 amber 박스 + "취소 요청 중" 시각화
        foreach ($this->finalPayments as $idx => $row) {
            if (! empty($row['transfer']) && (int) ($row['transfer']['id'] ?? 0) === $submittedTransferId) {
                $this->finalPayments[$idx]['transfer']['pending_void'] = true;
                $this->finalPayments[$idx]['transfer']['can_void'] = false;
            }
        }

        $this->dispatch('notify', message: '이체 취소 승인 요청을 보냈습니다.', type: 'success');
        $this->resetTransferVoidForm();
    }

    /**
     * 자금 이체 가능성·한도·후보 차량 산출.
     * eligible 조건:
     *   - editingId 있음 + source 차량 존재
     *   - source.unpaid_ratio ≤ 0.5 (50% 이상 받음)
     *   - source.buyer_id 있음
     *   - source/target에 paid Settlement 없음
     *   - 후보 차량 ≥ 1 (동일 buyer + 동일 currency + 자신 제외 + paid Settlement 없음)
     *
     * 추가 메타 (큐 19-C 보강 — 2026-05-15):
     *   - pending: pending인 InterVehicleTransfer 1건 정보 (있으면 amber 박스 + 버튼 비활성)
     *   - lastDecided: 가장 최근 결정된 ApprovalRequest 1건 (status=approved/rejected/cancelled),
     *     pending이 있으면 노출 안 함. 사용자가 마지막 처리 결과를 한눈에 확인 가능.
     */
    #[Computed]
    public function transferContext(): array
    {
        $base = ['eligible' => false, 'reason' => '', 'received' => 0.0, 'limit' => 0.0, 'candidates' => collect(),
            'pending' => null, 'lastDecided' => null];
        if ($this->editingId === null) {
            $base['reason'] = '차량 저장 후 이용 가능합니다.';

            return $base;
        }
        $source = Vehicle::find($this->editingId);
        if (! $source) {
            return $base;
        }

        // 최근 ApprovalRequest 조회 (이 차량이 source인 transfer 관련)
        $recentRequests = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)
            ->where('target_type', Vehicle::class)
            ->where('target_id', $source->id)
            ->orderByDesc('created_at')
            ->take(5)
            ->get();

        // 큐 19-G — InterVehicleTransfer 기준 미처리 상태 차단 (회의록 부록 A Step 4 발견 버그).
        // pending / approved_awaiting_finance / voided_awaiting_finance 중 연결된 ApprovalRequest가
        // 활성(pending/approved)인 것만 차단. rejected/cancelled는 stale 처리.
        $inProgressTransfer = InterVehicleTransfer::where('source_vehicle_id', $source->id)
            ->whereIn('status', [
                InterVehicleTransfer::STATUS_PENDING,
                InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
                InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
            ])
            ->whereHas('approvalRequest', fn ($q) => $q->whereIn('status', [
                ApprovalRequest::STATUS_PENDING,
                ApprovalRequest::STATUS_APPROVED,
            ]))
            ->latest('id')
            ->first();

        $pendingReq = $recentRequests->firstWhere('status', ApprovalRequest::STATUS_PENDING);
        if ($pendingReq) {
            $p = $pendingReq->payload ?? [];
            $base['pending'] = [
                'approval_request_id' => $pendingReq->id,
                'target_vehicle_number' => $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?'),
                'amount' => (float) ($p['amount'] ?? 0),
                'currency' => $p['currency'] ?? $source->currency,
                'created_at' => $pendingReq->created_at,
                'reason' => $pendingReq->reason,
            ];
        }

        // 큐 19-I — transfer + void 결정 중 가장 최근(decided_at) 1건만 lastDecided 로 표시.
        // 19-C 보강 #2 "혼란 없이 최신 상태" 일관성 — 사용자 결정 2026-05-16.
        // type 필드로 view 분기 ('transfer' 5상태 / 'void' rejected·cancelled).
        if (! $pendingReq) {
            $transferDecided = $recentRequests->first(fn ($r) => in_array($r->status, [
                ApprovalRequest::STATUS_APPROVED,
                ApprovalRequest::STATUS_REJECTED,
                ApprovalRequest::STATUS_CANCELLED,
            ], true));

            $transferIds = InterVehicleTransfer::where('source_vehicle_id', $source->id)->pluck('id');
            $voidDecided = $transferIds->isNotEmpty()
                ? ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
                    ->where('target_type', InterVehicleTransfer::class)
                    ->whereIn('target_id', $transferIds)
                    ->whereIn('status', [
                        ApprovalRequest::STATUS_APPROVED,
                        ApprovalRequest::STATUS_REJECTED,
                        ApprovalRequest::STATUS_CANCELLED,
                    ])
                    ->orderByDesc('decided_at')
                    ->first()
                : null;

            // decided_at 가장 늦은 1건 선택
            $mostRecent = collect([$transferDecided, $voidDecided])
                ->filter()
                ->sortByDesc(fn ($r) => $r->decided_at?->timestamp ?? 0)
                ->first();

            // 큐 19-J — void approved 인 경우 transferDecided 로 fallback.
            // transfer.status 가 voided / voided_awaiting_finance 로 변하므로
            // transferDecided 의 'approved:voided*' 5상태 분기로 표시하는 것이 정확.
            // (void approved 메타를 별도로 보여줄 필요 없음 — transfer 5상태가 이미 의미 포함)
            //
            // 큐 19-L — 단 void approved + transfer.void_finance_rejected_at IS NOT NULL 인 경우
            // "재무가 취소 거부" 케이스. transfer.status 는 executed 로 복귀했지만 void 시도가
            // 거부됐다는 정보가 의미 있음 → fallback 우회, voidDecided 그대로 'void:finance_rejected' 분기.
            if ($mostRecent
                && $mostRecent->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID
                && $mostRecent->status === ApprovalRequest::STATUS_APPROVED
                && $transferDecided) {
                $voidTransfer = InterVehicleTransfer::find($mostRecent->target_id);
                if (! $voidTransfer || $voidTransfer->void_finance_rejected_at === null) {
                    $mostRecent = $transferDecided;
                }
            }

            if ($mostRecent && $mostRecent->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER) {
                $p = $mostRecent->payload ?? [];
                $relatedTransfer = InterVehicleTransfer::where('approval_request_id', $mostRecent->id)
                    ->with(['financeConfirmer', 'financeRejecter'])->first();
                $base['lastDecided'] = [
                    'type' => 'transfer',
                    'approval_request_id' => $mostRecent->id,
                    'status' => $mostRecent->status,
                    'target_vehicle_number' => $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?'),
                    'amount' => (float) ($p['amount'] ?? 0),
                    'currency' => $p['currency'] ?? $source->currency,
                    'decision_note' => $mostRecent->decision_note,
                    'decided_at' => $mostRecent->decided_at,
                    'approver_name' => $mostRecent->approver?->name,
                    // 큐 19-F — transfer 5상태 분기를 위한 메타 부착
                    'transfer_status' => $relatedTransfer?->status,
                    'finance_confirmer_name' => $relatedTransfer?->financeConfirmer?->name,
                    'confirmed_at' => $relatedTransfer?->confirmed_at,
                    'finance_note' => $relatedTransfer?->finance_note,
                    // 큐 19-K — finance_rejected 분기 메타
                    'finance_rejecter_name' => $relatedTransfer?->financeRejecter?->name,
                    'finance_rejected_at' => $relatedTransfer?->finance_rejected_at,
                    'finance_reject_reason' => $relatedTransfer?->finance_reject_reason,
                ];
            } elseif ($mostRecent && $mostRecent->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID) {
                $p = $mostRecent->payload ?? [];
                $voidTransfer = InterVehicleTransfer::with('financeVoidRejecter')
                    ->find($p['transfer_id'] ?? $mostRecent->target_id);
                $base['lastDecided'] = [
                    'type' => 'void',
                    'approval_request_id' => $mostRecent->id,
                    'status' => $mostRecent->status,
                    'transfer_id' => $p['transfer_id'] ?? $mostRecent->target_id,
                    'target_vehicle_number' => $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?'),
                    'amount' => (float) ($p['amount'] ?? 0),
                    'currency' => $p['currency'] ?? $source->currency,
                    'decision_note' => $mostRecent->decision_note,
                    'reason' => $mostRecent->reason,
                    'decided_at' => $mostRecent->decided_at,
                    'approver_name' => $mostRecent->approver?->name,
                    // 큐 19-L — void 재무 거부 메타
                    'void_finance_rejecter_name' => $voidTransfer?->financeVoidRejecter?->name,
                    'void_finance_rejected_at' => $voidTransfer?->void_finance_rejected_at,
                    'void_finance_reject_reason' => $voidTransfer?->void_finance_reject_reason,
                ];
            }
        }

        if ($source->buyer_id === null) {
            $base['reason'] = '바이어가 지정된 차량만 자금 이체 가능합니다.';

            return $base;
        }

        // 큐 19-G — 미처리 transfer 있으면 신규 요청 차단 (한도 이중 부과 방지).
        // lastDecided 박스가 이미 상태 표시하지만, 일반 이체 박스도 reason으로 한 번 더 안내.
        if ($inProgressTransfer) {
            $label = InterVehicleTransfer::STATUSES[$inProgressTransfer->status] ?? $inProgressTransfer->status;
            $base['reason'] = "미처리 자금 이체가 있습니다 ({$label}). 처리 완료/거부 후 재시도하세요.";

            return $base;
        }

        $ratio = $source->unpaid_ratio;
        if ($ratio === null || $ratio > 0.5) {
            $pct = $ratio === null ? '—' : round($ratio * 100, 1).'%';
            $base['reason'] = "출처 차량에 50% 이상 입금되어야 합니다 (현재 미수율 {$pct}).";

            return $base;
        }
        if ($source->settlements()->where('settlement_status', 'paid')->exists()) {
            $base['reason'] = '출처 차량에 paid 정산이 있어 이체할 수 없습니다.';

            return $base;
        }
        $received = (float) $source->sale_total_amount - (float) $source->sale_unpaid_amount;
        $limit = round(max(0.0, $received) * 0.5, 2);

        // 후보 차량 — 동일 buyer, 동일 currency, paid Settlement 없음, 자신 제외
        $candidates = Vehicle::where('buyer_id', $source->buyer_id)
            ->where('currency', $source->currency)
            ->where('id', '!=', $source->id)
            ->whereNull('deleted_at')
            ->whereDoesntHave('settlements', fn ($q) => $q->where('settlement_status', 'paid'))
            ->orderBy('vehicle_number')
            ->get(['id', 'vehicle_number', 'sale_price', 'sale_unpaid_amount_krw_cache']);

        if ($candidates->isEmpty()) {
            $base['reason'] = '동일 바이어 + 동일 통화의 다른 차량이 없습니다.';
            $base['received'] = $received;
            $base['limit'] = $limit;

            return $base;
        }

        $base['eligible'] = true;
        $base['received'] = $received;
        $base['limit'] = $limit;
        $base['candidates'] = $candidates;

        return $base;
    }

    /**
     * 큐 14-4-4 — buyer 선택 + editingId=null + 비-canApprove user일 때
     * 미수 잔존 감지. 신규 등록 폼 상단 배너 분기 데이터.
     *
     * 5상태 (배너 분기):
     *   - 'nothing'           — 승인 이력 없음 (요청 가능)
     *   - 'pending'           — 동일 차량번호 대기중
     *   - 'approved_match'    — 동일 차량번호 승인됨 (저장 가능, green)
     *   - 'approved_mismatch' — 다른 차량번호로 승인됨 (차량번호 일치 또는 새 요청 필요)
     *   - 'rejected'          — 최근 거부됨 (사유 노출 + 새 요청 가능)
     */
    #[Computed]
    public function sameBuyerOverlap(): ?array
    {
        if ($this->editingId !== null || $this->buyer_id_str === '') {
            return null;
        }
        $user = auth()->user();
        if (! $user || $user->canApprove()) {
            return null;
        }
        $buyerId = (int) $this->buyer_id_str;
        $overlap = Vehicle::where('buyer_id', $buyerId)
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->whereNull('deleted_at')
            ->get(['vehicle_number', 'sale_unpaid_amount_krw_cache']);
        if ($overlap->isEmpty()) {
            return null;
        }

        $currentNumber = trim((string) $this->vehicle_number);

        // 같은 buyer에 대한 모든 요청 (최신 우선)
        $allRequests = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_BUYER_OVERLAP)
            ->where('target_type', Buyer::class)
            ->where('target_id', $buyerId)
            ->orderByDesc('id')
            ->get(['id', 'status', 'payload', 'decision_note', 'used_at', 'reason', 'decided_at']);

        $extractNumber = fn (ApprovalRequest $req) => trim((string) ($req->payload['new_vehicle_number'] ?? ''));

        // 동일 차량번호로 대기중 / 승인된 / 거부된 요청
        $pendingForThis = $currentNumber !== ''
            ? $allRequests->first(fn ($r) => $r->status === ApprovalRequest::STATUS_PENDING && $extractNumber($r) === $currentNumber)
            : null;
        $approvedForThis = $currentNumber !== ''
            ? $allRequests->first(fn ($r) => $r->status === ApprovalRequest::STATUS_APPROVED && $r->used_at === null && $extractNumber($r) === $currentNumber)
            : null;
        // 다른 차량번호로 승인된 (활성)
        $approvedForOther = $allRequests->first(fn ($r) => $r->status === ApprovalRequest::STATUS_APPROVED && $r->used_at === null && $extractNumber($r) !== $currentNumber);
        // 최신 결정 (rejected 노출용 — 동일 차량번호 한정, 이후 새 pending/approved 없을 때만)
        $latestRejected = $currentNumber !== ''
            ? $allRequests->first(fn ($r) => $r->status === ApprovalRequest::STATUS_REJECTED && $extractNumber($r) === $currentNumber)
            : null;

        $state = 'nothing';
        $approvedVehicleNumber = null;
        $rejectedNote = null;
        $rejectedReason = null;

        if ($approvedForThis) {
            $state = 'approved_match';
        } elseif ($pendingForThis) {
            $state = 'pending';
        } elseif ($approvedForOther) {
            $state = 'approved_mismatch';
            $approvedVehicleNumber = $extractNumber($approvedForOther);
        } elseif ($latestRejected) {
            $state = 'rejected';
            $rejectedNote = $latestRejected->decision_note;
            $rejectedReason = $latestRejected->reason;
        }

        return [
            'count' => $overlap->count(),
            'amount_krw' => (int) $overlap->sum('sale_unpaid_amount_krw_cache'),
            'vehicle_numbers' => $overlap->pluck('vehicle_number')->take(5)->all(),
            'state' => $state,
            'current_vehicle_number' => $currentNumber,
            'approved_vehicle_number' => $approvedVehicleNumber,
            'rejected_note' => $rejectedNote,
            'rejected_reason' => $rejectedReason,
        ];
    }

    public function lookupNiceApi(): void
    {
        // NICE 1단계가 소유자명 필수 — 차량번호만 있고 소유자명 비면 안내 후 중단.
        if (empty(trim($this->vehicle_number))) {
            return;
        }
        if (empty(trim($this->nice_reg_owner_name))) {
            session()->flash('notice', '소유자명을 먼저 입력하세요. (NICE 조회에 차량번호+소유자명 필요)');

            return;
        }

        $result = NiceApiService::fromConfig()->lookupVehicle(
            trim($this->vehicle_number),
            trim($this->nice_reg_owner_name),
        );

        // null = 엔드포인트 미설정(수동 입력 모드) / success=false = 조회 실패(원문 메시지 노출)
        if ($result === null) {
            session()->flash('notice', 'NICE 조회가 설정되지 않았습니다 — 수동 입력하세요.');

            return;
        }
        if (($result['success'] ?? false) !== true) {
            session()->flash('notice', $result['message'] ?? 'NICE 조회에 실패했습니다.');

            return;
        }

        foreach ($result['registration'] ?? [] as $key => $value) {
            $prop = $key.'_str';
            if (property_exists($this, $prop)) {
                $this->$prop = (string) $value;
            } elseif (property_exists($this, $key)) {
                $this->$key = (string) $value;
            }
        }
        foreach ($result['spec'] ?? [] as $key => $value) {
            $prop = $key.'_str';
            if (property_exists($this, $prop)) {
                $this->$prop = (string) $value;
            } elseif (property_exists($this, $key)) {
                $this->$key = (string) $value;
            }
        }

        // 응답 원본 보존 — 저장 시 nice_raw 로 기입 (미매핑 필드 재조회 없이 활용).
        // 성공 토스트는 띄우지 않음 — 폼 칸이 자동으로 채워지는 것 자체가 성공 신호이고,
        // wire:blur 재발 시 캐시 히트마다 토스트가 반복 노출되는 것을 피한다.
        $this->niceRaw = $result['raw'] ?? [];
    }

    public function addFinalPayment(): void
    {
        // 회의확장씬 #7 (2026-05-22) — 잔금 추가 시 차량 currency 의 실시간 환율 자동 기입.
        // KRW: 1.0 / 외화: ExchangeRateService::getRate (실패 시 빈 값 → 수동 입력 fallback).
        // payment_date 도 today 자동 (입금 날짜 = 추가 시점 자연).
        $autoRate = '';
        if ($this->currency === 'KRW') {
            $autoRate = '1';
        } else {
            $rate = app(\App\Services\ExchangeRateService::class)->getRate($this->currency);
            if ($rate !== null) {
                $autoRate = (string) $rate;
            }
        }

        $this->finalPayments[] = [
            'id' => null,
            'amount' => '',
            'exchange_rate' => $autoRate,
            'payment_date' => today()->toDateString(),
            'note' => '',
        ];
    }
    public function removeFinalPayment(int $idx): void
    {
        unset($this->finalPayments[$idx]);
        $this->finalPayments = array_values($this->finalPayments);
    }
    public function addPurchasePayment(): void
    {
        $this->purchaseBalancePayments[] = ['id' => null, 'amount' => '', 'payment_date' => '', 'note' => ''];
    }
    public function removePurchasePayment(int $idx): void
    {
        unset($this->purchaseBalancePayments[$idx]);
        $this->purchaseBalancePayments = array_values($this->purchaseBalancePayments);
    }

    private function resetForm(): void
    {
        $defaults = [
            'vehicle_number','brand','model_type','color','year_str','cc_str','weight_kg_str','mileage_str',
            'nice_reg_vin','nice_reg_engine_no','nice_reg_fuel_type','nice_reg_use_type','nice_reg_vehicle_form',
            'nice_reg_first_date','nice_reg_date','nice_reg_owner_name','nice_reg_owner_addr','nice_reg_owner_rrn',
            'nice_reg_max_load_str','nice_reg_passengers_str','nice_reg_color',
            'nice_spec_maker','nice_spec_model','nice_spec_year','nice_spec_displacement_str',
            'nice_spec_transmission','nice_spec_drive_type','nice_spec_length_str','nice_spec_width_str',
            'nice_spec_height_str','nice_spec_wheelbase_str','nice_spec_curb_weight_str','nice_spec_fuel_efficiency',
            'purchase_date','salesman_id_str','purchase_from',
            'purchase_seller_bank','purchase_seller_account','purchase_seller_holder','purchase_bank_memo',
            'purchase_price_str','selling_fee_str',
            'cost_deregistration_str','cost_license_str','cost_towing_str','cost_carry_str',
            'cost_shoring_str','cost_insurance_str','cost_transfer_str','cost_extra1_str','cost_extra2_str',
            'down_payment_str','selling_fee_payment_str','purchase_remittance_memo',
            'sale_date','exchange_rate_str','buyer_id_str','consignee_id_str',
            'sale_price_str','tax_dc_str','commission_str','transport_fee_str','auto_loading_str',
            'sale_other_costs_str','savings_used_str','savings_deposit_str',
            'deposit_down_payment_str','interim_payment_str','advance_payment1_str','advance_payment2_str',
            'export_buyer_id_str','export_consignee_id_str','forwarding_company_id_str',
            'export_declaration_amount_str','export_declaration_number','shipping_date','eta_date','shipping_method','port_of_loading',
            'incoterms','discharge_port_id_str',
            'bl_buyer_id_str','bl_consignee_id_str','bl_number','container_number',
            'bl_loading_location','vessel_name','bl_issue_date',
            'dhl_recipient_name','dhl_recipient_address','dhl_recipient_phone',
            'dhl_sender_name','dhl_sender_address','dhl_weight_str','dhl_dimensions','memo',
        ];
        foreach ($defaults as $prop) $this->$prop = '';

        $this->niceRaw = [];
        $this->sales_channel = 'export';
        $this->currency = 'USD';
        $this->is_deregistered = $this->is_export_cleared = false;
        $this->dhl_request = false;
        $this->finalPayments = $this->purchaseBalancePayments = [];
        $this->deregistrationDocFile = $this->exportDeclarationDocFile = $this->blDocFile = null;
        $this->deregistration_document_path = $this->export_declaration_document_path = $this->bl_document_path = '';
        $this->clearDeregistrationDoc = $this->clearExportDeclarationDoc = $this->clearBlDoc = false;
        $this->photoFiles = $this->existingPhotos = $this->deletePhotoIds = [];
    }

    private function unsetComputedProperties(): void
    {
        unset($this->vehicles);
    }
}; ?>

{{-- UX #6 (2026-05-20) — wire:poll.30s — 사이드바 뱃지 + 페이지 데이터 30초 자동 갱신. --}}
<div wire:poll.30s>
{{-- ── 플래시 메시지 ────────────────────────────────────────────── --}}
@if(session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
         class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
        {{ session('success') }}
    </div>
@endif
@if(session('notice'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
         class="fixed top-4 right-4 z-50 rounded-lg bg-amber-500 px-4 py-3 text-sm text-white shadow-lg">
        {{ session('notice') }}
    </div>
@endif

{{-- 토스트 리스너는 layouts/app/sidebar.blade.php 에 글로벌 추출됨 --}}

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- ── 페이지 헤더 ─────────────────────────────────────────────── --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">차량 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->vehicles->total() }}대</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">10개씩</option>
            <option value="30">30개씩</option>
            <option value="50">50개씩</option>
            <option value="100">100개씩</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            차량 등록
        </button>
    </div>
</div>

{{-- ── 필터 바 ─────────────────────────────────────────────────── --}}
<div class="space-y-2">
    {{-- 검색 + 날짜 + 조회 --}}
    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
        <input wire:model="search" wire:keydown.enter="applyFilters" type="text" placeholder="차량번호 · 브랜드 · 차종 · 소유자"
               class="input-filter w-52" />
        <select wire:model="dateType" class="input-filter">
            <option value="purchase">매입일</option>
            <option value="sale">판매일</option>
            <option value="shipping">선적일</option>
            <option value="bl">B/L발행일</option>
        </select>
        <input wire:model="dateFrom" type="date" class="input-filter" />
        <span class="text-gray-400 text-sm">~</span>
        <input wire:model="dateTo" type="date" class="input-filter" />
        <select wire:model.live="salesmanId" class="input-filter">
            <option value="">담당자 전체</option>
            @foreach($this->salesmen as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
        </select>
        {{-- 회의확장씬 #3 Phase 2-4 (2026-05-23) — 바이어 select 필터 --}}
        <select wire:model.live="buyerId" class="input-filter">
            <option value="">바이어 전체</option>
            @foreach($this->buyersForFilter as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <button wire:click="applyFilters" class="btn-search">조회</button>
    </div>
    {{-- 빠른 탭 필터 — 큐 16: 채널 pill 제거 (단일 채널) --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
        <div class="flex flex-wrap gap-1">
            {{-- 안건 1 v4 (2026-05-21) — 워크플로우 순서: 선적(반입) → 통관 → B/L → 거래완료. v3 호환 키는 매핑에서 같은 라벨로 흡수 --}}
            @foreach(['' => '전체', '매입중' => '매입중', '매입완료' => '매입완료', '말소완료' => '말소완료', '판매중' => '판매중', '판매완료' => '판매완료', '선적중' => '선적중', '선적완료' => '선적완료', '통관중' => '통관중', '통관완료' => '통관완료', '수출통관중' => '통관중', '수출통관완료' => '통관완료', '거래완료' => '거래완료'] as $val => $label)
            <button wire:click="$set('progressFilter', '{{ $val }}')"
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium transition
                           {{ $progressFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
    </div>
</div>

{{-- #3 다중차량 선적 서류 — 체크박스 선택(export 차량) 시 노출. 선택 N대를 1서류에 자동 기입(최대 30대). --}}
@if(count($shipDocIds) > 0)
@php
    $shipIds = implode(',', $shipDocIds);
    $shipCnt = count($shipDocIds);
    $shipDocs = [
        'container_invoice_packing' => '컨테이너 Invoice&Packing',
        'container_contract' => '컨테이너 Contract',
        'roro_invoice_packing' => 'RORO Invoice&Packing',
        'roro_contract' => 'RORO Contract',
    ];
@endphp
<div class="card-tight mb-3 flex flex-col gap-2 border-amber-300 bg-amber-50 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-2 text-sm">
        <span class="font-semibold text-amber-800">{{ $shipCnt }}대 선택</span>
        @if($shipCnt > 30)
            <span class="text-xs text-red-600">최대 30대까지 발급 가능</span>
        @endif
        <button type="button" wire:click="clearShipDocSelection" class="text-xs text-gray-500 hover:underline">선택 해제</button>
    </div>
    <div class="flex flex-wrap gap-2">
        @foreach($shipDocs as $type => $label)
            <a href="{{ $shipCnt <= 30 ? route('erp.vehicles.documents.multi', ['type' => $type, 'ids' => $shipIds]) : '#' }}"
               target="_blank"
               class="rounded border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-100 {{ $shipCnt > 30 ? 'pointer-events-none opacity-50' : '' }}">
                ↓ {{ $label }}
            </a>
        @endforeach
    </div>
</div>
@endif

{{-- ── 데스크탑 테이블 (회의확장씬 #10 컬럼 토글 + 정렬) ─────── --}}
<div class="hidden sm:block"
     x-data="vehicleColumnsToggle()"
     x-init="init()">

    {{-- 컬럼 토글 드롭다운 --}}
    <div class="mb-2 flex justify-end relative">
        <button type="button" @click="open = !open"
                class="rounded border border-gray-300 bg-white px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">
            컬럼 <span x-text="open ? '▲' : '▼'"></span>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false"
             class="absolute right-0 top-8 z-10 w-56 rounded-lg border border-gray-200 bg-white py-2 shadow-lg">
            <div class="px-3 pb-1 text-[10px] font-semibold uppercase text-gray-400">표시 컬럼</div>
            <template x-for="col in togglableColumns" :key="col.key">
                <label class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" :checked="visible[col.key]" @change="toggle(col.key)" class="rounded" />
                    <span x-text="col.label"></span>
                </label>
            </template>
            <div class="border-t border-gray-100 mt-1 px-3 py-1">
                <button @click="resetDefaults()" class="text-[11px] text-violet-600 hover:underline">기본값 복원</button>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm border-separate border-spacing-0">
        <thead>
            @php
                $sortBtn = function ($col, $label, $align = 'left') {
                    $isActive = $this->sortColumn === $col;
                    $indicator = $isActive ? ($this->sortDirection === 'asc' ? '▲' : '▼') : '';
                    $alignCls = $align === 'right' ? 'justify-end ml-auto' : '';

                    return '<button type="button" wire:click="setSort(\''.$col.'\')" class="flex items-center gap-1 hover:text-gray-700 '.$alignCls.'">'
                        .htmlspecialchars($label).' <span class="text-[10px]">'.$indicator.'</span></button>';
                };
            @endphp
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="w-6 pb-2 pr-2 font-medium" title="선적 서류용 다중 선택 (수출 차량)"></th>
                <th class="pb-2 pr-4 font-medium">{!! $sortBtn('vehicle_number', '차량번호') !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['brand_model']">{!! $sortBtn('brand', '브랜드/차종') !!}</th>
                <th class="pb-2 pr-4 font-medium">{!! $sortBtn('progress_status_cache', '진행상태') !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['purchase_date']">{!! $sortBtn('purchase_date', '매입일') !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['sale_date']">{!! $sortBtn('sale_date', '판매일') !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['shipping_date']">{!! $sortBtn('shipping_date', '선적일') !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['bl_issue_date']">{!! $sortBtn('bl_issue_date', 'B/L발행일') !!}</th>
                <th class="pb-2 pr-4 font-medium">{!! $sortBtn('salesman_id', '담당자') !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['buyer']">{!! $sortBtn('buyer_id', '바이어') !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['sales_channel']">{!! $sortBtn('sales_channel', '채널') !!}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['currency_rate']">통화/환율</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['purchase_price']">{!! $sortBtn('purchase_price', '매입가', 'right') !!}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['sale_price']">{!! $sortBtn('sale_price', '판매가', 'right') !!}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['unpaid_amount']">미수금</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['unpaid_ratio']">입금률</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->vehicles as $v)
            @php
                $status = $v->progress_status;
                $badgeClass = match(true) {
                    in_array($status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                    in_array($status, ['판매중','판매완료'])           => 'badge-purple',
                    in_array($status, ['선적중','선적완료'])            => 'badge-amber',
                    in_array($status, ['통관중','통관완료'])             => 'badge-green',
                    in_array($status, ['수출통관중','수출통관완료'])     => 'badge-amber',
                    $status === '거래완료'                             => 'badge-gray',
                    default                                            => 'badge-gray',
                };
            @endphp
            @php
                $unpaidRatio = $v->unpaid_ratio;
                $unpaidAmount = $v->sale_unpaid_amount;
            @endphp
            <tr class="cursor-pointer transition {{ $unpaidRatio === null ? 'hover:bg-gray-50' : '' }}"
                wire:click="openEdit({{ $v->id }})"
                @if($unpaidRatio !== null)
                    data-ratio="{{ number_format($unpaidRatio, 6, '.', '') }}"
                    data-unpaid="{{ (int) round($v->sale_unpaid_amount) }}"
                    data-total="{{ (int) round($v->sale_total_amount) }}"
                    data-currency="{{ $v->currency }}"
                @endif
            >
                <td class="py-3 pr-2" @click.stop>
                    @if($v->sales_channel === 'export')
                        <input type="checkbox" wire:model.live="shipDocIds" value="{{ $v->id }}" class="rounded border-gray-300" title="선적 서류 다중 선택" />
                    @endif
                </td>
                <td class="py-3 pr-4 font-mono font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                <td class="py-3 pr-4 text-gray-700" x-show="visible['brand_model']">
                    {{ $v->brand }} {{ $v->model_type }}
                    @if($v->year)<span class="text-xs text-gray-400">({{ $v->year }})</span>@endif
                </td>
                <td class="py-3 pr-4"><span class="badge {{ $badgeClass }}">{{ $status }}</span></td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['purchase_date']">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['sale_date']">{{ $v->sale_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['shipping_date']">{{ $v->shipping_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['bl_issue_date']">{{ $v->bl_issue_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $v->salesman?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['buyer']">{{ $v->buyer?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['sales_channel']">{{ $v->sales_channel ?? '-' }}</td>
                <td class="py-3 pr-4 text-right text-gray-500 text-xs" x-show="visible['currency_rate']">
                    {{ $v->currency }}
                    @if($v->exchange_rate && $v->exchange_rate != 1)
                        <br><span class="text-[10px]">{{ number_format($v->exchange_rate, 2) }}</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-right text-gray-600" x-show="visible['purchase_price']">
                    @if($v->purchase_price > 0)₩{{ number_format($v->purchase_price) }}@else -@endif
                </td>
                <td class="py-3 pr-4 text-right font-medium text-gray-800" x-show="visible['sale_price']">
                    @if($v->sale_price > 0)
                        {{ number_format($v->sale_price) }} <span class="text-xs text-gray-400">{{ $v->currency }}</span>
                    @else -
                    @endif
                </td>
                <td class="py-3 pr-4 text-right text-gray-600" x-show="visible['unpaid_amount']">
                    @if($unpaidAmount > 0)₩{{ number_format($unpaidAmount) }}@else -@endif
                </td>
                <td class="py-3 pr-4 text-right text-xs" x-show="visible['unpaid_ratio']">
                    @if($unpaidRatio === null)<span class="text-gray-300">-</span>
                    @elseif($unpaidRatio <= 0)<span class="text-green-600 font-medium">완납</span>
                    @else <span class="text-amber-600">{{ number_format((1 - $unpaidRatio) * 100, 0) }}%</span>
                    @endif
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $v->id }})"
                            wire:confirm="차량 {{ $v->vehicle_number }}을 삭제하시겠습니까?"
                            class="text-xs text-red-400 hover:text-red-600">삭제</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="17" class="py-12 text-center text-sm text-gray-400">차량이 없습니다.</td></tr>
            @endforelse
        </tbody>
        </table>
    </div>
</div>

{{-- 회의확장씬 #10 Phase 2-3 (2026-05-23) — 컬럼 토글 Alpine 컴포넌트 (localStorage 캐시). --}}
<script>
function vehicleColumnsToggle() {
    const STORAGE_KEY = 'car_erp_vehicles_columns';
    const defaultVisible = {
        brand_model: true, purchase_date: true, sale_price: true,
        sale_date: false, shipping_date: false, bl_issue_date: false,
        currency_rate: false, purchase_price: false,
        unpaid_amount: false, unpaid_ratio: false,
        buyer: false, sales_channel: false,
    };
    return {
        open: false,
        visible: {},
        togglableColumns: [
            { key: 'brand_model',    label: '브랜드/차종' },
            { key: 'purchase_date',  label: '매입일' },
            { key: 'sale_date',      label: '판매일' },
            { key: 'shipping_date',  label: '선적일' },
            { key: 'bl_issue_date',  label: 'B/L발행일' },
            { key: 'buyer',          label: '바이어' },
            { key: 'sales_channel',  label: '채널' },
            { key: 'currency_rate',  label: '통화/환율' },
            { key: 'purchase_price', label: '매입가' },
            { key: 'sale_price',     label: '판매가' },
            { key: 'unpaid_amount',  label: '미수금' },
            { key: 'unpaid_ratio',   label: '입금률' },
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

{{-- ── 모바일 카드 리스트 ───────────────────────────────────────── --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->vehicles as $v)
    @php
        $status = $v->progress_status;
        $badgeClass = match(true) {
            in_array($status, ['매입중','매입완료','말소완료']) => 'badge-blue',
            in_array($status, ['판매중','판매완료'])           => 'badge-purple',
            in_array($status, ['선적중','선적완료'])            => 'badge-amber',
            in_array($status, ['통관중','통관완료'])             => 'badge-green',
            in_array($status, ['수출통관중','수출통관완료'])     => 'badge-amber',
            $status === '거래완료'                             => 'badge-gray',
            default                                            => 'badge-gray',
        };
    @endphp
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $v->id }})">
        <div class="flex items-center gap-2">
            @if($v->sales_channel === 'export')
                <input type="checkbox" wire:model.live="shipDocIds" value="{{ $v->id }}" @click.stop
                       class="rounded border-gray-300" title="선적 서류 다중 선택" />
            @endif
        <div class="space-y-0.5">
            <div class="font-mono font-semibold text-gray-800">{{ $v->vehicle_number }}</div>
            <div class="text-xs text-gray-500">{{ $v->brand }} {{ $v->model_type }}</div>
            <div class="flex items-center gap-1.5">
                <span class="badge {{ $badgeClass }}">{{ $status }}</span>
                @if($v->sale_price > 0)
                    <span class="text-xs font-medium text-gray-700">{{ number_format($v->sale_price) }} {{ $v->currency }}</span>
                @endif
            </div>
        </div>
        </div>
        <div class="text-xs text-gray-400 text-right">
            <div>{{ $v->salesman?->name ?? '-' }}</div>
            <div>{{ $v->purchase_date?->format('m/d') ?? '' }}</div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">차량이 없습니다.</div>
    @endforelse
</div>

{{-- ── 페이지네이션 ────────────────────────────────────────────── --}}
<div>{{ $this->vehicles->links() }}</div>

</div>{{-- /flex col --}}

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- 슬라이드 패널                                                  --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
@if($showPanel)
{{-- 큐 18: close confirm — 기존 tab/switch-tab Alpine과 dirty 추적 병합 --}}
<div x-data="{
    tab: 'basic',
    dirty: false,
    confirmOpen: false,
    attemptClose() {
        if (this.confirmOpen) { this.confirmOpen = false; return; }
        if (this.dirty) { this.confirmOpen = true; } else { $wire.close(); }
    },
    confirmDiscard() { this.confirmOpen = false; $wire.close(); },
}" x-on:switch-tab.window="tab = $event.detail.tab" @panel-mark-dirty.window="dirty = true" @keyup.escape.window="attemptClose()">
{{-- Backdrop --}}
<div class="fixed inset-0 z-40 bg-black/40" @click="attemptClose()"></div>

{{-- Panel --}}
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[700px] lg:w-[820px]"
     @input="dirty = true" @change="dirty = true">

    {{-- Panel Header — 큐 14-4-4: 신규 등록 직후엔 "✓ 등록 완료" 배지로 시각 강화 --}}
    @php $justCreated = $justCreatedId !== null && $justCreatedId === $editingId; @endphp
    <div class="flex items-center justify-between border-b {{ $justCreated ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200' }} px-5 py-4">
        <div class="flex items-center gap-2">
            @if($justCreated)
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                등록 완료
            </span>
            @endif
            <div>
                <h2 class="text-base font-bold {{ $justCreated ? 'text-emerald-800' : 'text-gray-800' }}">
                    @if($justCreated)
                        편집 모드 — 다음 단계 진행
                    @else
                        {{ $editingId ? '차량 수정' : '차량 등록' }}
                    @endif
                </h2>
                @if($vehicle_number)
                <p class="text-xs {{ $justCreated ? 'text-emerald-600' : 'text-gray-400' }} font-mono mt-0.5">{{ $vehicle_number }}</p>
                @endif
            </div>
        </div>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 큐 2번 — 1대 흐름도 스트립 (편집 모드에서만). 큐 6 H13 — warn/pending/progress에 reason tooltip. --}}
    @if($this->progressFlow)
    <div class="border-b border-gray-100 bg-gray-50/50 px-5 py-3">
        <div class="overflow-x-auto">
            <div class="flex min-w-max items-center gap-1">
                @foreach($this->progressFlow as $i => $node)
                @php
                    $statusBadge = match($node['status']) {
                        'done'     => ['icon' => '✓', 'cls' => 'bg-green-100 text-green-700 border-green-300'],
                        'warn'     => ['icon' => '!', 'cls' => 'bg-amber-100 text-amber-700 border-amber-300'],
                        'progress' => ['icon' => '⋯', 'cls' => 'bg-blue-100 text-blue-700 border-blue-300'],
                        'pending'  => ['icon' => '-', 'cls' => 'bg-gray-100 text-gray-500 border-gray-200'],
                        'disabled' => ['icon' => '×', 'cls' => 'bg-gray-50 text-gray-300 border-gray-100'],
                    };
                @endphp
                {{-- tooltip은 native title만 사용 — 부모 overflow-x-auto가 absolute 자식 y-axis 클리핑 발동 --}}
                <button type="button" @click="tab = '{{ $node['tab'] }}'"
                    @if(!empty($node['reason'])) title="{{ $node['reason'] }}" @endif
                    class="flex flex-shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs transition hover:brightness-95 {{ $statusBadge['cls'] }}">
                    <span class="font-bold">{{ $statusBadge['icon'] }}</span>
                    <span class="font-medium">{{ $node['label'] }}</span>
                </button>
                @if($i < count($this->progressFlow) - 1)
                <span class="flex-shrink-0 text-gray-300" aria-hidden="true">›</span>
                @endif
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- 큐 21 — Ledger 잠금 배너 (회의록 2026-05-18). 회계 영향 컬럼 21개(매입가/판매가/환율/면장금액/비용9개/관련 5컬럼/바이어/담당자) 잠금 표시. --}}
    @if($isLedgerLocked)
    <div class="border-b {{ $hasLedgerUnlockToken ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-gray-50' }} px-5 py-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-start gap-2">
                <span class="text-base leading-none">{{ $hasLedgerUnlockToken ? '🔓' : '🔒' }}</span>
                <div class="text-xs">
                    @if($hasLedgerUnlockToken)
                    <p class="font-semibold text-amber-800">잠금 해제됨 — 저장 1회 후 자동 재잠금</p>
                    <p class="mt-0.5 text-amber-700">매입가·판매가·환율·면장금액·비용·바이어·담당자 변경 가능. 저장하면 즉시 잠김.</p>
                    @else
                    <p class="font-semibold text-gray-700">재무 확정 잔금 있음 — 회계 영향 컬럼 잠김</p>
                    <p class="mt-0.5 text-gray-500">매입가·판매가·환율·면장금액·비용9개·바이어·담당자 변경 불가. admin/super가 사유 입력 후 해제 가능.</p>
                    @endif
                </div>
            </div>
            @if(! $hasLedgerUnlockToken && auth()->user()?->canAccessAdmin())
            <button type="button" wire:click="openLedgerUnlockModal"
                    class="flex-shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                🔓 잠금 해제
            </button>
            @endif
        </div>
    </div>
    @endif

    {{-- Validation 에러 박스 (guard 메서드 throw + Livewire validate 모두 표시) --}}
    @if($errors->any())
    <div class="border-b border-red-200 bg-red-50 px-5 py-3">
        <div class="flex items-start gap-2">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1">
                <p class="text-xs font-semibold text-red-700">저장할 수 없습니다 — 아래 항목을 확인하세요</p>
                <ul class="mt-1 space-y-0.5 text-xs text-red-600">
                    @foreach($errors->all() as $msg)
                    <li>· {{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endif

    {{-- Tab Nav --}}
    {{-- 회의확장씬 #1 v4 (2026-05-21) — 워크플로우 순서 swap: 선적 → 수출통관 --}}
    <div class="flex overflow-x-auto border-b border-gray-200 px-5">
        @foreach([
            ['basic',    '기본정보'],
            ['purchase', '매입'],
            ['sale',     '판매'],
            ['bl',       '선적(B/L)'],
            ['clearance','수출통관'],
            ['dhl',      'DHL'],
            ['docs',     '서류'],
        ] as [$key, $label])
        <button @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'border-b-2 border-violet-600 text-violet-600' : 'text-gray-500 hover:text-gray-700'"
                class="flex-shrink-0 px-4 py-3 text-sm font-medium transition">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- Tab Content --}}
    <div class="flex-1 overflow-y-auto px-5 py-5">
        <div>

        {{-- ─── 기본정보 탭 ─────────────────────────────── --}}
        <div x-show="tab === 'basic'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">기본 정보</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="col-span-2 sm:col-span-1">
                    <label class="label-base">
                        차량번호 <span class="text-red-500">*</span>
                        @if($editingId)
                        <span class="ml-1 text-[10px] font-normal text-gray-400">(편집 모드 — 변경 불가)</span>
                        @endif
                    </label>
                    <div class="flex gap-1">
                        @if($editingId)
                            {{-- 편집 모드: 차량번호 readonly. 식별의 핵심이라 변경 차단(차량번호 변경 필요 시 별도 액션). --}}
                            <input wire:model="vehicle_number" type="text"
                                   class="input-base flex-1 bg-gray-100 text-gray-600 cursor-not-allowed"
                                   placeholder="12가3456" readonly />
                        @else
                            <input wire:model="vehicle_number" type="text" class="input-base flex-1" placeholder="12가3456"
                                   wire:blur="lookupNiceApi" />
                            <button type="button" wire:click="lookupNiceApi"
                                    wire:loading.attr="disabled" wire:target="lookupNiceApi"
                                    class="rounded-lg border border-gray-300 px-2 py-2 text-xs text-gray-600 hover:bg-gray-50 whitespace-nowrap disabled:opacity-50">
                                <span wire:loading.remove wire:target="lookupNiceApi">조회</span>
                                <span wire:loading wire:target="lookupNiceApi">조회중…</span>
                            </button>
                        @endif
                    </div>
                    @unless($editingId)
                        {{-- NICE 1단계가 소유자명 필수 → 차량번호와 함께 입력. nice_reg_owner_name 에 바인딩(아래 등록정보 소유자명과 동기화). --}}
                        <input wire:model="nice_reg_owner_name" type="text" class="input-base mt-1 w-full"
                               placeholder="소유자명 (NICE 조회 필수)" wire:blur="lookupNiceApi" />
                    @endunless
                    @error('vehicle_number')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                {{-- 큐 16 — 판매채널 select 제거. sales_channel은 hidden 'export' 고정. --}}
                <input type="hidden" wire:model="sales_channel" />
                {{-- 큐 17 — 폐기 체크박스 제거 (운영상 폐기 없음). --}}
                <div>
                    <label class="label-base">제조사</label>
                    <input wire:model="brand" type="text" class="input-base" placeholder="현대" />
                </div>
                <div>
                    <label class="label-base">차종</label>
                    <input wire:model="model_type" type="text" class="input-base" placeholder="쏘나타" />
                </div>
                <div>
                    <label class="label-base">연식</label>
                    <input wire:model="year_str" type="number" class="input-base" placeholder="2020" />
                </div>
                <div>
                    <label class="label-base">배기량 (cc)</label>
                    <input wire:model="cc_str" type="number" class="input-base" placeholder="1991" />
                </div>
                <div>
                    <label class="label-base">중량 (kg)</label>
                    <input wire:model="weight_kg_str" type="number" class="input-base" placeholder="1470" />
                </div>
                <div>
                    <label class="label-base">주행거리 (km)</label>
                    <input wire:model="mileage_str" type="number" class="input-base" placeholder="85000" />
                </div>
                <div>
                    <label class="label-base">색상</label>
                    <input wire:model="color" type="text" class="input-base" placeholder="흰색" />
                </div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-400"></span>
                <span class="section-title">NICE 등록정보 (12)</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">차대번호</label><input wire:model="nice_reg_vin" type="text" class="input-base" /></div>
                <div><label class="label-base">원동기형식</label><input wire:model="nice_reg_engine_no" type="text" class="input-base" /></div>
                <div><label class="label-base">연료종류</label><input wire:model="nice_reg_fuel_type" type="text" class="input-base" placeholder="가솔린" /></div>
                <div><label class="label-base">용도</label><input wire:model="nice_reg_use_type" type="text" class="input-base" placeholder="자가용" /></div>
                <div><label class="label-base">차체형상</label><input wire:model="nice_reg_vehicle_form" type="text" class="input-base" /></div>
                <div><label class="label-base">최초등록일</label><input wire:model="nice_reg_first_date" type="date" class="input-base" /></div>
                <div><label class="label-base">등록일</label><input wire:model="nice_reg_date" type="date" class="input-base" /></div>
                <div><label class="label-base">소유자명</label><input wire:model="nice_reg_owner_name" type="text" class="input-base" /></div>
                <div x-data="{ show: false }">
                    <label class="label-base">소유자 주민(법인)등록번호</label>
                    <div class="relative">
                        {{-- UX #4 (2026-05-20) — Alpine x-on:input + $store.rrnMask 로 자동 mask. wire:model.blur 로 blur 시점 sync. --}}
                        <input wire:model.blur="nice_reg_owner_rrn" :type="show ? 'text' : 'password'"
                               x-on:input="$el.value = $store.rrnMask.apply($el.value)"
                               class="input-base pr-10 font-mono" placeholder="000000-0000000" autocomplete="off" maxlength="14" />
                        <button type="button" @click="show = !show"
                                class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-gray-400 hover:text-gray-600"
                                :title="show ? '숨기기' : '표시'">
                            <svg x-show="!show" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <svg x-show="show" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        </button>
                    </div>
                </div>
                <div><label class="label-base">최대적재량 (kg)</label><input wire:model="nice_reg_max_load_str" type="number" class="input-base" /></div>
                <div><label class="label-base">승차인원</label><input wire:model="nice_reg_passengers_str" type="number" class="input-base" /></div>
                <div><label class="label-base">차량 색상</label><input wire:model="nice_reg_color" type="text" class="input-base" /></div>
                <div class="col-span-2 sm:col-span-1"><label class="label-base">소유자주소</label><input wire:model="nice_reg_owner_addr" type="text" class="input-base" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-sky-400"></span>
                <span class="section-title">NICE 제원정보 (12)</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">제조사</label><input wire:model="nice_spec_maker" type="text" class="input-base" /></div>
                <div><label class="label-base">모델명</label><input wire:model="nice_spec_model" type="text" class="input-base" /></div>
                <div><label class="label-base">연식</label><input wire:model="nice_spec_year" type="text" class="input-base" /></div>
                <div><label class="label-base">배기량 (cc)</label><input wire:model="nice_spec_displacement_str" type="number" class="input-base" /></div>
                <div><label class="label-base">변속기</label><input wire:model="nice_spec_transmission" type="text" class="input-base" placeholder="자동" /></div>
                <div><label class="label-base">구동방식</label><input wire:model="nice_spec_drive_type" type="text" class="input-base" placeholder="2WD" /></div>
                <div><label class="label-base">전장 (mm)</label><input wire:model="nice_spec_length_str" type="number" class="input-base" /></div>
                <div><label class="label-base">전폭 (mm)</label><input wire:model="nice_spec_width_str" type="number" class="input-base" /></div>
                <div><label class="label-base">전고 (mm)</label><input wire:model="nice_spec_height_str" type="number" class="input-base" /></div>
                <div><label class="label-base">축거 (mm)</label><input wire:model="nice_spec_wheelbase_str" type="number" class="input-base" /></div>
                <div><label class="label-base">공차중량 (kg)</label><input wire:model="nice_spec_curb_weight_str" type="number" class="input-base" /></div>
                <div><label class="label-base">연비 (km/L)</label><input wire:model="nice_spec_fuel_efficiency" type="text" class="input-base" /></div>
            </div>

            {{-- 차량 사진 (jpg/png · 최대 10장 — vehicle_photos, 운영 시 S3 저장). 여러 장은 한 번에 선택. --}}
            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-rose-400"></span>
                <span class="section-title">차량 사진 (jpg/png · 최대 10장)</span>
            </div>
            <div>
                <input type="file" wire:model="photoFiles" multiple accept="image/jpeg,image/png"
                       class="input-base text-sm" />
                <div wire:loading wire:target="photoFiles" class="mt-1 text-xs text-gray-400">사진 업로드 중…</div>
                @error('photoFiles')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                @error('photoFiles.*')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror

                @if(count($existingPhotos) || count($photoFiles))
                <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                    @foreach($existingPhotos as $p)
                    <div class="relative aspect-square overflow-hidden rounded border border-gray-200">
                        <img src="{{ $p['url'] }}" class="h-full w-full object-cover" alt="차량 사진" />
                        <button type="button" wire:click="removeExistingPhoto({{ $p['id'] }})"
                                class="absolute right-1 top-1 rounded-full bg-black/60 px-1.5 text-xs leading-none text-white hover:bg-red-600">×</button>
                    </div>
                    @endforeach
                    @foreach($photoFiles as $idx => $photo)
                    <div class="relative aspect-square overflow-hidden rounded border border-violet-300">
                        <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-cover" alt="신규 사진" />
                        <span class="absolute left-1 top-1 rounded bg-violet-600 px-1 text-[10px] text-white">신규</span>
                        <button type="button" wire:click="removeNewPhoto({{ $idx }})"
                                class="absolute right-1 top-1 rounded-full bg-black/60 px-1.5 text-xs leading-none text-white hover:bg-red-600">×</button>
                    </div>
                    @endforeach
                </div>
                @endif
                <p class="mt-1 text-xs text-gray-400">{{ count($existingPhotos) + count($photoFiles) }}/10장 · 저장 버튼을 눌러야 반영됩니다.</p>
            </div>
        </div>

        {{-- ─── 매입 탭 ──────────────────────────────────── --}}
        <div x-show="tab === 'purchase'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">매입 기본</span>
            </div>
            {{-- UX #1 (2026-05-20) — 매입 필수 입력란 노랑 배경. 영업이 입력 누락 방지. --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">매입일 </label><input wire:model="purchase_date" type="date" class="input-base input-required" /></div>
                <div>
                    <label class="label-base">매입담당자</label>
                    <select wire:model="salesman_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->salesmen as $sm)
                        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="label-base">구입처 </label>
                    <input wire:model="purchase_from" type="text" class="input-base input-required" placeholder="경매 / 딜러 / 개인" />
                </div>
                <div><label class="label-base">매입가 (원) </label><input wire:model="purchase_price_str" type="text" class="input-base input-required" placeholder="0" /></div>
                <div><label class="label-base">매도비 (원) </label><input wire:model="selling_fee_str" type="text" class="input-base input-required" placeholder="0" /></div>
            </div>

            {{-- 큐 20-A/C — 매입처 계좌 4컬럼 (계좌번호 자동 암호화 + AuditLog 마스킹) --}}
            {{-- UX #5 (2026-05-20) — 은행명 datalist 자동완성 (13개) + 계좌번호 동적 mask ($store.koreanBanks) --}}
            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-400"></span>
                <span class="section-title">매입처 계좌 정보 (송금 대상)</span>
            </div>
            <div x-data class="grid grid-cols-2 gap-3 sm:grid-cols-2">
                <div>
                    <label class="label-base">은행명 </label>
                    <input x-ref="bankInput" wire:model.blur="purchase_seller_bank" type="text" list="korean-banks-list"
                           class="input-base input-required" placeholder="국민은행 / 신한은행 / 우리은행 등" maxlength="100" autocomplete="off"
                           x-on:input="$refs.accountInput.value = $store.koreanBanks.applyMask($el.value, $refs.accountInput.value)" />
                    <datalist id="korean-banks-list">
                        <template x-for="bank in $store.koreanBanks.names()" :key="bank">
                            <option :value="bank"></option>
                        </template>
                    </datalist>
                </div>
                <div>
                    <label class="label-base">예금주 </label>
                    <input wire:model="purchase_seller_holder" type="text" class="input-base input-required" placeholder="개인명 또는 법인명" maxlength="100" />
                </div>
                <div class="col-span-2">
                    <label class="label-base flex items-center gap-1">
                        계좌번호                        <span class="text-[10px] font-normal text-violet-600">🔒 암호화 저장</span>
                        <span class="text-[10px] font-normal text-gray-400">— 은행 선택 시 자동 hyphen</span>
                    </label>
                    <input x-ref="accountInput" wire:model.blur="purchase_seller_account" type="text"
                           class="input-base input-required font-mono" placeholder="123-456-789012" autocomplete="off"
                           x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankInput.value, $el.value)" />
                </div>
                <div class="col-span-2">
                    <label class="label-base">계좌 메모</label>
                    <textarea wire:model="purchase_bank_memo" class="input-base" rows="2" placeholder="송금 시 참고할 내용 (선택)"></textarea>
                </div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-300"></span>
                <span class="section-title">비용 9개</span>
            </div>

            {{-- 회의확장씬 #9 보강 안내 (2026-05-23) — 2차 정산 대기 동안 실측 정정 가이드 --}}
            @php
                $hasSecondaryPending = $editingId && \App\Models\Settlement::where('vehicle_id', $editingId)
                    ->where('settlement_status', 'paid')
                    ->where('secondary_status', 'pending')
                    ->exists();
                $canEditCostNow = auth()->user()?->isAdmin()
                    || in_array(auth()->user()?->role, ['재무', '관리'], true);
            @endphp
            @if($hasSecondaryPending && $canEditCostNow)
            <div class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                <div class="font-semibold">⏳ 2차 정산 대기 — 실측 정정 가능</div>
                <div class="mt-0.5 text-amber-700">
                    한 달 운영 후 청구된 실제 비용으로 수정하세요.
                    저장 시 <strong>정산 화면의 총마진·정산액·실지급액이 자동 갱신</strong>됩니다.
                </div>
            </div>
            @endif

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">말소비</label><input wire:model="cost_deregistration_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">면허비</label><input wire:model="cost_license_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">탁송비</label><input wire:model="cost_towing_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">캐리비</label><input wire:model="cost_carry_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">쇼링비</label><input wire:model="cost_shoring_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">보험료</label><input wire:model="cost_insurance_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">이전비</label><input wire:model="cost_transfer_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">기타비1</label><input wire:model="cost_extra1_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">기타비2</label><input wire:model="cost_extra2_str" type="text" class="input-base" placeholder="0" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-indigo-400"></span>
                <span class="section-title">매입 지급</span>
            </div>
            {{-- 큐 22-C 핵심 (2026-05-20) — 자금 영역 권한 분기. 영업은 read-only, 재무·admin 만 입력. SoD 회의록 정합. --}}
            @php $canConfirmFinance = auth()->user()?->canConfirmFinance() ?? false; @endphp
            @unless($canConfirmFinance)
            <div class="mb-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] text-blue-800">
                <strong>매입 자금 영역</strong>은 재무·관리자(admin)만 입력. 영업은 매입가·계좌 입력 시 자동 매입 잔금 Draft 가 생성되며, 재무가 <code>/erp/transfers</code> 매입 잔금 탭에서 확정합니다.
            </div>
            @endunless
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="label-base">계약금 지급</label>
                    <input wire:model="down_payment_str" type="text"
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canConfirmFinance) disabled @endif />
                </div>
                <div>
                    <label class="label-base">매도비 지급</label>
                    <input wire:model="selling_fee_payment_str" type="text"
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canConfirmFinance) disabled @endif />
                </div>
            </div>
            {{-- 잔금 N건 --}}
            <div class="mt-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">잔금</span>
                    @if($canConfirmFinance)
                    <button type="button" wire:click="addPurchasePayment" class="text-xs text-violet-600 hover:underline">+ 추가</button>
                    @else
                    <span class="text-[10px] text-gray-400">재무·admin 만 추가 가능</span>
                    @endif
                </div>
                @foreach($purchaseBalancePayments as $idx => $row)
                @php
                    // 큐 20-C — confirmed_at 유무로 분자 A안 ledger 반영 상태 시각화
                    $isPbpConfirmed = !empty($row['confirmed_at']);
                    $pbpRowBg = $isPbpConfirmed ? 'bg-emerald-50/40 border-emerald-200' : (!empty($row['id']) ? 'bg-amber-50/40 border-amber-200' : 'border-transparent');
                @endphp
                <div class="flex gap-2 items-center rounded border px-2 py-1 {{ $pbpRowBg }}">
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.amount" type="text"
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           style="width: 96px; flex: none;"
                           placeholder="금액(원)" @if(!$canConfirmFinance) disabled @endif />
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.payment_date" type="date"
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           style="width: 112px; flex: none;"
                           @if(!$canConfirmFinance) disabled @endif />
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.note" type="text"
                           class="input-base flex-1 {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           style="min-width: 0;"
                           placeholder="비고" @if(!$canConfirmFinance) disabled @endif />
                    @if(!empty($row['id']))
                        @if($isPbpConfirmed)
                        <span class="text-[10px] font-semibold text-emerald-700 whitespace-nowrap"
                              title="재무 확정: {{ $row['confirmed_at'] }} ({{ $row['finance_confirmer'] ?? '?' }})">
                            ✓ 확정
                        </span>
                        @else
                        <span class="text-[10px] font-semibold text-amber-700 whitespace-nowrap"
                              title="재무 확정 대기 — ledger 미반영">
                            ⏳ 대기
                        </span>
                        @endif
                    @endif
                    @if($canConfirmFinance)
                    <button type="button" wire:click="removePurchasePayment({{ $idx }})" class="text-red-400 hover:text-red-600">×</button>
                    @endif
                </div>
                @endforeach
            </div>

            <hr class="section-divider">
            <div class="grid grid-cols-2 gap-3">
                {{-- 2026-05-19 풀회의 안건 C — 말소 [everyone]. 재무 role 제외 (canHandleDeregistration). --}}
                @if(auth()->user()->canHandleDeregistration())
                <div>
                    <label class="label-base">말소완료</label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer mt-1">
                        <input wire:model="is_deregistered" type="checkbox" class="rounded" /> 말소완료
                    </label>
                </div>
                <div>
                    <label class="label-base">말소신청서</label>
                    <input wire:model="deregistrationDocFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-violet-50 file:px-2 file:py-1 file:text-xs file:text-violet-700" />
                    @if($deregistration_document_path)
                    <div class="mt-1 flex items-center gap-3">
                        <a href="{{ \App\Support\VehicleDocUrl::for($deregistration_document_path) }}" target="_blank"
                           class="text-xs text-violet-600 hover:underline">기존 파일 보기</a>
                        <button type="button" wire:click="removeDeregistrationDoc"
                                class="text-xs text-red-500 hover:underline">삭제</button>
                    </div>
                    @endif
                </div>
                @endif
                <div class="col-span-2">
                    <label class="label-base">송금메모</label>
                    <textarea wire:model="purchase_remittance_memo" class="input-base" rows="2"></textarea>
                </div>
            </div>
        </div>

        {{-- ─── 판매 탭 ──────────────────────────────────── --}}
        <div x-show="tab === 'sale'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-purple-500"></span>
                <span class="section-title">판매 기본</span>
            </div>

            {{-- 큐 14-4-4 — 같은 바이어 미수 잔존 안내 배너 (신규 등록 + 비-canApprove user만, 5상태) --}}
            @php
                $overlap = $this->sameBuyerOverlap;
                $state = $overlap['state'] ?? null;
                $bannerColor = match($state) {
                    'approved_match' => ['border' => 'border-emerald-200', 'bg' => 'bg-emerald-50', 'title' => 'text-emerald-800', 'body' => 'text-emerald-700', 'icon' => 'text-emerald-600'],
                    'rejected' => ['border' => 'border-red-200', 'bg' => 'bg-red-50', 'title' => 'text-red-800', 'body' => 'text-red-700', 'icon' => 'text-red-600'],
                    default => ['border' => 'border-amber-200', 'bg' => 'bg-amber-50', 'title' => 'text-amber-800', 'body' => 'text-amber-700', 'icon' => 'text-amber-600'],
                };
            @endphp
            @if($overlap)
            <div class="rounded-lg border {{ $bannerColor['border'] }} {{ $bannerColor['bg'] }} px-3 py-2.5 text-xs">
                <div class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 flex-shrink-0 {{ $bannerColor['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <div class="flex-1">
                        <p class="font-semibold {{ $bannerColor['title'] }}">
                            이 바이어 미수 차량 {{ $overlap['count'] }}대 (₩{{ number_format($overlap['amount_krw']) }})
                            @if(! empty($overlap['vehicle_numbers']))
                            <span class="font-normal {{ $bannerColor['body'] }}"> · {{ implode(', ', $overlap['vehicle_numbers']) }}</span>
                            @endif
                        </p>

                        @if($state === 'approved_match')
                        <p class="mt-1 {{ $bannerColor['body'] }}">✓ 관리자 승인 받음 (차량번호 <strong>{{ $overlap['current_vehicle_number'] }}</strong>). 저장하면 신규 차량 등록됩니다 (이번 1회 한정).</p>

                        @elseif($state === 'pending')
                        <p class="mt-1 {{ $bannerColor['body'] }}">⏳ 차량번호 <strong>{{ $overlap['current_vehicle_number'] }}</strong> 관리자 승인 대기중. 승인 후 저장 가능합니다.</p>

                        @elseif($state === 'approved_mismatch')
                        <p class="mt-1 {{ $bannerColor['body'] }}">
                            현재 승인된 차량번호는 <strong>{{ $overlap['approved_vehicle_number'] }}</strong>입니다.
                            @if($overlap['current_vehicle_number'])
                            지금 입력한 <strong>{{ $overlap['current_vehicle_number'] }}</strong>로는 저장 차단.
                            @endif
                            차량번호를 일치시키거나 새 승인 요청을 보내세요.
                        </p>
                        <button type="button" wire:click="openOverlapRequestModal"
                                class="mt-2 rounded bg-amber-500 px-3 py-1 text-xs font-medium text-white hover:bg-amber-600">
                            새 차량번호로 승인 요청
                        </button>

                        @elseif($state === 'rejected')
                        <p class="mt-1 {{ $bannerColor['body'] }}">
                            ✗ 차량번호 <strong>{{ $overlap['current_vehicle_number'] }}</strong> 승인 요청이 <strong>거부됨</strong>.
                        </p>
                        @if($overlap['rejected_reason'])
                        <p class="mt-1 text-[11px] text-red-600">요청 사유: {{ $overlap['rejected_reason'] }}</p>
                        @endif
                        @if($overlap['rejected_note'])
                        <p class="mt-1 text-[11px] text-red-600">거부 사유: <strong>{{ $overlap['rejected_note'] }}</strong></p>
                        @endif
                        <button type="button" wire:click="openOverlapRequestModal"
                                class="mt-2 rounded bg-red-500 px-3 py-1 text-xs font-medium text-white hover:bg-red-600">
                            새 요청 다시 보내기
                        </button>

                        @else
                        {{-- nothing — 요청 가능 --}}
                        <p class="mt-1 {{ $bannerColor['body'] }}">신규 거래는 관리자 승인이 필요합니다. 승인은 (바이어 × 차량번호) 단위로 잠깁니다.</p>
                        <button type="button" wire:click="openOverlapRequestModal"
                                class="mt-2 rounded bg-amber-500 px-3 py-1 text-xs font-medium text-white hover:bg-amber-600">
                            신규 거래 승인 요청
                        </button>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            {{-- UX #1 (2026-05-20) — 판매 필수 입력란 노랑 배경 (KRW 환율은 자동 1 normalize 라 강조 X). --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">판매일 </label><input wire:model="sale_date" type="date" class="input-base input-required" /></div>
                <div>
                    <label class="label-base">통화 </label>
                    <select wire:model.live="currency" class="input-base input-required">
                        @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $cur)
                        <option value="{{ $cur }}">{{ $cur }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">환율 @if($currency !== 'KRW')  @endif</label><input wire:model="exchange_rate_str" type="text" class="input-base {{ $currency !== 'KRW' ? 'input-required' : '' }}" placeholder="1350" /></div>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">바이어 </label>
                        <button type="button" wire:click="openQuickAdd('buyer','sale')"
                                class="mb-1 text-[11px] text-primary-text hover:underline">+ 신규</button>
                    </div>
                    <select wire:model.live="buyer_id_str" class="input-base input-required">
                        <option value="">-- 선택 --</option>
                        @foreach($this->buyers as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">컨사이니 </label>
                        <button type="button" wire:click="openQuickAdd('consignee','sale')"
                                @if($buyer_id_str === '') disabled @endif
                                class="mb-1 text-[11px] text-primary-text hover:underline disabled:cursor-not-allowed disabled:text-gray-300 disabled:no-underline">+ 신규</button>
                    </div>
                    <select wire:model="consignee_id_str" class="input-base input-required"
                            @if($buyer_id_str === '') disabled @endif>
                        <option value="">{{ $buyer_id_str ? '-- 선택 --' : '바이어 먼저 선택' }}</option>
                        @foreach($this->consigneesForSale as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">판매가 </label><input wire:model="sale_price_str" type="text" class="input-base input-required" placeholder="0" /></div>
                <div><label class="label-base">TAX D/C</label><input wire:model="tax_dc_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">Commission</label><input wire:model="commission_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">운임비</label><input wire:model="transport_fee_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">자동하역비</label><input wire:model="auto_loading_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">기타 판매비용</label><input wire:model="sale_other_costs_str" type="text" class="input-base" placeholder="0" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-purple-300"></span>
                <span class="section-title">입금 현황</span>
            </div>
            {{-- 22-A-3a 사용자 정정 (2026-05-20) — 4 항목 (계약금/중도금/선수금) 입력 권한: 재무·관리·admin. 영업·수출통관은 disabled. --}}
            @php $canManagePayBreakdown = auth()->user()?->canManagePaymentBreakdown() ?? false; @endphp
            <div class="mb-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] text-blue-800">
                <strong>4 항목 (계약금·중도금·선수금)</strong>은 재무·관리자만 입력. 잔금 추가는 영업이 아래 [잔금] N+ 에서 Draft → 재무가 transfers 에서 확정.
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="label-base">계약금 입금</label>
                    <input wire:model="deposit_down_payment_str" type="text"
                           class="input-base {{ $canManagePayBreakdown ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canManagePayBreakdown) disabled @endif />
                </div>
                <div>
                    <label class="label-base">중도금</label>
                    <input wire:model="interim_payment_str" type="text"
                           class="input-base {{ $canManagePayBreakdown ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canManagePayBreakdown) disabled @endif />
                </div>
                <div>
                    <label class="label-base">선수금1</label>
                    <input wire:model="advance_payment1_str" type="text"
                           class="input-base {{ $canManagePayBreakdown ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canManagePayBreakdown) disabled @endif />
                </div>
                <div>
                    <label class="label-base">선수금2</label>
                    <input wire:model="advance_payment2_str" type="text"
                           class="input-base {{ $canManagePayBreakdown ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canManagePayBreakdown) disabled @endif />
                </div>
                <div><label class="label-base">적립금 사용</label><input wire:model="savings_used_str" type="text" class="input-base" placeholder="0" /></div>
                {{-- 회의확장씬 #12 (2026-05-22) — 적립금 적립 입력 + 누적 표시 --}}
                @php $canConfirmFinanceLocal = auth()->user()?->canConfirmFinance() ?? false; @endphp
                <div>
                    <label class="label-base">적립금 적립 <span class="text-[10px] text-gray-400">(저장 시 누적, reset)</span></label>
                    <input wire:model="savings_deposit_str" type="text"
                           class="input-base {{ $canConfirmFinanceLocal ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canConfirmFinanceLocal) disabled @endif />
                </div>
                <div>
                    <label class="label-base">누적 적립금 <span class="text-[10px] text-gray-400">(바이어×통화 SavingsStatus)</span></label>
                    <div class="input-base bg-gray-50 text-gray-700">
                        @if($this->buyerSavingsBalance === null)
                            —
                        @else
                            {{ $currency }} {{ number_format($this->buyerSavingsBalance) }}
                        @endif
                    </div>
                </div>
                <div>
                    <label class="label-base">미납률 <span class="text-[10px] text-gray-400">(저장 후 갱신)</span></label>
                    @if($panelUnpaidRatio === null)
                        <div class="input-base bg-gray-50 text-gray-400">—</div>
                    @elseif($panelUnpaidRatio <= 0)
                        <div class="input-base bg-emerald-50 text-emerald-700 font-medium">✓ 완납</div>
                    @else
                        <div class="input-base bg-gray-50 font-medium text-gray-800">{{ number_format($panelUnpaidRatio * 100, 1) }}%</div>
                    @endif
                </div>
            </div>
            {{-- 잔금 N건 --}}
            <div class="mt-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">잔금</span>
                    <button type="button" wire:click="addFinalPayment" class="text-xs text-violet-600 hover:underline">+ 추가</button>
                </div>
                @foreach($finalPayments as $idx => $row)
                @if(!empty($row['transfer']))
                {{-- 큐 19-C — 차량 간 자금 이체로 자동 생성된 잔금 (append-only). --}}
                @php
                    $tStatus = $row['transfer']['status'] ?? null;
                    $isVoided = $tStatus === \App\Models\InterVehicleTransfer::STATUS_VOIDED;
                    $pendingVoid = !empty($row['transfer']['pending_void']);
                    // 박스 배경: voided 회색 / pending_void amber / 일반 violet
                    $boxClass = $isVoided
                        ? 'bg-gray-100 border-gray-300'
                        : ($pendingVoid ? 'bg-amber-50 border-amber-300' : 'bg-violet-50 border-violet-200');
                    $textMutedClass = $isVoided ? 'text-gray-500' : ($pendingVoid ? 'text-amber-800' : 'text-violet-800');
                    $textMetaClass = $isVoided ? 'text-gray-400' : ($pendingVoid ? 'text-amber-600' : 'text-violet-600');
                @endphp
                <div class="flex gap-2 items-center rounded {{ $boxClass }} px-2 py-1.5 border">
                    <span class="text-xs">{{ $isVoided ? '⊘' : ($pendingVoid ? '⏳' : '🔁') }}</span>
                    <span class="w-24 text-sm font-semibold {{ $isVoided ? 'text-gray-500 line-through' : ($row['transfer']['direction'] === 'outgoing' ? 'text-red-600' : 'text-emerald-700') }}">
                        {{ number_format((float)$row['amount']) }} {{ $row['transfer']['currency'] }}
                    </span>
                    <span class="flex-1 text-xs {{ $textMutedClass }}">
                        @if($row['transfer']['direction'] === 'outgoing')
                            → 차량 <span class="font-mono">{{ $row['transfer']['counterpart_number'] ?? '#'.$row['transfer']['counterpart_id'] }}</span> 으로 이체
                        @else
                            ← 차량 <span class="font-mono">{{ $row['transfer']['counterpart_number'] ?? '#'.$row['transfer']['counterpart_id'] }}</span> 에서 이체
                        @endif
                        @if($isVoided)
                            <span class="ml-1 text-[10px] text-gray-500">(취소됨)</span>
                        @elseif($pendingVoid)
                            <span class="ml-1 text-[10px] font-semibold text-amber-700">(취소 승인 대기중)</span>
                        @endif
                    </span>
                    <span class="text-xs {{ $textMetaClass }} whitespace-nowrap">
                        {{ $row['payment_date'] ?: '-' }}
                        · 승인 #{{ $row['transfer']['approval_request_id'] }}
                    </span>
                    @if($pendingVoid)
                    <span class="text-[11px] text-amber-600 whitespace-nowrap">취소 요청 중</span>
                    @elseif(!$isVoided && !empty($row['transfer']['can_void']))
                    <button type="button" wire:click="openTransferVoidModal({{ $row['transfer']['id'] }})"
                            class="text-[11px] text-red-500 hover:underline whitespace-nowrap">
                        이체 취소 요청
                    </button>
                    @endif
                </div>
                @elseif(!empty($row['locked']))
                <div class="flex gap-2 items-center rounded bg-gray-50 px-2 py-1.5 border border-gray-200">
                    <span class="text-xs text-gray-400">🔒</span>
                    <span class="w-24 text-sm text-gray-600">{{ number_format((float)$row['amount']) }}</span>
                    <span class="w-28 text-sm text-gray-600">{{ $row['payment_date'] ?: '-' }}</span>
                    <span class="flex-1 min-w-0 text-xs text-gray-400 truncate" title="{{ $row['note'] ?: '' }}">{{ $row['note'] ?: '' }}</span>
                    <a href="{{ route('erp.receivables.index') }}" wire:navigate
                       class="text-xs text-violet-500 hover:underline whitespace-nowrap">채권관리에서 수정</a>
                </div>
                @else
                @php
                    // 큐 20-C — confirmed_at 유무로 분자 A안 ledger 반영 상태 시각화
                    $isConfirmed = !empty($row['confirmed_at']);
                    $rowBg = $isConfirmed ? 'bg-emerald-50/40 border-emerald-200' : ($row['id'] ? 'bg-amber-50/40 border-amber-200' : 'border-transparent');
                @endphp
                <div class="flex gap-2 items-center rounded border px-2 py-1 {{ $rowBg }}">
                    <input wire:model="finalPayments.{{ $idx }}.amount" type="text" class="input-base"
                           style="width: 96px; flex: none;" placeholder="금액" />
                    {{-- 회의확장씬 #7 (2026-05-22) — 잔금 row 별 환율 (외화만, 자동 기입 + 수정 가능) --}}
                    @if($currency !== 'KRW')
                    <input wire:model="finalPayments.{{ $idx }}.exchange_rate" type="text" class="input-base"
                           style="width: 80px; flex: none;" placeholder="환율" title="입금 시점 환율" />
                    @php
                        $rowAmt = (float) str_replace(',', '', $row['amount'] ?? '0');
                        $rowRate = (float) str_replace(',', '', $row['exchange_rate'] ?? '0');
                        $rowKrw = $rowAmt * $rowRate;
                    @endphp
                    {{-- 회의확장씬 #6 보강 (2026-05-23) — KRW 환산 readonly input + DB 저장 (FinalPayment::saving 훅 자동 계산). --}}
                    <input type="text" class="input-base text-right"
                           style="width: 130px; flex: none; background-color: #f9fafb; color: #4b5563;"
                           readonly tabindex="-1"
                           value="{{ $rowAmt > 0 && $rowRate > 0 ? '₩'.number_format($rowKrw) : '' }}"
                           placeholder="₩0" title="KRW 환산 (저장됨)" />
                    @endif
                    <input wire:model="finalPayments.{{ $idx }}.payment_date" type="date" class="input-base"
                           style="width: 112px; flex: none;" />
                    <input wire:model="finalPayments.{{ $idx }}.note" type="text" class="input-base flex-1"
                           style="min-width: 0;" placeholder="비고" />
                    @if($row['id'])
                        @if($isConfirmed)
                        <span class="text-[10px] font-semibold text-emerald-700 whitespace-nowrap"
                              title="재무 확정: {{ $row['confirmed_at'] }} ({{ $row['finance_confirmer'] ?? '?' }})">
                            ✓ 확정
                        </span>
                        @else
                        <span class="text-[10px] font-semibold text-amber-700 whitespace-nowrap"
                              title="재무 확정 대기 — ledger 미반영">
                            ⏳ 대기
                        </span>
                        @endif
                    @endif
                    <button type="button" wire:click="removeFinalPayment({{ $idx }})" class="text-red-400 hover:text-red-600">×</button>
                </div>
                @endif
                @endforeach
            </div>

            {{-- 큐 16 — 카풀/헤이맨 계산서 섹션 제거 (5컬럼 drop과 동기). --}}

            {{-- 큐 19-C — 차량 간 자금 이체 (회의록 v5 §13) ──────────────── --}}
            @php $transferCtx = $this->transferContext; @endphp
            @if($editingId !== null)
            <div class="mt-5">
                <div class="section-header">
                    <span class="section-dot bg-violet-500"></span>
                    <span class="section-title">차량 간 자금 이체</span>
                </div>

                {{-- pending 자금 이체 요청 — amber 박스 (관리 승인 대기 중) --}}
                @if(!empty($transferCtx['pending']))
                <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                    <div class="flex items-center gap-2">
                        <span>⏳</span>
                        <strong>이체 요청 승인 대기 중</strong>
                    </div>
                    <div class="mt-1 space-y-0.5 text-amber-800">
                        <div>
                            대상 차량 <span class="font-mono">{{ $transferCtx['pending']['target_vehicle_number'] }}</span>
                            · 금액 <strong>{{ number_format($transferCtx['pending']['amount']) }}</strong> {{ $transferCtx['pending']['currency'] }}
                        </div>
                        <div class="text-[11px] text-amber-700">
                            요청일 {{ $transferCtx['pending']['created_at']?->format('Y-m-d H:i') }}
                            · 요청 #{{ $transferCtx['pending']['approval_request_id'] }}
                        </div>
                        @if($transferCtx['pending']['reason'])
                        <div class="text-[11px] text-amber-700">사유: "{{ $transferCtx['pending']['reason'] }}"</div>
                        @endif
                    </div>
                </div>
                @else
                    {{-- 최근 결정 상태 표시 (pending 없을 때만) — 큐 19-F 5상태 분기.
                         status=approved 인 경우 transfer.status 에 따라 박스 색·라벨 달라짐:
                           approved_awaiting_finance → 파랑 (관리 승인 — 재무 처리 대기)
                           executed                  → 에메랄드 (이체 완료, 재무 확정)
                           voided_awaiting_finance   → 앰버 (취소 승인 — 재무 처리 대기)
                           voided                    → 회색 (이체 취소됨) --}}
                    @if(!empty($transferCtx['lastDecided']))
                    @php
                        $ld = $transferCtx['lastDecided'];
                        $isVoid = ($ld['type'] ?? 'transfer') === 'void';
                        if ($isVoid) {
                            $ldKey = 'void:'.$ld['status'];
                            // 큐 19-L — void approved + finance 거부 케이스
                            if ($ld['status'] === 'approved' && ! empty($ld['void_finance_rejected_at'])) {
                                $ldKey = 'void:finance_rejected';
                            }
                        } else {
                            $ldKey = $ld['status'];
                            if ($ld['status'] === 'approved' && ! empty($ld['transfer_status'])) {
                                $ldKey = 'approved:'.$ld['transfer_status'];
                            }
                        }
                        $ldClass = match($ldKey) {
                            'approved:approved_awaiting_finance' => ['border-blue-200', 'bg-blue-50', 'text-blue-900', 'text-blue-800', 'text-blue-700', 'border-blue-100', '⏳', '관리 승인 — 재무 처리 대기 중', '승인 메모'],
                            'approved:executed' => ['border-emerald-200', 'bg-emerald-50', 'text-emerald-900', 'text-emerald-800', 'text-emerald-700', 'border-emerald-100', '✓', '이체 완료 (재무 확정)', '승인 메모'],
                            'approved:voided_awaiting_finance' => ['border-amber-200', 'bg-amber-50', 'text-amber-900', 'text-amber-800', 'text-amber-700', 'border-amber-100', '⏳', '이체 취소 승인 — 재무 처리 대기 중', '승인 메모'],
                            'approved:voided' => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', '⊘', '이체 취소 완료', '승인 메모'],
                            // 큐 19-K — 재무 정방향 거부 (관리는 승인했지만 재무가 송금 불가 사유로 거부)
                            'approved:finance_rejected' => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', '재무 거부 (송금 불가)', '승인 메모'],
                            'approved' => ['border-emerald-200', 'bg-emerald-50', 'text-emerald-900', 'text-emerald-800', 'text-emerald-700', 'border-emerald-100', '✓', '최근 이체 요청 승인됨', '승인 메모'],
                            'rejected'  => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', '최근 이체 요청 거부됨', '거부 사유'],
                            'cancelled' => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', '⊘', '최근 이체 요청 취소됨', '메모'],
                            'void:rejected'  => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', '이체 취소 요청 거부됨', '거부 사유'],
                            'void:cancelled' => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', '⊘', '이체 취소 요청 취소됨', '메모'],
                            // 큐 19-L — void 재무 거부: 관리는 승인했지만 재무가 환불 불가로 거부 → transfer 살아있음
                            'void:finance_rejected' => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', '재무가 취소 거부 (이체 유지)', '승인 메모'],
                            default     => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', 'ℹ', '최근 이체 요청', '메모'],
                        };
                    @endphp
                    <div class="rounded-md border {{ $ldClass[0] }} {{ $ldClass[1] }} p-3 text-xs {{ $ldClass[2] }} mb-2">
                        <div class="flex items-center gap-2">
                            <span>{{ $ldClass[6] }}</span>
                            <strong>{{ $ldClass[7] }}</strong>
                        </div>
                        <div class="mt-1 space-y-0.5 {{ $ldClass[3] }}">
                            @if($isVoid)
                                <div>
                                    이체 #{{ $ld['transfer_id'] }}
                                    · {{ number_format($ld['amount']) }} {{ $ld['currency'] }}
                                    · {{ $ld['approver_name'] ?? '관리자' }}
                                    ({{ $ld['decided_at']?->format('Y-m-d H:i') }})
                                </div>
                                @if($ld['reason'] ?? null)
                                <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                    <span class="font-semibold">취소 요청 사유:</span> {{ $ld['reason'] }}
                                </div>
                                @endif
                            @else
                                <div>
                                    대상 <span class="font-mono">{{ $ld['target_vehicle_number'] }}</span>
                                    · {{ number_format($ld['amount']) }} {{ $ld['currency'] }}
                                    · {{ $ld['approver_name'] ?? '관리자' }}
                                    ({{ $ld['decided_at']?->format('Y-m-d H:i') }})
                                </div>
                            @endif
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">{{ $ldClass[8] }}:</span> {{ $ld['decision_note'] ?: '(메모 없음)' }}
                            </div>
                            @if(! $isVoid && in_array($ldKey, ['approved:executed', 'approved:voided'], true) && ! empty($ld['finance_confirmer_name']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">재무 확정:</span>
                                {{ $ld['finance_confirmer_name'] }}
                                ({{ $ld['confirmed_at']?->format('Y-m-d H:i') }})
                                @if($ld['finance_note'])
                                · {{ $ld['finance_note'] }}
                                @endif
                            </div>
                            @endif
                            {{-- 큐 19-K — 재무 거부 사유 표시 (approved:finance_rejected 한정) --}}
                            @if(! $isVoid && $ldKey === 'approved:finance_rejected' && ! empty($ld['finance_rejecter_name']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">재무 거부:</span>
                                {{ $ld['finance_rejecter_name'] }}
                                ({{ $ld['finance_rejected_at']?->format('Y-m-d H:i') }})
                            </div>
                            @if(! empty($ld['finance_reject_reason']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">거부 사유:</span> {{ $ld['finance_reject_reason'] }}
                            </div>
                            @endif
                            @endif
                            {{-- 큐 19-L — void 재무 거부 사유 표시 (void:finance_rejected 한정) --}}
                            @if($isVoid && $ldKey === 'void:finance_rejected' && ! empty($ld['void_finance_rejecter_name']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">재무 취소 거부:</span>
                                {{ $ld['void_finance_rejecter_name'] }}
                                ({{ $ld['void_finance_rejected_at']?->format('Y-m-d H:i') }})
                            </div>
                            @if(! empty($ld['void_finance_reject_reason']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">거부 사유:</span> {{ $ld['void_finance_reject_reason'] }}
                            </div>
                            @endif
                            @endif
                        </div>
                    </div>
                    @endif

                    {{-- 일반 자금 이체 박스 --}}
                    @if($transferCtx['eligible'])
                    @php
                        // 마지막 결정이 rejected이거나 큐 19-K 재무 거부면 "다시 요청"
                        $ldStatus = $transferCtx['lastDecided']['status'] ?? null;
                        $ldTransferStatus = $transferCtx['lastDecided']['transfer_status'] ?? null;
                        $isRetryCase = $ldStatus === 'rejected'
                            || ($ldStatus === 'approved' && $ldTransferStatus === 'finance_rejected');
                        $btnLabel = $isRetryCase ? '다시 요청' : '자금 이체 요청';
                    @endphp
                    <div class="rounded-md border border-violet-200 bg-violet-50 p-3 text-xs text-violet-900">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="space-y-0.5">
                                <div>받은 금액 <strong>{{ number_format($transferCtx['received']) }}</strong> {{ $currency ?: 'KRW' }}</div>
                                <div>이체 한도 <strong class="text-violet-700">{{ number_format($transferCtx['limit']) }}</strong> {{ $currency ?: 'KRW' }} <span class="text-violet-500">(받은 금액 × 50%)</span></div>
                                <div class="text-violet-700">동일 바이어 차량 {{ $transferCtx['candidates']->count() }}대로 이체 가능</div>
                            </div>
                            <button type="button" wire:click="openTransferRequestModal"
                                    class="rounded-md bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700">
                                {{ $btnLabel }}
                            </button>
                        </div>
                    </div>
                    @else
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-500">
                        {{ $transferCtx['reason'] ?: '자금 이체 가능 조건을 충족하지 않습니다.' }}
                    </div>
                    @endif
                @endif
            </div>
            @endif
        </div>

        {{-- ─── 수출통관 탭 ───────────────────────────────── --}}
        <div x-show="tab === 'clearance'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">수출통관</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">통관 바이어</label>
                        <button type="button" wire:click="openQuickAdd('buyer','export')"
                                class="mb-1 text-[11px] text-primary-text hover:underline">+ 신규</button>
                    </div>
                    <select wire:model.live="export_buyer_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->buyers as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">통관 컨사이니</label>
                        <button type="button" wire:click="openQuickAdd('consignee','export')"
                                @if($export_buyer_id_str === '') disabled @endif
                                class="mb-1 text-[11px] text-primary-text hover:underline disabled:cursor-not-allowed disabled:text-gray-300 disabled:no-underline">+ 신규</button>
                    </div>
                    <select wire:model="export_consignee_id_str" class="input-base"
                            @if($export_buyer_id_str === '') disabled @endif>
                        <option value="">{{ $export_buyer_id_str ? '-- 선택 --' : '바이어 먼저 선택' }}</option>
                        @foreach($this->consigneesForExport as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">포워딩사</label>
                    <select wire:model="forwarding_company_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->forwardingCompanies as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- 2026-05-21 — 면장금액 미입력 시 sale_price 자동 복사 (Vehicle::saving 훅). 인코텀즈 차이 등 명시 입력 시 그 값 우선 --}}
                <div>
                    <label class="label-base">면장금액</label>
                    <input wire:model="export_declaration_amount_str" type="text" class="input-base" placeholder="비워두면 판매가 자동 적용" />
                    <p class="mt-1 text-[11px] text-gray-400">통상 인보이스 금액과 동일. CIF/FOB 차이 시만 별도 입력</p>
                </div>
                <div><label class="label-base">수출신고번호</label><input wire:model="export_declaration_number" type="text" class="input-base" placeholder="123-12-123456" /></div>
                <div><label class="label-base">선적일</label><input wire:model="shipping_date" type="date" class="input-base" /></div>
                <div><label class="label-base">ETA</label><input wire:model="eta_date" type="date" class="input-base" /></div>
                <div>
                    <label class="label-base">선적방법</label>
                    <select wire:model="shipping_method" class="input-base">
                        <option value="">-- 선택 --</option>
                        <option value="RORO">RORO</option>
                        <option value="CONTAINER">CONTAINER</option>
                    </select>
                </div>
                {{-- 2026-05-21 CIPL 이식 — 선적항(Port of Loading) 드롭다운 --}}
                <div>
                    <label class="label-base">선적항 (Port of Loading)</label>
                    <select wire:model="port_of_loading" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->loadingPorts as $p)
                        <option value="{{ $p->name }}">{{ $p->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- 2026-05-21 — 인코텀즈 (FOB/CFR) — CIPL C32/C37 셀에 사용 --}}
                <div>
                    <label class="label-base">인코텀즈</label>
                    <select wire:model="incoterms" class="input-base">
                        <option value="">-- 선택 --</option>
                        <option value="FOB">FOB</option>
                        <option value="CFR">CFR</option>
                    </select>
                </div>
                {{-- 2026-05-21 — Discharge Port (도착항) FK — CIPL E16/F16 셀 --}}
                <div>
                    <label class="label-base">도착항 (Discharge Port)</label>
                    <select wire:model="discharge_port_id_str" class="input-base">
                        <option value="">-- 바이어 국가 자동 --</option>
                        @foreach($this->dischargePorts as $p)
                        <option value="{{ $p->id }}">{{ $p->display_name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] text-gray-400">미선택 시 바이어 국가명 사용</p>
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="is_export_cleared" type="checkbox" class="rounded" /> 수출통관 완료
                    </label>
                </div>
                <div class="col-span-2 sm:col-span-3">
                    <label class="label-base">수출신고서 <span class="text-xs text-gray-400">(업로드 시 선적완료 상태 달성 가능)</span></label>
                    <input wire:model="exportDeclarationDocFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-amber-50 file:px-2 file:py-1 file:text-xs file:text-amber-700" />
                    @if($export_declaration_document_path)
                    <div class="mt-1 flex items-center gap-3">
                        <a href="{{ \App\Support\VehicleDocUrl::for($export_declaration_document_path) }}" target="_blank"
                           class="text-xs text-violet-600 hover:underline">기존 파일 보기</a>
                        <button type="button" wire:click="removeExportDeclarationDoc"
                                class="text-xs text-red-500 hover:underline">삭제</button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─── B/L 탭 ────────────────────────────────────── --}}
        <div x-show="tab === 'bl'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">선적 (B/L)</span>
            </div>

            {{-- G1 100% B/L 게이트 상태 표시 (2026-05-26 회의). 기존 bl_document가 없는 차량만 검사 (grandfather). --}}
            @if($editingId)
            @php
                $g1Vehicle = \App\Models\Vehicle::with('unpaidExportOverrides')->find($editingId);
                $g1Ratio = $g1Vehicle?->unpaid_ratio;
                $g1HasExistingBl = $g1Vehicle && ! empty($g1Vehicle->bl_document);
                $g1HasShippingOverride = $g1Vehicle?->hasUnpaidOverride('shipping') ?? false;
            @endphp
            @if(! $g1HasExistingBl)
            <div class="mb-3 rounded-md border px-3 py-2 text-xs
                {{ $g1Ratio === null
                    ? 'border-amber-200 bg-amber-50 text-amber-800'
                    : ($g1Ratio > 0
                        ? ($g1HasShippingOverride ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-red-200 bg-red-50 text-red-800')
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">
                @if($g1Ratio === null)
                    <span class="font-semibold">⚠ 판매가 미입력</span> — 판매 정보(판매가) 입력 후 B/L 발행 가능
                @elseif($g1Ratio > 0)
                    @if($g1HasShippingOverride)
                        <span class="font-semibold">⚠ 미수율 {{ number_format($g1Ratio * 100, 1) }}%</span> — 관리/관리자 미입금 우회 승인(선적 단계) 적용됨 → B/L 발행 가능
                    @else
                        <span class="font-semibold">🔒 B/L 발행 잠김</span> — 미수율 {{ number_format($g1Ratio * 100, 1) }}% (잔금 100% 미완납). 완납 후 발행 가능. 또는 관리/관리자 미입금 우회 승인(선적 단계) 필요.
                    @endif
                @else
                    <span class="font-semibold">✓ B/L 발행 가능</span> — 잔금 100% 완납
                @endif
            </div>
            @endif
            @endif

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">선적 바이어</label>
                        <button type="button" wire:click="openQuickAdd('buyer','bl')"
                                class="mb-1 text-[11px] text-primary-text hover:underline">+ 신규</button>
                    </div>
                    <select wire:model.live="bl_buyer_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->buyers as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">선적 컨사이니</label>
                        <button type="button" wire:click="openQuickAdd('consignee','bl')"
                                @if($bl_buyer_id_str === '') disabled @endif
                                class="mb-1 text-[11px] text-primary-text hover:underline disabled:cursor-not-allowed disabled:text-gray-300 disabled:no-underline">+ 신규</button>
                    </div>
                    <select wire:model="bl_consignee_id_str" class="input-base"
                            @if($bl_buyer_id_str === '') disabled @endif>
                        <option value="">{{ $bl_buyer_id_str ? '-- 선택 --' : '바이어 먼저 선택' }}</option>
                        @foreach($this->consigneesForBl as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">B/L 번호</label><input wire:model="bl_number" type="text" class="input-base" /></div>
                <div><label class="label-base">컨테이너 번호</label><input wire:model="container_number" type="text" class="input-base" /></div>
                {{-- 2026-05-21 CIPL 이식 — 반입지 드롭다운 --}}
                <div>
                    <label class="label-base">반입지</label>
                    <select wire:model="bl_loading_location" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->unloadingPorts as $p)
                        <option value="{{ $p->name }}">{{ $p->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">VSL (선박명)</label><input wire:model="vessel_name" type="text" class="input-base" /></div>
                <div><label class="label-base">B/L 발행일</label><input wire:model="bl_issue_date" type="date" class="input-base" /></div>
                <div class="col-span-2 sm:col-span-3">
                    <label class="label-base">B/L 문서 <span class="text-xs text-gray-400">(업로드 시 선적완료 상태 달성 가능)</span></label>
                    <input wire:model="blDocFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-emerald-50 file:px-2 file:py-1 file:text-xs file:text-emerald-700" />
                    @if($bl_document_path)
                    <div class="mt-1 flex items-center gap-3">
                        <a href="{{ \App\Support\VehicleDocUrl::for($bl_document_path) }}" target="_blank"
                           class="text-xs text-violet-600 hover:underline">기존 파일 보기</a>
                        <button type="button" wire:click="removeBlDoc"
                                class="text-xs text-red-500 hover:underline">삭제</button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─── DHL 탭 ────────────────────────────────────── --}}
        <div x-show="tab === 'dhl'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-teal-500"></span>
                <span class="section-title">DHL 수취인</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label-base">수취인 성명</label><input wire:model="dhl_recipient_name" type="text" class="input-base" /></div>
                <div><label class="label-base">수취인 연락처</label><input wire:model="dhl_recipient_phone" type="text" class="input-base" /></div>
                <div class="col-span-2"><label class="label-base">수취인 주소</label><input wire:model="dhl_recipient_address" type="text" class="input-base" /></div>
            </div>
            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-teal-300"></span>
                <span class="section-title">DHL 발송인</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label-base">발송인 성명</label><input wire:model="dhl_sender_name" type="text" class="input-base" /></div>
                <div class="col-span-2"><label class="label-base">발송인 주소</label><input wire:model="dhl_sender_address" type="text" class="input-base" /></div>
                <div><label class="label-base">중량 (kg)</label><input wire:model="dhl_weight_str" type="text" class="input-base" placeholder="1.5" /></div>
                <div><label class="label-base">크기 (W×H×L cm)</label><input wire:model="dhl_dimensions" type="text" class="input-base" placeholder="30x20x10" /></div>
                <div class="col-span-2 flex items-center gap-2">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="dhl_request" type="checkbox" class="rounded" /> DHL 발송신청 완료
                    </label>
                </div>
            </div>
        </div>

        {{-- ─── 서류 탭 ───────────────────────────────────── --}}
        <div x-show="tab === 'docs'" x-cloak>
            @php
                // 큐 16 — sales_channel 단일화로 isExport 분기 제거 (영문 서류 항상 노출).
                $hasId = $editingId !== null;
                $url = fn (string $type) => $hasId
                    ? route('erp.vehicles.documents.show', ['id' => $editingId, 'type' => $type])
                    : '#';
            @endphp

            @unless ($hasId)
                <div class="card-tight mb-4 border-amber-200 bg-amber-50 text-sm text-amber-800">
                    차량을 먼저 저장한 뒤 서류를 생성할 수 있습니다.
                </div>
            @endunless

            {{-- 매입 서류 (전 채널) — system xlsx 자동기입 (노란칸만 채우고 노란 제거) --}}
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">매입 서류 (3종)</span>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <a href="{{ $url('deregistration') }}"
                   class="card-tight flex items-center justify-between hover:border-violet-400 hover:bg-violet-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">자동차말소등록신청서</div>
                        <div class="text-xs text-gray-500">별지 제17호 · .xlsx</div>
                    </div>
                    <span class="text-xs text-violet-600">↓</span>
                </a>
                <a href="{{ $url('deregistration_contract') }}"
                   class="card-tight flex items-center justify-between hover:border-violet-400 hover:bg-violet-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">말소 계약서</div>
                        <div class="text-xs text-gray-500">매입 · .xlsx</div>
                    </div>
                    <span class="text-xs text-violet-600">↓</span>
                </a>
                <a href="{{ $url('poa') }}"
                   class="card-tight flex items-center justify-between hover:border-violet-400 hover:bg-violet-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">위임장</div>
                        <div class="text-xs text-gray-500">매입 · .xlsx</div>
                    </div>
                    <span class="text-xs text-violet-600">↓</span>
                </a>
            </div>

            {{-- 영문 서류 (수출 단일 채널) ─────────────────── --}}
            <hr class="section-divider mt-5">
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">판매 서류 (수출)</span>
            </div>

            <div class="grid grid-cols-1 gap-3">
                <a href="{{ $url('invoice') }}"
                   class="card-tight flex items-center justify-between hover:border-emerald-400 hover:bg-emerald-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">Proforma Invoice</div>
                        <div class="text-xs text-gray-500">판매 · .xlsx</div>
                    </div>
                    <span class="text-xs text-emerald-600">↓</span>
                </a>
            </div>

            {{-- 선적 서류 (컨테이너/RORO × Invoice&Packing·Contract). 이 버튼은 이 차량 1대. 여러 대 → 1서류는 차량목록 체크박스. --}}
            <div class="section-header mt-5">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">선적 서류 (4종, 수출)</span>
            </div>
            <p class="mb-2 text-[11px] text-gray-500">여러 대를 한 서류에 담으려면 <span class="font-medium">차량 목록에서 체크박스로 선택</span> 후 상단 "선적 서류" 버튼을 사용하세요.</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <a href="{{ $url('container_invoice_packing') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">컨테이너 Invoice &amp; Packing</div>
                        <div class="text-xs text-gray-500">Container 선적 · .xlsx</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
                <a href="{{ $url('container_contract') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">컨테이너 Contract</div>
                        <div class="text-xs text-gray-500">Container 선적 · .xlsx</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
                <a href="{{ $url('roro_invoice_packing') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">RORO Invoice &amp; Packing</div>
                        <div class="text-xs text-gray-500">RORO 선적 · .xlsx</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
                <a href="{{ $url('roro_contract') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">RORO Contract</div>
                        <div class="text-xs text-gray-500">RORO 선적 · .xlsx</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
            </div>

            {{-- 통관 서류 (통관 SET — 구매리스트 1장 → 8시트 자동연동) --}}
            <div class="section-header mt-5">
                <span class="section-dot bg-green-500"></span>
                <span class="section-title">통관 서류</span>
            </div>
            <div class="grid grid-cols-1 gap-3">
                <a href="{{ $url('clearance') }}"
                   class="card-tight flex items-center justify-between hover:border-green-400 hover:bg-green-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">통관 SET (등록증·말소증·인보이스·팩킹)</div>
                        <div class="text-xs text-gray-500">구매리스트 1장 → 8시트 자동연동 · .xlsx</div>
                    </div>
                    <span class="text-xs text-green-600">↓</span>
                </a>
            </div>

            <div class="mt-5 text-xs text-gray-500 leading-relaxed">
                ※ PDF는 새 탭에서 열리며 자동 다운로드됩니다. Excel은 즉시 다운로드.<br>
                ※ 양식이 비어있는 항목은 차량 등록 정보를 채운 후 다시 생성하세요.
            </div>
        </div>

        {{-- ─── 메모 (공통) ──────────────────────────────── --}}
        <div class="mt-5">
            <label class="label-base">메모</label>
            <textarea wire:model="memo" class="input-base" rows="2" placeholder="내부 메모"></textarea>
        </div>

        </div>
    </div>

    {{-- 큐 2.6 — admin 미입금 우회 승인 (편집 모드 + 권한자 + 수출 채널) --}}
    @if($editingId && auth()->user()?->canApproveUnpaidExport())
    @php
        $editingVehicle = \App\Models\Vehicle::with('unpaidExportOverrides')->find($editingId);
        $existingOverrides = $editingVehicle?->unpaidExportOverrides ?? collect();
        $unpaidKrw = $editingVehicle?->sale_unpaid_amount_krw_cache;
    @endphp
    <div class="border-t border-amber-200 bg-amber-50 px-5 py-3">
        <div class="flex items-start gap-2">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 005 19z"/></svg>
            <div class="flex-1">
                <p class="text-xs font-semibold text-amber-800">관리/관리자 — 미입금 우회 승인</p>
                <p class="mt-0.5 text-[11px] text-amber-700">
                    미입금 잔존: {{ $unpaidKrw !== null ? number_format($unpaidKrw).' 원' : '없음' }}
                    @if($existingOverrides->count() > 0)
                    · 기존 승인 {{ $existingOverrides->count() }}건: {{ $existingOverrides->pluck('stage')->unique()->implode(' / ') }}
                    @endif
                </p>
                <div class="mt-2 flex flex-wrap items-end gap-2">
                    <div>
                        <label class="block text-[10px] text-amber-700">단계</label>
                        <select wire:model="overrideStage" class="input-filter">
                            <option value="">선택</option>
                            <option value="clearance">수출통관</option>
                            <option value="shipping">선적</option>
                            <option value="dhl">DHL</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[10px] text-amber-700">사유 (20자 이상)</label>
                        <input wire:model="overrideReason" type="text" class="input-filter w-full"
                               placeholder="예: 컨테이너 출항 일정상 강행. 잔금 5/20 입금 예정 확인됨." />
                    </div>
                    <button wire:click="approveUnpaidOverride" type="button"
                            class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">
                        승인 기록
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Panel Footer --}}
    <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4">
        <button @click="attemptClose()" type="button"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
            취소
        </button>
        {{-- 회의확장씬 (2026-05-22) — 영업·관리 + 4 탭(매입/판매/선적/통관) 시 모달. 현재 활성 탭을 인자로 전달 --}}
        <button x-on:click="$wire.requestSave(tab)" type="button" class="btn-primary" wire:loading.attr="disabled" wire:target="save,requestSave,confirmAndSave">
            <span wire:loading.remove wire:target="save,requestSave,confirmAndSave">{{ $editingId ? '수정 저장' : '신규 등록' }}</span>
            <span wire:loading wire:target="save,requestSave,confirmAndSave">저장 중...</span>
        </button>
    </div>

</div>{{-- /panel --}}

{{-- 큐 18: close confirm 모달 (.card) --}}
<div x-show="confirmOpen" x-cloak x-transition.opacity
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     @click.self="confirmOpen = false">
    <div class="card max-w-sm mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">변경 사항이 있습니다</h3>
        <p class="mt-2 text-sm text-gray-600">저장하지 않고 닫으면 변경 내용이 사라집니다.</p>
        <div class="mt-5 flex justify-end gap-2">
            <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button @click="confirmDiscard()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">닫기</button>
        </div>
    </div>
</div>


</div>{{-- /x-data --}}
@endif

{{-- 큐 21 후속 — 말소·수출통관 체크↔서류 mismatch 확인 모달 (사용자 결정 2026-05-18).
     운영 흐름상 체크/서류 순서가 비순차적이라 강제 차단 대신 모달로 인지 강제.
     슬라이드 패널 stacking context 밖에 배치. close()/save() 끝에서 정리됨. --}}
@if($showDocCheckModal && ! empty($docCheckMismatches))
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:key="doc-check-mismatch-modal">
    <div class="card max-w-lg mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">⚠ 단계 진입 누락 확인</h3>
        <p class="mt-1 text-xs text-gray-500">아래 항목은 체크박스와 문서 업로드가 한쪽만 되어있어 해당 단계가 진행되지 않습니다.</p>

        <div class="mt-3 space-y-2">
            @foreach($docCheckMismatches as $m)
            <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs">
                <div class="font-semibold text-amber-800">{{ $m['label'] }}</div>
                <div class="mt-1 grid grid-cols-2 gap-1 text-amber-700">
                    <div>{{ $m['checked'] ? '✓ 체크박스: 체크됨' : '☐ 체크박스: 미체크' }}</div>
                    <div>{{ $m['has_doc'] ? '✓ 문서: 업로드됨' : '☐ 문서: 미업로드' }}</div>
                </div>
                <div class="mt-1.5 text-[11px] text-amber-600">
                    @if($m['checked'] && ! $m['has_doc'])
                        → 체크는 됐지만 문서가 없어 "{{ $m['label'] }}완료" 단계 진입 안 됨
                    @elseif(! $m['checked'] && $m['has_doc'])
                        → 문서는 업로드됐지만 체크 안 되어 "{{ $m['label'] }}완료" 단계 진입 안 됨
                    @endif
                </div>
                <button type="button" wire:click="dismissDocCheckModal('{{ $m['tab'] }}')"
                        class="mt-2 text-[11px] text-amber-700 hover:underline">→ {{ $m['label'] }} 탭으로 이동해서 수정</button>
            </div>
            @endforeach
        </div>

        <div class="mt-3 rounded-md bg-gray-50 border border-gray-200 p-2.5 text-[11px] text-gray-600">
            저장 시 위 단계는 진행되지 않고 현재 단계로 유지됩니다. 나중에 체크/문서를 채워서 다시 저장하면 단계 진입.
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button wire:click="dismissDocCheckModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소 (수정하러 가기)</button>
            <button wire:click="confirmSaveWithDocMismatch" type="button"
                    class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                그대로 저장 (단계 미진입 인지함)
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 21 — Ledger 잠금 해제 모달 (회의록 2026-05-18, admin/super 전용).
     슬라이드 패널 stacking context 밖에 배치. close()에서 정리됨. --}}
@if($showLedgerUnlockModal)
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeLedgerUnlockModal"
     wire:key="ledger-unlock-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">🔓 Ledger 잠금 해제</h3>
        <p class="mt-1 text-xs text-gray-500">매입가·판매가·환율·면장금액·비용9개·바이어·담당자 변경이 1회 허용됩니다. 저장 직후 자동 재잠금.</p>

        <div class="mt-3 rounded-md bg-amber-50 border border-amber-200 p-2.5 text-xs text-amber-800">
            <p>⚠️ 잠금 해제는 audit_logs에 사용자·시각·사유와 함께 기록됩니다.</p>
        </div>

        <div class="mt-3">
            <label class="label-base">잠금 해제 사유 <span class="text-red-500">*</span> <span class="text-gray-400">(10자 이상)</span></label>
            <textarea wire:model="ledgerUnlockReason" rows="4"
                      class="input-base"
                      placeholder="예: 영업 매입가 오기 — 5,000,000 입력했으나 실제 계약 50,000,000. 영업 담당자 확인 완료."></textarea>
            @error('ledgerUnlockReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button wire:click="closeLedgerUnlockModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button wire:click="submitLedgerUnlock" type="button"
                    class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700"
                    wire:loading.attr="disabled" wire:target="submitLedgerUnlock">
                <span wire:loading.remove wire:target="submitLedgerUnlock">🔓 해제 (1회 변경 허용)</span>
                <span wire:loading wire:target="submitLedgerUnlock">처리 중...</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 14-4-4 G2: 같은 바이어 미수+신규 거래 승인 요청 모달
     ⚠️ 슬라이드 패널 stacking context 밖에 배치 — 패널 dirty 추적/backdrop 클릭과 분리.
     showPanel과 독립적으로 표시되지만 close()가 showOverlapRequestModal도 false로 리셋. --}}
@if($showOverlapRequestModal)
@php $overlapForModal = $this->sameBuyerOverlap; @endphp
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeOverlapRequestModal"
     wire:key="overlap-request-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">신규 거래 승인 요청</h3>
        <p class="mt-1 text-xs text-gray-500">관리자에게 사유를 알리고 승인을 받습니다. 승인은 (바이어 × 차량번호) 단위로 잠깁니다.</p>

        @if($overlapForModal)
        <div class="mt-3 rounded-md bg-amber-50 border border-amber-200 p-2.5 text-xs text-amber-800 space-y-0.5">
            <div><span class="text-amber-600">차량번호:</span> <strong>{{ $overlapForModal['current_vehicle_number'] ?: '(미입력)' }}</strong></div>
            <div><span class="text-amber-600">바이어 미수:</span> <strong>{{ $overlapForModal['count'] }}대</strong> · ₩{{ number_format($overlapForModal['amount_krw']) }}</div>
            @if(! empty($overlapForModal['vehicle_numbers']))
            <div class="text-amber-700">미수 차량: {{ implode(', ', $overlapForModal['vehicle_numbers']) }}</div>
            @endif
        </div>
        @endif

        <div class="mt-3">
            <label class="label-base">승인 요청 사유 <span class="text-red-500">*</span></label>
            <textarea wire:model="overlapRequestReason" rows="4"
                      class="input-base"
                      placeholder="예: 본 바이어 미수는 다음 달 입금 예정이며, 신규 차량은 별도 선수금 50% 받음. 최소 5자."></textarea>
            @error('overlapRequestReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" wire:click="closeOverlapRequestModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button type="button" wire:click="requestSameBuyerOverlapApproval"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50">
                요청 보내기
            </button>
        </div>
    </div>
</div>
@endif

{{-- UX #3 (2026-05-20) — 영업 저장 확인 모달.
     매입+판매 필수 항목 미리보기 → [확인] 시 save() 실행. 영업이 입력 누락 마지막 검증. --}}
@if($showSaveConfirmModal)
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeSaveConfirmModal"
     wire:key="save-confirm-modal">
    <div class="card max-w-2xl mx-4 shadow-2xl" @click.stop>
        @php
            $tabLabel = match($activeTabForSave) {
                'purchase'  => '매입',
                'sale'      => '판매',
                'bl'        => '선적(B/L)',
                'clearance' => '수출통관',
                default     => '저장',
            };
        @endphp
        <h3 class="text-base font-semibold text-gray-900">{{ $tabLabel }} 정보 확인</h3>
        <p class="mt-1 text-xs text-gray-500">아래 항목이 정확히 입력됐는지 확인 후 [확인 후 저장]을 클릭하세요.</p>

        <div class="mt-4">
            {{-- 회의확장씬 (2026-05-22) — 4 탭별 미리보기 분기 --}}
            @if($activeTabForSave === 'purchase')
            <div class="rounded-lg border border-blue-200 bg-blue-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-blue-900">매입 정보</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between"><dt class="text-gray-500">매입일</dt><dd class="font-medium">{{ $purchase_date ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">매입처</dt><dd class="font-medium">{{ $purchase_from ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">매입가</dt><dd class="font-medium">{{ $purchase_price_str ? number_format((float) str_replace(',', '', $purchase_price_str)) : '— 미입력' }} 원</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">매도비</dt><dd class="font-medium">{{ $selling_fee_str ? number_format((float) str_replace(',', '', $selling_fee_str)) : '0' }} 원</dd></div>
                    <hr class="border-blue-200 my-1">
                    <div class="flex justify-between"><dt class="text-gray-500">은행</dt><dd class="font-medium">{{ $purchase_seller_bank ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">예금주</dt><dd class="font-medium">{{ $purchase_seller_holder ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">계좌번호</dt><dd class="font-mono font-medium">{{ $purchase_seller_account ?: '— 미입력' }}</dd></div>
                </dl>
            </div>
            @elseif($activeTabForSave === 'sale')
            <div class="rounded-lg border border-purple-200 bg-purple-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-purple-900">판매 정보</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between"><dt class="text-gray-500">판매일</dt><dd class="font-medium">{{ $sale_date ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">통화</dt><dd class="font-medium">{{ $currency }}</dd></div>
                    @if($currency !== 'KRW')
                    <div class="flex justify-between"><dt class="text-gray-500">환율</dt><dd class="font-medium">{{ $exchange_rate_str ?: '— 미입력' }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-gray-500">판매가</dt><dd class="font-medium">{{ $sale_price_str ? number_format((float) str_replace(',', '', $sale_price_str)) : '— 미입력' }} {{ $currency }}</dd></div>
                    <hr class="border-purple-200 my-1">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">바이어</dt>
                        <dd class="font-medium">
                            @php $selectedBuyer = $this->buyers->firstWhere('id', (int) $buyer_id_str); @endphp
                            {{ $selectedBuyer?->name ?: '— 미선택' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">컨사이니</dt>
                        <dd class="font-medium">
                            @php $selectedConsignee = $this->consigneesForSale->firstWhere('id', (int) $consignee_id_str); @endphp
                            {{ $selectedConsignee?->name ?: '— 미선택' }}
                        </dd>
                    </div>
                </dl>
            </div>
            @elseif($activeTabForSave === 'bl')
            <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-amber-900">선적(B/L) 정보</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between"><dt class="text-gray-500">반입지(선적지)</dt><dd class="font-medium">{{ $bl_loading_location ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">B/L 번호</dt><dd class="font-medium">{{ $bl_number ?? '' ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">컨테이너 No</dt><dd class="font-medium">{{ $container_number ?? '' ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">VSL</dt><dd class="font-medium">{{ $bl_vsl ?? '' ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">B/L 문서</dt><dd class="font-medium">{{ ($bl_document_path ?? '') || ($blDocFile ?? null) ? '✓ 첨부' : '— 미첨부' }}</dd></div>
                </dl>
            </div>
            @elseif($activeTabForSave === 'clearance')
            <div class="rounded-lg border border-green-200 bg-green-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-green-900">수출통관 정보</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">통관 바이어</dt>
                        <dd class="font-medium">
                            @php $expBuyer = $this->buyers->firstWhere('id', (int) ($export_buyer_id_str ?? 0)); @endphp
                            {{ $expBuyer?->name ?: '— 미선택' }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-gray-500">선적 예정일</dt><dd class="font-medium">{{ $shipping_date ?: '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">포워딩사</dt>
                        <dd class="font-medium">
                            @php $fc = ($this->forwardingCompanies ?? collect())->firstWhere('id', (int) ($forwarding_company_id_str ?? 0)); @endphp
                            {{ $fc?->name ?: '— 미선택' }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-gray-500">면장 금액</dt><dd class="font-medium">{{ ($export_declaration_amount_str ?? '') !== '' ? number_format((float) str_replace(',', '', $export_declaration_amount_str)).' USD' : '— 미입력' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">수출신고서</dt><dd class="font-medium">{{ ($export_declaration_document_path ?? '') || ($exportDeclarationDocFile ?? null) ? '✓ 첨부' : '— 미첨부' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">통관 완료 체크</dt><dd class="font-medium">{{ ($is_export_cleared ?? false) ? '✓ 완료' : '— 미체크' }}</dd></div>
                </dl>
            </div>
            @endif
        </div>

        <div class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
             누락된 항목 (— 미입력) 이 있어도 저장은 가능하지만 다시 한 번 확인을 권장합니다.
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeSaveConfirmModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                돌아가서 수정
            </button>
            <button wire:click="confirmAndSave" wire:loading.attr="disabled" wire:target="confirmAndSave,save"
                    class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700">
                <span wire:loading.remove wire:target="confirmAndSave,save">확인 후 저장</span>
                <span wire:loading wire:target="confirmAndSave,save">저장 중...</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 19-C — 차량 간 자금 이체 요청 모달 (회의록 v5 §13).
     슬라이드 패널 stacking context 밖에 배치 (overlap 모달과 동일 패턴). --}}
@if($showTransferRequestModal)
@php $transferCtx = $this->transferContext; @endphp
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeTransferRequestModal"
     wire:key="transfer-request-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">차량 간 자금 이체 요청</h3>
        <p class="mt-1 text-xs text-gray-500">관리자 승인 후 출처 차량에 음수 잔금 + 대상 차량에 양수 잔금이 자동 생성됩니다.</p>

        <div class="mt-3 rounded-md bg-violet-50 border border-violet-200 p-2.5 text-xs text-violet-900 space-y-0.5">
            <div>받은 금액 <strong>{{ number_format($transferCtx['received']) }}</strong> {{ $currency ?: 'KRW' }}</div>
            <div>한도 <strong class="text-violet-700">{{ number_format($transferCtx['limit']) }}</strong> {{ $currency ?: 'KRW' }} (받은 금액 × 50%)</div>
        </div>

        <div class="mt-3 space-y-2">
            <div>
                <label class="label-base">이체 대상 차량 <span class="text-red-500">*</span></label>
                <select wire:model="transferTargetVehicleId" class="input-base">
                    <option value="">-- 선택 --</option>
                    @foreach($transferCtx['candidates'] as $cand)
                    <option value="{{ $cand->id }}">{{ $cand->vehicle_number }} (판매가 {{ number_format($cand->sale_price) }})</option>
                    @endforeach
                </select>
                @error('transferTargetVehicleId')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">이체 금액 <span class="text-red-500">*</span></label>
                <input wire:model="transferAmountStr" type="text" class="input-base"
                       placeholder="최대 {{ number_format($transferCtx['limit']) }} {{ $currency ?: 'KRW' }}" />
                @error('transferAmountStr')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">승인 요청 사유 <span class="text-red-500">*</span></label>
                <textarea wire:model="transferReason" rows="3" class="input-base"
                          placeholder="예: 출처 차량 50% 입금 받음. 같은 바이어가 대상 차량 계약 — 계약금으로 이체 요청. 최소 5자."></textarea>
                @error('transferReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">메모 <span class="text-[10px] text-gray-400">(선택)</span></label>
                <input wire:model="transferNotes" type="text" class="input-base" placeholder="이체 거래에 첨부될 메모" />
            </div>
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" wire:click="closeTransferRequestModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button type="button" wire:click="submitTransferRequest"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-50">
                요청 보내기
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 19-E — 이체 취소(void) 요청 모달. --}}
@if($showTransferVoidModal && $voidTransferId)
@php
    $voidTarget = \App\Models\InterVehicleTransfer::with('sourceVehicle:id,vehicle_number', 'targetVehicle:id,vehicle_number')->find($voidTransferId);
@endphp
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeTransferVoidModal"
     wire:key="transfer-void-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-red-700">이체 취소 요청</h3>
        <p class="mt-1 text-xs text-gray-500">관리자 승인 후 양 차량에 반대 부호 잔금이 추가되어 원래 이체가 회계상 상쇄됩니다. 기존 잔금은 삭제되지 않습니다 (append-only).</p>

        @if($voidTarget)
        <div class="mt-3 rounded-md bg-red-50 border border-red-200 p-2.5 text-xs text-red-900 space-y-0.5">
            <div>출처 <span class="font-mono">{{ $voidTarget->sourceVehicle?->vehicle_number ?? '#'.$voidTarget->source_vehicle_id }}</span>
                 → 대상 <span class="font-mono">{{ $voidTarget->targetVehicle?->vehicle_number ?? '#'.$voidTarget->target_vehicle_id }}</span></div>
            <div>금액 <strong>{{ number_format((float)$voidTarget->amount) }}</strong> {{ $voidTarget->currency }}</div>
        </div>
        @endif

        <div class="mt-3">
            <label class="label-base">취소 사유 <span class="text-red-500">*</span></label>
            <textarea wire:model="voidReason" rows="3" class="input-base"
                      placeholder="예: 바이어가 2번 차 계약을 무산. 자금 1번 차로 원상복구 필요. 최소 5자."></textarea>
            @error('voidReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" wire:click="closeTransferVoidModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button type="button" wire:click="submitTransferVoidRequest"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                취소 요청 보내기
            </button>
        </div>
    </div>
</div>
@endif

{{-- 작업2 (2026-05-27) — 차량 등록/수정 중 바이어·컨사이니 인라인 quick-add.
     패널 안 닫고 즉석 등록 → 자동 선택. 슬라이드 패널 stacking context 밖(z-100)에 배치. --}}
@if($quickAddOpen)
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60" wire:key="quick-add-modal">
    <div class="card w-full max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">
            {{ $quickAddType === 'buyer' ? '신규 바이어 등록' : '신규 컨사이니 등록' }}
            <span class="ml-1 text-xs font-normal text-gray-400">
                ({{ ['sale' => '판매', 'export' => '수출통관', 'bl' => '선적'][$quickAddContext] ?? '' }})
            </span>
        </h3>
        @if($quickAddType === 'consignee')
        <p class="mt-1 text-xs text-gray-500">바이어 <span class="font-medium text-gray-700">{{ $quickAddBuyerName }}</span> 에 종속됩니다.</p>
        @endif

        <div class="mt-4 space-y-3">
            <div>
                <label class="label-base">{{ $quickAddType === 'buyer' ? '바이어명' : '컨사이니명' }} <span class="text-red-500">*</span></label>
                <input wire:model="qaName" type="text" class="input-base" autofocus />
                @error('qaName')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">국가</label>
                <select wire:model="qaCountryId" class="input-base">
                    <option value="">-- 선택 --</option>
                    @foreach($this->countries as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($quickAddType === 'buyer')
            <div>
                <label class="label-base">영업담당자</label>
                <select wire:model="qaSalesmanId" class="input-base">
                    <option value="">-- 선택 --</option>
                    @foreach($this->salesmen as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-base">담당자</label>
                    <input wire:model="qaContactName" type="text" class="input-base" />
                </div>
                <div>
                    <label class="label-base">전화</label>
                    <input wire:model="qaContactPhone" type="text" class="input-base" />
                </div>
            </div>
            <p class="text-[11px] text-gray-400">이메일·주소·메모 등 상세 정보는 등록 후 바이어/컨사이니 화면에서 보완할 수 있습니다.</p>
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" wire:click="cancelQuickAdd"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">취소</button>
            <button type="button" wire:click="saveQuickAdd" wire:loading.attr="disabled"
                    class="btn-primary px-4 py-2 text-sm">등록 + 선택</button>
        </div>
    </div>
</div>
@endif

</div>{{-- /root --}}
