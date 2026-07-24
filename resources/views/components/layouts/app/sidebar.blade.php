<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <style>
        [data-flux-menu] { z-index: 50; }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
@php
    $user = auth()->user();
    $permissionLabel = match ($user->permission) {
        'super' => __('nav.permission.super'),
        'admin' => __('nav.permission.admin'),
        default => __('nav.permission.user'),
    };

    $sidebarBrand = \App\Models\Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR';

    // 업무 워크프로세스 Notion 가이드 (전 직원 공통). URL 은 Setting 으로 교체 가능 — 기본값은 현재 Notion 페이지.
    $workGuideUrl = \App\Models\Setting::get('work_guide_url', 'https://dashing-stick-008.notion.site/37345d82bd838108a418c76a210f1854') ?: '';

    // i18n Phase 0 — 영어 활성 시에만 상단바 언어 전환 노출
    $localeEnEnabled = (bool) \App\Models\Setting::get('locale_en_enabled', false);

    $routeName = request()->route()?->getName();
    $breadcrumb = match (true) {
        $routeName === 'dashboard' => __('nav.crumb.dashboard'),
        $routeName === 'admin.dashboard' => __('nav.crumb.admin_dashboard'),
        $routeName === 'admin.users.index' => __('nav.crumb.users'),
        $routeName === 'admin.ports.index' => __('nav.crumb.ports'),
        $routeName === 'admin.settings' => __('nav.crumb.settings'),
        $routeName === 'admin.document-access-logs.index' => __('nav.crumb.doc_logs'),
        $routeName === 'admin.audit-logs.index' => __('nav.crumb.audit_logs'),
        $routeName === 'admin.alimtalk-logs.index' => __('nav.crumb.alimtalk_logs'),
        $routeName === 'admin.alimtalk-catalog.index' => __('nav.crumb.alimtalk_catalog'),
        $routeName === 'admin.mail-delivery-logs.index' => __('nav.crumb.mail_logs'),
        $routeName === 'erp.dashboard' => __('nav.crumb.erp_dashboard'),
        $routeName === 'erp.vehicles.index' => __('nav.crumb.vehicles'),
        $routeName === 'erp.inventory.index' => __('nav.crumb.inventory'),
        $routeName === 'erp.shipping-requests.index' => __('nav.menu.shipping_requests'),
        $routeName === 'erp.buyers.index' => __('nav.crumb.buyers'),
        $routeName === 'erp.consignees.index' => __('nav.crumb.consignees'),
        $routeName === 'erp.forwarding-companies.index' => __('nav.crumb.forwarding'),
        $routeName === 'erp.salesmen.index' => __('nav.crumb.salesmen'),
        $routeName === 'erp.salesmen.cashflow' => __('nav.crumb.cashflow'),
        $routeName === 'erp.settlements.index' => __('nav.crumb.settlements'),
        $routeName === 'erp.receivables.index' => __('nav.crumb.receivables'),
        $routeName === 'erp.approvals.index' => __('nav.crumb.approvals'),
        $routeName === 'erp.transfers.index' => __('nav.crumb.transfers'),
        $routeName === 'settings.profile' => __('nav.crumb.profile'),
        $routeName === 'settings.password' => __('nav.crumb.password'),
        $routeName === 'settings.appearance' => __('nav.crumb.appearance'),
        default => '',
    };

    $mySalesman = $user->salesman ?? null;
    $isSalesUser = ! $user->canAccessAdmin() && $user->canAccessSales() && $mySalesman;

    // 큐 14-3 — 승인 대기 건수 (canApprove user만 계산)
    $pendingApprovals = $user->canApprove()
        ? \App\Models\ApprovalRequest::where('status', 'pending')->count()
        : 0;

    // Phase 2 — 이 사용자 차례(current_level==rank, super=전체)인 월배치 지급 대기 건수.
    $pendingPayoutBatches = $user->canApprove()
        ? \App\Models\SettlementPayoutBatch::where('status', 'pending')
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('current_level', $user->approvalRank()))
            ->count()
        : 0;

    // 2026-05-20 #1 피드백 — 수출통관 사이드바 카운트는 통관 후보 차량 (말소 대기 + 통관 준비 합집합).
    // link 도 clearance_candidates 액션 필터로 변경 (link ↔ badge 100% 일치).
    $clearanceBadge = $user->canAccessClearance()
        ? \App\Models\Vehicle::query()->whereNull('deleted_at')->action('clearance_candidates')->count()
        : 0;

    // 2026-06-18 ETA 통관서류 알람 — 미확인(미해소+미확인) 건수. canAccessClearance 만 계산.
    $alarmUnread = $user->canAccessClearance()
        ? \App\Models\TaskAlarm::query()->visibleTo($user)->unread()->count()
        : 0;

    // 2026-06-19 선적요청 — 미처리(requested) 배치 수. canAccessClearance 만 계산.
    $shippingOpen = $user->canAccessClearance()
        ? \App\Models\ShippingRequest::where('status', 'requested')->distinct()->count('batch_id')
        : 0;

    // 큐 19-F / 20-C — 재무 처리 대기 건수 합산 (자금 이체 + 매입 잔금 + 판매 잔금)
    // 단일 사용자(canConfirmFinanceTransfer)만 계산. 비-재무 사용자는 0.
    if ($user->canConfirmFinanceTransfer()) {
        $pendingTransferCount = \App\Models\InterVehicleTransfer::whereIn('status', [
            \App\Models\InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            \App\Models\InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
        ])->count();
        // 큐 20-C — 영업 직접 입력 잔금 중 미확정 (transfer_id IS NULL AND confirmed_at IS NULL)
        // 회의확장씬 (2026-05-22) — vehicle soft delete 시 자동 제외 (whereHas('vehicle')).
        $pendingFinalPaymentCount = \App\Models\FinalPayment::query()
            ->whereHas('vehicle')
            ->whereNull('transfer_id')
            ->whereNull('confirmed_at')
            ->count();
        $pendingPurchaseBalanceCount = \App\Models\PurchaseBalancePayment::query()
            ->whereHas('vehicle')
            ->whereNull('confirmed_at')
            ->count();
        $pendingFinanceConfirmations = $pendingTransferCount + $pendingFinalPaymentCount + $pendingPurchaseBalanceCount;
    } else {
        $pendingFinanceConfirmations = 0;
    }

    // 2026-07-13 — 알림톡 미도달(+발송실패) 미확인 건수. canAccessAdmin 만 계산(배지 ↔ 메뉴 게이트 일치).
    $alimtalkFailBadge = $user->canAccessAdmin()
        ? \App\Models\AlimtalkLog::query()->needsAttention()->count()
        : 0;

    // 2026-06-19 — 사이드바 트리 재편. role 게이트는 항목별 'show' 단일출처, 그룹은 'key'(접기 localStorage)+빈그룹 자동숨김(렌더에서 visible 항목 0이면 헤더 미출력).
    $menuGroups = [
        [
            'key' => 'main',
            'label' => __('nav.group.main'),
            'items' => [
                [
                    'label' => __('nav.menu.dashboard'),
                    'href' => route('dashboard'),
                    'icon' => 'home',
                    'active' => request()->routeIs('dashboard') || request()->routeIs('erp.dashboard'),
                    'show' => true,
                ],
                [
                    'label' => __('nav.menu.admin_dashboard'),
                    'href' => $user->canViewAdminDashboard() ? route('admin.dashboard') : '#',
                    'icon' => 'chart-bar',
                    'active' => request()->routeIs('admin.dashboard'),
                    'show' => $user->canViewAdminDashboard(),
                ],
            ],
        ],
        [
            'key' => 'work',
            'label' => __('nav.group.work'),
            'items' => [
                [
                    'label' => __('nav.menu.vehicles'),
                    'href' => route('erp.vehicles.index'),
                    'icon' => 'truck',
                    'active' => request()->routeIs('erp.vehicles.*'),
                    'show' => true,
                ],
                // 회의확장씬 큐 15 / G5 (2026-05-23) — 영업담당자별 재고관리.
                [
                    'label' => __('nav.menu.inventory'),
                    'href' => route('erp.inventory.index'),
                    'icon' => 'building',
                    'active' => request()->routeIs('erp.inventory.*'),
                    'show' => true,
                ],
                // 2026-05-20 #1 피드백 — 수출통관 사이드바 = 통관 후보 차량 (말소 대기 + 통관 준비 합집합).
                // 사용자 의도: (a) 매입완료 + 판매 진행 + 말소 안 됨 OR (b) 말소완료 + 판매 진행 + 입금률 ≥ 50%
                [
                    'label' => __('nav.menu.clearance'),
                    'href' => route('erp.vehicles.index').'?action=clearance_candidates',
                    'icon' => 'identification',
                    'active' => false,
                    'show' => $user->canAccessClearance(),
                    'badge' => $clearanceBadge > 0 ? $clearanceBadge : null,
                ],
                [
                    'label' => __('nav.menu.receivables'),
                    'href' => $user->canViewReceivables() ? route('erp.receivables.index') : '#',
                    'icon' => 'banknotes',
                    'active' => request()->routeIs('erp.receivables.*'),
                    'show' => $user->canViewReceivables(),
                ],
                [
                    'label' => __('nav.menu.salesmen'),
                    'href' => $user->canAccessAdmin() ? route('erp.salesmen.index') : '#',
                    'icon' => 'briefcase',
                    'active' => request()->routeIs('erp.salesmen.index'),
                    'show' => $user->canAccessAdmin(),
                ],
                // 영업 본인 캐시플로우 — 영업 role 만. car-erp 계정 가진 영업이 자기 현황 보는 진입.
                [
                    'label' => __('nav.menu.cashflow'),
                    'href' => $isSalesUser ? route('erp.salesmen.cashflow', $mySalesman->id) : '#',
                    'icon' => 'chart-bar',
                    'active' => request()->routeIs('erp.salesmen.cashflow'),
                    'show' => $isSalesUser,
                ],
            ],
        ],
        // 2026-06-19 — 통관·선적 그룹 신설 (알림 + 선적요청). canAccessClearance(admin·수출통관·관리).
        [
            'key' => 'clearance',
            'label' => __('nav.group.clearance'),
            'items' => [
                // ETA 통관서류 알림함 (벨).
                [
                    'label' => __('nav.menu.alarms'),
                    'href' => route('erp.alarms.index'),
                    'icon' => 'bell',
                    'active' => request()->routeIs('erp.alarms.*'),
                    'show' => $user->canAccessClearance(),
                    'badge' => $alarmUnread > 0 ? $alarmUnread : null,
                ],
                // board 영업포털 선적요청 목록 (배치별 묶음).
                [
                    'label' => __('nav.menu.shipping_requests'),
                    'href' => route('erp.shipping-requests.index'),
                    'icon' => 'truck',
                    'active' => request()->routeIs('erp.shipping-requests.*'),
                    'show' => $user->canAccessClearance(),
                    'badge' => $shippingOpen > 0 ? $shippingOpen : null,
                ],
            ],
        ],
        [
            'key' => 'customer',
            'label' => __('nav.group.customer'),
            'items' => [
                [
                    'label' => __('nav.menu.buyers'),
                    'href' => route('erp.buyers.index'),
                    'icon' => 'users',
                    'active' => request()->routeIs('erp.buyers.*'),
                    'show' => true,
                ],
                [
                    'label' => __('nav.menu.consignees'),
                    'href' => route('erp.consignees.index'),
                    'icon' => 'identification',
                    'active' => request()->routeIs('erp.consignees.*'),
                    'show' => true,
                ],
                [
                    'label' => __('nav.menu.forwarding'),
                    'href' => $user->canManageForwarding() ? route('erp.forwarding-companies.index') : '#',
                    'icon' => 'building',
                    'active' => request()->routeIs('erp.forwarding-companies.*'),
                    'show' => $user->canManageForwarding(),
                ],
                [
                    'label' => __('nav.menu.ports'),
                    'href' => $user->canManagePorts() ? route('admin.ports.index') : '#',
                    'icon' => 'building',
                    'active' => request()->routeIs('admin.ports.*'),
                    'show' => $user->canManagePorts(),
                ],
            ],
        ],
        [
            'key' => 'finance',
            'label' => __('nav.group.finance'),
            'items' => [
                [
                    'label' => __('nav.menu.settlements'),
                    'href' => $user->canAccessSettlement() ? route('erp.settlements.index') : '#',
                    'icon' => 'calculator',
                    'active' => request()->routeIs('erp.settlements.*'),
                    'show' => $user->canAccessSettlement(),
                ],
                [
                    'label' => __('nav.menu.approvals'),
                    'href' => $user->canApprove() ? route('erp.approvals.index') : '#',
                    'icon' => 'check-circle',
                    'active' => request()->routeIs('erp.approvals.*'),
                    'show' => $user->canApprove(),
                    'badge' => $pendingApprovals > 0 ? $pendingApprovals : null,
                ],
                [
                    'label' => __('nav.menu.payout_batches'),
                    'href' => $user->canApprove() ? route('erp.payout-batches.index') : '#',
                    'icon' => 'banknotes',
                    'active' => request()->routeIs('erp.payout-batches.*'),
                    'show' => $user->canApprove(),
                    'badge' => $pendingPayoutBatches > 0 ? $pendingPayoutBatches : null,
                ],
                [
                    'label' => __('nav.menu.transfers'),
                    'href' => $user->canConfirmFinanceTransfer() ? route('erp.transfers.index') : '#',
                    'icon' => 'banknotes',
                    'active' => request()->routeIs('erp.transfers.*'),
                    'show' => $user->canConfirmFinanceTransfer(),
                    'badge' => $pendingFinanceConfirmations > 0 ? $pendingFinanceConfirmations : null,
                ],
            ],
        ],
        [
            'key' => 'etc',
            'label' => __('nav.group.etc'),
            'items' => [
                [
                    'label' => __('nav.menu.users'),
                    'href' => $user->canManageUsers() ? route('admin.users.index') : '#',
                    'icon' => 'user-group',
                    'active' => request()->routeIs('admin.users.*'),
                    'show' => $user->canManageUsers(),
                ],
                [
                    'label' => __('nav.menu.settings'),
                    'href' => $user->isSuperAdmin() ? route('admin.settings') : '#',
                    'icon' => 'cog',
                    'active' => request()->routeIs('admin.settings'),
                    'show' => $user->isSuperAdmin(),
                ],
            ],
        ],
        // 회의확장씬 Phase 3-1 (d) (2026-05-23) — 로그 그룹 (canAccessAdmin: super/admin/업무관리자).
        [
            'key' => 'log',
            'label' => __('nav.group.log'),
            'items' => [
                [
                    'label' => __('nav.menu.doc_logs'),
                    'href' => $user->canAccessAdmin() ? route('admin.document-access-logs.index') : '#',
                    'icon' => 'identification',
                    'active' => request()->routeIs('admin.document-access-logs.*'),
                    'show' => $user->canAccessAdmin(),
                ],
                [
                    'label' => __('nav.menu.audit_logs'),
                    'href' => $user->canAccessAdmin() && \Illuminate\Support\Facades\Route::has('admin.audit-logs.index')
                        ? route('admin.audit-logs.index') : '#',
                    'icon' => 'check-circle',
                    'active' => request()->routeIs('admin.audit-logs.*'),
                    'show' => $user->canAccessAdmin(),
                ],
                [
                    'label' => __('nav.menu.alimtalk_logs'),
                    'href' => $user->canAccessAdmin() && \Illuminate\Support\Facades\Route::has('admin.alimtalk-logs.index')
                        ? route('admin.alimtalk-logs.index') : '#',
                    'icon' => 'bell',
                    'active' => request()->routeIs('admin.alimtalk-logs.*'),
                    'show' => $user->canAccessAdmin(),
                    'badge' => $alimtalkFailBadge > 0 ? $alimtalkFailBadge : null,
                ],
                [
                    'label' => __('nav.menu.alimtalk_catalog'),
                    'href' => $user->isSuperAdmin() && \Illuminate\Support\Facades\Route::has('admin.alimtalk-catalog.index')
                        ? route('admin.alimtalk-catalog.index') : '#',
                    'icon' => 'bell',
                    'active' => request()->routeIs('admin.alimtalk-catalog.*'),
                    'show' => $user->isSuperAdmin(),
                ],
                [
                    'label' => __('nav.menu.mail_logs'),
                    'href' => $user->canAccessAdmin() && \Illuminate\Support\Facades\Route::has('admin.mail-delivery-logs.index')
                        ? route('admin.mail-delivery-logs.index') : '#',
                    'icon' => 'envelope',
                    'active' => request()->routeIs('admin.mail-delivery-logs.*'),
                    'show' => $user->canAccessAdmin(),
                ],
            ],
        ],
    ];

    $icons = [
        'home'           => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4"/>',
        'truck'          => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 17h8m-8 0a2 2 0 11-4 0 2 2 0 014 0zm8 0a2 2 0 11-4 0 2 2 0 014 0zM3 4h10v13H3V4zm10 4h4l3 4v5h-7V8z"/>',
        'users'          => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'identification' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 4h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2zm5 5a2 2 0 11-4 0 2 2 0 014 0zm-2 4a4 4 0 00-4 4h8a4 4 0 00-4-4zm6-3h6m-6 4h6"/>',
        'calculator'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 7h6m-6 4h6m-6 4h6m-9 5h12a2 2 0 002-2V5a2 2 0 00-2-2H6a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
        'banknotes'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8h18v8H3V8zm9 4a2 2 0 11-4 0 2 2 0 014 0zm-9 0h.01M21 12h.01"/>',
        'building'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 21V5a2 2 0 012-2h12a2 2 0 012 2v16M4 21h16M9 7h6m-6 4h6m-6 4h6m-3 6v-4"/>',
        'briefcase'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5V3a2 2 0 012-2h2a2 2 0 012 2v2m-7 0h12a2 2 0 012 2v11a2 2 0 01-2 2H4a2 2 0 01-2-2V7a2 2 0 012-2h3z"/>',
        'chart-bar'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 17v-6m6 6V7m6 10v-4m6 4V5"/>',
        'user-group'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>',
        'cog'            => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'logout'         => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>',
        'menu'           => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16"/>',
        'check-circle'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'bell'           => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
        'envelope'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
        'book'           => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
    ];
