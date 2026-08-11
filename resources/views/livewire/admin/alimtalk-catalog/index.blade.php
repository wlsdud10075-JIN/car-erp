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

    /** 시각 규칙형 알림별 규칙 행: code => [['to'=>,'days'=>[],'from'=>,'till'=>], ...]. */
    public array $timeRules = [];

    /** 공휴일 수기 목록 (회사 공통) — 'YYYY-MM-DD' 를 줄바꿈으로. */
    public string $holidays = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        foreach (array_keys(AlimtalkTemplates::TEMPLATES) as $code) {
            if (AlimtalkRecipients::isBroadcast($code)) {
                $this->roles[$code] = AlimtalkRecipients::selectedRoles($code);
            }
            if (AlimtalkRecipients::isTimeRouted($code)) {
                $this->timeRules[$code] = AlimtalkRecipients::timeRules($code);
            }
        }
        $this->holidays = implode("
", AlimtalkRecipients::holidays());
    }

    public function isTimeRouted(string $code): bool
    {
        return AlimtalkRecipients::isTimeRouted($code);
    }

    /** 지금 이 순간 이 알림을 받을 사람 수 — 규칙이 의도대로 걸리는지 눈으로 확인하는 자리. */
    public function timeRuleCount(string $code): int
    {
        return count(AlimtalkRecipients::forTimeRules($code));
    }

    public function addTimeRule(string $code): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        $this->timeRules[$code][] = ['to' => 'admin', 'days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'till' => '18:00'];
    }

    public function removeTimeRule(string $code, int $idx): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        unset($this->timeRules[$code][$idx]);
        $this->timeRules[$code] = array_values($this->timeRules[$code]);
    }

    /**
     * 규칙 저장. ⚠️ **행을 전부 지운 채 저장하면 기본값으로 되돌린다** —
     * 빈 규칙은 "아무도 안 받음"이 되는데, 조용히 0명에게 가는 게 최악이기 때문이다.
     */
    public function saveTimeRules(string $code): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if (! AlimtalkRecipients::isTimeRouted($code)) {
            return;
        }

        $clean = [];
        foreach ($this->timeRules[$code] ?? [] as $rule) {
            $to = trim((string) ($rule['to'] ?? ''));
            $days = array_values(array_unique(array_filter(
                array_map('intval', (array) ($rule['days'] ?? [])),
                fn (int $d) => $d >= 1 && $d <= 7,
            )));
            if ($to === '' || $days === []) {
                continue;   // 수신자나 요일이 비면 영원히 안 걸리는 행 — 저장하지 않는다
            }
            sort($days);
            $clean[] = [
                'to' => $to,
                'days' => $days,
                'from' => $this->hhmm($rule['from'] ?? '00:00'),
                'till' => $this->hhmm($rule['till'] ?? '24:00'),
            ];
        }

        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(
            ['key' => "alimtalk_timerules_{$code}_{$set}"],
            ['value' => $clean === [] ? '' : json_encode($clean, JSON_UNESCAPED_UNICODE), 'type' => 'string',
                'description' => '알림톡 시각 규칙 '.$code.' ('.$set.')'],
        );

        // 저장한 결과를 그대로 되읽는다 — 빈 저장이면 기본값이 화면에 다시 뜬다(무엇이 적용됐는지 일치).
        $this->timeRules[$code] = AlimtalkRecipients::timeRules($code);
        $this->dispatch('notify', message: __('alimtalk_catalog.saved'), type: 'success');
    }

    public function saveHolidays(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(
            ['key' => "alimtalk_holidays_{$set}"],
            ['value' => $this->holidays, 'type' => 'string', 'description' => '알림톡 공휴일 목록 ('.$set.')'],
        );
        // 형식이 틀린 줄은 조용히 버려진다 — 무엇이 실제로 인식됐는지 되읽어 보여준다.
        $this->holidays = implode("
", AlimtalkRecipients::holidays());
        $this->dispatch('notify', message: __('alimtalk_catalog.saved'), type: 'success');
    }

    /** 'H:M' 을 'HH:MM' 으로 정규화(문자열 비교가 아니라 분으로 환산되므로 값만 맞으면 된다). */
    private function hhmm(mixed $raw): string
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', (string) $raw)), 2, 0);

        return sprintf('%02d:%02d', max(0, min(24, $h)), max(0, min(59, $m)));
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
                    @elseif($this->isTimeRouted($code))
                        {{-- 🕑 시각 규칙 — "17:30 이후엔 대표"를 예외 분기가 아니라 규칙 한 줄로 표현한다.
                             종료 < 시작이면 자정을 넘긴 구간(17:30–09:00)이고, 공휴일은 일요일로 취급한다. --}}
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-xs font-medium text-gray-500">
                            {{ __('alimtalk_catalog.time_rules') }}
                            <span class="text-gray-400">({{ __('alimtalk_catalog.now_receiving', ['n' => $this->timeRuleCount($code)]) }})</span>
                        </div>
                        <div class="flex flex-col gap-2">
                            @foreach($this->timeRules[$code] ?? [] as $i => $rule)
                                <div wire:key="tr-{{ $code }}-{{ $i }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2">
                                    <input type="text" wire:model="timeRules.{{ $code }}.{{ $i }}.to"
                                           class="input-base w-44 text-xs" placeholder="{{ __('alimtalk_catalog.rule_to_ph') }}" />
                                    <span class="flex items-center gap-1.5">
                                        @foreach(__('alimtalk_catalog.weekdays') as $d => $dl)
                                            <label class="flex items-center gap-0.5 text-[11px] text-gray-600">
                                                <input type="checkbox" value="{{ $d }}" wire:model="timeRules.{{ $code }}.{{ $i }}.days"
                                                       class="h-3.5 w-3.5 rounded border-gray-300" />{{ $dl }}
                                            </label>
                                        @endforeach
                                    </span>
                                    <input type="time" wire:model="timeRules.{{ $code }}.{{ $i }}.from" class="input-base w-28 text-xs" />
                                    <span class="text-xs text-gray-400">~</span>
                                    <input type="time" wire:model="timeRules.{{ $code }}.{{ $i }}.till" class="input-base w-28 text-xs" />
                                    <button type="button" wire:click="removeTimeRule('{{ $code }}', {{ $i }})"
                                            class="ml-auto rounded px-2 py-1 text-[11px] text-red-600 hover:bg-red-50">
                                        {{ __('alimtalk_catalog.rule_remove') }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="addTimeRule('{{ $code }}')" class="rounded border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                {{ __('alimtalk_catalog.rule_add') }}
                            </button>
                            <button type="button" wire:click="saveTimeRules('{{ $code }}')" class="btn-primary ml-auto px-3 py-1 text-xs">
                                {{ __('alimtalk_catalog.save') }}
                            </button>
                        </div>
                        <p class="mt-2 text-[11px] leading-relaxed text-gray-400">{{ __('alimtalk_catalog.rule_help') }}</p>

                        <div class="mt-3 border-t border-gray-100 pt-3">
                            <div class="mb-1 text-xs font-medium text-gray-500">{{ __('alimtalk_catalog.holidays') }}</div>
                            <textarea wire:model="holidays" rows="3" class="input-base w-full font-mono text-xs" placeholder="2026-01-01&#10;2026-03-01"></textarea>
                            <div class="mt-1 flex items-center gap-2">
                                <p class="text-[11px] text-gray-400">{{ __('alimtalk_catalog.holidays_help') }}</p>
                                <button type="button" wire:click="saveHolidays" class="btn-primary ml-auto px-3 py-1 text-xs">
                                    {{ __('alimtalk_catalog.save') }}
                                </button>
                            </div>
                        </div>
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
