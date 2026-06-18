<?php

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    public int $selectedSalesmanId = 0;
    public int $perPage = 10;
    // role=전체/관리/admin 사용자의 뷰 토글 — localStorage 연동
    public string $roleView = '영업';
    // 큐 5 — 업무 대시보드 모드 토글 (admin/role∈{전체,관리}만 의미).
    //   'salesman' = 담당자별 보기 (담당자 드롭다운 + 영업 시각 고정)
    //   'role'     = 역할별 보기 (역할 탭 영업/통관/정산 + 전체 차량)
    public string $viewMode = 'salesman';

    public function mount(): void
    {
        $user = auth()->user();
        if (! $user->isAdmin()) {
            $salesman = $user->salesman;
            if ($salesman) {
                $this->selectedSalesmanId = $salesman->id;
            }
            // 본인 role이 영업/통관/정산/관리면 그것을 초기 뷰로 (큐 14-2: '관리' 추가)
            if (in_array($user->role, ['영업', '수출통관', '재무', '관리'], true)) {
                $this->roleView = $user->role;
            }
            // 비-admin: viewMode는 본인 role로 자연 결정 (영업이면 salesman, 그 외엔 role)
            $this->viewMode = $user->role === '영업' ? 'salesman' : 'role';
        }
    }

    // 큐 5 — viewMode 변경 시 동기화.
    //   'salesman'으로 전환: roleView='영업' 강제 (담당자별 = 영업 시각 고정 정책)
    //   'role'로 전환: selectedSalesmanId 비움 (전체 차량 시각)
    public function updatedViewMode(): void
    {
        $user = auth()->user();
        $canToggle = $user->isAdmin() || $user->role === '관리';
        if (! $canToggle) {
            $this->viewMode = $user->role === '영업' ? 'salesman' : 'role';

            return;
        }
        if (! in_array($this->viewMode, ['salesman', 'role'], true)) {
            $this->viewMode = 'salesman';
        }
        if ($this->viewMode === 'salesman') {
            $this->roleView = '영업';
        } else {
            // 'role' 모드 — admin/관리는 selectedSalesmanId 비움 (전체 차량 시각)
            if ($user->isAdmin() || $user->role === '관리') {
                $this->selectedSalesmanId = 0;
            }
        }
    }

    // M2 보안: 비-admin·비-관리가 selectedSalesmanId 변경 시 본인 ID로 즉시 복귀.
    // 큐 14-2 — '관리' role도 서브관리자라 다른 담당자 시각 조회 허용 (업무 파악 의도).
    public function updatedSelectedSalesmanId(): void
    {
        $user = auth()->user();
        if (! $user->isAdmin() && $user->role !== '관리') {
            $this->selectedSalesmanId = $user->salesman?->id ?? 0;
        }
    }

    // M3 가드: 토글 권한 없는 user가 roleView 변경 시도 → 본인 role로 강제 복귀.
    public function updatedRoleView(): void
    {
        $user = auth()->user();
        $canToggle = $user->isAdmin() || $user->role === '관리';
        if (! $canToggle) {
            $this->roleView = in_array($user->role, ['영업', '수출통관', '재무', '관리'], true) ? $user->role : '영업';

            return;
        }
        // 큐 14-2 — '관리' 4번째 탭 허용
        if (! in_array($this->roleView, ['영업', '수출통관', '재무', '관리'], true)) {
            $this->roleView = '영업';
        }
    }

    // M2 SQL 강제: 담당자 필터는 영업 뷰에서만 의미 있음.
    // admin이 영업 뷰에서 담당자 N 선택 → 통관/정산 뷰로 전환 시 N 무시 (KPI/목록 정합성).
    private function effectiveSalesmanId(): ?int
    {
        if ($this->roleView !== '영업') {
            return null;
        }
        $user = auth()->user();

        // admin/super: selectedSalesmanId 자유 선택 (전체 영업)
        if ($user->isAdmin()) {
            return $this->selectedSalesmanId ?: null;
        }

        // 회의확장씬 #11 (2026-05-22) — [관리] 는 본인 담당 영업 중에서만 선택 가능.
        // UI 제한 (salesmen() select 옵션) + 보안 가드 (임의 ID 차단) 이중.
        if ($user->role === '관리') {
            if (! $this->selectedSalesmanId) {
                return null;
            }
            $allowed = $user->getSubordinateSalesmanIds();

            return in_array((int) $this->selectedSalesmanId, $allowed, true)
                ? (int) $this->selectedSalesmanId
                : null;   // 본인 담당 외 임의 ID → 빈 결과 (보안 가드)
        }

        return $user->salesman?->id;
    }

    // 회의확장씬 #7 (2026-05-22) — 실시간 환율 (네이버 marketindex 1h 캐시).
    // [관리]/일반사용자 대시보드 위젯 + 잔금N+ 자동 기입 데이터 소스.
    #[Computed]
    public function exchangeRates(): ?array
    {
        return app(ExchangeRateService::class)->getRates();
    }

    public function refreshExchangeRates(): void
    {
        app(ExchangeRateService::class)->refresh();
        unset($this->exchangeRates);
        $this->dispatch('notify', message: __('dashboard.fx_refreshed'), type: 'success');
    }

    #[Computed]
    public function salesmen()
    {
        $q = Salesman::where('is_active', true)->orderBy('name');

        // 회의확장씬 #11 (2026-05-22) — [관리] 본인 담당 영업만 select 옵션 노출.
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && $user->role === '관리') {
            $q->whereIn('id', $user->getSubordinateSalesmanIds());
        }

        return $q->get(['id', 'name']);
    }

    #[Computed]
    public function kpis(): array
    {
        return match ($this->roleView) {
            '수출통관' => $this->buildClearanceKpis(),
            '재무' => $this->buildSettlementKpis(),
            '관리' => $this->buildManagementKpis(),
            default => $this->buildSalesKpis(),
        };
    }

    #[Computed]
    public function actions(): array
    {
        return match ($this->roleView) {
            '수출통관' => $this->buildClearanceActions(),
            '재무' => $this->buildSettlementActions(),
            '관리' => $this->buildManagementActions(),
            default => $this->buildSalesActions(),
        };
    }

    #[Computed]
    public function activeVehicles()
    {
        $sid = $this->effectiveSalesmanId();

        // 안건 J 본격 (2026-05-20) — v2/v3 호환. progress_status_cache 단일 출처.
        return Vehicle::query()
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->with(['salesman', 'finalPayments', 'purchaseBalancePayments', 'receivableHistories'])
            ->orderBy('purchase_date', 'desc')
            ->limit($this->perPage)
            ->get();
    }

    // 큐 2번 — 파이프라인 카운트 스트립 (11단계).
    // 영업 뷰면 본인 salesman 한정, 통관/정산/admin은 전체.
    #[Computed]
    public function pipelineCounts(): array
    {
        $sid = $this->effectiveSalesmanId();

        return Vehicle::query()
            ->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->selectRaw('progress_status_cache, COUNT(*) as cnt')
            ->groupBy('progress_status_cache')
            ->pluck('cnt', 'progress_status_cache')
            ->toArray();
    }

    public function pipelineUrl(string $status): string
    {
        $params = ['progressFilter' => $status];
        $sid = $this->effectiveSalesmanId();
        if ($sid) {
            $params['salesmanId'] = $sid;
        }

        return route('erp.vehicles.index').'?'.http_build_query($params);
    }

    // ── 영업 role 빌더 ───────────────────────────────────
    private function buildSalesKpis(): array
    {
        $sid = $this->effectiveSalesmanId();
        $ms = now()->startOfMonth()->toDateString();
        $me = now()->endOfMonth()->toDateString();

        $base = Vehicle::query()->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid));

        // 안건 J 본격 (2026-05-20) — v2/v3 호환. progress_status_cache 단일 출처.
        $active = (clone $base)->where(fn ($q) => $q
            ->where('progress_status_cache', '!=', '거래완료')
            ->orWhereNull('progress_status_cache'))->count();
        $monthBuy = (clone $base)->whereBetween('purchase_date', [$ms, $me])->count();
        $monthSale = (clone $base)->where('sale_price', '>', 0)->whereBetween('sale_date', [$ms, $me])->count();
        $monthDone = (clone $base)->where('progress_status_cache', '거래완료')->whereBetween('updated_at', [$ms.' 00:00:00', $me.' 23:59:59'])->count();
        $monthLabel = app()->getLocale() === 'en' ? now()->format('M') : now()->format('n').'월';
        $unit = __('dashboard.unit_vehicle');

        return [
            ['label' => __('dashboard.kpi.sales.active.l'),     'value' => $active,    'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.active.h')],
            ['label' => __('dashboard.kpi.sales.month_buy.l'),  'value' => $monthBuy,  'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.month_buy.h', ['month' => $monthLabel])],
            ['label' => __('dashboard.kpi.sales.month_sale.l'), 'value' => $monthSale, 'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.month_sale.h', ['month' => $monthLabel])],
            ['label' => __('dashboard.kpi.sales.month_done.l'), 'value' => $monthDone, 'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.month_done.h', ['month' => $monthLabel])],
        ];
    }

    private function buildSalesActions(): array
    {
        $sid = $this->effectiveSalesmanId();
        $base = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid));

        $pendingSettlements = Settlement::query()
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid))
            ->where('settlement_status', 'pending')
            ->count();

        $t = fn (string $k, string $f) => __("dashboard.act.sales.$k.$f");

        return [
            $this->row($t('purchase_unpaid', 'l'),  $t('purchase_unpaid', 'd'),  $base('purchase_unpaid')->count(),  'bg-red-500',   'purchase_unpaid',  true),
            $this->row($t('sale_unpaid', 'l'),      $t('sale_unpaid', 'd'),      $base('sale_unpaid')->count(),      'bg-amber-500', 'sale_unpaid',      true),
            $this->row($t('clearance_needed', 'l'), $t('clearance_needed', 'd'), $base('clearance_needed')->count(), 'bg-blue-500',  'clearance_needed'),
            $this->row($t('shipping_needed', 'l'),  $t('shipping_needed', 'd'),  $base('shipping_needed')->count(),  'bg-green-500', 'shipping_needed'),
            $this->row($t('dhl_needed', 'l'),       $t('dhl_needed', 'd'),       $base('dhl_needed')->count(),       'bg-teal-500',  'dhl_needed'),
            // 정산 대기는 settlements 라우트로 직접 이동
            ['label' => $t('settlement_wait', 'l'), 'desc' => $t('settlement_wait', 'd'),
             'count' => $pendingSettlements, 'dot' => 'bg-violet-500', 'urgent' => false,
             'href' => route('erp.settlements.index')],
        ];
    }

    // ── 통관 role 빌더 ───────────────────────────────────
    private function buildClearanceKpis(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $unit = __('dashboard.unit_vehicle');
        $t = fn (string $k, string $f) => __("dashboard.kpi.clearance.$k.$f");

        return [
            ['label' => $t('derg_wait', 'l'),    'value' => $c('deregistration_needed'),            'suffix' => $unit, 'hint' => $t('derg_wait', 'h')],
            ['label' => $t('clr_req_wait', 'l'), 'value' => $c('clearance_request_needed'),         'suffix' => $unit, 'hint' => $t('clr_req_wait', 'h')],
            ['label' => $t('decl_wait', 'l'),    'value' => $c('export_declaration_upload_needed'), 'suffix' => $unit, 'hint' => $t('decl_wait', 'h')],
            ['label' => $t('ship_wait', 'l'),    'value' => $c('shipping_process_needed'),          'suffix' => $unit, 'hint' => $t('ship_wait', 'h')],
            ['label' => $t('dhl_wait', 'l'),     'value' => $c('dhl_dispatch_needed'),              'suffix' => $unit, 'hint' => $t('dhl_wait', 'h')],
        ];
    }

    private function buildClearanceActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $t = fn (string $k, string $f) => __("dashboard.act.clearance.$k.$f");

        return [
            $this->row($t('deregistration_needed', 'l'),            $t('deregistration_needed', 'd'),            $c('deregistration_needed'),            'bg-red-500',   'deregistration_needed', true),
            $this->row($t('clearance_request_needed', 'l'),         $t('clearance_request_needed', 'd'),         $c('clearance_request_needed'),         'bg-blue-500',  'clearance_request_needed'),
            $this->row($t('clearance_info_missing', 'l'),           $t('clearance_info_missing', 'd'),           $c('clearance_info_missing'),           'bg-amber-500', 'clearance_info_missing', true),
            // 2026-06-18 데이터 보정 — 선적했는데 도착일(ETA) 미입력. 클릭 → 알림함(도착일 인라인 입력).
            $this->row($t('eta_missing', 'l'),                      $t('eta_missing', 'd'),                      $c('eta_missing'),                      'bg-amber-500', 'eta_missing', true, route('erp.alarms.index')),
            $this->row($t('forwarding_missing', 'l'),               $t('forwarding_missing', 'd'),               $c('forwarding_missing'),               'bg-amber-500', 'forwarding_missing', true),
            $this->row($t('export_declaration_upload_needed', 'l'), $t('export_declaration_upload_needed', 'd'), $c('export_declaration_upload_needed'), 'bg-blue-500',  'export_declaration_upload_needed'),
            $this->row($t('shipping_process_needed', 'l'),          $t('shipping_process_needed', 'd'),          $c('shipping_process_needed'),          'bg-amber-500', 'shipping_process_needed'),
            $this->row($t('bl_upload_needed', 'l'),                 $t('bl_upload_needed', 'd'),                 $c('bl_upload_needed'),                 'bg-green-500', 'bl_upload_needed'),
            $this->row($t('dhl_dispatch_needed', 'l'),              $t('dhl_dispatch_needed', 'd'),              $c('dhl_dispatch_needed'),              'bg-teal-500',  'dhl_dispatch_needed'),
        ];
    }

    // ── 정산 role 빌더 ───────────────────────────────────
    private function buildSettlementKpis(): array
    {
        // M4 — "판매 미입금 총액"은 환율 입력 차량만 합산 (KRW 캐시 NOT NULL).
        //       환율 미입력 외화 차량은 별도 KPI "환율 미입력 외화"에서만 카운트.
        // 안건 J 본격 (2026-05-20) — v2/v3 호환.
        $totalSaleUnpaid = (int) (Vehicle::query()
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->where('sale_price', '>', 0)
            ->whereNotNull('sale_unpaid_amount_krw_cache')
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->sum('sale_unpaid_amount_krw_cache') ?? 0);

        $today = now()->toDateString();
        // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP 후 단순화.
        $totalPurchaseUnpaid = (int) (Vehicle::query()
            ->whereNull('deleted_at')
            ->where('purchase_price', '>', 0)
            // CAST AS SIGNED — BIGINT UNSIGNED 빼기 결과가 음수면 underflow. WHERE/SELECT 양쪽 적용.
            // Review.md #6 (2026-06-09) — confirmed_at IS NOT NULL 필터 추가.
            // 누락 시 미확정 Draft PBP(매입가 입력 시 자동생성, payment_date=매입일)가 집계를 깎아
            // 카드 카운트(scopeAction·accessor=confirmed만)와 KPI 금액 모집단이 불일치했음.
            ->whereRaw('(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?
                                      AND confirmed_at IS NOT NULL), 0)) > 0', [$today])
            ->selectRaw('SUM(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?
                                      AND confirmed_at IS NOT NULL), 0)) as total', [$today])
            ->value('total') ?? 0);

        $pendingSettlements = Settlement::query()->where('settlement_status', 'pending')->count();
        $exchangeMissing = Vehicle::query()->whereNull('deleted_at')->action('exchange_rate_missing')->count();

        // 2026-05-20 #2 피드백 — 거래완료지만 미수금 남은 차량 (정산 진행 차단 상태).
        $settlementBlockedQuery = Vehicle::query()->whereNull('deleted_at')->action('settlement_blocked_by_unpaid');
        $settlementBlockedCount = (clone $settlementBlockedQuery)->count();
        $settlementBlockedAmount = (int) ((clone $settlementBlockedQuery)->sum('sale_unpaid_amount_krw_cache') ?? 0);

        $t = fn (string $k, string $f) => __("dashboard.kpi.settlement.$k.$f");

        return [
            ['label' => $t('pur_unpaid', 'l'),  'value' => $this->formatKrw($totalPurchaseUnpaid), 'suffix' => '',                            'hint' => $t('pur_unpaid', 'h')],
            ['label' => $t('sale_unpaid', 'l'), 'value' => $this->formatKrw($totalSaleUnpaid),     'suffix' => '',                            'hint' => $t('sale_unpaid', 'h')],
            ['label' => $t('wait', 'l'),        'value' => $pendingSettlements,                    'suffix' => __('dashboard.unit_count'),    'hint' => $t('wait', 'h')],
            ['label' => $t('fx_missing', 'l'),  'value' => $exchangeMissing,                       'suffix' => __('dashboard.unit_vehicle'),  'hint' => $t('fx_missing', 'h')],
            // 2026-05-20 #2 피드백 — 거래완료 미수금 차량 (정산 진행 차단)
            ['label' => $t('blocked', 'l'),     'value' => $settlementBlockedCount, 'suffix' => __('dashboard.unit_vehicle'),
             'hint' => __('dashboard.kpi.settlement.blocked.h', ['amount' => $this->formatKrw($settlementBlockedAmount)])],
        ];
    }

    private function buildSettlementActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $t = fn (string $k, string $f) => __("dashboard.act.settlement.$k.$f");

        return [
            $this->row($t('purchase_unpaid', 'l'),           $t('purchase_unpaid', 'd'),           $c('purchase_unpaid'),           'bg-red-500',    'purchase_unpaid',           true),
            $this->row($t('sale_unpaid', 'l'),               $t('sale_unpaid', 'd'),               $c('sale_unpaid'),               'bg-amber-500',  'sale_unpaid',               true),
            $this->row($t('exchange_rate_missing', 'l'),     $t('exchange_rate_missing', 'd'),     $c('exchange_rate_missing'),     'bg-red-500',    'exchange_rate_missing',     true),
            $this->row($t('settlement_create_needed', 'l'),  $t('settlement_create_needed', 'd'),  $c('settlement_create_needed'),  'bg-blue-500',   'settlement_create_needed'),
            $this->row($t('settlement_confirm_needed', 'l'), $t('settlement_confirm_needed', 'd'), $c('settlement_confirm_needed'), 'bg-violet-500', 'settlement_confirm_needed'),
            $this->row($t('settlement_pay_needed', 'l'),     $t('settlement_pay_needed', 'd'),     $c('settlement_pay_needed'),     'bg-violet-500', 'settlement_pay_needed'),
            $this->row($t('receivable_risk', 'l'),           $t('receivable_risk', 'd'),           $c('receivable_risk'),           'bg-red-500',    'receivable_risk',           true),
        ];
    }

    // ── 관리 role 빌더 (큐 14-2) ─────────────────────────
    // 서브관리자 시각 — 승인 대기·정산 대기·채권 위험·정체 차량 모니터링.
    // 회의록 2026-05-14-management-role-dashboard.md §2 4 KPI 합의안.
    private function buildManagementKpis(): array
    {
        // approval_requests는 큐 14-3에서 신설 — 14-2 시점엔 카운트 0으로 표시.
        // 14-3 완료 후 ApprovalRequest::where('status','pending')->count()로 교체.
        $pendingApprovals = 0;

        $pendingSettlements = Settlement::query()
            ->whereIn('settlement_status', ['pending', 'confirmed'])
            ->count();

        $riskCount = Vehicle::query()->whereNull('deleted_at')
            ->whereIn('receivable_risk', ['danger', 'critical'])
            ->count();

        $stuckDate = now()->subDays(30)->toDateString();
        // 안건 J 본격 (2026-05-20) — v2/v3 호환.
        $stuckCount = Vehicle::query()->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->where('sale_price', '>', 0)
            ->where(fn ($q) => $q->whereNull('sale_unpaid_amount_krw_cache')
                ->orWhere('sale_unpaid_amount_krw_cache', '<=', 0))
            ->whereNull('export_declaration_document')
            ->whereNotNull('sale_date')
            ->where('sale_date', '<=', $stuckDate)
            ->count();

        $cnt = __('dashboard.unit_count');
        $unit = __('dashboard.unit_vehicle');
        $t = fn (string $k, string $f) => __("dashboard.kpi.management.$k.$f");

        return [
            ['label' => $t('appr_wait', 'l'),   'value' => $pendingApprovals,   'suffix' => $cnt,  'hint' => $t('appr_wait', 'h')],
            ['label' => $t('settle_wait', 'l'), 'value' => $pendingSettlements, 'suffix' => $cnt,  'hint' => $t('settle_wait', 'h')],
            ['label' => $t('risk', 'l'),        'value' => $riskCount,          'suffix' => $unit, 'hint' => $t('risk', 'h')],
            ['label' => $t('clr_stuck', 'l'),   'value' => $stuckCount,         'suffix' => $unit, 'hint' => $t('clr_stuck', 'h')],
        ];
    }

    private function buildManagementActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $t = fn (string $k, string $f) => __("dashboard.act.management.$k.$f");

        return [
            // 승인 대기는 별도 화면 /erp/approvals — 큐 14-3에서 활성화 (현재 카운트만 표시)
            ['label' => $t('approval_wait', 'l'), 'desc' => $t('approval_wait', 'd'), 'count' => 0,
             'dot' => 'bg-violet-500', 'urgent' => false,
             'href' => '#'],
            $this->row($t('settlement_confirm_needed', 'l'), $t('settlement_confirm_needed', 'd'), $c('settlement_confirm_needed'), 'bg-violet-500', 'settlement_confirm_needed'),
            $this->row($t('settlement_pay_needed', 'l'),     $t('settlement_pay_needed', 'd'),     $c('settlement_pay_needed'),     'bg-violet-500', 'settlement_pay_needed'),
            $this->row($t('receivable_risk', 'l'),           $t('receivable_risk', 'd'),           $c('receivable_risk'),           'bg-red-500',    'receivable_risk', true),
            $this->row($t('clearance_stuck', 'l'),           $t('clearance_stuck', 'd'),           $c('clearance_stuck'),           'bg-amber-500',  'clearance_stuck', true),
        ];
    }

    private function row(string $label, string $desc, int $count, string $dot, string $action, bool $urgent = false, ?string $href = null): array
    {
        return [
            'label' => $label, 'desc' => $desc, 'count' => $count,
            'dot' => $dot, 'urgent' => $urgent,
            'href' => $href ?? $this->vehiclesUrl($action),
        ];
    }

    private function vehiclesUrl(string $action): string
    {
        $url = route('erp.vehicles.index').'?action='.$action;
        $sid = $this->effectiveSalesmanId();
        if ($sid) {
            $url .= '&salesmanId='.$sid;
        }

        return $url;
    }

    private function formatKrw(int $amount): string
    {
        // 로케일별 축약 (ko: 억/만 만단위까지 정확 / en: K/M/B). 공통 헬퍼 단일 출처.
        return '₩'.\App\Support\Money::krw($amount);
    }
}; ?>