@endphp

<div class="flex min-h-screen"
     x-data="{
        open: localStorage.getItem('sidebar-open') !== 'false',
        mobileOpen: false,
        isMobile: window.innerWidth < 768,
        init() {
            const mq = window.matchMedia('(max-width: 767px)');
            this.isMobile = mq.matches;
            mq.addEventListener('change', e => {
                this.isMobile = e.matches;
                if (!e.matches) this.mobileOpen = false;
            });
        },
        toggle() {
            if (this.isMobile) {
                this.mobileOpen = !this.mobileOpen;
            } else {
                this.open = !this.open;
                localStorage.setItem('sidebar-open', this.open);
            }
        },
        closeMobile() { this.mobileOpen = false; }
     }">

    {{-- 모바일 backdrop --}}
    <div x-show="isMobile && mobileOpen"
         x-transition.opacity
         @click="closeMobile()"
         class="sidebar-backdrop"
         style="display: none;"></div>

    {{-- 사이드바 --}}
    <aside class="app-sidebar flex flex-col text-white shrink-0"
           :class="isMobile ? 'sidebar-mobile' : 'sticky top-0 h-screen'"
           :style="isMobile ? '' : ('width: ' + (open ? '220px' : '48px'))"
           x-show="!isMobile || mobileOpen"
           x-transition:enter="sidebar-enter-active"
           x-transition:enter-start="sidebar-enter-from"
           x-transition:enter-end="sidebar-enter-to"
           x-transition:leave="sidebar-enter-active"
           x-transition:leave-start="sidebar-enter-to"
           x-transition:leave-end="sidebar-enter-from">

        {{-- 로고 --}}
        <div class="flex items-center h-12 px-2 border-b border-white/5 shrink-0">
            <a href="{{ route('dashboard') }}" wire:navigate
               @click="if(isMobile) closeMobile()"
               class="flex items-center gap-2 overflow-hidden w-full"
               :class="(isMobile || open) ? 'px-1.5' : 'justify-center'">
                <span class="flex items-center justify-center w-7 h-7 rounded-md text-white text-[10px] font-bold shrink-0"
                      style="background-color: var(--color-primary);">ERP</span>
                <span x-show="isMobile || open" x-transition.opacity class="flex-1 min-w-0 truncate text-[13px] font-medium text-white">{{ $sidebarBrand }} ERP</span>
            </a>
        </div>

        {{-- 사용자 정보 --}}
        <div class="px-3 py-3 border-b border-white/5 shrink-0 overflow-hidden">
            <div x-show="isMobile || open" x-transition.opacity>
                <div class="text-[13px] font-medium text-white truncate">{{ $user->name }}</div>
                <div class="text-[11px]" style="color: var(--color-sidebar-text);">{{ $permissionLabel }}</div>
            </div>
            <div x-show="!isMobile && !open" class="flex justify-center">
                <div class="w-7 h-7 rounded-full bg-white/10 flex items-center justify-center text-[11px] font-medium text-white"
                     :title="'{{ addslashes($user->name) }} · {{ $permissionLabel }}'">
                    {{ $user->initials() }}
                </div>
            </div>
        </div>

        {{-- 메뉴 --}}
        <nav class="flex-1 overflow-y-auto py-3 space-y-3">
            @foreach($menuGroups as $group)
                {{-- 빈그룹 자동숨김 — role 로 항목이 전부 가려지면 헤더도 미출력 (단일출처=항목 'show') --}}
                @php $visibleItems = array_values(array_filter($group['items'], fn ($it) => $it['show'])); @endphp
                @if(count($visibleItems))
                    <div x-data="{ grpOpen: localStorage.getItem('navgrp-{{ $group['key'] }}') !== 'false' }">
                        {{-- 그룹 헤더 = 접기 토글 (펼친 사이드바에서만). 아이콘 모드(!open)에선 헤더 숨고 항목은 그대로 노출 --}}
                        <button type="button" x-show="isMobile || open" x-transition.opacity
                                @click="grpOpen = !grpOpen; localStorage.setItem('navgrp-{{ $group['key'] }}', grpOpen)"
                                class="sidebar-section-label flex w-full items-center justify-between hover:text-white">
                            <span>{{ $group['label'] }}</span>
                            <svg class="h-3 w-3 shrink-0 transition-transform" :class="{ '-rotate-90': !grpOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        {{-- 항목: 아이콘 모드면 항상 표시, 펼친 모드면 grpOpen 일 때만 --}}
                        <div x-show="!(isMobile || open) || grpOpen" x-transition.opacity>
                            @foreach($visibleItems as $item)
                                <a href="{{ $item['href'] }}" wire:navigate
                                   @click="if(isMobile) closeMobile()"
                                   :title="(isMobile || open) ? '' : '{{ $item['label'] }}'"
                                   class="sidebar-item {{ $item['active'] ? 'is-active' : '' }}"
                                   :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$item['icon']] !!}</svg>
                                    <span x-show="isMobile || open" class="flex-1 truncate">{{ $item['label'] }}</span>
                                    @if(! empty($item['badge']))
                                    <span x-show="isMobile || open"
                                          class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                                        {{ $item['badge'] }}
                                    </span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        {{-- 하단 고정: 업무 가이드 + 설정 + 로그아웃 --}}
        <div class="border-t border-white/5 py-2 shrink-0">
            @if($workGuideUrl)
            {{-- 업무 워크프로세스 Notion (외부 새 탭, wire:navigate 미사용) --}}
            <a href="{{ $workGuideUrl }}" target="_blank" rel="noopener noreferrer"
               @click="if(isMobile) closeMobile()"
               :title="(isMobile || open) ? '' : '{{ __('nav.action.work_guide') }}'"
               class="sidebar-item"
               :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['book'] !!}</svg>
                <span x-show="isMobile || open" class="flex-1 truncate">{{ __('nav.action.work_guide') }}</span>
                <svg x-show="isMobile || open" class="ml-auto h-3 w-3 opacity-60 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5h5m0 0v5m0-5L10 14M9 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-3"/>
                </svg>
            </a>
            @endif

            <a href="{{ route('settings.profile') }}" wire:navigate
               @click="if(isMobile) closeMobile()"
               :title="(isMobile || open) ? '' : '{{ __('nav.action.my_settings') }}'"
               class="sidebar-item {{ request()->routeIs('settings.*') ? 'is-active' : '' }}"
               :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['cog'] !!}</svg>
                <span x-show="isMobile || open" class="truncate">{{ __('nav.action.my_settings') }}</span>
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        :title="(isMobile || open) ? '' : '{{ __('nav.action.logout') }}'"
                        class="sidebar-item w-[calc(100%-16px)] text-left"
                        :class="{ 'sidebar-item-collapsed w-[calc(100%-12px)]': !isMobile && !open }">
                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['logout'] !!}</svg>
                    <span x-show="isMobile || open" class="truncate">{{ __('nav.action.logout') }}</span>
                </button>
            </form>
        </div>
    </aside>

    {{-- 메인 영역 --}}
    <div class="flex-1 min-w-0 flex flex-col overflow-hidden">

        {{-- Topbar --}}
        <header class="flex items-center h-11 bg-white border-b border-gray-200 px-3 shrink-0">
            <button type="button" @click="toggle()"
                    class="flex items-center justify-center w-8 h-8 rounded text-gray-600 hover:bg-gray-100 transition"
                    aria-label="{{ __('nav.action.toggle_sidebar') }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['menu'] !!}</svg>
            </button>

            <div class="ml-2 text-[13px] text-gray-700 truncate">{{ $breadcrumb }}</div>

            <div class="flex-1"></div>

            {{-- i18n Phase 0 — 언어 전환 (영어 활성 시만) --}}
            @if($localeEnEnabled)
                <form method="POST" action="{{ route('locale.update') }}" class="flex items-center gap-0.5 rounded-md bg-gray-100 p-0.5">
                    @csrf
                    @foreach(['ko', 'en'] as $loc)
                        <button type="submit" name="locale" value="{{ $loc }}"
                                @class([
                                    'rounded px-2 py-0.5 text-[11px] font-medium transition',
                                    'text-white' => app()->getLocale() === $loc,
                                    'text-gray-500 hover:bg-gray-200' => app()->getLocale() !== $loc,
                                ])
                                @style(['background-color: var(--color-primary)' => app()->getLocale() === $loc])>
                            {{ __('nav.lang.'.$loc) }}
                        </button>
                    @endforeach
                </form>
            @endif
        </header>

        {{-- Content --}}
        <main class="flex-1 overflow-auto bg-gray-50">
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Livewire dispatch('notify') 글로벌 토스트 listener — 모든 ERP 페이지 공통 --}}
<div x-data="{ items: [] }"
     @notify.window="
        let id = Date.now() + Math.random();
        items.push({ id, msg: $event.detail.message, type: $event.detail.type || 'info' });
        setTimeout(() => items = items.filter(i => i.id !== id), 4500);
     "
     class="fixed top-4 right-4 z-50 flex flex-col gap-2">
    <template x-for="item in items" :key="item.id">
        <div x-transition.opacity
             :class="{
                'bg-green-600': item.type === 'success',
                'bg-amber-500': item.type === 'warning',
                'bg-red-600': item.type === 'error',
                'bg-blue-600': item.type === 'info'
             }"
             class="rounded-lg px-4 py-3 text-sm text-white shadow-lg max-w-md">
            <span x-text="item.msg"></span>
        </div>
    </template>
</div>

{{-- 2026-06-18 ETA 통관서류 상주 알람 카드 (우하단, A동작) — canAccessClearance 만 --}}
@if($user->canAccessClearance())
    <livewire:erp.alarm-center />
@endif

{{-- 사내 업무 도우미 (로컬 LLM 챗봇, jin 2026-07-24) —
     .env(인프라: LLM 연결 준비) AND 기능설정 토글(super) AND canUseAssistant 모두 충족 시 노출.
     config 게이트를 앞에 둬 인프라 off 서버는 Setting 조회조차 안 함(성능). --}}
@if(config('assistant.enabled') && $user->canUseAssistant() && \App\Models\Setting::get('assistant_enabled', false))
    <livewire:assistant.widget />
@endif

@fluxScripts
</body>
</html>
