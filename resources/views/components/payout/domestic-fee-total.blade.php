{{-- 🧾 내수직원 합계 줄 (jin 2026-11-03 — 「평가수수료정산」 엑셀 합계 행 G74:P74 와 같은 값). 카드 목록 바로 아래, 월정산·승인 링크 공통.
     숫자는 BatchPayoutBreakdown::domesticFeeTotal(이번 달 카드에 들어간 내수직원 표의 열 합). 옛 박제엔 키가 없어 안 그린다. --}}
@props(['total' => null])
@if(! empty($total['items']))
<div class="rounded-lg border border-emerald-200 bg-emerald-50/40 px-3 py-2" data-domestic-fee-total>
    <div class="text-xs font-semibold text-gray-800">{{ __('payout_card.domestic_total', ['n' => $total['count']]) }}</div>
    <div class="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 sm:grid-cols-3">
        @foreach($total['items'] as $it)
        <div class="flex justify-between text-[11px]" data-domestic-total-item="{{ $it['label'] }}"><span class="text-gray-500">{{ $it['label'] }}</span><span class="font-mono {{ $it['label'] === \App\Services\Payroll\DomesticFeeImporter::PAYOUT_HEADER ? 'font-bold text-gray-900' : 'text-gray-700' }}">{{ number_format((float) $it['value']) }}</span></div>
        @endforeach
    </div>
</div>
@endif