{{-- UX #6 (2026-05-20) — wire:poll.30s — 사이드바 뱃지 + 페이지 데이터 30초 자동 갱신. 본인 액션은 Livewire 자체 re-render 로 즉시 반영. --}}
<div wire:poll.30s>
<div class="flex h-full flex-col gap-5 p-3 md:p-6" x-data="{
    roleView: @entangle('roleView').live,
    viewMode: @entangle('viewMode').live,
    initView() {
        @if(auth()->user()->isAdmin() || auth()->user()->role === '관리')
        const savedRole = localStorage.getItem('erp_dashboard_role_view');
        if (savedRole && ['영업','수출통관','재무','관리'].includes(savedRole)) {
            this.roleView = savedRole;
        }
        const savedMode = localStorage.getItem('erp_dashboard_view_mode');
        if (savedMode && ['salesman','role'].includes(savedMode)) {
            this.viewMode = savedMode;
        }
        @endif
    },
    setView(v) {
        this.roleView = v;
        localStorage.setItem('erp_dashboard_role_view', v);
    },
    setMode(m) {
        this.viewMode = m;
        localStorage.setItem('erp_dashboard_view_mode', m);
    }
}" x-init="initView()">

@php
    $user = auth()->user();
    $canToggleView = $user->isAdmin() || $user->role === '관리';
    $viewLabel = __('dashboard.view_label.'.(in_array($roleView, ['수출통관','재무','관리'], true) ? $roleView : '영업'));
    $viewBadge = match($roleView) {
        '수출통관' => 'badge-amber',
        '재무' => 'badge-green',
        '관리' => 'badge-purple',
        default => 'badge-purple',
    };
    $salesmanMissing = $user->role === '영업' && ! $user->isAdmin() && ! $user->salesman;
@endphp

{{-- 헤더 --}}
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ $viewLabel }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">
            <span class="badge {{ $viewBadge }}">{{ __('domain.role.'.$roleView) }}</span>
            · {{ app()->getLocale() === 'en' ? now()->translatedFormat('M j, Y') : now()->format('Y년 m월 d일') }}
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        {{-- 큐 5 — 모드 토글 [담당자별]↔[역할별] (canToggleView만 노출) --}}
        @if($canToggleView)
        <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-0.5">
            <button type="button"
                @click="setMode('salesman')"
                :class="viewMode === 'salesman' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                class="rounded px-3 py-1 text-xs font-medium transition">{{ __('dashboard.mode_by_salesman') }}</button>
            <button type="button"
                @click="setMode('role')"
                :class="viewMode === 'role' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                class="rounded px-3 py-1 text-xs font-medium transition">{{ __('dashboard.mode_by_role') }}</button>
        </div>
        @endif

        {{-- M7 역할 탭 pill — 역할별 모드에서만 노출 (담당자별 모드는 영업 시각 고정) --}}
        @if($canToggleView)
        <div class="flex items-center gap-1" x-show="viewMode === 'role'" x-cloak>
            @foreach(['영업','수출통관','재무','관리'] as $v)
            <button type="button"
                @click="setView('{{ $v }}')"
                :class="roleView === '{{ $v }}' ? 'tab-pill is-active' : 'tab-pill'"
                class="px-3 py-1 text-xs">{{ __('domain.role.'.$v) }}</button>
            @endforeach
        </div>
        @endif

        {{-- 담당자 드롭다운 — 담당자별 모드 + admin/관리만 노출 (영업 시각 고정) --}}
        @if($user->isAdmin() || $user->role === '관리')
        <div class="flex items-center gap-2" x-show="viewMode === 'salesman'" x-cloak>
            <span class="text-xs text-gray-500">{{ __('dashboard.salesman') }}</span>
            <select wire:model.live="selectedSalesmanId" class="input-filter">
                <option value="0">{{ __('dashboard.all') }}</option>
                @foreach($this->salesmen as $sm)
                <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
    </div>
