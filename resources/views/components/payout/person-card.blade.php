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
    // 「− 항목」 줄 전용 — 값이 음수면 빼는 게 더하는 것이라 「+」로 뒤집는다(−+3,600,000 / −−500,000 방지). null 은 0.
    $minus = fn ($v) => ((($v ?? 0) < 0) ? '+' : '−').number_format(abs($v ?? 0));
    $typeBadge = ['employee' => 'badge-blue', 'freelance' => 'badge-purple', 'inspector' => 'badge-amber'][$t] ?? 'badge-gray';
    $chg = fn (string $k) => in_array($k, $changed, true) ? ' bg-amber-50' : '';
    // 이익배수 = 인센티브 전 회사이익 ÷ (급여 + 정산금) (jin 2026-10-12). 옛 박제엔 키가 없다 → 같은 식으로 되살린다(단일 출처 = PersonPayoutBreakdown::profitMultiple).
    $basePay = $p['base_pay'] ?? ((int) ($p['payroll'] ?? 0) + (int) ($p['settlement_pay'] ?? 0));
    $profitBefore = $p['profit_before_incentive'] ?? ((int) ($p['total_margin'] ?? 0) - $basePay - (int) ($p['shipping'] ?? 0));
    $ratioVal = array_key_exists('profit_multiple', $p)
        ? $p['profit_multiple']
        : \App\Services\Payout\PersonPayoutBreakdown::profitMultiple($profitBefore, $basePay);
    $ratioText = $ratioVal === null ? '—' : (($ratioVal < 0 ? '−' : '+').number_format(abs($ratioVal), 1).__('payout_card.times'));
    $shareText = ! array_key_exists('share', $p) || $p['share'] === null ? null : (($p['share'] < 0 ? '−' : '').number_format(abs($p['share']), 1).'%');
    $payrollMissing = $t !== 'freelance' && $p['payroll'] === null;
