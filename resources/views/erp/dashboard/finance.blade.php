{{-- 업무 대시보드 · 재무 뷰 (jin 2026-10-17) — 받을 돈·줄 돈 두 숫자 → 정산 진행줄 → 통장잔액 입력 → 채권 위험 표. $this = erp.dashboard 컴포넌트. --}}
@php
    $m = $this->moneyOverview;
    $steps = $this->settlementSteps;
    $t = fn (string $k, array $r = []) => __("dashboard.v.finance.$k", $r);
    $bar = fn (int $num, int $den, string $color) => '<div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200"><div class="h-full '.$color.'" style="width: '.($den > 0 ? max(0, min(100, (int) round($num / $den * 100))) : 0).'%"></div></div>';
@endphp

<div class="grid grid-cols-1 gap-3 md:grid-cols-2" data-finance-money>
    <div class="card">
        <div class="text-xs text-gray-500">{{ $t('receivable.title') }}</div>
        <div class="mt-1 text-2xl font-bold text-gray-900 md:text-3xl">{{ $this->krw($m['receivable_krw']) }}</div>
        <div class="mt-1 text-xs text-gray-500">{{ $t('receivable.count', ['n' => $m['receivable_count']]) }} · {{ $t('receivable.after') }} <b>{{ $this->krw($m['after_krw']) }}</b>({{ $m['after_count'] }}) · {{ $t('receivable.before') }} {{ $this->krw($m['before_krw']) }}({{ $m['before_count'] }}) · {{ $t('receivable.grace') }} {{ $this->krw($m['grace_krw']) }}({{ $m['grace_count'] }})</div>
        <div class="mt-2">{!! $bar($m['risk_krw'], $m['receivable_krw'], 'bg-red-500') !!}</div>
        <div class="mt-1 text-[11px] text-gray-500">{!! str_replace([':n', ':amount'], ['<b class="text-red-700">'.$m['risk_count'].'</b>', '<b class="text-red-700">'.e($this->krw($m['risk_krw'])).'</b>'], e($t('receivable.risk', ['n' => ':n', 'amount' => ':amount']))) !!}
            · <a href="{{ route('erp.receivables.index') }}" wire:navigate class="text-primary-text hover:underline">{{ $t('receivable.link') }}</a></div>
    </div>
    <div class="card">
        <div class="text-xs text-gray-500">{{ $t('payable.title') }}</div>
        <div class="mt-1 text-2xl font-bold text-gray-900 md:text-3xl">{{ $this->krw($m['payable_krw']) }}</div>
        <div class="mt-1 text-xs text-gray-500">{{ $t('payable.count', ['n' => $m['payable_count']]) }} · {{ $t('payable.week', ['n' => $m['payable_week_count']]) }}</div>
        <div class="mt-2">{!! $bar($m['payable_week_count'], $m['payable_count'], 'bg-amber-500') !!}</div>
        <div class="mt-1 text-[11px] text-gray-500">{{ $t('payable.cancel', ['n' => $m['cancel_count']]) }}
            · <a href="{{ route('erp.vehicles.index') }}?action=purchase_unpaid" wire:navigate class="text-primary-text hover:underline">{{ $t('payable.link') }}</a></div>
    </div>
</div>

{{-- 정산 진행줄 --}}
<div class="card" data-finance-steps>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ $t('steps.title') }}</span></div>
        <a href="{{ route('erp.settlements.index') }}" wire:navigate class="text-xs text-primary-text hover:underline">{{ $t('steps.link') }}</a>
    </div>
    <div class="mt-2 grid grid-cols-2 overflow-hidden rounded-lg border border-gray-200 text-sm md:grid-cols-4 md:divide-x md:divide-gray-200">
        @foreach([['pending', '', 'text-gray-900', route('erp.settlements.index').'?status=pending'], ['confirmed', '', 'text-gray-900', route('erp.settlements.index').'?status=confirmed'], ['held', 'bg-amber-50', 'text-amber-700', route('erp.settlements.index').'?held=1'], ['secondary', '', 'text-gray-900', route('erp.settlements.index').'?secondary=pending']] as [$k, $bg, $color, $href])
        <a href="{{ $href }}" wire:navigate class="block px-3 py-2.5 hover:bg-gray-50 {{ $bg }}">
            <div class="text-xs text-gray-500">{{ $t("steps.$k") }}</div>
            <div class="text-xl font-bold {{ $color }}">{{ $steps[$k] }}<span class="text-xs font-normal text-gray-500">{{ $t('steps.unit') }}</span></div>
            <div class="text-[11px] text-gray-500">{{ $t("steps.{$k}_h") }}</div>
        </a>
        @endforeach
    </div>
</div>

{{-- 통장 마감잔액 입력 (현행 그대로) --}}
@include('erp.dashboard._cash')

{{-- 채권 위험 표 --}}
@php $risk = $this->riskTable; @endphp
<div class="card" data-finance-risk>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-red-500"></span><span class="section-title">{{ $t('risk.title') }}</span></div>
        <a href="{{ route('erp.receivables.index') }}?riskFilter=danger" wire:navigate class="text-xs text-primary-text hover:underline">{{ $t('risk.link') }}</a>
    </div>
    @if($risk->isEmpty())
    <p class="mt-3 text-sm text-gray-400">{{ $t('risk.empty') }}</p>
    @else
    <div class="mt-2 overflow-x-auto">
        <table class="w-full min-w-[640px] text-xs tabular-nums">
            <thead><tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                <th class="py-1.5 pr-3 font-medium">{{ $t('risk.col.vehicle') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('risk.col.salesman') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('risk.col.buyer') }}</th>
                <th class="py-1.5 pr-3 text-right font-medium">{{ $t('risk.col.unpaid') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('risk.col.grade') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ $t('risk.col.days') }}</th><th class="py-1.5 font-medium">{{ $t('risk.col.manager') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50 whitespace-nowrap text-gray-800">
            @foreach($risk as $v)
            <tr class="hover:bg-gray-50">
                <td class="py-2 pr-3"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="font-medium text-gray-900 hover:text-violet-700">{{ $v->vehicle_number }}</a></td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->salesman?->name ?? '—' }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->buyer?->name ?? '—' }}</td>
                <td class="py-2 pr-3 text-right font-semibold text-red-700">{{ number_format($v->sale_unpaid_amount, 0) }} <span class="font-normal text-gray-500">{{ $v->currency }}</span></td>
                <td class="py-2 pr-3"><span class="badge {{ $v->receivable_risk === 'critical' ? 'badge-red' : 'badge-amber' }}">{{ __('receivable.risk.'.$v->receivable_risk) }}</span></td>
                <td class="py-2 pr-3 text-right text-gray-600">{{ $v->days_since_sale !== null ? $v->days_since_sale.__('dashboard.v.unit_day') : '—' }}</td>
                <td class="py-2 text-gray-600">{{ $v->receivableManager?->name ?? '—' }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
