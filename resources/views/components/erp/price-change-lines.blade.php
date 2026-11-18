@props(['meta' => [], 'limit' => 3])
{{-- 💱 판매가·환율 수정 알림 줄 (jin 2026-11-18) — 「판매가 8,249 → 8,244 EUR · 유재숙 · 11-17 14:02」. 최근 것부터 limit 줄. --}}
@php
    $changes = array_reverse($meta['changes'] ?? []);
    $cur = $meta['currency'] ?? '';
    $names = \App\Models\User::whereIn('id', array_filter(array_column($changes, 'by')))->pluck('name', 'id');
@endphp
<div {{ $attributes->merge(['class' => 'space-y-0.5']) }} data-price-change-lines>
    @foreach (array_slice($changes, 0, $limit) as $c)
        @php
            $isRate = ($c['field'] ?? '') === 'exchange_rate';
            $fmt = fn ($n) => $isRate ? rtrim(rtrim(number_format((float) $n, 4), '0'), '.') : number_format((float) $n, fmod((float) $n, 1) != 0 ? 2 : 0);
        @endphp
        <div class="text-[12px] text-gray-700">
            <span class="font-semibold">{{ __('alarm.price_field.'.($c['field'] ?? '')) }}</span>
            <span class="tabular-nums">{{ $fmt($c['old'] ?? 0) }} → <b>{{ $fmt($c['new'] ?? 0) }}</b>{{ $isRate ? '' : ' '.$cur }}</span>
            <span class="text-gray-400">· {{ ($c['by'] ?? null) ? ($names[$c['by']] ?? '#'.$c['by']) : __('alarm.price_by_system') }} · {{ $c['at'] ?? '' }}</span>
        </div>
    @endforeach
    @if (count($changes) > $limit)
        <div class="text-[11px] text-gray-400">{{ __('alarm.price_more', ['n' => count($changes) - $limit]) }}</div>
    @endif
</div>
