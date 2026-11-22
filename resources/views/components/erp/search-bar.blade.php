{{-- 🔎 검색공용 겉모양 (jin 2026-11-09 기획 · 11-22) — 동작·선언 = App\Support\ListSearch.
     검색칸 + (슬롯: 같은 줄에 놓을 select·날짜 등) + [검색] (+ 검색어가 있으면 [지우기]).
     🚫 치는 동안 조회하지 않는다 — wire:model(deferred) + Enter/[검색] (SearchRequiresButtonTest).
     쓰는 법: <x-erp.search-bar :placeholder="__('buyer.search_ph')" width="w-64"> …select… </x-erp.search-bar>
     줄 바깥 틀(div)은 화면이 가진다 — 칩줄·탭이 같은 틀 안에 오는 화면이 많다. --}}
@props(['placeholder' => '', 'width' => 'w-64', 'model' => 'search', 'action' => 'searchNow', 'clear' => null, 'value' => null])
<input wire:model="{{ $model }}" wire:keydown.enter="{{ $action }}" type="text" placeholder="{{ $placeholder }}"
       class="input-filter {{ $width }}" data-search-input />
{{ $slot }}
<button type="button" wire:click="{{ $action }}" class="btn-search" data-search-button>{{ __('common.search') }}</button>
@if($clear && filled($value))
<button type="button" wire:click="{{ $clear }}" class="text-xs text-gray-500 hover:underline" data-search-clear>{{ __('common.clear') }}</button>
@endif
