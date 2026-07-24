<?php

use App\Services\Assistant\AssistantService;
use Livewire\Volt\Component;

/**
 * 사내 업무 도우미 위젯 (jin 2026-07-24) — 플로팅 채팅.
 *   레이아웃에 @livewire('assistant.widget') 로 임베드. canUseAssistant 게이트.
 *   B(미수·채권·자금)=DB 즉답 / A(업무가이드)=로컬 LLM RAG.
 */
new class extends Component
{
    /** @var array<int,array{role:string,text:string,sources?:array}> */
    public array $messages = [];

    public string $q = '';

    public function send(): void
    {
        $user = auth()->user();
        abort_unless($user?->canUseAssistant(), 403);

        $q = trim($this->q);
        if ($q === '') {
            return;
        }
        $this->messages[] = ['role' => 'me', 'text' => $q];
        $this->q = '';

        $res = app(AssistantService::class)->ask($q, $user);
        $this->messages[] = [
            'role' => 'bot',
            'text' => $res['answer'],
            'sources' => collect($res['sources'] ?? [])->pluck('title')->all(),
        ];
    }
}; ?>

<div x-data="{ open: false }" class="fixed bottom-5 right-5 z-50 print:hidden">
    {{-- 플로팅 버튼 --}}
    <button type="button" @click="open = !open"
            class="flex h-14 w-14 items-center justify-center rounded-full bg-primary text-white shadow-lg transition hover:bg-primary-hover"
            :aria-expanded="open" aria-label="업무 도우미 열기">
        <span x-show="!open" class="text-2xl">💬</span>
        <span x-show="open" class="text-2xl" x-cloak>×</span>
    </button>

    {{-- 채팅 패널 --}}
    <div x-show="open" x-cloak x-transition
         class="absolute bottom-16 right-0 flex h-[520px] w-[360px] max-w-[calc(100vw-2.5rem)] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl">
        <div class="border-b border-gray-100 bg-gray-50 px-4 py-3">
            <p class="text-sm font-bold text-gray-800">SSANCAR 업무 도우미</p>
            <p class="text-[11px] text-gray-400">로컬 LLM · 미수·채권·자금·업무가이드</p>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto p-4" id="assistant-msgs">
            @forelse($messages as $m)
                @if($m['role'] === 'me')
                    <div class="ml-auto max-w-[85%] rounded-xl rounded-br-sm bg-primary px-3 py-2 text-sm text-white">{{ $m['text'] }}</div>
                @else
                    <div class="mr-auto max-w-[90%] rounded-xl rounded-bl-sm bg-gray-100 px-3 py-2 text-sm text-gray-800 whitespace-pre-wrap">{{ $m['text'] }}</div>
                    @if(! empty($m['sources']))
                        <div class="mr-auto text-[11px] text-gray-400">📎 {{ implode(' · ', $m['sources']) }}</div>
                    @endif
                @endif
            @empty
                <div class="mr-auto max-w-[90%] rounded-xl rounded-bl-sm bg-gray-100 px-3 py-2 text-sm text-gray-600">
                    안녕하세요. 미수 현황·채권 요약·자금 현황이나 사내 업무 가이드를 물어보세요.
                    <span class="mt-1 block text-[11px] text-gray-400">예: "바이어별 미수 현황", "채권 요약", "정산은 누가 확정해?"</span>
                </div>
            @endforelse
            <div wire:loading wire:target="send" class="mr-auto rounded-xl bg-gray-100 px-3 py-2 text-sm text-gray-400">⏳ 조회 중…</div>
        </div>

        <form wire:submit="send" class="flex gap-2 border-t border-gray-100 p-3">
            <input wire:model="q" type="text" placeholder="질문을 입력하세요…" autocomplete="off"
                   class="input-base flex-1 text-sm" wire:loading.attr="disabled" wire:target="send" />
            <button type="submit" class="btn-primary px-4 text-sm" wire:loading.attr="disabled" wire:target="send">전송</button>
        </form>
    </div>
</div>
