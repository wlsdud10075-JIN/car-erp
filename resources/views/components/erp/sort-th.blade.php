{{-- 🔼🔽 헤더정렬공용 겉모양 (jin 2026-11-09 기획 · 11-22) — 동작 = App\Support\StackedHeaderSort.
     ▲/▼, 겹치면 순번(2▲). 화면은 th 안에 한 줄: $this->sortTh('name', __('buyer.col_name')) 를 th 안에 찍는다
     (sortTh 가 이 컴포넌트를 그린다 — 표시(indicator)는 트레이트가 계산해 넘긴다.) --}}
@props(['col', 'label', 'indicator' => '', 'align' => 'left', 'extra' => ''])
<button type="button" wire:click="clickSort('{{ $col }}')" title="{{ __('vehicle.sort.shift_hint') }}" data-sort-key="{{ $col }}"
        class="flex items-center gap-1 hover:text-gray-700 {{ $align === 'right' ? 'justify-end ml-auto' : '' }} {{ $extra }}">{{ $label }} <span class="whitespace-nowrap text-[10px]">{{ $indicator }}</span></button>
