<?php

use App\Support\AlimtalkConfig;
use App\Support\AlimtalkTemplates;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccessAdmin(), 403);
    }

    #[Computed]
    public function config(): AlimtalkConfig
    {
        return AlimtalkConfig::active();
    }

    #[Computed]
    public function rows(): array
    {
        return AlimtalkTemplates::catalog();
    }

    /** 수신자 라벨 — 내부 코드값을 사람 설명으로. */
    public function recipientLabel(string $r): string
    {
        return [
            '관리' => '관리 · 업무관리자',
            '영업' => '담당 영업',
            '대표' => '대표(최고관리자)',
            'admin' => '대표(최고관리자)',
            '관리/재무' => '관리(승인) → 재무(확정)',
            '기안자' => '기안자 본인',
            '승인자' => '승인 계단 담당',
            '제출자' => '제출자 본인',
            'dealer' => '국내 딜러(수동 발송)',
        ][$r] ?? $r;
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('alimtalk_catalog.title') }}</h2>
            <p class="mt-1 text-xs text-gray-500">{{ __('alimtalk_catalog.subtitle') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500">{{ __('alimtalk_catalog.company') }}: <span class="font-semibold text-gray-700">{{ $this->config->set }}</span></span>
            @if($this->config->enabled)
                <span class="badge badge-green">{{ __('alimtalk_catalog.master_on') }}</span>
            @else
                <span class="badge badge-gray">{{ __('alimtalk_catalog.master_off') }}</span>
            @endif
        </div>
    </div>

    <div class="card overflow-hidden p-0">
        {{-- 데스크탑 --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                        <th class="px-4 py-3 font-medium">{{ __('alimtalk_catalog.col.name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('alimtalk_catalog.col.recipient') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('alimtalk_catalog.col.when') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('alimtalk_catalog.col.body') }}</th>
                        <th class="px-4 py-3 text-center font-medium">{{ __('alimtalk_catalog.col.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->rows as $r)
                        @php $canSend = $this->config->canSend($r['code']); @endphp
                        <tr class="border-b border-gray-100 align-top" x-data="{ open: false }">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-800">{{ $r['name'] }}</div>
                                <div class="mt-0.5 font-mono text-[10px] text-gray-400">{{ $r['code'] }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ $this->recipientLabel($r['recipient']) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $r['when'] }}</td>
                            <td class="px-4 py-3">
                                <button type="button" class="text-xs text-primary-text underline" @click="open = !open"
                                    x-text="open ? '{{ __('alimtalk_catalog.hide') }}' : '{{ __('alimtalk_catalog.show') }}'"></button>
                                <div x-show="open" x-collapse class="mt-2 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-700">{{ $r['body'] }}</div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($canSend)
                                    <span class="badge badge-green">{{ __('alimtalk_catalog.sending') }}</span>
                                @else
                                    <span class="badge badge-gray">{{ __('alimtalk_catalog.off') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- 모바일 --}}
        <div class="divide-y divide-gray-100 sm:hidden">
            @foreach($this->rows as $r)
                @php $canSend = $this->config->canSend($r['code']); @endphp
                <div class="p-4" x-data="{ open: false }">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold text-gray-800">{{ $r['name'] }}</div>
                            <div class="mt-0.5 font-mono text-[10px] text-gray-400">{{ $r['code'] }}</div>
                        </div>
                        @if($canSend)
                            <span class="badge badge-green shrink-0">{{ __('alimtalk_catalog.sending') }}</span>
                        @else
                            <span class="badge badge-gray shrink-0">{{ __('alimtalk_catalog.off') }}</span>
                        @endif
                    </div>
                    <div class="mt-2 text-xs text-gray-600"><span class="text-gray-400">{{ __('alimtalk_catalog.col.recipient') }}:</span> {{ $this->recipientLabel($r['recipient']) }}</div>
                    <div class="mt-1 text-xs text-gray-600"><span class="text-gray-400">{{ __('alimtalk_catalog.col.when') }}:</span> {{ $r['when'] }}</div>
                    <button type="button" class="mt-2 text-xs text-primary-text underline" @click="open = !open"
                        x-text="open ? '{{ __('alimtalk_catalog.hide') }}' : '{{ __('alimtalk_catalog.show') }}'"></button>
                    <div x-show="open" x-collapse class="mt-2 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-700">{{ $r['body'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <p class="mt-3 text-xs text-gray-400">{{ __('alimtalk_catalog.footnote') }}</p>
</div>