@endphp
<details class="rounded-lg border border-gray-200 bg-white" data-person-card="{{ $p['salesman_id'] }}" data-person-type="{{ $t }}" @if($open) open @endif>
    <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-left" title="{{ __('payout_card.open_hint') }}">
        {{-- 이름 묶음은 줄어들지 않는다(shrink-0) — 오른쪽 숫자가 길면 이름이 0px 로 눌려 뱃지가 세로로 서던 것(jin 2026-10-09 스크린샷). 숫자 쪽이 줄바꿈한다. --}}
        <span class="flex shrink-0 items-center gap-1.5 whitespace-nowrap">
            <span class="text-xs font-semibold text-gray-800">{{ $p['name'] }}</span>
            <span class="badge {{ $typeBadge }} text-[10px] whitespace-nowrap">{{ __('salesman.type.'.$t) }}</span>
            @if($t !== 'inspector')<span class="pill-count whitespace-nowrap">{{ __('payout_card.count', ['n' => $p['count']]) }}</span>@endif
            @if(!empty($changed))<span class="badge badge-amber text-[10px]" data-changed>{{ __('payout_card.changed') }}</span>@endif
        </span>
        <span class="ml-auto flex min-w-0 flex-wrap items-center gap-x-4 gap-y-0.5 text-[11px]">
            {{-- 접힌 줄 순서(jin 2026-10-12): 총마진 / 실지급액 / 회사이익 / 마진율 / 이익배수 / 지분율. 전부 지분율과 같은 모양(회색 라벨 + 굵은 고정폭, 음수 빨강). --}}
            @if($t !== 'inspector' && ! $p['unsupported'])
            <span class="text-gray-500">{{ __('payout_card.total_margin') }} <b class="font-mono {{ $cls($p['total_margin']) }}" data-total-margin-head>{{ $signed($p['total_margin']) }}</b></span>
            @endif
            <span class="text-gray-500">{{ $t === 'inspector' ? __('payout_card.payroll_total') : __('payout_card.payout') }} <b class="font-mono text-gray-800" data-payout>₩{{ $fmt($p['payout']) }}</b></span>
            @if($t === 'employee' && ! $p['unsupported'])
            {{-- 🔀 2026-10-12 jin — 「급여공제후 마진·초과 배율」(프리랜서 환산 계열)은 뺐다. 이익배수 = 인센티브 전 회사이익 ÷ (급여+정산금) · 회사이익 = 최종(인센티브 뺀 뒤). --}}
            <span class="text-gray-500">{{ __('payout_card.company_profit') }} <b class="font-mono {{ $cls($p['company_contribution']) }}" data-contribution>{{ $signed($p['company_contribution']) }}</b></span>
            @elseif($t === 'freelance' && ! $p['unsupported'])
            <span class="text-gray-500">{{ __('payout_card.contribution') }} <b class="font-mono {{ $cls($p['company_contribution']) }}">{{ $signed($p['company_contribution']) }}</b></span>
            @endif
            @if($t !== 'inspector' && array_key_exists('margin_rate', $p))
            <span class="text-gray-500">{{ __('payout_batch.margin.label') }} <b class="font-mono {{ $cls($p['margin_rate']) }}" data-margin-rate>{{ \App\Models\Settlement::formatMarginRate($p['margin_rate']) }}</b></span>
            @endif
            @if($t === 'employee' && ! $p['unsupported'])
            <span class="text-gray-500">{{ __('payout_card.profit_multiple') }} <b class="font-mono {{ $cls($ratioVal) }}" data-profit-multiple>{{ $ratioText }}</b></span>
            @endif
            {{-- 지분율은 접힌 줄에서 뺐다(jin 2026-10-12) — 펼침 상세에만 --}}
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
        {{-- 사내직원 — 왼쪽: 회사 손익 산출 (jin 2026-10-12 3차 «총마진 − 실지급액 = 회사이익 이렇게만. 딱 봐도 이해되게»).
             총마진·회사이익(왼쪽)과 실지급액(오른쪽)만 초록 배경. 이익배수·지분율은 상세 줄로 색만 유지.
             발송비는 0 이 아닐 때만 한 줄(없으면 산수가 안 닫힌다, §8 #64). 🚫 프리랜서 환산 계열은 안 그린다(값은 PersonPayoutBreakdown 이 계속 계산). --}}
        <div class="rounded border border-gray-100">
            <div class="bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-700">{{ __('payout_card.ledger_company') }}</div>
            <div class="flex justify-between px-2 py-1.5 text-xs font-bold {{ $p['total_margin'] < 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-800' }}" data-total-margin><span>{{ __('payout_card.total_margin') }}</span><span class="font-mono">{{ $fmt($p['total_margin']) }}</span></div>
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.minus_payout') }}</span><span class="font-mono text-red-600">{{ $minus($p['payout']) }}</span></div>
            @if($p['shipping'] !== 0)
            <div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.minus_shipping') }}</span><span class="font-mono text-red-600">{{ $minus($p['shipping']) }}</span></div>
            @endif
            <div class="flex justify-between border-t border-gray-200 px-2 py-1.5 text-xs font-bold {{ $p['company_contribution'] < 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' }}" title="{{ __('payout_card.company_profit_formula') }}"><span>{{ __('payout_card.company_profit') }}</span><span class="font-mono" data-contribution-expanded>{{ $signed($p['company_contribution']) }}</span></div>
            {{-- 상세 — 이익배수(인센티브 전 기준, 툴팁에 식)·지분율 --}}
            <div class="flex justify-between border-t border-dashed border-gray-100 px-2 py-1 text-[11px]" title="{{ __('payout_card.profit_multiple_formula') }}: {{ $signed($profitBefore) }} ÷ {{ $fmt($basePay) }}"><span class="text-gray-500">{{ __('payout_card.profit_multiple') }}</span><span class="font-mono {{ $cls($ratioVal) }}">{{ $ratioText }}</span></div>
            @if($shareText !== null)<div class="flex justify-between px-2 py-1 text-[11px]"><span class="text-gray-500">{{ __('payout_card.share') }}</span><span class="font-mono {{ $cls($p['share']) }}" data-share>{{ $shareText }}</span></div>@endif
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
            <div class="flex justify-between border-t border-gray-100 bg-emerald-50 px-2 py-1.5 text-xs font-bold text-emerald-800" data-payout-expanded><span>{{ __('payout_card.payout') }}</span><span class="font-mono">{{ $fmt($p['payout']) }}</span></div>
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
        {{-- 차량별 보기 — 머리글 클릭 정렬 (jin 2026-10-12). 순수 JS(승인 링크 페이지엔 Alpine 이 없다) · 문서 위임 1회 등록이라 Livewire morph 뒤에도 산다. --}}
        <details class="sm:col-span-2">
            <summary class="cursor-pointer text-[11px] text-gray-500 hover:text-violet-700">{{ __('payout_card.vehicles') }} ({{ count($p['vehicles']) }})</summary>
            <div class="mt-1 overflow-x-auto">
                {{-- 모든 열 가운데 정렬(jin 2026-10-12 「문자든 숫자든 좌우 쏠림 없이」) --}}
                <table class="w-full min-w-[420px] text-[11px]" data-vehicle-table>
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500">
                            <th class="py-1 px-1 text-center font-medium"><button type="button" class="hover:text-violet-700" data-vehicle-sort="vehicle_number" data-sort-type="text">{{ __('payout_card.vh_vehicle') }}<span data-sort-mark></span></button></th>
                            <th class="py-1 px-1 text-center font-medium"><button type="button" class="hover:text-violet-700" data-vehicle-sort="total_margin" data-sort-type="num">{{ __('payout_card.total_margin') }}<span data-sort-mark></span></button></th>
                            <th class="py-1 px-1 text-center font-medium"><button type="button" class="hover:text-violet-700" data-vehicle-sort="margin_rate" data-sort-type="num">{{ __('payout_batch.margin.label') }}<span data-sort-mark></span></button></th>
                            <th class="py-1 px-1 text-center font-medium"><button type="button" class="hover:text-violet-700" data-vehicle-sort="type_label" data-sort-type="text">{{ __('payout_card.vh_type') }}<span data-sort-mark></span></button></th>
                            <th class="py-1 px-1 text-center font-medium"><button type="button" class="hover:text-violet-700" data-vehicle-sort="actual_payout" data-sort-type="num">{{ __('payout_card.vh_payout') }}<span data-sort-mark></span></button></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($p['vehicles'] as $v)
                        <tr class="text-gray-600" data-vehicle-number="{{ $v['vehicle_number'] }}" data-total_margin="{{ (int) $v['total_margin'] }}" data-margin_rate="{{ $v['margin_rate'] === null ? '' : (float) $v['margin_rate'] }}" data-type_label="{{ $v['type_label'] ?? '' }}" data-actual_payout="{{ (int) $v['actual_payout'] }}">
                            <td class="py-1 px-1 text-center font-medium text-gray-700">{{ $v['vehicle_number'] }}</td>
                            <td class="py-1 px-1 text-center font-mono {{ $v['total_margin'] < 0 ? 'text-red-600' : '' }}">{{ number_format($v['total_margin']) }}</td>
                            <td class="py-1 px-1 text-center font-mono {{ ($v['margin_rate'] ?? 0) < 0 ? 'text-red-600' : '' }}">{{ \App\Models\Settlement::formatMarginRate($v['margin_rate']) }}</td>
                            <td class="py-1 px-1 text-center text-gray-500">{{ $v['type_label'] ?? '' }}</td>
                            <td class="py-1 px-1 text-center font-mono {{ $v['actual_payout'] < 0 ? 'text-red-600' : '' }}">{{ $v['actual_payout'] < 0 ? '−' : '' }}₩{{ number_format(abs($v['actual_payout'])) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
        <script>
            if (!window.__payoutVehicleSort) {
                window.__payoutVehicleSort = true;
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('[data-vehicle-sort]');
                    if (!btn) return;
                    var table = btn.closest('[data-vehicle-table]');
                    var key = btn.getAttribute('data-vehicle-sort');
                    var num = btn.getAttribute('data-sort-type') === 'num';
                    var dir = btn.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
                    table.querySelectorAll('[data-vehicle-sort]').forEach(function (b) { b.removeAttribute('data-sort-dir'); b.querySelector('[data-sort-mark]').textContent = ''; });
                    btn.setAttribute('data-sort-dir', dir);
                    btn.querySelector('[data-sort-mark]').textContent = dir === 'asc' ? ' ▲' : ' ▼';
                    var tbody = table.querySelector('tbody');
                    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                    var attr = key === 'vehicle_number' ? 'data-vehicle-number' : 'data-' + key;
                    rows.sort(function (a, b) {
                        var x = a.getAttribute(attr) || '', y = b.getAttribute(attr) || '';
                        var c = num ? (parseFloat(x || '-Infinity') - parseFloat(y || '-Infinity')) : x.localeCompare(y, 'ko');
                        return dir === 'asc' ? c : -c;
                    });
                    rows.forEach(function (r) { tbody.appendChild(r); });
                });
            }
        </script>
        @endif
    </div>
</details>
