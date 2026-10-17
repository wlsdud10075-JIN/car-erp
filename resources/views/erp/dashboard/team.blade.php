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

{{-- 영업별 카드 — 접힌 한 줄(이름 · 미수 · 미지급 · 입금률)이 기본, 누르면 급한 차 3대 (jin 2026-10-17 «너무 많이 차지한다»).
     접힘 상태는 Alpine(브라우저) — 「전부 펼치기」만 localStorage 에 남긴다. wire:poll 모프를 넘기도록 카드마다 wire:key. --}}
@if(count($cards))
<div data-team-cards wire:key="team-cards" x-data="{
    all: (() => { try { return localStorage.getItem('erp_dashboard_team_cards_all') === '1'; } catch (e) { return false; } })(),
    open: {},
    isOpen(id) { return id in this.open ? this.open[id] : this.all; },
    toggle(id) { this.open[id] = ! this.isOpen(id); },
    setAll(v) { this.all = v; this.open = {}; try { localStorage.setItem('erp_dashboard_team_cards_all', v ? '1' : '0'); } catch (e) {} }
}">
    <div class="mb-1 flex items-center justify-between text-xs text-gray-500">
        <span>{{ $t('cards_title', ['n' => count($cards)]) }} <span class="text-gray-400">· {{ $t('card_hint') }}</span></span>
        <button type="button" data-team-cards-toggle x-on:click="setAll(! all)" x-text="all ? @js($t('collapse_all')) : @js($t('expand_all'))" class="text-primary-text hover:underline"></button>
    </div>
    <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
    @foreach($cards as $c)
    @php $sm = $c['salesman']; $pct = $c['paid_pct']; @endphp
    <div class="card card-tight" data-team-card="{{ $sm->id }}" wire:key="team-card-{{ $sm->id }}">
        <button type="button" x-on:click="toggle({{ $sm->id }})" class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-left text-sm">
            <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="isOpen({{ $sm->id }}) && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
            <span class="font-semibold text-gray-900">{{ $sm->name }}</span>
            <span class="badge badge-gray">{{ $sm->type === 'freelance' ? __('salesman.type.freelance') : __('salesman.type.employee') }}</span>
            <span class="text-xs text-gray-500">{{ $t('card.active', ['n' => $c['active_count']]) }}</span>
            <span class="ml-auto flex items-center gap-x-3 whitespace-nowrap text-xs tabular-nums">
                <span><span class="text-gray-500">{{ $t('card.unpaid') }}</span> <b class="{{ $c['unpaid_krw'] > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $c['unpaid_krw'] > 0 ? $this->krw($c['unpaid_krw']) : '—' }}</b>@if($c['risk_count'] > 0) <span class="text-red-600">({{ $t('card.risk', ['n' => $c['risk_count']]) }})</span>@endif</span>
                <span><span class="text-gray-500">{{ $t('card.purchase') }}</span> <b class="text-gray-900">{{ $this->krw($c['purchase_krw']) }}</b></span>
                @if($pct !== null)<span><span class="text-gray-500">{{ $t('card.paid_pct') }}</span> <b class="{{ $pct < 50 ? 'text-red-700' : 'text-gray-900' }}">{{ $pct }}%</b></span>@endif
            </span>
        </button>
        <div x-show="isOpen({{ $sm->id }})" x-collapse x-cloak data-team-card-detail>
            @if($pct !== null)
            <div class="mt-2 flex items-center gap-2">
                <span class="text-[11px] text-gray-500">{{ $t('card.paid_pct') }}</span>
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200"><div class="h-full {{ $pct < 50 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $pct }}%"></div></div>
            </div>
            @endif
            <div class="mt-1 text-[11px] text-gray-500">{{ $t('card.purchase') }} {{ $t('card.count', ['n' => $c['purchase_count']]) }}</div>
            @if($c['top']->isNotEmpty())
            <div class="mt-1 divide-y divide-gray-100 text-xs">
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
    </div>
    @endforeach
    </div>
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
