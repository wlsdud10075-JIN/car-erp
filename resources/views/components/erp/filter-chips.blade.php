{{-- 🏷️ 필터칩공용 겉모양 (jin 2026-11-09 기획 · 11-22) — 동작·선언 = App\Support\ListFilters.
     칩은 행 뱃지와 같은 클래스를 그대로 쓴다(jin 11-20). 꺼짐 = 흐리게 · 켜짐 = 테두리. 축마다 왼쪽 선으로 구분한다.
     쓰는 법: <x-erp.filter-chips :axes="$this->chipAxes()" />  (일부 축만 = chipAxes(['active'])) --}}
@props(['axes' => []])
@foreach($axes as $axis)
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1 border-l border-gray-200 pl-3']) }} data-filter-axis="{{ $axis['axis'] }}">
    @foreach($axis['chips'] as $chip)
    <button type="button" wire:click="chipClick('{{ $axis['axis'] }}', '{{ $chip['value'] }}')" aria-pressed="{{ $chip['on'] ? 'true' : 'false' }}"
            @if($chip['title']) title="{{ $chip['title'] }}" @endif data-filter-chip="{{ $axis['axis'] }}:{{ $chip['value'] }}"
            class="{{ $chip['class'] }} cursor-pointer whitespace-nowrap {{ $chip['on'] ? 'ring-2' : 'opacity-60 hover:opacity-100' }}">
        {{ $chip['label'] }}@if($chip['count'] !== null) ({{ number_format((int) $chip['count']) }})@endif
    </button>
    @endforeach
</div>
@endforeach
