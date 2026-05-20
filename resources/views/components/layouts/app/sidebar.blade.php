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
        'super' => '시스템관리자',
        'admin' => '최고관리자',
        default => '일반사용자',
    };

    $sidebarBrand = \App\Models\Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR';

    $routeName = request()->route()?->getName();
    $breadcrumb = match (true) {
        $routeName === 'dashboard' => '대시보드',
        $routeName === 'admin.dashboard' => '관리자 대시보드',
        $routeName === 'admin.users.index' => '사용자 관리',
        $routeName === 'admin.settings' => '기능 설정',
        $routeName === 'erp.dashboard' => 'ERP 대시보드',
        $routeName === 'erp.vehicles.index' => '차량 관리',
        $routeName === 'erp.buyers.index' => '바이어 관리',
        $routeName === 'erp.consignees.index' => '컨사이니 관리',
        $routeName === 'erp.forwarding-companies.index' => '포워딩사 관리',
        $routeName === 'erp.salesmen.index' => '영업담당자 관리',
        $routeName === 'erp.salesmen.cashflow' => '내 캐시플로우',
        $routeName === 'erp.settlements.index' => '정산 관리',
        $routeName === 'erp.receivables.index' => '채권관리',
        $routeName === 'erp.approvals.index' => '승인 큐',
        $routeName === 'erp.transfers.index' => '재무 처리',
        $routeName === 'settings.profile' => '프로필 설정',
        $routeName === 'settings.password' => '비밀번호 변경',
        $routeName === 'settings.appearance' => '테마 설정',
        default => '',
    };

    $mySalesman = $user->salesman ?? null;
    $isSalesUser = ! $user->canAccessAdmin() && $user->canAccessSales() && $mySalesman;

    // 큐 14-3 — 승인 대기 건수 (canApprove user만 계산)
    $pendingApprovals = $user->canApprove()
        ? \App\Models\ApprovalRequest::where('status', 'pending')->count()
        : 0;

    // 큐 19-F / 20-C — 재무 처리 대기 건수 합산 (자금 이체 + 매입 잔금 + 판매 잔금)
    // 단일 사용자(canConfirmFinanceTransfer)만 계산. 비-재무 사용자는 0.
    if ($user->canConfirmFinanceTransfer()) {
        $pendingTransferCount = \App\Models\InterVehicleTransfer::whereIn('status', [
            \App\Models\InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
            \App\Models\InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
        ])->count();
        // 큐 20-C — 영업 직접 입력 잔금 중 미확정 (transfer_id IS NULL AND confirmed_at IS NULL)
        $pendingFinalPaymentCount = \App\Models\FinalPayment::query()
            ->whereNull('transfer_id')
            ->whereNull('confirmed_at')
            ->count();
        $pendingPurchaseBalanceCount = \App\Models\PurchaseBalancePayment::query()
            ->whereNull('confirmed_at')
            ->count();
        $pendingFinanceConfirmations = $pendingTransferCount + $pendingFinalPaymentCount + $pendingPurchaseBalanceCount;
    } else {
        $pendingFinanceConfirmations = 0;
    }

    $menuGroups = [
        [
            'label' => '메인',
            'show' => true,
            'items' => [
                [
                    'label' => '대시보드',
                    'href' => route('dashboard'),
                    'icon' => 'home',
                    'active' => request()->routeIs('dashboard') || request()->routeIs('admin.dashboard') || request()->routeIs('erp.dashboard'),
                    'show' => true,
                ],
            ],
        ],
        [
            'label' => 'ERP',
            'show' => $user->canAccessErp(),
            'items' => [
                [
                    'label' => '차량 관리',
                    'href' => route('erp.vehicles.index'),
                    'icon' => 'truck',
                    'active' => request()->routeIs('erp.vehicles.*'),
                    'show' => true,
                ],
                // 2026-05-19 풀회의 안건 H — 통관 role 단축 링크 (차량 목록 수출통관중 필터)
                [
                    'label' => '수출통관',
                    'href' => route('erp.vehicles.index').'?progressFilter=수출통관중',
                    'icon' => 'identification',
                    'active' => false,
                    'show' => $user->canAccessClearance(),
                ],
                [
                    'label' => '바이어',
                    'href' => route('erp.buyers.index'),
                    'icon' => 'users',
                    'active' => request()->routeIs('erp.buyers.*'),
                    'show' => true,
                ],
                [
                    'label' => '컨사이니',
                    'href' => route('erp.consignees.index'),
                    'icon' => 'identification',
                    'active' => request()->routeIs('erp.consignees.*'),
                    'show' => true,
                ],
                [
                    'label' => '재무',
                    'href' => $user->canAccessSettlement() ? route('erp.settlements.index') : '#',
                    'icon' => 'calculator',
                    'active' => request()->routeIs('erp.settlements.*'),
                    'show' => $user->canAccessSettlement(),
                ],
                [
                    'label' => '채권관리',
                    'href' => $user->canViewReceivables() ? route('erp.receivables.index') : '#',
                    'icon' => 'banknotes',
                    'active' => request()->routeIs('erp.receivables.*'),
                    'show' => $user->canViewReceivables(),
                ],
                [
                    'label' => '승인 큐',
                    'href' => $user->canApprove() ? route('erp.approvals.index') : '#',
                    'icon' => 'check-circle',
                    'active' => request()->routeIs('erp.approvals.*'),
                    'show' => $user->canApprove(),
                    'badge' => $pendingApprovals > 0 ? $pendingApprovals : null,
                ],
                [
                    'label' => '재무 처리',
                    'href' => $user->canConfirmFinanceTransfer() ? route('erp.transfers.index') : '#',
                    'icon' => 'banknotes',
                    'active' => request()->routeIs('erp.transfers.*'),
                    'show' => $user->canConfirmFinanceTransfer(),
                    'badge' => $pendingFinanceConfirmations > 0 ? $pendingFinanceConfirmations : null,
                ],
                [
                    'label' => '포워딩사',
                    'href' => $user->canAccessAdmin() ? route('erp.forwarding-companies.index') : '#',
                    'icon' => 'building',
                    'active' => request()->routeIs('erp.forwarding-companies.*'),
                    'show' => $user->canAccessAdmin(),
                ],
                [
                    'label' => '영업담당자',
                    'href' => $user->canAccessAdmin() ? route('erp.salesmen.index') : '#',
                    'icon' => 'briefcase',
                    'active' => request()->routeIs('erp.salesmen.index'),
                    'show' => $user->canAccessAdmin(),
                ],
                [
                    'label' => '내 캐시플로우',
                    'href' => $isSalesUser ? route('erp.salesmen.cashflow', $mySalesman->id) : '#',
                    'icon' => 'chart-bar',
                    'active' => request()->routeIs('erp.salesmen.cashflow'),
                    'show' => $isSalesUser,
                ],
            ],
        ],
        [
            'label' => '기타관리',
            'show' => $user->canAccessAdmin(),
            'items' => [
                [
                    'label' => '관리자 대시보드',
                    'href' => route('admin.dashboard'),
                    'icon' => 'chart-bar',
                    'active' => request()->routeIs('admin.dashboard'),
                    'show' => true,
                ],
                [
                    'label' => '사용자 관리',
                    'href' => route('admin.users.index'),
                    'icon' => 'user-group',
                    'active' => request()->routeIs('admin.users.*'),
                    'show' => true,
                ],
                [
                    'label' => '기능 설정',
                    'href' => $user->isSuperAdmin() ? route('admin.settings') : '#',
                    'icon' => 'cog',
                    'active' => request()->routeIs('admin.settings'),
                    'show' => $user->isSuperAdmin(),
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
        <nav class="flex-1 overflow-y-auto py-3 space-y-4">
            @foreach($menuGroups as $group)
                @if($group['show'])
                    <div>
                        <div x-show="isMobile || open" x-transition.opacity class="sidebar-section-label">{{ $group['label'] }}</div>
                        @foreach($group['items'] as $item)
                            @if($item['show'])
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
                            @endif
                        @endforeach
                    </div>
                @endif
            @endforeach
        </nav>

        {{-- 하단 고정: 설정 + 로그아웃 --}}
        <div class="border-t border-white/5 py-2 shrink-0">
            <a href="{{ route('settings.profile') }}" wire:navigate
               @click="if(isMobile) closeMobile()"
               :title="(isMobile || open) ? '' : '내 설정'"
               class="sidebar-item {{ request()->routeIs('settings.*') ? 'is-active' : '' }}"
               :class="{ 'sidebar-item-collapsed': !isMobile && !open }">
                <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['cog'] !!}</svg>
                <span x-show="isMobile || open" class="truncate">내 설정</span>
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        :title="(isMobile || open) ? '' : '로그아웃'"
                        class="sidebar-item w-[calc(100%-16px)] text-left"
                        :class="{ 'sidebar-item-collapsed w-[calc(100%-12px)]': !isMobile && !open }">
                    <svg class="sidebar-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['logout'] !!}</svg>
                    <span x-show="isMobile || open" class="truncate">로그아웃</span>
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
                    aria-label="사이드바 토글">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons['menu'] !!}</svg>
            </button>

            <div class="ml-2 text-[13px] text-gray-700 truncate">{{ $breadcrumb }}</div>

            <div class="flex-1"></div>
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

@fluxScripts
</body>
</html>
