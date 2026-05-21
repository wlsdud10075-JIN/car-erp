@props([
    'counts' => [],            // ['매입중' => 5, '매입완료' => 5, ...]
    'urlBuilder' => null,      // callable(string $status): string
    'title' => '차량 진행 단계',
    'subtitle' => null,        // 옵션 부제목 (예: 담당자명·기간)
])

@php
    // 10단계 — CLAUDE.md 진행상태 우선순위 + SKILLS.md §10 뱃지 색 매핑.
    // 큐 17 — 폐기 컨셉 제거 (운영상 없음). 11단계 → 10단계.
    // 안건 1 v4 (2026-05-21) — 워크플로우 순서 변경: 선적(반입) → 통관 → 거래완료.
    // 색 매핑은 v3 amber/green 순서 그대로 유지 (단계명만 swap — 사용자 결정).
    $stages = [
        ['key' => '매입중',       'badge' => 'badge-blue'],
        ['key' => '매입완료',     'badge' => 'badge-blue'],
        ['key' => '말소완료',     'badge' => 'badge-blue'],
        ['key' => '판매중',       'badge' => 'badge-purple'],
        ['key' => '판매완료',     'badge' => 'badge-purple'],
        ['key' => '선적중',       'badge' => 'badge-amber'],
        ['key' => '선적완료',     'badge' => 'badge-amber'],
        ['key' => '통관중',       'badge' => 'badge-green'],
        ['key' => '통관완료',     'badge' => 'badge-green'],
        ['key' => '거래완료',     'badge' => 'badge-gray'],
    ];
@endphp

<div class="card">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">{{ $title }}</h2>
        @if($subtitle)
        <p class="text-xs text-gray-400">{{ $subtitle }}</p>
        @endif
    </div>
    <div class="overflow-x-auto">
        <div class="flex min-w-max items-stretch gap-1">
            @foreach($stages as $i => $stage)
                @php
                    $count = $counts[$stage['key']] ?? 0;
                    $href = $urlBuilder ? $urlBuilder($stage['key']) : null;
                @endphp
                @if($href)
                <a href="{{ $href }}" wire:navigate
                   class="flex flex-shrink-0 flex-col items-center gap-1.5 rounded-md px-3 py-2 transition hover:bg-gray-50">
                    <span class="badge {{ $stage['badge'] }}">{{ $stage['key'] }}</span>
                    <span class="text-lg font-bold {{ $count > 0 ? 'text-gray-800' : 'text-gray-300' }}">{{ $count }}</span>
                </a>
                @else
                <div class="flex flex-shrink-0 flex-col items-center gap-1.5 rounded-md px-3 py-2">
                    <span class="badge {{ $stage['badge'] }}">{{ $stage['key'] }}</span>
                    <span class="text-lg font-bold {{ $count > 0 ? 'text-gray-800' : 'text-gray-300' }}">{{ $count }}</span>
                </div>
                @endif

                @if($i < count($stages) - 1)
                <span class="flex items-center text-xs text-gray-300" aria-hidden="true">›</span>
                @endif
            @endforeach
        </div>
    </div>
</div>