</div>

@if($salesmanMissing)
{{-- S4 영업 user salesman 미연결 empty state --}}
<div class="card flex flex-col items-center justify-center py-12 text-center">
    <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
        <svg class="h-7 w-7 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
    </div>
    <p class="text-base font-semibold text-gray-800">{{ __('dashboard.salesman_missing_title') }}</p>
    <p class="mt-1 text-sm text-gray-500">{{ __('dashboard.salesman_missing_sub') }}</p>
</div>
@else

{{-- 큐 2번 — 11단계 파이프라인 카운트 스트립 --}}
@php
    $stripSubtitle = match(true) {
        $user->isAdmin() && $this->selectedSalesmanId && $roleView === '영업'
            => __('dashboard.subtitle_only', ['name' => $this->salesmen->firstWhere('id', $this->selectedSalesmanId)?->name ?? __('dashboard.salesman')]),
        $roleView === '영업' && ! $user->isAdmin()
            => $user->salesman?->name ? __('dashboard.subtitle_only', ['name' => $user->salesman->name]) : null,
        default => __('dashboard.subtitle_all'),
    };
@endphp
<x-erp.pipeline-strip
    :counts="$this->pipelineCounts"
    :url-builder="fn (string $s) => $this->pipelineUrl($s)"
    :subtitle="$stripSubtitle" />

