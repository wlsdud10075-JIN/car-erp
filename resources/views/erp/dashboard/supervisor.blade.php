{{-- 업무 대시보드 · 업무관리자 뷰 (jin 2026-10-17: 결재는 대표 — 여기는 올릴 것·멈춘 것·팀별 미처리·보고용 숫자). $this = erp.dashboard 컴포넌트. --}}
@php
    $s = $this->submissionStatus;
    $sp = $this->stuckPaged;
    $stuck = $sp['rows'];
    $teams = $this->managerTeams;
    $report = $this->reportNumbers;
    $managerNames = $this->managerNames;
    $t = fn (string $k, array $r = []) => __("dashboard.v.supervisor.$k", $r);
    $batch = $s['batch'];
    $monthLabel = \Illuminate\Support\Carbon::parse($s['month'].'-01')->format('n');
    $batchLabel = fn ($b) => $b->kind === \App\Models\SettlementPayoutBatch::KIND_LEAVER
        ? $t('submit.label_leaver', ['name' => $b->salesman?->name ?? '—'])
        : $t('submit.label_month', ['month' => \Illuminate\Support\Carbon::parse($b->month.'-01')->format('n')]);
@endphp

{{-- 이번 달 올릴 것 --}}
<div class="card" data-supervisor-submit>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-violet-500"></span><span class="section-title">{{ $t('submit.title') }}</span></div>
        <span class="text-xs text-gray-500">{{ $t('submit.sub') }}</span>
    </div>
    <div class="mt-2 rounded-lg border border-gray-200 px-3 py-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div><span class="badge badge-purple">{{ $t('submit.badge') }}</span> <b class="text-gray-900">{{ $t('submit.month', ['month' => $monthLabel]) }}</b>
                <span class="ml-1 text-xs text-gray-500">—
                    @if(! $batch) {{ $t('submit.not_submitted') }}
                    @elseif($batch->status === \App\Models\SettlementPayoutBatch::STATUS_PENDING) {{ $t('submit.batch_pending') }}
                    @elseif($batch->status === \App\Models\SettlementPayoutBatch::STATUS_APPROVED) {{ $t('submit.batch_approved') }}
                    @else {{ $t('submit.batch_rejected') }}
                    @endif
                </span></div>
            <a href="{{ route('erp.settlements.index') }}" wire:navigate class="btn-primary px-3 py-1 text-xs">{{ $t('submit.go') }}</a>
        </div>
        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <span class="flex items-center gap-2"><span class="section-dot bg-emerald-500"></span>{{ $t('submit.eligible', ['n' => $s['eligible_count']]) }}</span>
            <span class="flex items-center gap-2"><span class="section-dot {{ $s['unconfirmed_count'] > 0 ? 'bg-red-500' : 'bg-gray-300' }}"></span><span class="{{ $s['unconfirmed_count'] > 0 ? 'text-red-700' : '' }}">{{ $t('submit.unconfirmed', ['n' => $s['unconfirmed_count']]) }}</span>
                <a href="{{ route('erp.settlements.index') }}?status=pending" wire:navigate class="text-xs text-primary-text hover:underline">→</a></span>
            <span class="flex items-center gap-2"><span class="section-dot {{ $s['held_count'] > 0 ? 'bg-amber-500' : 'bg-gray-300' }}"></span><span class="{{ $s['held_count'] > 0 ? 'text-amber-700' : '' }}">{{ $t('submit.held', ['n' => $s['held_count']]) }}</span>
                <a href="{{ route('erp.settlements.index') }}?held=1" wire:navigate class="text-xs text-primary-text hover:underline">→</a></span>
        </div>
    </div>
    @foreach($s['leavers'] as $lv)
    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2">
        <div><span class="badge badge-amber">{{ $t('submit.leaver') }}</span> <b class="text-gray-900">{{ $t('submit.leaver_row', ['name' => $lv->name, 'date' => $lv->left_at?->format('m-d')]) }}</b></div>
        <a href="{{ route('erp.settlements.index') }}?leavers=1" wire:navigate class="rounded border border-gray-300 bg-white px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">{{ $t('submit.leaver_go') }}</a>
    </div>
    @endforeach
    <div class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-gray-500">
        <span class="font-medium text-gray-700">{{ $t('submit.awaiting') }}:</span>
        @forelse($s['awaiting'] as $b)
        <span>{{ $t('submit.awaiting_row', ['label' => $batchLabel($b), 'who' => $b->submitter?->name ?? '—', 'days' => $b->submitted_at ? (int) $b->submitted_at->diffInDays(now()) + 1 : 0]) }}</span>
        @empty
        <span>{{ $t('submit.none_awaiting') }}</span>
        @endforelse
        @if($s['last_approved'])
        <span class="text-gray-400">{{ $t('submit.last_approved', ['month' => \Illuminate\Support\Carbon::parse($s['last_approved']->month.'-01')->format('n'), 'date' => $s['last_approved']->decided_at?->format('m-d')]) }}</span>
        @endif
    </div>
