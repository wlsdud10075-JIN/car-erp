{{-- 업무 대시보드 · 관리 뷰 (jin 2026-10-16/17: 관리 = 수출통관 + 재무 겸직) — 「오늘 먼저」(= 처리 필요 항목, 두 큐를 긴급순으로) 는 공통 블록이 그린다. 여기는 서류 축약 + 돈 + 표. --}}
@php
    $kb = $this->documentKanban;
    $m = $this->moneyOverview;
    $steps = $this->settlementSteps;
    $t = fn (string $k, array $r = []) => __("dashboard.v.manage.$k", $r);
    $ft = fn (string $k, array $r = []) => __("dashboard.v.finance.$k", $r);
@endphp

<div class="grid grid-cols-1 gap-3 lg:grid-cols-2" data-manage-two>
    <div class="card">
        <div class="flex items-center justify-between">
            <div class="section-header"><span class="section-dot bg-amber-500"></span><span class="section-title">{{ $t('docs.title') }}</span></div>
            <button type="button" @click="setView('수출통관')" class="text-xs text-primary-text hover:underline">{{ $t('docs.link') }}</button>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-2">
            @foreach($kb as $key => $col)
            <a href="{{ route('erp.vehicles.index') }}?action={{ $key }}" wire:navigate class="rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50">
                <div class="flex items-center justify-between"><span class="badge {{ $col['badge'] }}">{{ __("dashboard.v.clearance.kanban.cols.$key") }}</span><span class="text-lg font-bold text-gray-900">{{ $col['count'] }}</span></div>
                <div class="text-[11px] text-gray-500">{{ $col['oldest_days'] !== null ? __('dashboard.v.clearance.kanban.oldest', ['n' => $col['oldest_days']]) : __('dashboard.v.clearance.kanban.empty') }}</div>
            </a>
            @endforeach
        </div>
    </div>
    <div class="card">
        <div class="flex items-center justify-between">
            <div class="section-header"><span class="section-dot bg-red-500"></span><span class="section-title">{{ $t('money.title') }}</span></div>
            <button type="button" @click="setView('재무')" class="text-xs text-primary-text hover:underline">{{ $t('money.link') }}</button>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
            <div><div class="text-xs text-gray-500">{{ $t('money.receivable') }}</div><div class="text-xl font-bold text-gray-900">{{ $this->krw($m['receivable_krw']) }}</div><div class="text-[11px] text-gray-500">{{ $ft('receivable.count', ['n' => $m['receivable_count']]) }} · {{ $ft('receivable.risk', ['n' => $m['risk_count'], 'amount' => $this->krw($m['risk_krw'])]) }}</div></div>
            <div><div class="text-xs text-gray-500">{{ $t('money.payable') }}</div><div class="text-xl font-bold text-gray-900">{{ $this->krw($m['payable_krw']) }}</div><div class="text-[11px] text-gray-500">{{ $ft('payable.count', ['n' => $m['payable_count']]) }} · {{ $ft('payable.week', ['n' => $m['payable_week_count']]) }}</div></div>
        </div>
        <div class="mt-3 grid grid-cols-4 overflow-hidden rounded-lg border border-gray-200 text-xs divide-x divide-gray-200">
            @foreach(['pending', 'confirmed', 'held', 'secondary'] as $k)
            <div class="px-2 py-1.5 {{ $k === 'held' ? 'bg-amber-50' : '' }}"><div class="text-gray-500">{{ $ft("steps.$k") }}</div><b class="{{ $k === 'held' ? 'text-amber-700' : 'text-gray-900' }}">{{ $steps[$k] }}</b></div>
            @endforeach
        </div>
    </div>
</div>

@include('erp.dashboard._document-table', ['withMoney' => true])
