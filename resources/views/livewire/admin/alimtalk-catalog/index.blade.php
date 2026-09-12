<?php

use App\Models\BoardRequest;
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

    /** 단계별 확대 일수 [code][group] => 일 (jin 2026-09-07). 「체크 = 받을지 / 숫자 = 언제부터」. */
    public array $escalate = [];

    /** 시각 규칙형 알림별 규칙 행: code => [['to'=>,'days'=>[],'from'=>,'till'=>,'types'=>[]], ...]. */
    public array $timeRules = [];

    /** 「번호 직접 추가」 입력칸 임시값: code => idx => 문자열. 저장 대상이 아니다. */
    public array $numberDraft = [];

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
                if (AlimtalkRecipients::supportsEscalation($code)) {
                    foreach (array_keys(AlimtalkRecipients::BROADCAST_GROUPS) as $g) {
                        $this->escalate[$code][$g] = (string) AlimtalkRecipients::escalationDays($code, $g);
                    }
                }
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

        // 토큰을 사람 말로 — `user:12` 가 그대로 보이면 요약이 요약 구실을 못 한다(2026-09-12).
        $to = array_map(function (string $t) {
            if (isset(AlimtalkRecipients::BROADCAST_GROUPS[$t])) {
                return AlimtalkRecipients::BROADCAST_GROUPS[$t];
            }
            $id = AlimtalkRecipients::userIdOf($t);
            if ($id === null) {
                return $t;   // 직접 적은 번호는 그대로
            }
            // ⚠️ 규칙 × 토큰마다 조회하면 N+1 이다 — 이미 불러온 역할 목록에서 찾는다(추가 쿼리 0).
            foreach (array_keys(AlimtalkRecipients::BROADCAST_GROUPS) as $g) {
                foreach ($this->groupMembers($g) as $m) {
                    if ($m['id'] === $id) {
                        return $m['name'];
                    }
                }
            }

            return $t;
        }, $this->ruleTokens($rule));

        return __('alimtalk_catalog.rule_summary', [
            'days' => implode('·', $days) ?: '—',
            'when' => $when,
            'to' => implode(', ', $to) ?: '—',
        ]);
    }

    // ── 수신자 피커 (jin 2026-09-12) ─────────────────────────────────────────────
    //   예전엔 `to` 칸에 역할 키와 전화번호를 **손으로** 적었다. 함정이 둘이었다:
    //     ① 퇴사해도 번호가 규칙에 남아 계속 발송된다.
    //     ② 계정에 번호가 없는 사람은 조용히 빠진다 — 화면에 아무 표시가 없었다.
    //   ⇒ 사람은 `user:{id}` 로 가리키고(계정이 없어지면 자동으로 빠짐), 번호 없는 사람은 ⚠️ 로 보여준다.
    //   저장 형식은 그대로 「콤마로 이은 토큰」이라 기존 규칙·기본값이 전부 그대로 산다.

    /** 수신자 문구를 토큰 배열로 — 화면과 저장이 같은 파서를 쓴다. */
    public function ruleTokens(array $rule): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($rule['to'] ?? '')))));
    }

    /**
     * 역할 그룹의 현재 상태 — 'all' / 'some' / 'none'.
     *
     * 🔑 'all'(역할째 켬) 과 'some'(개별 지정)은 **뜻이 다르다.** 개별로 고르면 명단이 얼어붙어
     *    나중에 그 역할에 사람이 늘어도 안 간다. 화면이 이 차이를 말하지 않으면 아무도 모른다.
     */
    public function groupState(array $rule, string $group): string
    {
        $tokens = $this->ruleTokens($rule);
        if (in_array($group, $tokens, true)) {
            return 'all';
        }

        return $this->selectedMembers($rule, $group) === [] ? 'none' : 'some';
    }

    /** 이 그룹에서 개별 지정된 사용자 id 들. */
    public function selectedMembers(array $rule, string $group): array
    {
        $ids = array_column(AlimtalkRecipients::groupMembers($group), 'id');
        $picked = [];
        foreach ($this->ruleTokens($rule) as $t) {
            $id = AlimtalkRecipients::userIdOf($t);
            if ($id !== null && in_array($id, $ids, true)) {
                $picked[] = $id;
            }
        }

        return $picked;
    }

    /** 역할별 사람 목록 — 한 렌더에 규칙 수 × 그룹 수만큼 불리므로 요청 안에서 한 번만 조회한다. */
    private array $memberCache = [];

    public function groupMembers(string $group): array
    {
        return $this->memberCache[$group] ??= AlimtalkRecipients::groupMembers($group);
    }

    /** 신호 라벨 — board 뱃지 문구를 그대로 쓴다(같은 말로 부르게). */
    public function typeLabel(string $type): string
    {
        return __(BoardRequest::meta($type)['badge'] ?? '') ?: $type;
    }

    /** 규칙에 붙은 「직접 적은 번호」 토큰들 — 역할·사람이 아닌 것. */
    public function ruleNumbers(array $rule): array
    {
        return array_values(array_filter(
            $this->ruleTokens($rule),
            fn ($t) => ! isset(AlimtalkRecipients::BROADCAST_GROUPS[$t]) && AlimtalkRecipients::userIdOf($t) === null,
        ));
    }

    /** 토큰 목록을 규칙에 도로 심는다 — 쓰기 지점을 하나로 묶어 형식이 갈리지 않게. */
    private function putTokens(string $code, int $idx, array $tokens): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if (! isset($this->timeRules[$code][$idx])) {
            return;
        }
        $this->timeRules[$code][$idx]['to'] = implode(',', array_values(array_unique(array_filter($tokens))));
    }

    /** 역할째 켜기/끄기 — 켜면 그 그룹의 개별 지정은 의미가 없어지므로 같이 지운다. */
    public function toggleGroup(string $code, int $idx, string $group): void
    {
        $rule = $this->timeRules[$code][$idx] ?? null;
        if ($rule === null) {
            return;
        }
        $memberIds = array_column(AlimtalkRecipients::groupMembers($group), 'id');
        $tokens = array_filter($this->ruleTokens($rule), function ($t) use ($group, $memberIds) {
            $id = AlimtalkRecipients::userIdOf($t);

            return $t !== $group && ! ($id !== null && in_array($id, $memberIds, true));
        });
        if ($this->groupState($rule, $group) !== 'all') {
            $tokens[] = $group;
        }
        $this->putTokens($code, $idx, $tokens);
    }

    /** 개별 켜기/끄기 — 개별을 고르면 「역할 전체」는 해제한다(둘이 겹치면 뜻이 모호해진다). */
    public function toggleMember(string $code, int $idx, string $group, int $userId): void
    {
        $rule = $this->timeRules[$code][$idx] ?? null;
        if ($rule === null || ! in_array($userId, array_column(AlimtalkRecipients::groupMembers($group), 'id'), true)) {
            return;   // 그 그룹에 없는 id 는 무시 — 클라이언트 주입 방어
        }
        $token = 'user:'.$userId;
        $tokens = array_filter($this->ruleTokens($rule), fn ($t) => $t !== $group && $t !== $token);
        if (! in_array($token, $this->ruleTokens($rule), true)) {
            $tokens[] = $token;
        }
        $this->putTokens($code, $idx, $tokens);
    }

    /** 직접 적은 번호 지우기 — ERP 계정이 없는 외부 수신자용 통로는 남겨둔다. */
    public function removeNumber(string $code, int $idx, string $number): void
    {
        $rule = $this->timeRules[$code][$idx] ?? null;
        if ($rule === null) {
            return;
        }
        $this->putTokens($code, $idx, array_filter($this->ruleTokens($rule), fn ($t) => $t !== $number));
    }

    public function addNumber(string $code, int $idx): void
    {
        $rule = $this->timeRules[$code][$idx] ?? null;
        $raw = trim((string) ($this->numberDraft[$code][$idx] ?? ''));
        if ($rule === null || $raw === '' || ! AlimtalkRecipients::isValidTarget($raw)) {
            $this->dispatch('notify', message: __('alimtalk_catalog.rule_number_bad'), type: 'error');

            return;
        }
        $this->putTokens($code, $idx, [...$this->ruleTokens($rule), $raw]);
        $this->numberDraft[$code][$idx] = '';
    }

    /** 이 규칙이 적용되는 신호 — 비어 있으면 **전 신호**(하위호환). */
    public function ruleTypes(array $rule): array
    {
        return array_values(array_filter(array_map('strval', (array) ($rule['types'] ?? []))));
    }

    public function toggleRuleType(string $code, int $idx, string $type): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        $rule = $this->timeRules[$code][$idx] ?? null;
        if ($rule === null || ! in_array($type, BoardRequest::TYPES, true)) {
            return;
        }
        $cur = $this->ruleTypes($rule);
        $next = in_array($type, $cur, true)
            ? array_values(array_diff($cur, [$type]))
            : [...$cur, $type];
        // 전부 켜면 빈 배열로 눕힌다 — 「전 신호」와 같은 뜻이고, 신호가 늘어도 자동으로 따라온다.
        sort($next);
        $all = BoardRequest::TYPES;
        sort($all);
        $this->timeRules[$code][$idx]['types'] = $next === $all ? [] : $next;
    }

    public function boardTypes(): array
    {
        return BoardRequest::TYPES;
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
            // 🚨 2026-09-12 — **여기 안 실으면 화면에선 켜지는데 저장하면 조용히 사라진다.**
            //    `active` 가 정확히 그 상태로 방치돼 있다(ruleMatches 는 읽는데 여기서 안 쓴다).
            //    types = 이 규칙이 적용되는 board 신호. **비면 전 신호**(하위호환 — 기존 저장값엔 이 키가 없다).
            $types = array_values(array_filter(
                array_map('strval', (array) ($rule['types'] ?? [])),
                fn ($t) => in_array($t, BoardRequest::TYPES, true),
            ));
            sort($types);
            $allTypes = BoardRequest::TYPES;
            sort($allTypes);

            $row = [
                'to' => $to,
                'days' => $days,
                'from' => $this->hhmm($rule['from'] ?? '00:00'),
                'till' => $this->hhmm($rule['till'] ?? '24:00'),
            ];
            // 🔑 「전 신호」면 키를 **아예 안 남긴다** — 2026-09-12 이전 저장값과 글자 단위로 같아지고,
            //    나중에 신호가 늘어도 그 규칙이 자동으로 따라온다. (빈 배열을 남겨도 뜻은 같지만
            //    저장물이 달라져 「무엇이 바뀌었나」를 볼 때 잡음이 된다.)
            if ($types !== [] && $types !== $allTypes) {
                $row['types'] = $types;
            }
            $clean[] = $row;
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
        // 단계별 확대 일수도 같은 [저장]으로 함께 — 체크와 숫자를 따로 저장하게 하면 한쪽만 눌러 어긋난다.
        if (AlimtalkRecipients::supportsEscalation($code)) {
            foreach ($valid as $g) {
                $raw = trim((string) ($this->escalate[$code][$g] ?? ''));
                Setting::updateOrCreate(
                    ['key' => "alimtalk_escalate_{$code}_{$g}_{$set}"],
                    ['value' => $raw === '' ? '' : (string) max(0, (int) $raw), 'type' => 'string',
                        'description' => '알림톡 단계별 확대 일수 '.$code.'/'.$g.' ('.$set.')'],
                );
                $this->escalate[$code][$g] = (string) AlimtalkRecipients::escalationDays($code, $g);
            }
        }
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
                        {{-- 🎯 스코프형은 「몇 명이 받는다」만으로는 거짓말이 된다 — 같은 내용을 받는 게 아니라
                             각자 자기가 볼 수 있는 차만 받기 때문(jin 2026-08-24). 그 차이를 화면에 적는다. --}}
                        @if(\App\Support\AlimtalkRecipients::isScoped($code))
                        <p class="mb-2 text-[11px] leading-relaxed text-primary-text">{{ __('alimtalk_catalog.scoped_note') }}</p>
                        @endif
                        @if(\App\Support\AlimtalkRecipients::supportsEscalation($code))
                        <p class="mb-2 text-[11px] leading-relaxed text-gray-500">{{ __('alimtalk_catalog.escalate_note') }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            @foreach($this->groups() as $gkey => $glabel)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700">
                                    <input type="checkbox" value="{{ $gkey }}" wire:model="roles.{{ $code }}" class="h-4 w-4 rounded border-gray-300" />
                                    {{ $glabel }}
                                    {{-- 🪜 「체크 = 받을지 / 숫자 = 언제부터」. 일수를 코드에 박으면 화면에
                                         안 보이는 규칙이 되어 「체크했는데 왜 안 와?」가 된다(§8 #60). --}}
                                    @if(\App\Support\AlimtalkRecipients::supportsEscalation($code))
                                        <span class="ml-0.5 inline-flex items-center gap-1 text-xs text-gray-500">
                                            D+<input type="number" min="0" max="365" inputmode="numeric"
                                                wire:model="escalate.{{ $code }}.{{ $gkey }}"
                                                class="w-12 rounded border border-gray-300 px-1 py-0.5 text-center text-xs" />{{ __('alimtalk_catalog.escalate_unit') }}
                                        </span>
                                    @endif
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
                                    {{-- 🔀 적용 신호 (jin 2026-09-12) — board 요청 3종이 템플릿 하나를 공유하므로
                                         「계약금은 대표, 매입잔금은 관리」를 여기서 가른다. 하나도 안 고르면 전 신호. --}}
                                    @php $rTypes = $this->ruleTypes($rule); @endphp
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        <span class="text-[11px] font-medium text-gray-500">{{ __('alimtalk_catalog.rule_types') }}</span>
                                        @foreach($this->boardTypes() as $bt)
                                            @php $on = in_array($bt, $rTypes, true) || $rTypes === []; @endphp
                                            <button type="button" wire:click="toggleRuleType('{{ $code }}', {{ $i }}, '{{ $bt }}')"
                                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $on ? 'bg-primary-light text-primary-text' : 'bg-gray-200 text-gray-500 line-through' }}">
                                                {{ $this->typeLabel($bt) }}
                                            </button>
                                        @endforeach
                                        @if($rTypes === [])
                                            <span class="text-[11px] text-gray-400">{{ __('alimtalk_catalog.rule_types_all') }}</span>
                                        @endif
                                    </div>

                                    {{-- 👤 받을 사람 (jin 2026-09-12) — 예전엔 역할 키와 번호를 손으로 적었다.
                                         ①퇴사해도 번호가 남아 계속 갔고 ②번호 없는 사람은 조용히 빠졌다.
                                         역할째 켜면 **전원(자동 반영)**, 펼쳐서 개별로 고르면 **고정 명단**이다 — 그 차이를 글로 적는다. --}}
                                    <div class="mt-2" x-data="{ open: '' }">
                                        <div class="mb-1 text-[11px] font-medium text-gray-500">{{ __('alimtalk_catalog.rule_recipients') }}</div>
                                        <div class="flex flex-col gap-0.5">
                                            @foreach(\App\Support\AlimtalkRecipients::BROADCAST_GROUPS as $g => $gLabel)
                                                @php
                                                    $st = $this->groupState($rule, $g);
                                                    $members = $this->groupMembers($g);
                                                    $picked = $this->selectedMembers($rule, $g);
                                                @endphp
                                                <div class="rounded bg-white px-2 py-1">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <button type="button" x-on:click="open = (open === '{{ $g }}' ? '' : '{{ $g }}')"
                                                                class="w-4 text-[11px] text-gray-400 hover:text-gray-700"
                                                                x-text="open === '{{ $g }}' ? '▾' : '▸'">▸</button>
                                                        <label class="flex items-center gap-1 text-[11px] text-gray-700">
                                                            <input type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300"
                                                                   wire:click="toggleGroup('{{ $code }}', {{ $i }}, '{{ $g }}')"
                                                                   @checked($st === 'all') />
                                                            <span class="font-medium">{{ $gLabel }}</span>
                                                        </label>
                                                        @if($st === 'all')
                                                            <span class="text-[11px] text-emerald-700">{{ __('alimtalk_catalog.rule_group_all', ['n' => count($members)]) }}</span>
                                                        @elseif($st === 'some')
                                                            <span class="text-[11px] text-amber-700">{{ __('alimtalk_catalog.rule_group_some', ['n' => count($picked)]) }}</span>
                                                        @else
                                                            <span class="text-[11px] text-gray-300">{{ __('alimtalk_catalog.rule_group_none') }}</span>
                                                        @endif
                                                    </div>
                                                    <div x-show="open === '{{ $g }}'" x-cloak class="mt-1 flex flex-wrap gap-x-4 gap-y-1 pl-6">
                                                        @forelse($members as $m)
                                                            <label wire:key="mem-{{ $code }}-{{ $i }}-{{ $g }}-{{ $m['id'] }}"
                                                                   class="flex items-center gap-1 text-[11px] text-gray-600">
                                                                <input type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300"
                                                                       wire:click="toggleMember('{{ $code }}', {{ $i }}, '{{ $g }}', {{ $m['id'] }})"
                                                                       @checked(in_array($m['id'], $picked, true)) />
                                                                {{ $m['name'] }}
                                                                @if($m['phone'] === '')
                                                                    <span class="rounded bg-red-100 px-1 font-bold text-red-700"
                                                                          title="{{ __('alimtalk_catalog.rule_no_phone_hint') }}">⚠️ {{ __('alimtalk_catalog.rule_no_phone') }}</span>
                                                                @else
                                                                    <span class="text-gray-400">{{ $m['phone'] }}</span>
                                                                @endif
                                                            </label>
                                                        @empty
                                                            <span class="text-[11px] text-gray-400">—</span>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>

                                        {{-- ERP 계정이 없는 외부 수신자용 통로는 남긴다. 다만 그 번호는 퇴사해도 계속 가므로 경고를 붙인다. --}}
                                        @php $nums = $this->ruleNumbers($rule); @endphp
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                            @foreach($nums as $num)
                                                <span wire:key="num-{{ $code }}-{{ $i }}-{{ $loop->index }}"
                                                      class="inline-flex items-center gap-1 rounded-full bg-gray-200 px-2 py-0.5 text-[11px] text-gray-700">
                                                    {{ $num }}
                                                    <button type="button" wire:click="removeNumber('{{ $code }}', {{ $i }}, '{{ $num }}')"
                                                            class="text-gray-500 hover:text-red-600">✕</button>
                                                </span>
                                            @endforeach
                                            <input type="text" wire:model="numberDraft.{{ $code }}.{{ $i }}"
                                                   wire:keydown.enter="addNumber('{{ $code }}', {{ $i }})"
                                                   placeholder="{{ __('alimtalk_catalog.rule_number_ph') }}"
                                                   title="{{ __('alimtalk_catalog.rule_number_hint') }}"
                                                   class="input-base w-36 text-[11px]" />
                                            <button type="button" wire:click="addNumber('{{ $code }}', {{ $i }})"
                                                    class="rounded border border-gray-300 px-2 py-1 text-[11px] text-gray-600 hover:bg-white">
                                                {{ __('alimtalk_catalog.rule_number_add') }}
                                            </button>
                                        </div>
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
                                    {{-- 🚨 0명 경고 (jin 2026-09-12) — 개별 지정한 사람이 퇴사하면 여기가 0 이 된다.
                                         그래도 발송이 끊기지는 않지만(최고관리자 폴백), **의도한 사람이 못 받는다**.
                                         숫자만으로는 눈에 안 띄어 한 줄로 적는다. --}}
                                    @if($n === 0)
                                        <div class="mt-1 rounded bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700">
                                            {{ __('alimtalk_catalog.rule_nobody') }}
                                        </div>
                                    @endif
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
