<?php

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
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
        // 큐 14-2 — admin + 관리는 selectedSalesmanId 자유 선택 가능
        if ($user->isAdmin() || $user->role === '관리') {
            return $this->selectedSalesmanId ?: null;
        }

        return $user->salesman?->id;
    }

    #[Computed]
    public function salesmen()
    {
        return Salesman::where('is_active', true)->orderBy('name')->get(['id', 'name']);
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

        return Vehicle::query()
            ->whereNull('deleted_at')
            ->where('dhl_request', false)
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

        $active = (clone $base)->where('dhl_request', false)->count();
        $monthBuy = (clone $base)->whereBetween('purchase_date', [$ms, $me])->count();
        $monthSale = (clone $base)->where('sale_price', '>', 0)->whereBetween('sale_date', [$ms, $me])->count();
        $monthDone = (clone $base)->where('dhl_request', true)->whereBetween('updated_at', [$ms.' 00:00:00', $me.' 23:59:59'])->count();
        $monthLabel = now()->format('m').'월';

        return [
            ['label' => '현재 진행중',  'value' => $active,    'suffix' => '대', 'hint' => '거래완료 제외'],
            ['label' => '이달 매입',    'value' => $monthBuy,  'suffix' => '대', 'hint' => $monthLabel.' 매입 기준'],
            ['label' => '이달 판매',    'value' => $monthSale, 'suffix' => '대', 'hint' => $monthLabel.' 판매 기준'],
            ['label' => '이달 거래완료','value' => $monthDone, 'suffix' => '대', 'hint' => $monthLabel.' 완료 기준'],
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

        return [
            $this->row('매입 미지급',         '매입가 입력 후 잔금 미지급',    $base('purchase_unpaid')->count(),   'bg-red-500',    'purchase_unpaid',   true),
            $this->row('판매 미입금',         '판매 후 미회수 금액 존재',     $base('sale_unpaid')->count(),       'bg-amber-500',  'sale_unpaid',       true),
            $this->row('수출통관 신청 필요',  '판매 완납 → 면장서류 미업로드',$base('clearance_needed')->count(),  'bg-blue-500',   'clearance_needed'),
            $this->row('선적 처리 필요',      '수출통관 완료 → B/L 미처리',   $base('shipping_needed')->count(),   'bg-green-500',  'shipping_needed'),
            $this->row('DHL 발송 대기',       '선적 완료 → DHL 미신청',       $base('dhl_needed')->count(),        'bg-teal-500',   'dhl_needed'),
            // 정산 대기는 settlements 라우트로 직접 이동
            ['label' => '정산 대기', 'desc' => '정산 방식 미입력 또는 확인 필요',
             'count' => $pendingSettlements, 'dot' => 'bg-violet-500', 'urgent' => false,
             'href' => route('erp.settlements.index')],
        ];
    }

    // ── 통관 role 빌더 ───────────────────────────────────
    private function buildClearanceKpis(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        return [
            ['label' => '통관 신청 대기',          'value' => $c('clearance_request_needed'),         'suffix' => '대', 'hint' => '판매 완납 → 면장 미업로드'],
            ['label' => '수출신고서 업로드 대기',  'value' => $c('export_declaration_upload_needed'), 'suffix' => '대', 'hint' => '수출통관중'],
            ['label' => '선적 처리 대기',          'value' => $c('shipping_process_needed'),          'suffix' => '대', 'hint' => '면장 완료 → 반입지 미입력'],
            ['label' => 'DHL 발송 대기',           'value' => $c('dhl_dispatch_needed'),              'suffix' => '대', 'hint' => 'B/L 발행 → 미신청'],
        ];
    }

    private function buildClearanceActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        return [
            // 2026-05-20 사용자 요청 — canHandleDeregistration 사용자(수출통관 포함) 액션.
            $this->row('말소 처리 필요',      '매입 완료 → 말소 미처리',                   $c('deregistration_needed'),            'bg-red-500',   'deregistration_needed', true),
            $this->row('수출통관 신청 필요',  '판매 완납 → 면장 미업로드',                 $c('clearance_request_needed'),         'bg-blue-500',  'clearance_request_needed'),
            $this->row('통관 바이어/일자 누락','판매 진입 → export_buyer 또는 shipping_date 없음', $c('clearance_info_missing'),     'bg-amber-500', 'clearance_info_missing',         true),
            $this->row('포워딩사 미지정',     '통관 진입 → forwarding 없음',                $c('forwarding_missing'),               'bg-amber-500', 'forwarding_missing',             true),
            $this->row('수출신고서 업로드',   '수출통관중 → 신고서 없음',                  $c('export_declaration_upload_needed'), 'bg-blue-500',  'export_declaration_upload_needed'),
            $this->row('선적 처리 필요',      '면장 완료 → 반입지 미입력',                 $c('shipping_process_needed'),          'bg-green-500', 'shipping_process_needed'),
            $this->row('B/L 업로드 필요',     '선적중 → B/L 미업로드',                     $c('bl_upload_needed'),                 'bg-green-500', 'bl_upload_needed'),
            $this->row('DHL 발송 대기',       '선적완료 → 미신청',                         $c('dhl_dispatch_needed'),              'bg-teal-500',  'dhl_dispatch_needed'),
        ];
    }

    // ── 정산 role 빌더 ───────────────────────────────────
    private function buildSettlementKpis(): array
    {
        // M4 — "판매 미입금 총액"은 환율 입력 차량만 합산 (KRW 캐시 NOT NULL).
        //       환율 미입력 외화 차량은 별도 KPI "환율 미입력 외화"에서만 카운트.
        $totalSaleUnpaid = (int) (Vehicle::query()
            ->whereNull('deleted_at')->where('dhl_request', false)
            ->where('sale_price', '>', 0)
            ->whereNotNull('sale_unpaid_amount_krw_cache')
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->sum('sale_unpaid_amount_krw_cache') ?? 0);

        $today = now()->toDateString();
        $totalPurchaseUnpaid = (int) (Vehicle::query()
            ->whereNull('deleted_at')
            ->where('purchase_price', '>', 0)
            // CAST AS SIGNED — BIGINT UNSIGNED 빼기 결과가 음수면 underflow. WHERE/SELECT 양쪽 적용.
            ->whereRaw('(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - CAST(down_payment AS SIGNED) - CAST(selling_fee_payment AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?), 0)) > 0', [$today])
            ->selectRaw('SUM(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - CAST(down_payment AS SIGNED) - CAST(selling_fee_payment AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?), 0)) as total', [$today])
            ->value('total') ?? 0);

        $pendingSettlements = Settlement::query()->where('settlement_status', 'pending')->count();
        $exchangeMissing = Vehicle::query()->whereNull('deleted_at')->action('exchange_rate_missing')->count();

        return [
            ['label' => '매입 미지급 총액', 'value' => $this->formatKrw($totalPurchaseUnpaid), 'suffix' => '', 'hint' => '진행중 매입 잔금 합계'],
            ['label' => '판매 미입금 총액', 'value' => $this->formatKrw($totalSaleUnpaid),     'suffix' => '', 'hint' => '환율 입력 차량만 합산'],
            ['label' => '정산 대기',        'value' => $pendingSettlements,                    'suffix' => '건','hint' => 'pending 상태'],
            ['label' => '환율 미입력 외화', 'value' => $exchangeMissing,                       'suffix' => '대','hint' => '외화 판매 → 환율 없음'],
        ];
    }

    private function buildSettlementActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        return [
            $this->row('매입 미지급',     '매입가 입력 → 잔금 미지급',     $c('purchase_unpaid'),           'bg-red-500',    'purchase_unpaid',           true),
            $this->row('판매 미입금',     '판매 → 미회수 잔존',           $c('sale_unpaid'),               'bg-amber-500',  'sale_unpaid',               true),
            $this->row('환율 입력 필요',  '외화 판매 → 환율 미입력',      $c('exchange_rate_missing'),     'bg-red-500',    'exchange_rate_missing',     true),
            $this->row('정산 생성 필요',  '거래완료 → settlement 없음',   $c('settlement_create_needed'),  'bg-blue-500',   'settlement_create_needed'),
            $this->row('정산 확정 필요',  'settlement = pending',         $c('settlement_confirm_needed'), 'bg-violet-500', 'settlement_confirm_needed'),
            $this->row('정산 지급 필요',  'settlement = confirmed',       $c('settlement_pay_needed'),     'bg-violet-500', 'settlement_pay_needed'),
            $this->row('채권 위험',       '회수 위험·심각 등급',          $c('receivable_risk'),           'bg-red-500',    'receivable_risk',           true),
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
        $stuckCount = Vehicle::query()->whereNull('deleted_at')
            ->where('dhl_request', false)
            ->where('sale_price', '>', 0)
            ->where(fn ($q) => $q->whereNull('sale_unpaid_amount_krw_cache')
                ->orWhere('sale_unpaid_amount_krw_cache', '<=', 0))
            ->whereNull('export_declaration_document')
            ->whereNotNull('sale_date')
            ->where('sale_date', '<=', $stuckDate)
            ->count();

        return [
            ['label' => '승인 대기',     'value' => $pendingApprovals,    'suffix' => '건', 'hint' => '4 액션 통합 (큐 14-3에서 활성화)'],
            ['label' => '정산 대기',     'value' => $pendingSettlements,  'suffix' => '건', 'hint' => 'pending + confirmed'],
            ['label' => '채권 위험',     'value' => $riskCount,           'suffix' => '대', 'hint' => '위험·심각 등급'],
            ['label' => '통관 정체',     'value' => $stuckCount,          'suffix' => '대', 'hint' => '판매완료 30일+ → 면장 미업로드'],
        ];
    }

    private function buildManagementActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        return [
            // 승인 대기는 별도 화면 /erp/approvals — 큐 14-3에서 활성화 (현재 카운트만 표시)
            ['label' => '승인 대기 항목', 'desc' => '큐 14-3 활성화 예정', 'count' => 0,
             'dot' => 'bg-violet-500', 'urgent' => false,
             'href' => '#'],
            $this->row('정산 확정 필요',  'settlement = pending → 승인 후 paid',  $c('settlement_confirm_needed'), 'bg-violet-500', 'settlement_confirm_needed'),
            $this->row('정산 지급 필요',  'settlement = confirmed → 승인 후 paid', $c('settlement_pay_needed'),    'bg-violet-500', 'settlement_pay_needed'),
            $this->row('채권 위험',       '회수 위험·심각 → 추가 한도 승인 검토',  $c('receivable_risk'),           'bg-red-500',    'receivable_risk',           true),
            $this->row('통관 정체',       '판매완료 30일+ → 진행 점검',           $c('clearance_stuck'),           'bg-amber-500',  'clearance_stuck',           true),
        ];
    }

    private function row(string $label, string $desc, int $count, string $dot, string $action, bool $urgent = false): array
    {
        return [
            'label' => $label, 'desc' => $desc, 'count' => $count,
            'dot' => $dot, 'urgent' => $urgent,
            'href' => $this->vehiclesUrl($action),
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
        if ($amount === 0) {
            return '₩0';
        }
        if ($amount >= 100000000) {
            return '₩'.number_format($amount / 100000000, 1).'억';
        }
        if ($amount >= 10000) {
            return '₩'.number_format($amount / 10000, 0).'만';
        }

        return '₩'.number_format($amount);
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
    $viewLabel = match($roleView) {
        '수출통관' => '내 수출통관 업무',
        '재무' => '내 재무 업무',
        '관리' => '내 관리 업무',
        default => '내 영업 업무',
    };
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
            <span class="badge {{ $viewBadge }}">{{ $roleView }}</span>
            · {{ now()->format('Y년 m월 d일') }}
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        {{-- 큐 5 — 모드 토글 [담당자별]↔[역할별] (canToggleView만 노출) --}}
        @if($canToggleView)
        <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-0.5">
            <button type="button"
                @click="setMode('salesman')"
                :class="viewMode === 'salesman' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                class="rounded px-3 py-1 text-xs font-medium transition">담당자별</button>
            <button type="button"
                @click="setMode('role')"
                :class="viewMode === 'role' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                class="rounded px-3 py-1 text-xs font-medium transition">역할별</button>
        </div>
        @endif

        {{-- M7 역할 탭 pill — 역할별 모드에서만 노출 (담당자별 모드는 영업 시각 고정) --}}
        @if($canToggleView)
        <div class="flex items-center gap-1" x-show="viewMode === 'role'" x-cloak>
            @foreach(['영업','수출통관','재무','관리'] as $v)
            <button type="button"
                @click="setView('{{ $v }}')"
                :class="roleView === '{{ $v }}' ? 'tab-pill is-active' : 'tab-pill'"
                class="px-3 py-1 text-xs">{{ $v }}</button>
            @endforeach
        </div>
        @endif

        {{-- 담당자 드롭다운 — 담당자별 모드 + admin/관리만 노출 (영업 시각 고정) --}}
        @if($user->isAdmin() || $user->role === '관리')
        <div class="flex items-center gap-2" x-show="viewMode === 'salesman'" x-cloak>
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
</div>

@if($salesmanMissing)
{{-- S4 영업 user salesman 미연결 empty state --}}
<div class="card flex flex-col items-center justify-center py-12 text-center">
    <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
        <svg class="h-7 w-7 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
    </div>
    <p class="text-base font-semibold text-gray-800">담당자 정보가 연결되지 않았습니다</p>
    <p class="mt-1 text-sm text-gray-500">관리자에게 본 계정의 salesman 연결을 요청하세요.</p>
</div>
@else

{{-- 큐 2번 — 11단계 파이프라인 카운트 스트립 --}}
@php
    $stripSubtitle = match(true) {
        $user->isAdmin() && $this->selectedSalesmanId && $roleView === '영업'
            => ($this->salesmen->firstWhere('id', $this->selectedSalesmanId)?->name ?? '담당자').' 한정',
        $roleView === '영업' && ! $user->isAdmin()
            => $user->salesman?->name ? $user->salesman->name.' 한정' : null,
        default => '전체 차량',
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

{{-- 할일 목록 --}}
<div class="card">
    <h2 class="mb-4 text-sm font-semibold text-gray-700">처리 필요 항목</h2>
    @php
        $totalActions = collect($this->actions)->sum('count');
        $emptyMessage = match($roleView) {
            '수출통관' => ['title' => '처리 대기 항목 없음', 'sub' => '수출통관/선적/DHL 흐름 정상'],
            '재무' => ['title' => '회수·재무 대기 없음', 'sub' => '모든 채권/정산 정상'],
            default => ['title' => '처리할 항목이 없습니다', 'sub' => '모든 작업이 최신 상태입니다'],
        };
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

{{-- 진행중 차량 목록 (모든 role 공통 페어 렌더) --}}
<div class="card">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">진행중 차량</h2>
        <div class="flex items-center gap-3">
            <select wire:model.live="perPage" class="input-filter">
                <option value="10">10대</option>
                <option value="30">30대</option>
                <option value="50">50대</option>
                <option value="100">100대</option>
            </select>
            <a href="{{ route('erp.vehicles.index') }}" wire:navigate class="text-xs text-violet-600 hover:underline">전체 보기 →</a>
        </div>
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

@endif

</div>
</div>
