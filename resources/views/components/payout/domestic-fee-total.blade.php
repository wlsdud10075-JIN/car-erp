{{-- 🧾 내수직원 합계 줄 (jin 2026-11-03 — 「평가수수료정산」 엑셀 합계 행 G74:P74 와 같은 값). 카드 목록 바로 아래, 월정산·승인 링크 공통.
     숫자는 BatchPayoutBreakdown::domesticFeeTotal(이번 달 카드에 들어간 내수직원 표의 열 합). 옛 박제엔 키가 없어 안 그린다.
     compact = 사내직원 월정산(내수직원 월정산이 켜진 회사) — 한 줄(건수 · 회사수익 · 지급합계) + 그 달 내수직원 월정산 결재 상태.
     내수직원 월정산이 먼저 끝나므로(jin «내수가 먼저 이루어질거야») 여기선 확인용이다 — 송금·회사이익엔 안 들어간다. --}}
@props(['total' => null, 'compact' => false, 'status' => null])
@if(! empty($total['items']))
@if($compact)
@php
    $val = fn (string $label) => collect($total['items'])->firstWhere('label', $label)['value'] ?? null;
    $profit = $val('총 회사수익') ?? $val('회사수익');
    $pay = $val(\App\Services\Payroll\DomesticFeeImporter::PAYOUT_HEADER);
    [$stLabel, $stCls] = match ($status) {
        'approved' => [__('payout_card.domestic_line_approved'), 'badge-green'],
        'pending' => [__('payout_card.domestic_line_pending'), 'badge-amber'],
        default => [__('payout_card.domestic_line_none'), 'badge-gray'],
    };
@endphp
<div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-emerald-200 bg-emerald-50/40 px-3 py-2 text-[11px]" data-domestic-fee-total data-domestic-line>
    <span class="flex shrink-0 items-center gap-1.5 whitespace-nowrap">
        <span class="text-xs font-semibold text-gray-800">{{ __('payout_card.domestic_line', ['n' => $total['count']]) }}</span>
        <span class="badge {{ $stCls }} text-[10px] whitespace-nowrap" data-domestic-line-status="{{ $status ?? 'none' }}">{{ $stLabel }}</span>
    </span>
    <span class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-0.5">
        <span class="text-gray-500">{{ __('payout_card.domestic_count') }} <b class="font-mono text-gray-800">{{ $val('건수') === null ? '—' : number_format((float) $val('건수')) }}</b></span>
        <span class="text-gray-500">{{ __('payout_card.domestic_profit') }} <b class="font-mono text-emerald-600">{{ $profit === null ? '—' : '₩'.number_format((float) $profit) }}</b></span>
        <span class="text-gray-500">{{ __('payout_card.payroll_total') }} <b class="font-mono text-gray-800">{{ $pay === null ? '—' : '₩'.number_format((float) $pay) }}</b></span>
    </span>
</div>
@else
<div class="rounded-lg border border-emerald-200 bg-emerald-50/40 px-3 py-2" data-domestic-fee-total>
    <div class="text-xs font-semibold text-gray-800">{{ __('payout_card.domestic_total', ['n' => $total['count']]) }}</div>
    <div class="mt-1 grid grid-cols-1 gap-x-6 gap-y-0.5 sm:grid-cols-2">
        @foreach($total['items'] as $it)
        <div class="flex justify-between text-[11px]" data-domestic-total-item="{{ $it['label'] }}"><span class="whitespace-nowrap text-gray-500">{{ $it['label'] }}</span><span class="font-mono {{ $it['label'] === \App\Services\Payroll\DomesticFeeImporter::PAYOUT_HEADER ? 'font-bold text-gray-900' : 'text-gray-700' }}">{{ number_format((float) $it['value']) }}</span></div>
        @endforeach
    </div>
</div>
@endif
@endif