</div>

{{-- 오래 멈춘 차 --}}
<div class="card" data-supervisor-stuck>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-red-500"></span><span class="section-title">{{ $t('stuck.title') }}</span>@if($sp['total']) <span class="pill-count">{{ $t('stuck.total', ['n' => $sp['total']]) }}</span>@endif</div>
        <span class="text-xs text-gray-500">{{ $t('stuck.sub') }}</span>
    </div>
    @if($stuck->isEmpty())
    <p class="mt-3 text-sm text-gray-400">{{ $t('stuck.empty') }}</p>
    @else
    <div class="mt-2 overflow-x-auto">
        <table class="w-full min-w-[640px] text-xs tabular-nums">
            <thead><tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                <th class="py-1.5 pr-3 font-medium">{{ $t('stuck.col.vehicle') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('stuck.col.salesman') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('stuck.col.manager') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ $t('stuck.col.where') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ $t('stuck.col.days') }}</th><th class="py-1.5 font-medium">{{ $t('stuck.col.action') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50 whitespace-nowrap text-gray-800">
            @foreach($stuck as $row)
            @php $v = $row['vehicle']; $mgrs = $managerNames[$v->salesman_id] ?? []; $days = $row['days']; @endphp
            <tr class="hover:bg-gray-50">
                <td class="py-2 pr-3"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="font-medium text-gray-900 hover:text-violet-700">{{ $v->vehicle_number }}</a></td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->salesman?->name ?? '—' }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ $mgrs ? implode(' · ', $mgrs) : $t('stuck.unassigned') }}</td>
                <td class="py-2 pr-3 text-gray-700">{{ $t('stuck.where.'.$row['where']) }}</td>
                <td class="py-2 pr-3 text-right font-semibold {{ $days !== null && $days >= 60 ? 'text-red-700' : 'text-amber-700' }}">{{ $days !== null ? $days.__('dashboard.v.unit_day') : '—' }}</td>
                <td class="py-2"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="text-primary-text hover:underline">{{ $mgrs ? $t('stuck.to', ['name' => $mgrs[0]]) : $t('stuck.unassigned') }} →</a></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if($sp['pages'] > 1)
    {{-- 15대씩 — 페이지 상태는 Livewire 프로퍼티(stuckPage). 공용 paginator 는 ?page= 링크라 안 쓴다. --}}
    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500" data-supervisor-stuck-pager>
        <span>{{ $t('stuck.page', ['from' => $sp['from'], 'to' => $sp['to'], 'total' => $sp['total']]) }}</span>
        <div class="flex items-center gap-1">
            <button type="button" wire:click="moveStuckPage(-1)" class="rounded border border-gray-300 bg-white px-2 py-1 text-gray-700 hover:bg-gray-50 {{ $sp['page'] <= 1 ? 'pointer-events-none opacity-40' : '' }}">‹ {{ $t('stuck.prev') }}</button>
            <span class="px-1 tabular-nums">{{ $sp['page'] }} / {{ $sp['pages'] }}</span>
            <button type="button" wire:click="moveStuckPage(1)" class="rounded border border-gray-300 bg-white px-2 py-1 text-gray-700 hover:bg-gray-50 {{ $sp['page'] >= $sp['pages'] ? 'pointer-events-none opacity-40' : '' }}">{{ $t('stuck.next') }} ›</button>
        </div>
    </div>
    @endif
    @endif
</div>

