<x-layouts.app>
    {{-- 업무 가이드 씬 한 장 (jin 2026-11-13). 본문 = resources/guides/{slug}/index.html (레포에 든 우리 글 — 그대로 내보낸다).
         본문이 쓰는 모양은 아래 네 가지뿐: 레인(.gd-lane) · 카드(.gd-step) · 카톡 말풍선(.gd-bubble) · 접힌 개발 블록(details). --}}
    <style>
        .gd-scene { max-width: 980px; margin: 0 auto; color: #1f2937; font-size: 13.5px; }
        .gd-scene h3 { font-size: 14px; font-weight: 700; margin: 0; }
        .gd-lane { display: grid; grid-template-columns: 110px 1fr; gap: 14px; margin-bottom: 14px; }
        .gd-lane > .gd-who2 { font-size: 12px; font-weight: 700; color: #fff; border-radius: 8px; padding: 8px 6px; text-align: center; align-self: start; }
        .gd-l-erp { background: #7c6fcd; } .gd-l-talk { background: #b45309; } .gd-l-out { background: #0f766e; } .gd-l-board { background: #1d4ed8; }
        .gd-step { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; }
        .gd-step .gd-n { font-size: 11.5px; color: #6b7280; font-weight: 700; }
        .gd-step p { margin: 6px 0 0; line-height: 1.65; }
        .gd-step img { margin-top: 10px; max-width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; }
        .gd-bubble { margin-top: 10px; max-width: 340px; background: #fee500; border-radius: 4px 14px 14px 14px; padding: 10px 12px; font-size: 12.5px; line-height: 1.6; white-space: pre-line; }
        .gd-bubble small { display: block; margin-top: 6px; color: #6b5a00; font-size: 11px; }
        .gd-scene details { margin-top: 10px; font-size: 12px; color: #4b5563; }
        .gd-scene details summary { cursor: pointer; color: #6b7280; }
        .gd-scene details pre { background: #111827; color: #e5e7eb; border-radius: 6px; padding: 10px; overflow: auto; font-size: 11.5px; margin: 6px 0 0; }
        .gd-check { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 14px; }
        .gd-check ul { margin: 6px 0 0; padding-left: 18px; list-style: disc; }
        .gd-check li { margin: 3px 0; }
        @media (max-width: 640px) {
            .gd-lane { grid-template-columns: 1fr; }
            .gd-lane > .gd-who2 { justify-self: start; padding: 4px 10px; }
        }
    </style>

    <div class="p-3 md:p-6">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <a href="{{ route('guides.index') }}" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700">{{ __('guides.back') }}</a>
            <div class="min-w-0 flex-1">
                <h2 class="text-xl font-bold text-gray-800">{{ $scene['title'] }}</h2>
                <p class="mt-0.5 text-sm text-gray-500">{{ $scene['summary'] }} · {{ implode('·', array_map(fn ($w) => __('guides.roles.'.$w), $scene['who'])) }} · {{ __('guides.updated', ['date' => $scene['updated']]) }}</p>
            </div>
            @if($scene['lap'] !== null && $near['lapTotal'])
                <span class="pill-count">{{ __('guides.lap_pos', ['i' => $scene['lap'], 'n' => $near['lapTotal']]) }}</span>
            @endif
        </div>

        @if($scene['koreanOnly'])
            <p class="mb-3 text-sm text-gray-500" style="max-width: 980px; margin-left: auto; margin-right: auto">{{ __('guides.korean_only_note') }}</p>
        @endif
        <div class="gd-scene">{!! $html !!}</div>

        <div class="flex items-center justify-between gap-3" style="max-width: 980px; margin: 24px auto 0; padding-top: 16px; border-top: 1px solid #e5e7eb">
            <div>
                @if($near['prev'])
                    <a href="{{ route('guides.show', $near['prev']['slug']) }}" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700">← {{ \Illuminate\Support\Str::before($near['prev']['title'], ' — ') }}</a>
                @endif
            </div>
            <div>
                @if($near['next'])
                    <a href="{{ route('guides.show', $near['next']['slug']) }}" class="btn-primary">{{ __('guides.next', ['title' => \Illuminate\Support\Str::before($near['next']['title'], ' — ')]) }}</a>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
