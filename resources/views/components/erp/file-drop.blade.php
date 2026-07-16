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
    'label' => '여기로 드래그하거나 파일을 선택하세요',
])
<div
    x-data="{ over: false, uploading: false, progress: 0 }"
    x-on:livewire-upload-start="uploading = true; progress = 0"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
    x-on:livewire-upload-finish="progress = 100; setTimeout(() => { uploading = false }, 500)"
    x-on:livewire-upload-error="uploading = false; progress = 0"
    x-on:dragover.prevent="over = true"
    x-on:dragleave.prevent="over = false"
    x-on:drop.prevent="over = false; if ($event.dataTransfer && $event.dataTransfer.files.length) { $refs.fd.files = $event.dataTransfer.files; $refs.fd.dispatchEvent(new Event('change', { bubbles: true })); }"
    class="rounded-md border border-dashed px-3 py-2 transition-colors"
    :class="over ? 'border-primary bg-primary-light' : 'border-gray-200 bg-gray-50/50'"
>
    <input x-ref="fd" type="file" wire:model="{{ $model }}" accept="{{ $accept }}" @if($multiple) multiple @endif class="hidden" />

    <div class="flex items-center gap-2 text-xs text-gray-500">
        <button type="button" @click="$refs.fd.click()"
                class="shrink-0 rounded border-0 bg-primary-light px-2 py-1 text-[11px] font-medium text-primary-text hover:bg-primary/10">
            파일 선택
        </button>
        <span x-show="!over" class="truncate">{{ $label }}</span>
        <span x-show="over" x-cloak class="font-medium text-primary-text">여기에 놓으면 업로드됩니다</span>
    </div>

    {{-- 업로드 진행 게이지 — 이 존 업로드 중에만 노출. 즉시완료·0% 도 보이게 최소폭 12%, 완료 후 0.5s 잔류 --}}
    <div x-show="uploading" x-cloak class="mt-2 h-1.5 overflow-hidden rounded bg-gray-200">
        <div class="h-full rounded bg-primary transition-[width] duration-200 ease-out"
             :style="`width: ${Math.max(progress, 12)}%`"></div>
    </div>

    {{ $slot }}
</div>
