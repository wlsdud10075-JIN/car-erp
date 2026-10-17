{{-- 업무 대시보드 · 담당 영업 뷰 (jin 2026-10-17) — 관리가 자기 담당 영업들의 돈을 한데 본다. $this = erp.dashboard 컴포넌트. --}}
@php
    $tt = $this->teamTotals;
    $cards = $this->salesmanCards;
    $t = fn (string $k, array $r = []) => __("dashboard.v.team.$k", $r);
@endphp

{{-- 팀 합계 한 줄 --}}
<div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-gray-700" data-team-totals>
    <span>{{ $t('totals.unpaid') }} <b class="text-base text-red-700">{{ $this->krw($tt['unpaid_krw']) }}</b>
        <span class="text-xs text-gray-500">· {{ $t('totals.count', ['n' => $tt['unpaid_count']]) }} · {{ $t('totals.risk', ['n' => $tt['risk_count']]) }}</span></span>
    <span class="text-gray-300">|</span>
    <span>{{ $t('totals.purchase') }} <b class="text-base text-gray-900">{{ $this->krw($tt['purchase_krw']) }}</b>
        <span class="text-xs text-gray-500">· {{ $t('totals.count', ['n' => $tt['purchase_count']]) }}</span></span>
    <span class="text-gray-300">|</span>
    <span>{{ $t('totals.active') }} <b class="text-base text-gray-900">{{ $tt['active_count'] }}{{ __('dashboard.unit_vehicle') }}</b></span>
</div>

{{-- 영업별 카드 --}}
@if(count($cards))
<div class="grid grid-cols-1 gap-3 lg:grid-cols-2" data-team-cards>
    @foreach($cards as $c)
    @php $sm = $c['salesman']; $pct = $c['paid_pct']; @endphp
    <div class="card" data-team-card="{{ $sm->id }}">
        <div class="flex items-center justify-between">
            <div><span class="font-semibold text-gray-900">{{ $sm->name }}</span>
                <span class="badge badge-gray ml-1">{{ $sm->type === 'freelance' ? __('salesman.type.freelance') : __('salesman.type.employee') }}</span></div>
            <span class="text-xs text-gray-500">{{ $t('card.active', ['n' => $c['active_count']]) }}</span>
        </div>
        <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
            <div>
                <div class="text-[11px] text-gray-500">{{ $t('card.unpaid') }}</div>
                <div class="font-bold {{ $c['unpaid_krw'] > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $c['unpaid_krw'] > 0 ? $this->krw($c['unpaid_krw']) : $t('card.no_unpaid') }}</div>
                <div class="text-[11px] text-gray-500">{{ $t('card.risk', ['n' => $c['risk_count']]) }}</div>
            </div>
            <div>
                <div class="text-[11px] text-gray-500">{{ $t('card.purchase') }}</div>
                <div class="font-bold text-gray-900">{{ $this->krw($c['purchase_krw']) }}</div>
                <div class="text-[11px] text-gray-500">{{ $t('card.count', ['n' => $c['purchase_count']]) }}</div>
            </div>
        </div>
        @if($pct !== null)
        <div class="mt-2 flex items-center gap-2">
            <span class="text-[11px] text-gray-500">{{ $t('card.paid_pct') }}</span>
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200"><div class="h-full {{ $pct < 50 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $pct }}%"></div></div>
            <span class="w-10 text-right text-[11px] text-gray-500">{{ $pct }}%</span>
        </div>
        @endif
        @if($c['top']->isNotEmpty())
        <div class="mt-2 divide-y divide-gray-100 text-xs">
            @foreach($c['top'] as $v)
            @php $ratio = $v->unpaid_ratio; $paid = $ratio === null ? null : (int) round((1 - $ratio) * 100); @endphp
            <div class="flex items-center gap-2 py-1 whitespace-nowrap">
                <a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="w-20 font-medium text-gray-900 hover:text-violet-700">{{ $v->vehicle_number }}</a>
                <span class="badge {{ $this->progressBadge($v->progress_status_cache) }}">{{ $v->progress_status_label }}</span>
                <span class="min-w-0 flex-1 truncate text-gray-600">{{ $v->buyer?->name ?? '—' }}@if($paid !== null) · {{ $t('card.paid_pct') }} {{ $paid }}%@endif @if($v->days_since_sale !== null) · {{ $v->days_since_sale }}{{ __('dashboard.v.unit_day') }}@endif</span>
                <span class="font-semibold text-red-700">{{ number_format($v->sale_unpaid_amount, 0) }} <span class="font-normal text-gray-500">{{ $v->currency }}</span></span>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endforeach
</div>
@endif

