{{--
    공통 파일 업로드 드롭존 (jin 2026-07-16)
    기존 <input type="file" wire:model> 를 감싸 드래그앤드랍 + 업로드 진행 게이지 추가.
    숨은 native input 을 그대로 두고 위에 Alpine 드롭존만 얹어 기존 wire:model·검증·훅 보존.
    - model    : wire:model 대상 프로퍼티명 (필수)
    - accept   : input accept 속성
    - multiple : 다중 선택 여부
    - label    : 드롭 안내 문구
    슬롯: 선택/기존 파일 미리보기·삭제 UI (부모 스코프 변수 그대로 접근).

    ⚠️ 업로드 이벤트(livewire-upload-*)는 input 요소에서 bubbles:true 로 발생 → 이 div 를 거쳐 버블링.
       .window 없이 div 에서 받으면 이 존에만 스코프됨(다른 드롭존 간섭 방지). — 검증: vendor dist.
--}}
@props([
    'model',
    'accept' => '',
    'multiple' => false,
    'label' => null,   // null = common.filedrop.label
    // 📦 고르는 순간 크기 확인 (jin 2026-11-17 «100MB 넘으면 줄여서 올리라고») — Livewire 임시 업로드 상한과 같은 값.
    //    서버(nginx 110M)까지 가서 막히면 이유 없는 실패로 보이고 다 올린 뒤에야 안다. 넘으면 올리지 않고 안내만.
    'maxMb' => intdiv(\App\Models\Vehicle::DOCUMENT_MAX_KB, 1024),
])
{{--
    🎯 2026-11-24 jin «드래그앤드롭으로 넣기가 너무 어렵다» — ①칸이 한 줄(약 36px)이라 정확히 겨눠야 했고
    ②빗나가 화면에 떨어뜨리면 브라우저가 PDF 를 열어 화면을 떠났고 ③칸 안의 버튼·글자 위를 지날 때 강조가 꺼졌다 켜졌다.
    ⇒ 파일을 끌고 화면에 들어오는 순간(window dragenter) **모든 칸**을 크게 강조 · 칸 밖 drop 은 막는다(window drop prevent)
       · 칸 안 들락날락은 깊이 카운터로 센다. 새 클래스 없이(빌드 불필요) 이미 빌드된 유틸만 쓴다.
--}}
<div
    data-filedrop
    x-data="{ over: false, dragging: false, depth: 0, uploading: false, progress: 0, tooBig: '',
              hasFiles(e) { return Array.from((e.dataTransfer && e.dataTransfer.types) || []).includes('Files') },
              reset() { this.over = false; this.dragging = false; this.depth = 0 } }"
    x-on:dragenter.window="if (hasFiles($event)) dragging = true"
    x-on:dragover.window="if (hasFiles($event)) $event.preventDefault()"
    x-on:dragleave.window="if (! $event.relatedTarget) reset()"
    x-on:dragend.window="reset()"
    x-on:drop.window="if (hasFiles($event) && ! ($event.target.closest && $event.target.closest('[data-filedrop]'))) $event.preventDefault(); reset()"
    x-on:change.capture="
        const f = Array.from($event.target.files || []).find(x => x.size > {{ (int) $maxMb }} * 1024 * 1024);
        tooBig = '';
        if (f) { $event.stopImmediatePropagation(); tooBig = f.name; $event.target.value = ''; }
    "
    x-on:livewire-upload-start="uploading = true; progress = 0"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
    x-on:livewire-upload-finish="progress = 100; setTimeout(() => { uploading = false }, 500)"
    x-on:livewire-upload-error="uploading = false; progress = 0"
    x-on:dragenter.prevent="depth++; over = true"
    x-on:dragover.prevent="over = true"
    x-on:dragleave.prevent="depth--; if (depth <= 0) { depth = 0; over = false }"
    x-on:drop.prevent="if ($event.dataTransfer && $event.dataTransfer.files.length) { $refs.fd.files = $event.dataTransfer.files; $refs.fd.dispatchEvent(new Event('change', { bubbles: true })); }"
    class="rounded-md border-dashed px-3 transition-colors"
    :class="over ? 'border-2 border-primary bg-primary-light py-6'
        : (dragging ? 'border-2 border-primary bg-white py-6' : 'border border-gray-200 bg-gray-50/50 py-2')"
>
    <input x-ref="fd" type="file" wire:model="{{ $model }}" accept="{{ $accept }}" @if($multiple) multiple @endif class="hidden" />

    <div x-show="!dragging" class="flex items-center gap-2 text-xs text-gray-500">
        <button type="button" @click="$refs.fd.click()"
                class="shrink-0 rounded border-0 bg-primary-light px-2 py-1 text-[11px] font-medium text-primary-text hover:bg-primary/10">
            {{ __('common.filedrop.browse') }}
        </button>
        <span class="truncate">{{ $label ?? __('common.filedrop.label') }}</span>
    </div>
    {{-- 끄는 동안엔 칸 전체가 「여기에 놓기」 — 버튼·글자가 없어 안에서 강조가 깜빡이지 않는다. --}}
    <p x-show="dragging" x-cloak class="pointer-events-none text-center text-sm font-semibold text-primary-text">{{ __('common.filedrop.drop_here') }}</p>

    <p x-show="tooBig" x-cloak class="mt-1 text-[11px] font-medium text-red-600" data-filedrop-too-big>
        <span x-text="tooBig"></span> — {{ __('common.filedrop.too_big', ['mb' => (int) $maxMb]) }}
    </p>

    {{-- 업로드 진행 게이지 — 이 존 업로드 중에만 노출. 즉시완료·0% 도 보이게 최소폭 12%, 완료 후 0.5s 잔류 --}}
    <div x-show="uploading" x-cloak class="mt-2 h-1.5 overflow-hidden rounded bg-gray-200">
        <div class="h-full rounded bg-primary transition-[width] duration-200 ease-out"
             :style="`width: ${Math.max(progress, 12)}%`"></div>
    </div>

    {{ $slot }}
</div>
