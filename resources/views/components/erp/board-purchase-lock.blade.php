@props(['request', 'detail' => false])
{{-- 🔐 매입 락 해제 요청(board) 한 건 — 승인큐 표·모바일 카드·결정 모달이 같이 쓴다 (jin 2026-11-19).
     보이는 것 = 바이어 · 대수 · 요청이 시작된 차 · 누른 사람 (+ detail: 사유 · 이 바이어의 「지금」 미수).
     🚫 금액은 없다 — 판단은 「이 바이어로 몇 대를 더 등록하게 할 것인가」 하나다.
     미수 숫자는 요청 때 값이 아니라 **지금** ERP 가 계산한 값이다(요청 뒤 입금이 들어왔을 수 있다). --}}
@php
    $p = $request->payload ?? [];
    $state = \App\Services\BoardPurchaseLockAllowance::stateOf($request);
    $approved = \App\Services\BoardPurchaseLockAllowance::approvedCount($request);
    $used = \App\Services\BoardPurchaseLockAllowance::usedCount($request);
    $expires = \App\Services\BoardPurchaseLockAllowance::expiresAt($request);
    $buyer = $detail ? \App\Models\Buyer::find($request->target_id) : null;
    $verdict = $buyer ? \App\Services\PurchaseRegistrationGate::forBuyer($buyer) : null;
    $g = $verdict['gauge'] ?? null;
@endphp
<div {{ $attributes->merge(['class' => 'space-y-0.5 text-xs']) }} data-board-purchase-lock>
    <div class="font-semibold text-gray-800">
        {{ $p['buyer_name'] ?? __('approval.t.buyer', ['id' => $request->target_id]) }}
        <span class="ml-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[11px] font-bold text-red-700">{{ __('approval.lock.want', ['n' => (int) ($p['requested_count'] ?? 0)]) }}</span>
    </div>
    @if ($request->status === 'approved')
        <div class="{{ $state === 'approved' ? 'text-emerald-700' : 'text-gray-500' }}">
            {{ __('approval.lock.usage', ['approved' => $approved, 'used' => $used]) }}
            · {{ __('approval.lock.state.'.$state) }}@if ($state === 'approved' && $expires) ({{ __('approval.lock.until', ['at' => $expires->format('m-d H:i')]) }})@endif
        </div>
    @endif
    @if (! empty($p['vehicle_number']) || ! empty($p['vehicle_label']))
        <div class="text-gray-500">{{ __('approval.lock.from_vehicle') }} <span class="font-mono text-gray-700">{{ $p['vehicle_number'] ?? '' }}</span> {{ $p['vehicle_label'] ?? '' }}</div>
    @endif
    <div class="text-gray-500">
        {{ __('approval.lock.salesman') }} {{ $p['salesman_name'] ?? '-' }}
        @if (($p['requested_by_name'] ?? '') !== '' && ($p['requested_by_name'] ?? '') !== ($p['salesman_name'] ?? ''))
            · {{ __('approval.lock.pressed_by', ['name' => $p['requested_by_name']]) }}
        @endif
    </div>
    @if ($detail)
        @if ($request->reason)
            <div class="text-gray-700">{{ __('approval.lock.reason') }} {{ $request->reason }}</div>
        @endif
        <div class="mt-1.5 rounded-md bg-red-50 p-2 text-[12px]" data-lock-now>
            <div class="mb-0.5 font-bold text-red-700">{{ __('approval.lock.now_title') }}</div>
            @if ($g)
                <div class="flex justify-between"><span>{{ __('approval.lock.now_count') }}</span><span class="font-semibold">{{ __('approval.lock.cars', ['n' => (int) $g['vehicle_count']]) }}</span></div>
                <div class="flex justify-between"><span>{{ __('approval.lock.now_unpaid') }}</span><span class="font-semibold">₩{{ number_format((int) $g['unpaid_krw']) }}</span></div>
                @if (($verdict['mode'] ?? '') === \App\Services\PurchaseRegistrationGate::MODE_UNSECURED)
                    <div class="flex justify-between"><span>{{ __('approval.lock.now_unsecured') }}</span><span class="font-bold text-red-700">₩{{ number_format((int) $g['unsecured_available_krw']) }} / ₩{{ number_format((int) $g['unsecured_limit_krw']) }}</span></div>
                @else
                    <div class="flex justify-between"><span>{{ __('approval.lock.now_ratio') }}</span><span class="font-bold {{ ($verdict['locked'] ?? false) ? 'text-red-700' : 'text-emerald-700' }}">{{ round($g['ratio'] * 100, 1) }}% / {{ round(($verdict['threshold'] ?? 0) * 100, 1) }}%</span></div>
                @endif
                @unless ($verdict['locked'] ?? false)
                    <div class="mt-0.5 text-emerald-700">{{ __('approval.lock.now_unlocked') }}</div>
                @endunless
            @else
                <div class="text-gray-500">{{ __('approval.lock.now_none') }}</div>
            @endif
        </div>
        <div class="mt-1 text-[11px] text-gray-500">{{ __('approval.lock.note', ['days' => \App\Services\BoardPurchaseLockAllowance::VALID_DAYS]) }}</div>
    @endif
</div>
