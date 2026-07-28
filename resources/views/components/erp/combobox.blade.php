@props([
    'model',                 // wire 프로퍼티명 (예: 'buyer_id_str')
    'options',               // {id,name} 컬렉션
    'selected' => '',        // 현재 선택 id
    'placeholder' => '',
    'disabled' => false,
    'required' => false,
])
@php
    // item 8(b) — 바이어/컨사이니 자동완성 콤보박스 (드롭다운 + 텍스트 검색 겸용).
    //   선택 시 $wire.set(model, id) → 서버 updated 훅(컨사이니 종속·전파) 발화.
    //   wire:key(호출측)를 selected 값에 묶으면 서버 변경(quick-add·전파·버이어변경) 시 재init.
    $optList = collect($options)->map(fn ($o) => ['id' => (string) $o->id, 'name' => (string) $o->name])->values();
    $selectedName = collect($options)->firstWhere('id', (int) $selected)?->name ?? '';
@endphp
{{-- ⚠️ class 는 반드시 merge — 예전엔 {{ $attributes }} 와 class="relative" 를 따로 찍었다.
     호출측이 class 를 주면(재고관리 w-44) 같은 속성이 두 번 렌더돼 뒤엣것(relative)이 무시되고,
     드롭다운의 absolute 가 엉뚱한 조상을 기준으로 잡혀 화면 밖까지 벌어졌다(jin 2026-07-28 제보). --}}
<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data="{
        open: false,
        query: @js($selectedName),
        selectedName: @js($selectedName),
        options: {{ \Illuminate\Support\Js::from($optList) }},
        filtered() {
            const q = this.query.trim().toLowerCase();
            if (q === '' || q === this.selectedName.toLowerCase()) return this.options;
            return this.options.filter(o => o.name.toLowerCase().includes(q));
        },
        choose(o) {
            this.selectedName = o.name; this.query = o.name; this.open = false;
            $wire.set('{{ $model }}', o.id);
        },
        clear() {
            this.selectedName = ''; this.query = ''; this.open = false;
            $wire.set('{{ $model }}', '');
        },
        syncBack() { this.query = this.selectedName; },
     }"
     @click.outside="open = false; syncBack()">
    <div class="relative">
        <input type="text" x-model="query"
               @focus="open = true" @click="open = true" @input="open = true"
               @keydown.escape.stop="open = false; syncBack()"
               placeholder="{{ $placeholder }}"
               {{ $disabled ? 'disabled' : '' }}
               class="input-base pr-6 {{ $required ? 'input-required' : '' }}" />
        @unless($disabled)
        <button type="button" x-show="selectedName" @click="clear()" tabindex="-1"
                class="absolute right-1.5 top-1/2 -translate-y-1/2 text-lg leading-none text-gray-300 hover:text-gray-500">&times;</button>
        @endunless
    </div>
    <div x-show="open" x-cloak
         class="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
        <template x-for="opt in filtered()" :key="opt.id">
            <button type="button" @click="choose(opt)"
                    class="block w-full truncate px-3 py-1.5 text-left text-sm hover:bg-primary-light" x-text="opt.name"></button>
        </template>
        <div x-show="filtered().length === 0" class="px-3 py-2 text-xs text-gray-400">{{ __('vehicle.panel.no_match') }}</div>
    </div>
</div>
