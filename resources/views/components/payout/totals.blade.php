{{-- 🧾 월정산 합계 띠 (월정산 v3) — 회사 순이익(급여 차감 후) · 총 판매 · 환산 합계 · 송금 총액. 숫자는 BatchPayoutBreakdown totals. --}}
@props(['totals', 'settlementTotal' => null])
@php $t = $totals; $net = (int) $t['company_net']; @endphp
<div class="rounded-md bg-gray-50 px-2.5 py-2" data-payout-totals>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div class="rounded border {{ $net < 0 ? 'border-red-200 bg-red-50' : 'border-violet-200 bg-violet-50' }} px-2 py-1.5">
            <div class="text-[10px] text-gray-500">{{ __('payout_card.totals.company_net') }}</div>
            <div class="font-mono text-sm font-bold {{ $net < 0 ? 'text-red-700' : 'text-primary-text' }}" data-company-net>{{ $net < 0 ? '−' : '' }}₩{{ number_format(abs($net)) }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-white px-2 py-1.5">
            <div class="text-[10px] text-gray-500">{{ __('payout_card.totals.vehicles') }}</div>
            <div class="font-mono text-sm font-bold text-gray-800">{{ number_format($t['vehicles']) }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-white px-2 py-1.5">
            <div class="text-[10px] text-gray-500">{{ __('payout_card.totals.equiv_sum') }}</div>
            <div class="font-mono text-sm font-bold text-gray-800">₩{{ number_format($t['equiv_sum']) }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-white px-2 py-1.5">
            <div class="text-[10px] text-gray-500">{{ __('payout_card.totals.transfer_total') }}</div>
            <div class="font-mono text-sm font-bold text-gray-800" data-transfer-total>₩{{ number_format($t['transfer_total']) }}</div>
        </div>
    </div>
    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[10px] text-gray-500">
        @if($settlementTotal !== null)<span>{{ __('payout_card.totals.settlement_total') }} ₩{{ number_format($settlementTotal) }}</span>@endif
        <span>{{ __('payout_card.totals.contribution_sum') }} ₩{{ number_format($t['contribution_sum']) }}</span>
        @if($t['common_labor'] !== 0)<span>{{ __('payout_card.totals.common_labor') }} −₩{{ number_format($t['common_labor']) }}</span>@endif
    </div>
    <p class="mt-0.5 text-[10px] text-gray-400">{{ __('payout_card.totals.hint') }}</p>
</div>
