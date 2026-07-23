<?php

use App\Models\Setting;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    /** 브로드캐스트형 알림별 선택 역할: code => [group keys]. */
    public array $roles = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        foreach (array_keys(AlimtalkTemplates::TEMPLATES) as $code) {
            if (AlimtalkRecipients::isBroadcast($code)) {
                $this->roles[$code] = AlimtalkRecipients::selectedRoles($code);
            }
        }
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

    public function groups(): array
    {
        return AlimtalkRecipients::BROADCAST_GROUPS;
    }

    public function isBroadcast(string $code): bool
    {
        return AlimtalkRecipients::isBroadcast($code);
    }

    public function targetedLabel(string $code): ?string
    {
        return AlimtalkRecipients::TARGETED_LABELS[$code] ?? null;
    }

    public function autoExtra(string $code): ?string
    {
        return AlimtalkRecipients::AUTO_EXTRA[$code] ?? null;
    }

    /** 이 알림 현재 실제 수신 인원 수(선택 역할 기준). */
    public function recipientCount(string $code): int
    {
        return count(AlimtalkRecipients::forBroadcast($code));
    }

    /** 역할 선택 저장 (회사별). super 전용 — 돈 알림 라우팅이라 감사로그. */
    public function saveRoles(string $code): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if (! AlimtalkRecipients::isBroadcast($code)) {
            return;
        }
        $valid = array_keys(AlimtalkRecipients::BROADCAST_GROUPS);
        $selected = array_values(array_intersect($this->roles[$code] ?? [], $valid));
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(
            ['key' => "alimtalk_roles_{$code}_{$set}"],
            ['value' => implode(',', $selected), 'type' => 'string', 'description' => '알림톡 수신 역할 '.$code.' ('.$set.')'],
        );
        $this->roles[$code] = $selected;   // 정규화된 선택 반영 (recipientCount 는 메서드라 자동 재계산)
        $this->dispatch('notify', message: __('alimtalk_catalog.saved'), type: 'success');
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

    <div class="flex flex-col gap-3">
        @foreach($this->rows as $r)
            @php
                $code = $r['code'];
                $broadcast = $this->isBroadcast($code);
                $canSend = $this->config->canSend($code);
                $autoExtra = $this->autoExtra($code);
                $targeted = $this->targetedLabel($code);
            @endphp
            <div class="card" x-data="{ open: false }">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-800">{{ $r['name'] }}</span>
                            @if($canSend)
                                <span class="badge badge-green">{{ __('alimtalk_catalog.sending') }}</span>
                            @else
                                <span class="badge badge-gray">{{ __('alimtalk_catalog.off') }}</span>
                            @endif
                        </div>
                        <div class="mt-0.5 font-mono text-[10px] text-gray-400">{{ $code }}</div>
                        <div class="mt-1 text-xs text-gray-500">🕑 {{ $r['when'] }}</div>
                    </div>
                    <button type="button" class="shrink-0 text-xs text-primary-text underline" @click="open = !open"
                        x-text="open ? '{{ __('alimtalk_catalog.hide') }}' : '{{ __('alimtalk_catalog.show') }}'"></button>
                </div>

                {{-- 수신자 --}}
                <div class="mt-3 border-t border-gray-100 pt-3">
                    @if($broadcast)
                        <div class="mb-2 text-xs font-medium text-gray-500">
                            {{ __('alimtalk_catalog.recipient_roles') }}
                            <span class="ml-1 text-gray-400">({{ __('alimtalk_catalog.now_count', ['n' => $this->recipientCount($code)]) }})</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            @foreach($this->groups() as $gkey => $glabel)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700">
                                    <input type="checkbox" value="{{ $gkey }}" wire:model="roles.{{ $code }}" class="h-4 w-4 rounded border-gray-300" />
                                    {{ $glabel }}
                                </label>
                            @endforeach
                            <button type="button" wire:click="saveRoles('{{ $code }}')" class="btn-primary ml-auto px-3 py-1 text-xs">
                                {{ __('alimtalk_catalog.save') }}
                            </button>
                        </div>
                        @if($autoExtra)
                            <div class="mt-2 text-xs text-gray-500">＋ {{ __('alimtalk_catalog.auto_prefix') }} <span class="font-medium text-gray-600">{{ $autoExtra }}</span> {{ __('alimtalk_catalog.auto_suffix') }}</div>
                        @endif
                    @else
                        <div class="text-xs text-gray-500">{{ __('alimtalk_catalog.recipient') }}: <span class="font-medium text-gray-700">{{ $targeted ?? $r['recipient'] }}</span></div>
                        <div class="mt-1 text-[11px] text-gray-400">{{ __('alimtalk_catalog.auto_fixed') }}</div>
                    @endif
                </div>

                {{-- 본문 --}}
                <div x-show="open" x-collapse class="mt-3 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-700">{{ $r['body'] }}</div>
            </div>
        @endforeach
    </div>

    <p class="mt-3 text-xs text-gray-400">{{ __('alimtalk_catalog.footnote') }}</p>
</div>