{{-- 관리별 미처리 --}}
<div class="card" data-supervisor-teams>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-gray-300"></span><span class="section-title">{{ $t('teams.title') }}</span></div>
        <a href="{{ route('admin.users.index') }}" wire:navigate class="text-xs text-primary-text hover:underline">{{ $t('teams.link') }}</a>
    </div>
    @if(! count($teams))
    <p class="mt-3 text-sm text-gray-400">{{ $t('teams.empty') }}</p>
    @else
    <div class="mt-2 overflow-x-auto">
        <table class="w-full min-w-[720px] text-xs tabular-nums">
            <thead><tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                <th class="py-1.5 pr-3 font-medium">{{ $t('teams.col.manager') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('teams.col.salesmen') }}</th>
                <th class="py-1.5 pr-3 text-right font-medium">{{ $t('teams.col.docs') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ $t('teams.col.unpaid') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ $t('teams.col.risk') }}</th>
                <th class="py-1.5 pr-3 text-right font-medium">{{ $t('teams.col.purchase') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ $t('teams.col.held') }}</th><th class="py-1.5 text-right font-medium">{{ $t('teams.col.stuck') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50 text-gray-800">
            @foreach($teams as $row)
            <tr>
                <td class="py-2 pr-3 whitespace-nowrap font-medium text-gray-900">{{ $row['name'] ?? $t('teams.unassigned') }}@if($row['delegating']) <span class="badge badge-amber">{{ $t('teams.delegating') }}</span>@endif</td>
                <td class="py-2 pr-3 text-gray-600">{{ $row['salesmen'] ? implode(' · ', $row['salesmen']) : '—' }}</td>
                <td class="py-2 pr-3 text-right {{ $row['docs'] > 0 ? 'font-semibold text-amber-700' : 'text-gray-400' }}">{{ $row['docs'] }}</td>
                <td class="py-2 pr-3 text-right {{ $row['unpaid_krw'] > 0 ? 'font-semibold text-red-700' : 'text-gray-400' }}">{{ $this->krw($row['unpaid_krw']) }}</td>
                <td class="py-2 pr-3 text-right {{ $row['risk_count'] > 0 ? 'font-semibold text-red-700' : 'text-gray-400' }}">{{ $row['risk_count'] }}</td>
                <td class="py-2 pr-3 text-right text-gray-700">{{ $this->krw($row['purchase_krw']) }}</td>
                <td class="py-2 pr-3 text-right {{ $row['held'] > 0 ? 'font-semibold text-amber-700' : 'text-gray-400' }}">{{ $row['held'] }}</td>
                <td class="py-2 text-right {{ $row['stuck'] > 0 ? 'font-semibold text-gray-900' : 'text-gray-400' }}">{{ $row['stuck'] }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- 보고용 숫자 — 이 화면 안에 둔다(관리자 대시보드는 대표만 본다, jin 2026-10-17 「전자」) --}}
<div class="card" data-supervisor-report>
    <div class="section-header"><span class="section-dot bg-gray-300"></span><span class="section-title">{{ $t('report.title') }}</span></div>
    <div class="mt-2 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
        <div><div class="text-xs text-gray-500">{{ $t('report.receivable') }}</div><div class="text-lg font-bold text-gray-900">{{ $this->krw($report['receivable_krw']) }}</div><div class="text-[11px] text-gray-500">{{ $t('report.risk', ['n' => $report['risk_count']]) }}</div></div>
        <div><div class="text-xs text-gray-500">{{ $t('report.payable') }}</div><div class="text-lg font-bold text-gray-900">{{ $this->krw($report['payable_krw']) }}</div><div class="text-[11px] text-gray-500">{{ $t('report.week', ['n' => $report['payable_week_count']]) }}</div></div>
        <div><div class="text-xs text-gray-500">{{ $t('report.ship') }}</div><div class="text-lg font-bold text-gray-900">{{ $report['ship_week'] }}{{ __('dashboard.unit_vehicle') }}</div><div class="text-[11px] text-gray-500">{{ $t('report.eta') }} {{ $report['eta_week'] }}{{ __('dashboard.unit_vehicle') }}</div></div>
        <div><div class="text-xs text-gray-500">{{ $t('report.cash') }}</div><div class="text-lg font-bold text-gray-900">{{ $report['cash_as_of'] ?? $t('report.cash_none') }}</div></div>
    </div>
</div>

@include('erp.dashboard._cash')