{{-- KPI 4카드 (S2 통일 — .card + text-xl md:text-2xl + truncate) --}}
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach($this->kpis as $kpi)
    <div class="card">
        <p class="text-xs text-gray-500">{{ $kpi['label'] }}</p>
        <p class="mt-1 truncate text-xl md:text-2xl font-bold text-gray-800" title="{{ $kpi['value'] }}{{ $kpi['suffix'] }}">
            {{ $kpi['value'] }}@if($kpi['suffix'] !== '')<span class="ml-0.5 text-sm font-normal text-gray-400">{{ $kpi['suffix'] }}</span>@endif
        </p>
        <p class="mt-1 text-xs text-gray-400">{{ $kpi['hint'] }}</p>
    </div>
    @endforeach
</div>

{{-- 회의확장씬 #7 (2026-05-22) — 실시간 환율 위젯 (KRW 기준, 1h 캐시, 네이버 marketindex) --}}
@php $exchangeRates = $this->exchangeRates; @endphp
@if($exchangeRates !== null)
<div class="card mt-3">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">
            {{ __('dashboard.fx_title') }}
            <span class="ml-1 text-xs font-normal text-gray-400">{{ __('dashboard.fx_sub') }}</span>
        </h2>
        <button wire:click="refreshExchangeRates" class="text-xs text-violet-600 hover:underline">{{ __('dashboard.fx_refresh') }}</button>
    </div>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
        @foreach(['USD','JPY','EUR','GBP','CNY'] as $cur)
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-center">
            <div class="text-xs text-gray-400">{{ $cur }}@if($cur === 'JPY') <span class="text-[10px]">(100)</span>@endif</div>
            <div class="text-base font-bold text-gray-800">
                @if(isset($exchangeRates[$cur]))
                    ₩{{ number_format($exchangeRates[$cur], 2) }}
                @else
                    <span class="text-xs text-gray-400">—</span>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</div>
@else
<div class="card mt-3 bg-amber-50">
    <p class="text-xs text-amber-700">{{ __('dashboard.fx_fail') }} <button wire:click="refreshExchangeRates" class="text-violet-600 hover:underline">{{ __('dashboard.fx_refresh') }}</button></p>
</div>
@endif

{{-- 할일 목록 --}}
<div class="card">
    <h2 class="mb-4 text-sm font-semibold text-gray-700">{{ __('dashboard.actions_title') }}</h2>
    @php
        $totalActions = collect($this->actions)->sum('count');
        $emptyKey = in_array($roleView, ['수출통관','재무'], true) ? $roleView : 'default';
        $emptyMessage = ['title' => __("dashboard.empty.$emptyKey.title"), 'sub' => __("dashboard.empty.$emptyKey.sub")];
    @endphp
    @if($totalActions === 0)
    <div class="flex flex-col items-center justify-center py-8 text-center">
        <div class="mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
            <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <p class="text-sm font-medium text-green-700">{{ $emptyMessage['title'] }}</p>
        <p class="mt-0.5 text-xs text-gray-400">{{ $emptyMessage['sub'] }}</p>
    </div>
    @else
    <div class="divide-y divide-gray-100">
        @foreach($this->actions as $action)
        @if($action['count'] > 0)
        <a href="{{ $action['href'] }}" wire:navigate
           class="-mx-4 flex items-center gap-3 px-4 py-3 transition first:-mt-1 last:-mb-1 hover:bg-gray-50">
            <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full {{ $action['dot'] }}"></span>
            <div class="min-w-0 flex-1">
                <span class="text-sm font-medium text-gray-800">{{ $action['label'] }}</span>
                <span class="ml-2 hidden text-xs text-gray-400 sm:inline">{{ $action['desc'] }}</span>
            </div>
            <span class="flex-shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold
                         {{ $action['urgent'] ? 'bg-red-100 text-red-700' : 'text-gray-700' }}">
                {{ $action['count'] }}{{ __('dashboard.unit_count') }}
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

{{-- 진행중 차량 목록 (모든 role 공통 페어 렌더) --}}
<div class="card">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">{{ __('dashboard.active_title') }}</h2>
        <div class="flex items-center gap-3">
            <select wire:model.live="perPage" class="input-filter">
                <option value="10">{{ __('dashboard.per_page', ['count' => 10]) }}</option>
                <option value="30">{{ __('dashboard.per_page', ['count' => 30]) }}</option>
                <option value="50">{{ __('dashboard.per_page', ['count' => 50]) }}</option>
                <option value="100">{{ __('dashboard.per_page', ['count' => 100]) }}</option>
            </select>
            <a href="{{ route('erp.vehicles.index') }}" wire:navigate class="text-xs text-violet-600 hover:underline">{{ __('dashboard.view_all') }}</a>
        </div>
    </div>

    {{-- 데스크탑 테이블 --}}
    <div class="hidden overflow-x-auto sm:block">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-left text-xs text-gray-400">
                    <th class="pb-2 pr-4 font-medium">{{ __('dashboard.col.number') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('dashboard.col.salesman') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('dashboard.col.status') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('dashboard.col.next') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('dashboard.col.purchase_date') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('dashboard.col.unpaid_purchase') }}</th>
                    <th class="pb-2 font-medium">{{ __('dashboard.col.unpaid_sale') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($this->activeVehicles as $v)
                @php
                    // 안건 1 v4 (2026-05-21) — 워크플로우 순서: 선적(반입) → 통관 → B/L → 거래완료
                    // 색 매핑: 선적=amber, 통관=green (v3 amber/green 순서 유지 + 단계명만 swap)
                    $pb = match(true) {
                        in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                        in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                        in_array($v->progress_status, ['선적중','선적완료'])            => 'badge-amber',
                        in_array($v->progress_status, ['통관중','통관완료'])            => 'badge-green',
                        // v3 grandfather 호환 (운영 데이터 0이지만 안전망)
                        in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                        default                                                         => 'badge-gray',
                    };
                    $nextAction = trans()->has('domain.next_action.'.$v->progress_status)
                        ? __('domain.next_action.'.$v->progress_status)
                        : __('domain.next_action.none');
                    $puAmt = $v->purchase_unpaid_amount;
                    $suAmt = $v->sale_unpaid_amount;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="py-2.5 pr-4 font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                    <td class="py-2.5 pr-4 text-gray-500">{{ $v->salesman?->name ?? '-' }}</td>
                    <td class="py-2.5 pr-4"><span class="badge {{ $pb }}">{{ $v->progress_status_label }}</span></td>
                    <td class="py-2.5 pr-4 text-xs text-gray-500">{{ $nextAction }}</td>
                    <td class="py-2.5 pr-4 text-gray-500">{{ $v->purchase_date?->format('m-d') ?? '-' }}</td>
                    <td class="py-2.5 pr-4 text-xs {{ $puAmt > 0 ? 'font-medium text-red-600' : 'text-gray-300' }}">
                        {{ $puAmt > 0 ? '₩'.number_format($puAmt) : __('dashboard.none') }}
                    </td>
                    <td class="py-2.5 text-xs {{ $suAmt > 0 ? 'font-medium text-amber-600' : 'text-gray-300' }}">
                        {{ $suAmt > 0 ? number_format($suAmt, 0).' ('.$v->currency.')' : __('dashboard.none') }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="py-8 text-center text-sm text-gray-400">{{ __('dashboard.no_active') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block space-y-2 sm:hidden">
        @forelse($this->activeVehicles as $v)
        @php
            // 안건 1 v4 (2026-05-21) — 워크플로우 순서: 선적(반입) → 통관 → B/L → 거래완료
            $pb = match(true) {
                in_array($v->progress_status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                in_array($v->progress_status, ['판매중','판매완료'])            => 'badge-purple',
                in_array($v->progress_status, ['선적중','선적완료'])            => 'badge-amber',
                in_array($v->progress_status, ['통관중','통관완료'])            => 'badge-green',
                in_array($v->progress_status, ['수출통관중','수출통관완료'])   => 'badge-amber',
                default                                                         => 'badge-gray',
            };
            $nextAction = trans()->has('domain.next_action.'.$v->progress_status)
                ? __('domain.next_action.'.$v->progress_status)
                : __('domain.next_action.none');
        @endphp
        <div class="rounded-lg border border-gray-100 px-3 py-2.5">
            <div class="flex items-center justify-between">
                <span class="font-medium text-gray-800">{{ $v->vehicle_number }}</span>
                <span class="badge {{ $pb }}">{{ $v->progress_status_label }}</span>
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
        <div class="py-8 text-center text-sm text-gray-400">{{ __('dashboard.no_active') }}</div>
        @endforelse
    </div>
</div>

@endif

</div>
</div>
