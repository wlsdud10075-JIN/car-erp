<?php

use App\Models\Setting;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkRecipients;
use App\Services\KoreanHolidayService;
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

    /** 공휴일 API 활용기간 만료일 (YYYY-MM-DD). 24개월마다 갱신해야 한다. */
    public string $holidayExpiresAt = '';

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
        $this->holidayExpiresAt = (string) (Setting::get(KoreanHolidayService::expiresAtKey(), '') ?: '');
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

    /** 이 행이 지금 걸려 있는가 — 「지금 적용」 표시용. 판정은 발송과 같은 함수를 쓴다. */
    public function appliesNow(array $rule): bool
    {
        return AlimtalkRecipients::ruleAppliesNow($rule);
    }

    /** 이 행이 '종일'인가 — 00:00~24:00. 별도 상태를 두지 않고 값에서 파생한다(둘이 어긋날 일이 없다). */
    public function isAllDay(array $rule): bool
    {
        return ($rule['from'] ?? '') === '00:00' && ($rule['till'] ?? '') === '24:00';
    }

    /**
     * 종료가 시작보다 이르거나 같으면 **자정을 넘긴 구간**이다(17:30~익일 09:00).
     * 화면에 「익일」을 찍지 않으면 "당일 09시인가?" 로 읽힌다(jin 지적).
     */
    public function crossesMidnight(array $rule): bool
    {
        return ! $this->isAllDay($rule) && ($rule['till'] ?? '') <= ($rule['from'] ?? '');
    }

    /** 규칙 한 줄을 사람 말로 — 시간 칸만 보고는 해석이 갈린다. */
    public function describeRule(array $rule): string
    {
        $names = __('alimtalk_catalog.weekdays');
        $days = array_map(fn ($d) => $names[(int) $d] ?? $d, (array) ($rule['days'] ?? []));
        $when = $this->isAllDay($rule)
            ? __('alimtalk_catalog.rule_allday')
            : ($rule['from'] ?? '').' ~ '.($this->crossesMidnight($rule) ? __('alimtalk_catalog.rule_nextday').' ' : '').($rule['till'] ?? '');

        return __('alimtalk_catalog.rule_summary', [
            'days' => implode('·', $days) ?: '—',
            'when' => $when,
            'to' => trim((string) ($rule['to'] ?? '')) ?: '—',
        ]);
    }

    /** 종일 ↔ 시간 지정 전환. 24:00 은 <input type="time"> 에 못 들어가므로 버튼으로만 만든다. */
    public function toggleAllDay(string $code, int $idx): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        $rule = $this->timeRules[$code][$idx] ?? null;
        if ($rule === null) {
            return;
        }
        [$from, $till] = $this->isAllDay($rule) ? ['09:00', '18:00'] : ['00:00', '24:00'];
        $this->timeRules[$code][$idx]['from'] = $from;
        $this->timeRules[$code][$idx]['till'] = $till;
    }

    /** 자동 수집 상태 — 켜졌는지, 몇 건인지, 언제 받았는지. */
    public function holidayAuto(): array
    {
        $year = (int) now()->year;

        $synced = (string) (Setting::get(KoreanHolidayService::lastSyncedKey(), '') ?: '');

        return [
            'configured' => KoreanHolidayService::isConfigured(),
            'this_year' => KoreanHolidayService::cached($year),
            'next_year' => KoreanHolidayService::cached($year + 1),
            'synced_at' => $synced,
            // 마지막 수집이 오래됐으면 조용히 늙고 있다는 뜻 — 만료·장애의 첫 신호다.
            'stale' => $synced !== '' && \Illuminate\Support\Carbon::parse($synced)->lt(now()->subDays(3)),
            'expires_in' => KoreanHolidayService::daysUntilExpiry(),
            'year' => $year,
        ];
    }

    /**
     * 활용기간 만료일 저장 — API 가 알려주지 않으므로 사람이 적어 둔다(24개월).
     * 안 적어두면 만료 후 **수집만 조용히 실패**하고 저장분이 늙는다(발송은 계속돼 아무도 모른다).
     */
    public function saveHolidayExpiry(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        $v = trim($this->holidayExpiresAt);
        Setting::updateOrCreate(
            ['key' => KoreanHolidayService::expiresAtKey()],
            ['value' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '', 'type' => 'string',
                'description' => '공휴일 API 활용기간 만료일'],
        );
        $this->holidayExpiresAt = (string) (Setting::get(KoreanHolidayService::expiresAtKey(), '') ?: '');
        $this->dispatch('notify', message: __('alimtalk_catalog.saved'), type: 'success');
    }

    /** 지금 받아오기 — 연말·임시공휴일 지정 직후에 하루를 안 기다리게. */
    public function syncHolidays(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if (! KoreanHolidayService::isConfigured()) {
            $this->dispatch('notify', message: __('alimtalk_catalog.holidays_auto_unset'), type: 'warning');

            return;
        }
        $svc = app(KoreanHolidayService::class);
        $n = ($svc->syncYear((int) now()->year) ?? 0) + ($svc->syncYear((int) now()->year + 1) ?? 0);
        $this->dispatch('notify',
            message: $n > 0 ? __('alimtalk_catalog.holidays_synced', ['n' => $n]) : __('alimtalk_catalog.holidays_sync_failed'),
            type: $n > 0 ? 'success' : 'warning');
    }

    /** 화면이 보여줄 고정 공휴일(코드 내장) — 수기로 또 적지 않게. */
    public function fixedHolidays(): array
    {
        return AlimtalkRecipients::FIXED_HOLIDAYS;
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
        $bad = [];
        foreach ($this->timeRules[$code] ?? [] as $rule) {
            // 잘못 적은 수신자는 저장하지 않는다 — 남기면 "수신자는 있는데 아무에게도 안 가는" 상태가 된다.
            $tokens = array_filter(array_map('trim', explode(',', (string) ($rule['to'] ?? ''))));
            $good = array_values(array_filter($tokens, fn ($t) => AlimtalkRecipients::isValidTarget($t)));
            $bad = array_merge($bad, array_diff($tokens, $good));
            $to = implode(',', $good);
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
        $this->dispatch('notify',
            message: $bad === []
                ? __('alimtalk_catalog.saved')
                : __('alimtalk_catalog.rule_bad_target', ['list' => implode(', ', array_unique($bad))]),
            type: $bad === [] ? 'success' : 'warning');
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
                                @php
                                    $allDay = $this->isAllDay($rule);
                                    $overnight = $this->crossesMidnight($rule);
                                @endphp
                                <div wire:key="tr-{{ $code }}-{{ $i }}" class="rounded-lg bg-gray-50 p-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input type="text" wire:model.live="timeRules.{{ $code }}.{{ $i }}.to"
                                               class="input-base w-44 text-xs" placeholder="{{ __('alimtalk_catalog.rule_to_ph') }}" />
                                        <span class="flex items-center gap-1.5">
                                            @foreach(__('alimtalk_catalog.weekdays') as $d => $dl)
                                                <label class="flex items-center gap-0.5 text-[11px] text-gray-600">
                                                    <input type="checkbox" value="{{ $d }}" wire:model.live="timeRules.{{ $code }}.{{ $i }}.days"
                                                           class="h-3.5 w-3.5 rounded border-gray-300" />{{ $dl }}
                                                </label>
                                            @endforeach
                                        </span>

                                        {{-- 종일이면 시간칸을 아예 안 보여준다. 24:00 은 <input type="time"> 에
                                             넣을 수 없어(최대 23:59) 빈칸으로 보이던 게 혼란의 원인이었다(jin 지적). --}}
                                        @if($allDay)
                                            <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-bold text-primary-text">
                                                {{ __('alimtalk_catalog.rule_allday') }}
                                            </span>
                                        @else
                                            <input type="time" wire:model.live="timeRules.{{ $code }}.{{ $i }}.from" class="input-base w-28 text-xs" />
                                            <span class="text-xs text-gray-400">~</span>
                                            @if($overnight)
                                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-bold text-amber-800">
                                                    {{ __('alimtalk_catalog.rule_nextday') }}
                                                </span>
                                            @endif
                                            <input type="time" wire:model.live="timeRules.{{ $code }}.{{ $i }}.till" class="input-base w-28 text-xs" />
                                        @endif

                                        <button type="button" wire:click="toggleAllDay('{{ $code }}', {{ $i }})"
                                                class="rounded border border-gray-300 px-2 py-1 text-[11px] text-gray-600 hover:bg-white">
                                            {{ $allDay ? __('alimtalk_catalog.rule_set_hours') : __('alimtalk_catalog.rule_allday') }}
                                        </button>
                                        <button type="button" wire:click="removeTimeRule('{{ $code }}', {{ $i }})"
                                                class="ml-auto rounded px-2 py-1 text-[11px] text-red-600 hover:bg-red-50">
                                            {{ __('alimtalk_catalog.rule_remove') }}
                                        </button>
                                    </div>
                                    {{-- 사람 말 요약 — 시간칸만 보고는 "당일인지 익일인지"가 안 갈린다. --}}
                                    <div class="mt-1 flex items-center gap-1.5 text-[11px] text-gray-500">
                                        @php $n = \App\Support\AlimtalkRecipients::countTargets((string) ($rule['to'] ?? '')); @endphp
                                        <span>↳ {{ $this->describeRule($rule) }}</span>
                                        {{-- 실제 인원수 — 오타나 전화번호 미등록이면 즉시 0명으로 보인다. --}}
                                        <span class="{{ $n === 0 ? 'font-bold text-red-600' : 'text-gray-400' }}">
                                            ({{ __('alimtalk_catalog.rule_people', ['n' => $n]) }})
                                        </span>
                                        @if($this->appliesNow($rule))
                                            <span class="rounded-full bg-green-100 px-1.5 py-0.5 font-bold text-green-700">{{ __('alimtalk_catalog.rule_active_now') }}</span>
                                        @endif
                                    </div>
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
                            {{-- 공휴일은 달력에 늘 있는 정보다 — 사람이 옮겨 적게 하면 결국 안 적게 되고,
                                 그러면 그날 담당자에게 알림이 가버린다(jin 지적). 그래서 자동 수집이 주 출처다. --}}
                            @php $auto = $this->holidayAuto(); @endphp
                            <div class="mb-2 rounded-lg border p-2 text-[11px] {{ $auto['configured'] ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-bold">{{ __('alimtalk_catalog.holidays_auto') }}</span>
                                    @if($auto['configured'])
                                        <span>{{ __('alimtalk_catalog.holidays_auto_on', [
                                            'y' => $auto['year'], 'a' => count($auto['this_year']),
                                            'y2' => $auto['year'] + 1, 'b' => count($auto['next_year']),
                                        ]) }}</span>
                                        @if($auto['synced_at'])
                                            <span class="opacity-70">· {{ $auto['synced_at'] }}</span>
                                        @endif
                                        <button type="button" wire:click="syncHolidays" wire:loading.attr="disabled"
                                                class="ml-auto rounded border border-green-300 bg-white px-2 py-0.5 font-medium hover:bg-green-100">
                                            {{ __('alimtalk_catalog.holidays_sync_now') }}
                                        </button>
                                    @else
                                        <span>{{ __('alimtalk_catalog.holidays_auto_off') }}</span>
                                    @endif
                                </div>
                                @if($auto['configured'] && $auto['this_year'])
                                    <div class="mt-1 opacity-80">
                                        {{ collect($auto['this_year'])->map(fn ($name, $d) => substr($d, 5).' '.$name)->implode(' · ') }}
                                    </div>
                                @endif

                                @if($auto['configured'])
                                    {{-- ⏳ 활용기간(24개월). API 가 안 알려주므로 사람이 적어 둔다 —
                                         안 적어두면 만료 후 **수집만 조용히 실패**하고 저장분이 늙는다. --}}
                                    @php
                                        $d = $auto['expires_in'];
                                        $tone = $d === null ? 'text-gray-500'
                                            : ($d < 0 ? 'text-red-700 font-bold' : ($d <= 60 ? 'text-amber-700 font-bold' : 'text-gray-600'));
                                    @endphp
                                    <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-green-200 pt-2">
                                        <span class="font-medium text-gray-600">{{ __('alimtalk_catalog.holidays_expiry') }}</span>
                                        <input type="date" wire:model="holidayExpiresAt" class="input-base w-40 text-xs" />
                                        <button type="button" wire:click="saveHolidayExpiry"
                                                class="rounded border border-gray-300 bg-white px-2 py-0.5 font-medium text-gray-700 hover:bg-gray-50">
                                            {{ __('alimtalk_catalog.save') }}
                                        </button>
                                        <span class="{{ $tone }}">
                                            @if($d === null)
                                                {{ __('alimtalk_catalog.holidays_expiry_unset') }}
                                            @elseif($d < 0)
                                                {{ __('alimtalk_catalog.holidays_expired', ['n' => abs($d)]) }}
                                            @else
                                                {{ __('alimtalk_catalog.holidays_expires_in', ['n' => $d]) }}
                                            @endif
                                        </span>
                                    </div>
                                    @if($auto['stale'])
                                        <div class="mt-1 font-bold text-red-700">{{ __('alimtalk_catalog.holidays_stale') }}</div>
                                    @endif
                                @endif
                            </div>
                            <div class="mb-2 rounded-lg bg-gray-50 p-2 text-[11px] text-gray-500">
                                <span class="font-medium text-gray-600">{{ __('alimtalk_catalog.holidays_fixed') }}</span>
                                <span class="ml-1">{{ implode(' · ', $this->fixedHolidays()) }}</span>
                                <div class="mt-1">{{ __('alimtalk_catalog.holidays_fixed_hint') }}</div>
                            </div>
                            <textarea wire:model="holidays" rows="3" class="input-base w-full font-mono text-xs" placeholder="2026-02-16&#10;2026-09-24"></textarea>
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
