@props([
    'name',
    'value' => null,
    'placeholder' => null,
    'allowClear' => true,
])
@php
    // 컨사이니 일괄 업로드 deep-interview (2026-05-28) — Q5 결정 후속.
    // 200개 country 시드 들어가서 일반 <select> 답답 → Alpine 인라인 필터링 dropdown.
    // 사용 예: <x-country-picker name="country_id_str" :value="$country_id_str" />
    //   - $name : 부모 Livewire 컴포넌트의 프로퍼티명 ($wire.set 대상)
    //   - $value: 초기값 (country_id, string 형태로 받음)
    $countries = \App\Models\Country::query()->orderBy('name')->get(['id', 'name', 'code']);
    $selected = $value ? $countries->firstWhere('id', (int) $value) : null;
@endphp

<div
    x-data="{
        open: false,
        query: @js($selected?->name ?? ''),
        selectedId: @js($value ? (string) $value : ''),
        items: @js($countries->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'code' => $c->code])->values()),
        get filtered() {
            const q = this.query.trim();
            if (q === '') return this.items.slice(0, 30);
            const lower = q.toLowerCase();
            return this.items
                .filter(i => i.name.toLowerCase().includes(lower) || i.code.toLowerCase().includes(lower))
                .slice(0, 50);
        },
        init() {
            this.$watch('selectedId', value => $wire.set(@js($name), value));
        },
        select(item) {
            this.selectedId = item.id;
            this.query = item.name;
            this.open = false;
        },
        clear() {
            this.selectedId = '';
            this.query = '';
            this.open = false;
        },
    }"
    @click.outside="open = false"
    @keydown.escape="open = false"
    class="relative"
>
    <div class="relative">
        <input
            type="text"
            x-model="query"
            @focus="open = true"
            @input="open = true; if (query.trim() === '') selectedId = ''"
            @keydown.tab="open = false"
            placeholder="{{ $placeholder ?? __('common.country_search_ph') }}"
            class="input-base w-full pr-8"
            autocomplete="off"
        />
        @if($allowClear)
            <button
                type="button"
                x-show="selectedId !== '' || query !== ''"
                @click="clear()"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                tabindex="-1"
                style="display: none;"
            >×</button>
        @endif
    </div>

    <div
        x-show="open && filtered.length > 0"
        x-transition.opacity
        class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg"
        style="display: none;"
    >
        <template x-for="item in filtered" :key="item.id">
            <button
                type="button"
                @click="select(item)"
                :class="item.id === selectedId ? 'bg-blue-50' : ''"
                class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50"
            >
                <span x-text="item.name"></span>
                <span class="text-xs text-gray-400" x-text="item.code"></span>
            </button>
        </template>
    </div>

    <div
        x-show="open && filtered.length === 0 && query.trim() !== ''"
        class="absolute z-30 mt-1 w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow"
        style="display: none;"
    >
        {{ __('common.country_no_match') }}
    </div>
</div>
