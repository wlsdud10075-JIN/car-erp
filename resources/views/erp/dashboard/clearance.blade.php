{{-- 업무 대시보드 · 수출통관 뷰 (jin 2026-10-17) — 서류 칸반 4칸 + 이번 주 배 + 서류 기준 표. $this = erp.dashboard 컴포넌트. --}}
@php
    $kb = $this->documentKanban;
    $week = $this->weekCalendar;
    $t = fn (string $k, array $r = []) => __("dashboard.v.clearance.$k", $r);
    $vehiclesUrl = fn (string $action) => route('erp.vehicles.index').'?action='.$action;
@endphp

{{-- 서류 칸반 --}}
<div class="card" data-clearance-kanban>
    <div class="section-header"><span class="section-dot bg-amber-500"></span><span class="section-title">{{ $t('kanban.title') }}</span></div>
    <div class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach($kb as $key => $col)
        <div class="rounded-lg border border-gray-200 bg-white p-3" data-kanban="{{ $key }}">
            <div class="flex items-center justify-between">
                <span class="badge {{ $col['badge'] }}">{{ $t("kanban.cols.$key") }}</span>
                <span class="text-2xl font-bold text-gray-900">{{ $col['count'] }}<span class="ml-0.5 text-xs font-normal text-gray-500">{{ __('dashboard.unit_vehicle') }}</span></span>
            </div>
            <div class="mt-1 text-[11px] text-gray-500">
                @if($col['oldest_days'] !== null){!! str_replace(':n', '<b class="text-red-700">'.$col['oldest_days'].'</b>', e($t('kanban.oldest', ['n' => ':n']))) !!}@else {{ $t('kanban.empty') }}@endif
            </div>
            <div class="mt-2 divide-y divide-gray-100">
                @foreach($col['top'] as $row)
                <div class="flex items-center justify-between py-1 text-xs">
                    <a href="{{ $this->vehicleLink($row['id']) }}" wire:navigate class="font-medium text-gray-800 hover:text-violet-700">{{ $row['number'] }}</a>
                    <span class="text-gray-500">{{ $row['days'] !== null ? $row['days'].__('dashboard.v.unit_day') : '—' }}</span>
                </div>
                @endforeach
            </div>
            <a href="{{ $vehiclesUrl($key) }}" wire:navigate class="mt-2 block text-right text-xs text-primary-text hover:underline">{{ $t('kanban.all') }}</a>
        </div>
        @endforeach
    </div>
</div>

{{-- 이번 주 배 --}}
<div class="card" data-clearance-week>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-emerald-500"></span><span class="section-title">{{ $t('week.title') }}</span></div>
        <span class="text-xs text-gray-500">{{ $t('week.range', ['from' => $week['start']->format('m-d'), 'to' => $week['end']->format('m-d')]) }} · {{ $t('week.ship', ['n' => $week['ship_total']]) }} · {{ $t('week.eta', ['n' => $week['eta_total']]) }}
            · <a href="{{ route('erp.shipping-requests.index') }}" wire:navigate class="text-primary-text hover:underline">{{ $t('week.link') }}</a></span>
    </div>
    @if($week['ship_total'] === 0 && $week['eta_total'] === 0)
    <p class="mt-3 text-sm text-gray-400">{{ $t('week.none') }}</p>
    @else
    <div class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-4 lg:grid-cols-7">
        @foreach($week['days'] as $d)
        <div class="min-h-[88px] rounded-lg border px-2 py-1.5 {{ $d['is_today'] ? 'border-violet-400 bg-violet-50' : 'border-gray-200 bg-white' }}">
            <div class="text-[11px] font-semibold {{ $d['is_today'] ? 'text-violet-700' : 'text-gray-500' }}">{{ $d['date']->locale(app()->getLocale())->isoFormat('ddd D') }}@if($d['is_today']) · {{ __('dashboard.v.today') }}@endif</div>
            @foreach($d['ships'] as $s)
            <div class="mt-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">🚢 {{ $t('week.ship', ['n' => $s['count']]) }}@if($s['vessel']) <span class="text-amber-600">({{ $s['vessel'] }})</span>@endif</div>
            @endforeach
            @if($d['eta'] > 0)
            <div class="mt-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800">⚓ {{ $t('week.eta', ['n' => $d['eta']]) }}</div>
            @endif
        </div>
        @endforeach
    </div>
    @endif
</div>

{{-- 진행중 차량 — 서류 기준 --}}
@include('erp.dashboard._document-table', ['withMoney' => false])