{{-- 돈이 막힌 차 --}}
@php $blocked = $this->blockedSales; @endphp
<div class="card" data-team-blocked>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-red-500"></span><span class="section-title">{{ $t('blocked.title') }}</span></div>
        <span class="text-xs text-gray-500">{{ $t('blocked.sub', ['n' => $tt['risk_count']]) }} · <a href="{{ route('erp.receivables.index') }}" wire:navigate class="text-primary-text hover:underline">{{ $t('blocked.link') }}</a></span>
    </div>
    @if($blocked->isEmpty())
    <p class="mt-3 text-sm text-gray-400">{{ $t('blocked.empty') }}</p>
    @else
    <div class="mt-2 overflow-x-auto">
        <table class="w-full min-w-[760px] text-xs tabular-nums">
            <thead><tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                <th class="py-1.5 pr-3 font-medium">{{ $t('blocked.col.vehicle') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('blocked.col.salesman') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('blocked.col.buyer') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ $t('blocked.col.status') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('blocked.col.paid') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ $t('blocked.col.unpaid') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ $t('blocked.col.last') }}</th><th class="py-1.5 font-medium">{{ $t('blocked.col.next') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50 whitespace-nowrap text-gray-800">
            @foreach($blocked as $v)
            @php
                $ratio = $v->unpaid_ratio; $paid = $ratio === null ? null : (int) round((1 - $ratio) * 100);
                $warn = in_array($v->receivable_risk, ['danger', 'critical'], true);
                $last = $v->finalPayments->first()?->payment_date;
                $next = trans()->has('domain.next_action.'.$v->progress_status) ? __('domain.next_action.'.$v->progress_status) : __('domain.next_action.none');
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="py-2 pr-3"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="font-medium text-gray-900 hover:text-violet-700">{{ $v->vehicle_number }}</a></td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->salesman?->name ?? '—' }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->buyer?->name ?? '—' }}</td>
                <td class="py-2 pr-3"><span class="badge {{ $this->progressBadge($v->progress_status_cache) }}">{{ $v->progress_status_label }}</span></td>
                <td class="py-2 pr-3">
                    @if($paid !== null)
                    <div class="flex items-center gap-2"><div class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-200"><div class="h-full {{ $warn ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ max(0, min(100, $paid)) }}%"></div></div><span class="w-9 text-right text-[11px] text-gray-500">{{ $paid }}%</span></div>
                    @else —
                    @endif
                </td>
                <td class="py-2 pr-3 text-right font-semibold {{ $warn ? 'text-red-700' : 'text-gray-900' }}">{{ number_format($v->sale_unpaid_amount, 0) }} <span class="font-normal text-gray-500">{{ $v->currency }}</span></td>
                <td class="py-2 pr-3 text-gray-500">{{ $last ? $last->format('m-d') : $t('blocked.no_payment') }}</td>
                <td class="py-2"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="text-primary-text hover:underline">{{ $next }} →</a></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- 딜러에게 줄 매입 잔금 --}}
@php $due = $this->purchaseDue; @endphp
<div class="card" data-team-purchase>
    <div class="flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-amber-500"></span><span class="section-title">{{ $t('purchase.title') }}</span></div>
        <span class="text-xs text-gray-500">{{ $t('purchase.sub', ['amount' => $this->krw($tt['purchase_krw']), 'n' => $tt['purchase_count']]) }}</span>
    </div>
    @if($due->isEmpty())
    <p class="mt-3 text-sm text-gray-400">{{ $t('purchase.empty') }}</p>
    @else
    <div class="mt-2 overflow-x-auto">
        <table class="w-full min-w-[640px] text-xs tabular-nums">
            <thead><tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                <th class="py-1.5 pr-3 font-medium">{{ $t('purchase.col.vehicle') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('purchase.col.salesman') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('purchase.col.from') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ $t('purchase.col.date') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ $t('purchase.col.unpaid') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('purchase.col.due') }}</th><th class="py-1.5 font-medium">{{ $t('purchase.col.next') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50 whitespace-nowrap text-gray-800">
            @foreach($due as $v)
            <tr class="hover:bg-gray-50">
                <td class="py-2 pr-3"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="font-medium text-gray-900 hover:text-violet-700">{{ $v->vehicle_number }}</a></td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->salesman?->name ?? '—' }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->purchase_from ?: '—' }}</td>
                <td class="py-2 pr-3 text-gray-500">{{ $v->purchase_date?->format('m-d') ?? '—' }}</td>
                <td class="py-2 pr-3 text-right font-semibold text-red-700">₩{{ number_format((int) $v->unpaid_calc) }}</td>
                <td class="py-2 pr-3 text-gray-500">{{ $v->next_due ? \Illuminate\Support\Carbon::parse($v->next_due)->format('m-d') : '—' }}</td>
                <td class="py-2"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="text-primary-text hover:underline">{{ $t('purchase.send') }} →</a></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-[11px] text-gray-400">{{ $t('purchase.due_note') }}</p>
    @endif
</div>

{{-- 다음 단계로 넘길 것 — 영업별 대수 --}}
@php
    $next = $this->nextStepCounts;
    $sms = $this->teamSalesmen();
    $links = ['clearance_needed' => route('erp.vehicles.index').'?action=clearance_needed', 'freight_confirm_pending' => route('erp.vehicles.index').'?action=freight_confirm_pending',
              'general_stock' => route('erp.inventory.index').'?category=general', 'deregistration_needed' => route('erp.vehicles.index').'?action=deregistration_needed'];
@endphp
<div class="card" data-team-next>
    <div class="section-header"><span class="section-dot bg-blue-500"></span><span class="section-title">{{ $t('next.title') }}</span></div>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-xs tabular-nums">
            <thead><tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                <th class="py-1.5 pr-3 font-medium"></th>
                @foreach($sms as $sm)<th class="py-1.5 pr-3 text-right font-medium whitespace-nowrap">{{ $sm->name }}</th>@endforeach
                <th class="py-1.5 text-right font-medium">{{ $t('next.total') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50 text-gray-800">
            @foreach($next as $key => $bySm)
            @php $sum = array_sum($bySm); @endphp
            <tr>
                <td class="py-1.5 pr-3 whitespace-nowrap">{{ $t("next.rows.$key") }}</td>
                @foreach($sms as $sm)
                @php $n = $bySm[$sm->id] ?? 0; @endphp
                <td class="py-1.5 pr-3 text-right {{ $n > 0 ? 'font-semibold text-gray-900' : 'text-gray-300' }}">{{ $n }}</td>
                @endforeach
                <td class="py-1.5 text-right whitespace-nowrap"><b>{{ $sum }}</b> <a href="{{ $links[$key] }}" wire:navigate class="ml-1 text-primary-text hover:underline">{{ $t("next.links.$key") }} →</a></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
