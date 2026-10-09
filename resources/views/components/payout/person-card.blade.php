{{-- 🧾 월정산 사람 카드 (월정산 v3, 2026-10-09) — 정산관리 담당자별 합계 · 월정산 · 폰 승인 링크가 **같은 카드**를 쓴다.
     숫자는 PersonPayoutBreakdown 한 곳(§8 #44·#45). 접기/펼치기는 <details> — 승인 링크 페이지엔 JS(Alpine)가 없다.
     mode: batch(월정산·승인) / preview(정산관리, 인센티브는 제출 때) · changed: 상신 뒤 바뀐 칸 키(노란 표시) --}}
@props(['person', 'mode' => 'batch', 'open' => false, 'changed' => []])
@php
    $p = $person;
    $t = $p['type'];
    $fmt = fn ($v) => $v === null ? '—' : number_format($v);
    $signed = fn ($v) => $v === null ? '—' : (($v < 0 ? '−' : '+').number_format(abs($v)));
    $cls = fn ($v) => $v === null ? 'text-gray-400' : ($v < 0 ? 'text-red-600' : 'text-emerald-600');
    $typeBadge = ['employee' => 'badge-blue', 'freelance' => 'badge-purple', 'inspector' => 'badge-amber'][$t] ?? 'badge-gray';
    $chg = fn (string $k) => in_array($k, $changed, true) ? ' bg-amber-50' : '';
    $ratioText = $p['excess_ratio'] === null ? '—' : (($p['excess_ratio'] < 0 ? '−' : '+').number_format(abs($p['excess_ratio']), 1).__('payout_card.times'));
    $shareText = ! array_key_exists('share', $p) || $p['share'] === null ? null : (($p['share'] < 0 ? '−' : '').number_format(abs($p['share']), 1).'%');
    $payrollMissing = $t !== 'freelance' && $p['payroll'] === null;
@endphp
<details class="rounded-lg border border-gray-200 bg-white" data-person-card="{{ $p['salesman_id'] }}" data-person-type="{{ $t }}" @if($open) open @endif>
    <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-left" title="{{ __('payout_card.open_hint') }}">
        {{-- 이름 묶음은 줄어들지 않는다(shrink-0) — 오른쪽 숫자가 길면 이름이 0px 로 눌려 뱃지가 세로로 서던 것(jin 2026-10-09 스크린샷). 숫자 쪽이 줄바꿈한다. --}}
        <span class="flex shrink-0 items-center gap-1.5 whitespace-nowrap">
            <span class="text-xs font-semibold text-gray-800">{{ $p['name'] }}</span>
            <span class="badge {{ $typeBadge }} text-[10px] whitespace-nowrap">{{ __('salesman.type.'.$t) }}</span>
            @if($t !== 'inspector')<span class="pill-count whitespace-nowrap">{{ __('payout_card.count', ['n' => $p['count']]) }}</span>
            @if(array_key_exists('margin_rate', $p))<span class="text-[10px] text-gray-400">{{ __('payout_batch.margin.label') }} {{ \App\Models\Settlement::formatMarginRate($p['margin_rate']) }}</span>@endif
            @endif
            @if(!empty($changed))<span class="badge badge-amber text-[10px]" data-changed>{{ __('payout_card.changed') }}</span>@endif
        </span>
        <span class="ml-auto flex min-w-0 flex-wrap items-center gap-x-4 gap-y-0.5 text-[11px]">
            @if($t === 'employee' && ! $p['unsupported'])
            <span class="text-gray-500">{{ __('payout_card.margin_after_pay') }} <b class="font-mono {{ $cls($p['margin_after_pay']) }}" data-margin-after-pay>{{ $signed($p['margin_after_pay']) }}</b></span>
            <span class="text-gray-500">{{ __('payout_card.excess_ratio') }} <b class="font-mono {{ $cls($p['excess_ratio']) }}" data-excess-ratio>{{ $ratioText }}</b></span>
            {{-- 실제로 회사에 남긴 돈도 접힌 줄에(jin 2026-10-09 「실제 사내직원으로 벌어들인 돈은 모르는 거 아닌가」) --}}
            <span class="text-gray-500">{{ __('payout_card.contribution') }} <b class="font-mono {{ $cls($p['company_contribution']) }}" data-contribution>{{ $signed($p['company_contribution']) }}</b></span>
            @elseif($t === 'freelance' && ! $p['unsupported'])
            <span class="text-gray-500">{{ __('payout_card.contribution') }} <b class="font-mono {{ $cls($p['company_contribution']) }}">{{ $signed($p['company_contribution']) }}</b></span>
            @endif
            <span class="text-gray-500">{{ $t === 'inspector' ? __('payout_card.payroll_total') : __('payout_card.payout') }} <b class="font-mono text-gray-800" data-payout>₩{{ $fmt($p['payout']) }}</b></span>
            @if($shareText !== null)
            <span class="text-gray-500">{{ __('payout_card.share') }} <b class="font-mono {{ $cls($p['share']) }}" data-share>{{ $shareText }}</b></span>
            @endif
        </span>
    </summary>

    <div class="grid gap-2 border-t border-gray-100 px-3 pb-3 pt-2 sm:grid-cols-2">
        @if($t === 'inspector')
        {{-- 검차직원 = 급여만 --}}
        <div class="rounded border border-gray-100">
            <div class="bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-700">{{ __('payout_card.payroll') }}</div>
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('payroll') }}"><span class="text-gray-500">{{ __('payout_card.payroll') }}</span>
                <span class="font-mono">@if($payrollMissing)<span class="text-amber-700" data-payroll-missing>{{ __('payout_card.payroll_missing') }}</span>@else{{ $fmt($p['payroll']) }}@endif</span></div>
            @if($p['incentive'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('incentive') }}"><span class="text-gray-500">{{ __('payout_card.incentive') }}</span><span class="font-mono">{{ $signed($p['incentive']) }}</span></div>
            @endif
            <div class="flex justify-between border-t border-gray-100 px-2 py-1 text-xs font-bold"><span>{{ __('payout_card.payout') }}</span><span class="font-mono">{{ $fmt($p['payout']) }}</span></div>
        </div>
        <div class="rounded border border-gray-100">
            <div class="bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-700">{{ __('payout_card.where_reflected') }}</div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.inspector_in_transfer') }}</span><span>{{ __('payout_card.inspector_in_transfer_v') }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.inspector_in_net') }}</span><span>{{ __('payout_card.inspector_in_net_v') }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.inspector_in_share') }}</span><span>{{ __('payout_card.inspector_in_share_v') }}</span></div>
        </div>

        @elseif($p['unsupported'])
        <div class="rounded border border-gray-100 sm:col-span-2">
            <p class="px-2 py-1 text-[11px] text-gray-500">{{ __('payout_card.unsupported') }}</p>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.settlement_pay') }}</span><span class="font-mono">{{ $fmt($p['settlement_pay']) }}</span></div>
            @if($t === 'employee')<div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.payroll') }}</span><span class="font-mono">{{ $fmt($p['payroll']) }}</span></div>@endif
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('incentive') }}"><span class="text-gray-500">{{ __('payout_card.incentive') }}</span><span class="font-mono">{{ $fmt($p['incentive']) }}</span></div>
            <div class="flex justify-between border-t border-gray-100 px-2 py-1 text-xs font-bold"><span>{{ __('payout_card.payout') }}</span><span class="font-mono">{{ $fmt($p['payout']) }}</span></div>
        </div>

        @elseif($t === 'employee')
        {{-- 사내직원 — 왼쪽: 회사 손익 산출 --}}
        <div class="rounded border border-gray-100">
            <div class="bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-700">{{ __('payout_card.ledger_company') }}</div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.equiv_sale_rate') }}</span><span class="font-mono">{{ $signed($p['equiv_sale_rate']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.fx_primary') }}</span><span class="font-mono {{ $cls($p['fx_primary']) }}">{{ $signed($p['fx_primary']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.carry_employee') }}</span><span class="font-mono {{ $cls($p['carry']) }}">{{ $signed($p['carry']) }}</span></div>
            @if(($p['fx_secondary'] ?? 0) !== 0)
            <div class="flex justify-between px-2 py-0.5 pl-5 text-[10px] text-gray-400"><span>{{ __('payout_card.fx_secondary') }}</span><span class="font-mono">{{ $signed($p['fx_secondary']) }}</span></div>
            @endif
            @if($p['adj_manual'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('adj_manual') }}"><span class="text-gray-500">{{ __('payout_card.adj_manual') }}</span><span class="font-mono">{{ $signed($p['adj_manual']) }}</span></div>
            @endif
            <div class="flex justify-between bg-gray-50 px-2 py-1 text-[11px] font-semibold"><span>{{ __('payout_card.equiv_total') }}</span><span class="font-mono" data-equiv-total>{{ $fmt($p['equiv_total']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.minus_payout') }}</span><span class="font-mono text-red-600">−{{ $fmt($p['payout']) }}</span></div>
            <div class="flex justify-between border-t border-gray-100 px-2 py-1 text-xs font-bold {{ $p['margin_after_pay'] < 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' }}"><span>{{ __('payout_card.margin_after_pay') }}</span><span class="font-mono">{{ $signed($p['margin_after_pay']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.excess_ratio') }}</span><span class="font-mono {{ $cls($p['excess_ratio']) }}">{{ $ratioText }}</span></div>
            <div class="flex justify-between border-t border-gray-200 px-2 py-1 text-xs font-bold {{ $p['company_contribution'] < 0 ? 'bg-red-50 text-red-700' : 'bg-sky-50 text-sky-800' }}" title="{{ __('payout_card.contribution_formula') }}"><span>{{ __('payout_card.contribution_actual') }}</span><span class="font-mono">{{ $signed($p['company_contribution']) }}</span></div>
            <div class="px-2 py-1 text-[10px] text-gray-400">
                {{ __('payout_card.contribution_formula') }}: {{ $fmt($p['total_margin']) }} − {{ $fmt($p['payout']) }} − {{ $fmt($p['shipping']) }}@if($shareText !== null) · {{ __('payout_card.share') }} {{ $shareText }}@endif
            </div>
        </div>
        {{-- 오른쪽: 담당자 지급 --}}
        <div class="rounded border border-gray-100">
            <div class="bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-700">{{ __('payout_card.ledger_person') }}</div>
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('payroll') }}"><span class="text-gray-500">{{ __('payout_card.payroll') }}</span>
                <span class="font-mono">@if($payrollMissing)<span class="text-amber-700" title="{{ __('payout_card.payroll_missing_hint') }}" data-payroll-missing>{{ __('payout_card.payroll_missing') }}</span>@else{{ $fmt($p['payroll']) }}@endif</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.settlement_pay') }} ({{ __('payout_card.count', ['n' => $p['count']]) }} · {{ ! empty($p['tier']) ? __('payout_card.settlement_pay_tier') : __('payout_card.settlement_pay_per_unit') }})</span><span class="font-mono">{{ $fmt($p['settlement_pay']) }}</span></div>
            @if($p['adj_manual'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('adj_manual') }}"><span class="text-gray-500">{{ __('payout_card.adj_manual') }}</span><span class="font-mono">{{ $signed($p['adj_manual']) }}</span></div>
            @endif
            @if($p['adj_carry'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.adj_carry') }}</span><span class="font-mono">{{ $signed($p['adj_carry']) }}</span></div>
            @endif
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('incentive') }}"><span class="text-gray-500">{{ __('payout_card.incentive') }}</span>
                <span class="font-mono">@if($mode === 'preview' && $p['incentive'] === 0)<span class="text-gray-400">{{ __('payout_card.incentive_preview') }}</span>@else{{ $signed($p['incentive']) }}@endif</span></div>
            <div class="flex justify-between border-t border-gray-100 px-2 py-1 text-xs font-bold"><span>{{ __('payout_card.payout') }}</span><span class="font-mono">{{ $fmt($p['payout']) }}</span></div>
        </div>

        @else
        {{-- 프리랜서 — 왼쪽: 회사 손익 --}}
        <div class="rounded border border-gray-100">
            <div class="bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-700">{{ __('payout_card.ledger_company') }}</div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.total_margin') }}</span><span class="font-mono">{{ $fmt($p['total_margin']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.minus_payout') }}</span><span class="font-mono text-red-600">−{{ $fmt($p['payout']) }}</span></div>
            @if($p['shipping'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.minus_shipping') }}</span><span class="font-mono text-red-600">−{{ $fmt($p['shipping']) }}</span></div>
            @endif
            <div class="flex justify-between border-t border-gray-100 px-2 py-1 text-xs font-bold {{ $p['company_contribution'] < 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' }}"><span>{{ __('payout_card.contribution') }}</span><span class="font-mono">{{ $signed($p['company_contribution']) }}</span></div>
            @if($shareText !== null)<div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.share') }}</span><span class="font-mono {{ $cls($p['share']) }}">{{ $shareText }}</span></div>@endif
            <div class="border-t border-dashed border-gray-100 px-2 py-1 text-[10px] text-gray-400">{{ __('payout_card.freelance_note') }}</div>
        </div>
        {{-- 오른쪽: 담당자 지급 --}}
        <div class="rounded border border-gray-100">
            <div class="bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-700">{{ __('payout_card.ledger_person') }}</div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.equiv_sale_rate') }}</span><span class="font-mono">{{ $fmt($p['equiv_sale_rate']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.fx_primary') }}</span><span class="font-mono {{ $cls($p['fx_primary']) }}">{{ $signed($p['fx_primary']) }}</span></div>
            @if($p['carry'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.carry_freelance') }}</span><span class="font-mono {{ $cls($p['carry']) }}">{{ $signed($p['carry']) }}</span></div>
            @endif
            @if(($p['fx_secondary'] ?? 0) !== 0)
            <div class="flex justify-between px-2 py-0.5 pl-5 text-[10px] text-gray-400"><span>{{ __('payout_card.fx_secondary') }}</span><span class="font-mono">{{ $signed($p['fx_secondary']) }}</span></div>
            @endif
            @if($p['adj_manual'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('adj_manual') }}"><span class="text-gray-500">{{ __('payout_card.adj_manual') }}</span><span class="font-mono">{{ $signed($p['adj_manual']) }}</span></div>
            @endif
            <div class="flex justify-between bg-gray-50 px-2 py-1 text-[11px] font-semibold"><span>{{ __('payout_card.equiv_total') }}</span><span class="font-mono" data-equiv-total>{{ $fmt($p['equiv_total']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]{{ $chg('incentive') }}"><span class="text-gray-500">{{ __('payout_card.incentive') }}</span>
                <span class="font-mono">@if($mode === 'preview' && $p['incentive'] === 0)<span class="text-gray-400">{{ __('payout_card.incentive_preview') }}</span>@else{{ $signed($p['incentive']) }}@endif</span></div>
            <div class="flex justify-between border-t border-gray-100 px-2 py-1 text-xs font-bold"><span>{{ __('payout_card.payout') }}</span><span class="font-mono">{{ $fmt($p['payout']) }}</span></div>
            @if(($p['deposit'] ?? null) !== null)
            <div class="px-2 py-1 text-[10px] text-gray-400">({{ __('payout_card.deposit') }} {{ number_format($p['deposit']) }} · {{ __('payout_card.deposit_hint') }})</div>
            @endif
        </div>
        @endif

        @if($t !== 'inspector' && !empty($p['vehicles']))
        <details class="sm:col-span-2">
            <summary class="cursor-pointer text-[11px] text-gray-500 hover:text-violet-700">{{ __('payout_card.vehicles') }} ({{ count($p['vehicles']) }})</summary>
            <div class="mt-1 space-y-0.5 pl-3">
                @foreach($p['vehicles'] as $v)
                <div class="flex items-center justify-between text-[11px] text-gray-500">
                    <span>{{ $v['vehicle_number'] }} <span class="ml-1 text-gray-400">{{ __('payout_card.total_margin') }} {{ number_format($v['total_margin']) }} · {{ \App\Models\Settlement::formatMarginRate($v['margin_rate']) }}@if(!empty($v['type_label'])) · {{ $v['type_label'] }}@endif</span></span>
                    <span class="font-mono {{ $v['actual_payout'] < 0 ? 'text-red-600' : '' }}">{{ $v['actual_payout'] < 0 ? '−' : '' }}₩{{ number_format(abs($v['actual_payout'])) }}</span>
                </div>
                @endforeach
            </div>
        </details>
        @endif
    </div>
</details>
