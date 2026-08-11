<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 알림톡 수신자 해석 — 역할 기반 하이브리드 (users.phone) + 회사(set)별 기능설정 override.
 *
 * - 대표(admin)  = permission='admin' + phone. super(진)은 업무알림 제외.
 * - 관리         = role='관리' + phone.
 * - 픽업(영업)   = per-vehicle 담당 영업(Salesman.phone) — 전체 영업 아님, 그 차 담당자에게만.
 * - override     = Setting 'alimtalk_recipients_{group}_{set}'(콤마 구분 번호). 있으면 역할기반 대신 사용.
 *
 * 반환 = 숫자/하이픈 포함 번호 문자열 배열(중복 제거). BizmAlimtalkService 가 숫자만 정규화.
 * 전화 없는 사람은 자동 제외(빈 배열 → 호출측이 skip).
 */
class AlimtalkRecipients
{
    /**
     * 브로드캐스트 역할 그룹 (2026-07-23, jin) — 알림톡 안내 화면에서 알림별로 선택.
     * key => 화면 라벨. 선택된 그룹의 (전화 있는) 사용자가 받는다.
     */
    public const BROADCAST_GROUPS = [
        'admin' => '최고관리자',
        'manager' => '업무관리자',
        '관리' => '관리',
        '영업' => '영업',
        '수출통관' => '수출통관',
        '재무' => '재무',
        // 시스템관리자(super)는 평소 업무알림 대상이 아니지만, 개발자가 실제 발송물을 눈으로 봐야
        // 카드·문구 이상을 잡을 수 있어 **명시 선택 시에만** 수신한다(jin 2026-08-03).
        // ⚠️ DEFAULT_ROLES 에는 절대 넣지 말 것 — 넣으면 전 알림이 자동으로 super 에게도 간다.
        'super' => '시스템관리자',
    ];

    /**
     * 브로드캐스트형 알림별 기본 수신 역할 (미설정 시 사용 — 현행 동작 보존).
     * 여기 없는 코드 = 자동(본인/기안자/계단) 대상 = 역할 선택 불가(TARGETED_LABELS).
     */
    public const DEFAULT_ROLES = [
        'erp_daily_summary' => ['admin'],
        'erp_weekly_summary' => ['admin'],
        'erp_capital_weekly' => ['admin'],   // 자본·손익 기밀 — 대표(admin) 전용
        'erp_monthly_closing' => ['admin'],
        'erp_vehicle_new' => ['관리', 'manager'],
        'erp_purchase_unpaid' => ['관리', 'manager'],
        'erp_sale_unpaid' => ['관리', 'manager'],
        'erp_settle_pending' => ['관리', 'manager'],
        'erp_eta_balance_due' => ['관리', 'manager'],
        'erp_shipping_due' => ['관리', 'manager'],
        'erp_dealer_balance_due' => ['관리', 'manager'],
        'erp_deposit_cash_due' => ['관리', 'manager'],
        'erp_deposit_cash_overdue' => ['admin'],
    ];

    /** 자동 대상(역할 선택 불가) 알림 — 안내 화면 고정 라벨. */
    public const TARGETED_LABELS = [
        // 보증금 선지급 3종(request/done/rejected)은 2026-07-30 제거 — 승인 사다리 자체가
        // 2026-07-29 에 폐기돼 발송할 이벤트가 없어졌다. 안내 화면에 "절대 안 오는 알림"을 남기지 않는다.
        'erp_pickup_reminder' => '담당 영업 본인',
        'erp_deregistration_notice' => '국내 딜러(수동 발송)',
        'erp_payout_request' => '승인 계단 담당자',
        'erp_payout_done' => '제출자 본인',
        'erp_payout_rejected' => '제출자 본인',
    ];

    /** 브로드캐스트형이면서 자동 대상도 함께 가는 혼합 알림 — 안내 화면 부가 표시. */
    public const AUTO_EXTRA = [
        'erp_deposit_cash_due' => '담당 영업 본인',
    ];

    /** 역할 선택형 알림인가 (DEFAULT_ROLES 에 있으면). */
    public static function isBroadcast(string $code): bool
    {
        return isset(self::DEFAULT_ROLES[$code]);
    }

    /**
     * 이 알림의 현재 선택 역할 (회사별). 미설정 = 기본값 / 명시 저장 = 그 값(빈=아무도 안 받음).
     *
     * @return string[] 그룹 key 배열
     */
    public static function selectedRoles(string $code): array
    {
        $set = Setting::companyTemplateSet();
        $raw = Setting::get("alimtalk_roles_{$code}_{$set}", '__unset__');
        if ($raw === '__unset__') {
            return self::DEFAULT_ROLES[$code] ?? [];
        }

        return collect(explode(',', (string) $raw))->map(fn ($g) => trim($g))->filter()->unique()->values()->all();
    }

    // ── 시각 규칙 라우팅 (jin 2026-08-11, board 요청 신호) ────────────────────────────────
    //
    // jin: "평시엔 알림톡이 없지만 지정한 담당자 1~2명은 알림톡을 받는거지. 그 알림톡은 시간대를
    //       지정할 수 있거나 요일을 지정할 수 있고.. 하이브리드적인?"
    //
    // 🧭 **"17:30 이후엔 대표" 를 예외 분기로 박지 않는다.** 규칙 테이블 한 장으로 두면 그게
    //    예외가 아니라 규칙의 자연스러운 결과가 되고, 시간대를 바꿀 때 코드를 안 고쳐도 된다.

    /** 시각 규칙으로 수신자가 갈리는 알림 — 역할 체크박스 대신 규칙 편집기를 쓴다. */
    public const TIME_RULE_CODES = ['erp_board_request'];

    /**
     * 규칙 미설정 시 기본값. 인계문서 §5-1 표 그대로.
     *   `to`    = 콤마 구분. BROADCAST_GROUPS 키(역할) 또는 전화번호를 직접 적어도 된다.
     *   `days`  = ISO 요일(월=1 … 일=7).
     *   `from`/`till` = 'HH:MM'. **till < from 이면 자정을 넘긴 구간**(17:30–09:00).
     *                   두 행으로 쪼개는 것보다 설정 실수가 적다.
     */
    public const DEFAULT_TIME_RULES = [
        ['to' => '관리,manager', 'days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'till' => '17:30'],
        ['to' => 'admin', 'days' => [1, 2, 3, 4, 5], 'from' => '17:30', 'till' => '09:00'],
        ['to' => 'admin', 'days' => [6, 7], 'from' => '00:00', 'till' => '24:00'],
    ];

    /** 이 알림이 시각 규칙형인가. */
    public static function isTimeRouted(string $code): bool
    {
        return in_array($code, self::TIME_RULE_CODES, true);
    }

    /** 저장된 규칙(회사별). 미설정·깨진 JSON 이면 기본값. */
    public static function timeRules(string $code): array
    {
        $set = Setting::companyTemplateSet();
        $raw = (string) (Setting::get("alimtalk_timerules_{$code}_{$set}", '') ?: '');
        if (trim($raw) === '') {
            return self::DEFAULT_TIME_RULES;
        }

        $rules = json_decode($raw, true);

        // ⚠️ 깨진 설정으로 **아무도 안 받는 상태**가 되는 게 최악이다(조용히 0명 = 카톡보다 나쁨).
        //    파싱 실패나 빈 배열이면 기본값으로 되돌린다.
        return is_array($rules) && $rules !== [] ? $rules : self::DEFAULT_TIME_RULES;
    }

    /**
     * 해마다 날짜가 같은 **양력 고정 공휴일** — 코드에 내장한다(jin 2026-08-11).
     *
     * 매년 손으로 다시 적게 하면 결국 안 적게 되고, 그러면 그날 담당자에게 알림이 가버린다.
     * 🗓️ 여기 없는 것 = **해마다 날짜가 바뀌는 것뿐** → 수기 등록 대상:
     *    설날·추석(음력) · 부처님오신날(음력) · 대체공휴일 · 임시공휴일 · 선거일.
     *
     * @var array<string, string> 'MM-DD' => 이름
     */
    public const FIXED_HOLIDAYS = [
        '01-01' => '신정',
        '03-01' => '삼일절',
        '05-05' => '어린이날',
        '06-06' => '현충일',
        '08-15' => '광복절',
        '10-03' => '개천절',
        '10-09' => '한글날',
        '12-25' => '성탄절',
    ];

    /**
     * 이 날이 공휴일인가 — **고정 공휴일 + 회사별 수기 등록분**.
     * 공휴일이면 요일 판정이 일요일로 바뀐다(주말과 동일 취급, jin 확정).
     */
    public static function isHoliday(\DateTimeInterface $date): bool
    {
        return isset(self::FIXED_HOLIDAYS[$date->format('m-d')])
            || in_array($date->format('Y-m-d'), self::holidays(), true);
    }

    /**
     * **수기 등록** 공휴일 목록(회사별) — 'YYYY-MM-DD' 를 줄바꿈·콤마로 구분.
     * 고정 공휴일은 위 FIXED_HOLIDAYS 라 여기 적을 필요 없다(적어도 무해).
     * 외부 API 를 끌어오지 않는다(발송 경로에 외부 의존을 넣지 않는다).
     *
     * @return array<int, string>
     */
    public static function holidays(): array
    {
        $set = Setting::companyTemplateSet();
        $raw = (string) (Setting::get("alimtalk_holidays_{$set}", '') ?: '');

        return collect(preg_split('/[\s,]+/', $raw))
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1)
            ->unique()->values()->all();
    }

    /**
     * 시각 규칙 수신자 — **현재 시각에 매칭되는 모든 행**의 수신자 합집합.
     *
     * ⚠️ **공휴일은 일요일(7)로 취급한다** (jin: "공휴일 = 주말과 동일 취급").
     *    행마다 '공휴일 포함' 체크를 두는 대신 요일을 갈아끼우면, "토·일 종일 대표" 한 줄이
     *    공휴일까지 자동으로 덮고 "월~금 담당자" 줄은 자동으로 빠진다 — 설정 실수가 줄어든다.
     *    공휴일 판정 = 고정 공휴일(내장) + 수기 등록분. `self::isHoliday()`.
     * ⚠️ **매칭 0명이면 대표에게 강제 발송한다.** 조용히 0명에게 가는 게 최악이다.
     */
    public static function forTimeRules(string $code, ?\DateTimeInterface $at = null): array
    {
        $now = $at ? Carbon::instance($at) : now();
        $dow = self::isHoliday($now) ? 7 : (int) $now->isoWeekday();
        $mins = $now->hour * 60 + $now->minute;

        $phones = [];
        foreach (self::timeRules($code) as $rule) {
            if (! self::ruleMatches($rule, $dow, $mins)) {
                continue;
            }
            foreach (explode(',', (string) ($rule['to'] ?? '')) as $target) {
                $target = trim($target);
                if ($target === '') {
                    continue;
                }
                // 역할 그룹이면 그 그룹 사용자 번호, 아니면 직접 적은 번호로 본다.
                $phones = array_merge(
                    $phones,
                    isset(self::BROADCAST_GROUPS[$target]) ? self::groupPhones($target) : [$target]
                );
            }
        }

        $phones = collect($phones)->map(fn ($p) => trim((string) $p))->filter()->unique()->values()->all();

        return $phones !== [] ? $phones : self::admins();
    }

    /** 한 규칙 행이 지금 시각에 걸리는가. till < from 이면 자정 넘김 구간. */
    private static function ruleMatches(array $rule, int $dow, int $mins): bool
    {
        if (($rule['active'] ?? true) === false) {
            return false;
        }
        $days = array_map('intval', (array) ($rule['days'] ?? []));
        if (! in_array($dow, $days, true)) {
            return false;
        }

        $from = self::minutes((string) ($rule['from'] ?? '00:00'));
        $till = self::minutes((string) ($rule['till'] ?? '24:00'));

        return $till > $from
            ? ($mins >= $from && $mins < $till)
            : ($mins >= $from || $mins < $till);   // 자정 넘김
    }

    /** 'HH:MM' → 자정부터의 분. '24:00' = 1440(하루 끝). */
    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $hhmm)), 2, 0);

        return max(0, min(1440, $h * 60 + $m));
    }

    /** 브로드캐스트형 알림 수신자 번호 — 선택된 역할 그룹 사용자 phone (중복 제거). */
    public static function forBroadcast(string $code): array
    {
        $phones = [];
        foreach (self::selectedRoles($code) as $group) {
            $phones = array_merge($phones, self::groupPhones($group));
        }

        return collect($phones)->map(fn ($p) => trim((string) $p))->filter()->unique()->values()->all();
    }

    /** 한 역할 그룹의 (전화 있는) 사용자 번호. */
    private static function groupPhones(string $group): array
    {
        $query = match ($group) {
            'admin' => User::query()->where('permission', 'admin'),
            'manager' => User::query()->where('permission', 'manager'),
            'super' => User::query()->where('permission', 'super'),
            '관리' => User::query()->where('permission', 'user')->where('role', '관리'),
            '영업' => User::query()->where('permission', 'user')->where('role', '영업'),
            '수출통관' => User::query()->where('permission', 'user')->where('role', '수출통관'),
            '재무' => User::query()->where('permission', 'user')->where('role', '재무'),
            default => null,
        };
        if ($query === null) {
            return [];
        }
        // super 는 'super' 그룹을 직접 고른 경우에만 받는다 — 다른 그룹(role='관리' 겸직 등)으로
        // 딸려 들어오면 안 된다(2026-07-08 대표 오수신 fix 와 같은 취지).
        if ($group !== 'super') {
            $query->whereNotIn('permission', ['super']);
        }

        return self::phones($query);
    }

    /** 대표(회사 최고관리자) 번호들. */
    public static function admins(): array
    {
        return self::override('admin') ?? self::phones(
            User::query()->where('permission', 'admin')
        );
    }

    /**
     * 관리 알림 수신자 — role='관리'(rank1) + 업무관리자(permission='manager', rank2).
     * (jin 2026-07-07: 업무관리자도 대표에 준하는 운영 권한이라 관리 6종 알림을 함께 받는다.)
     * ⚠️ 대표(admin)·super 는 제외 — 대표는 요약(admins())만, super(진)는 업무알림 제외.
     *    (jin 2026-07-08: 최고관리자가 role='관리'도 겸해 관리 알림을 오수신하던 버그 fix.)
     */
    public static function managers(): array
    {
        return self::override('manager') ?? self::phones(
            User::query()
                ->where(fn ($q) => $q->where('role', '관리')->orWhere('permission', 'manager'))
                ->whereNotIn('permission', ['admin', 'super'])
        );
    }

    // financeConfirmers() 제거 (2026-07-30) — 유일한 호출처였던 보증금 선지급 알림이
    // 승인 사다리와 함께 폐기돼 호출처 0인 죽은 코드로 남아 있었다.

    /**
     * 월배치 정산지급 승인 사다리 — 특정 계단(current_level)의 승인자 번호.
     * level 2 = 업무관리자(manager) / level 3 = 대표(admin). super(4)는 업무알림 제외.
     */
    public static function payoutApprovers(int $level): array
    {
        return match ($level) {
            2 => self::phones(User::query()->where('permission', 'manager')),
            3 => self::phones(User::query()->where('permission', 'admin')),
            default => [],
        };
    }

    /**
     * 승인 사다리 계단별 승인자 User 목록(전화 있는 사람만) — 정산 승인 알림톡 버튼을
     * 사용자별 서명 링크로 바인딩하려고 phone 이 아닌 User 를 반환.
     *
     * @return Collection<int, User>
     */
    public static function payoutApproverUsers(int $level): Collection
    {
        $query = match ($level) {
            2 => User::query()->where('permission', 'manager'),
            3 => User::query()->where('permission', 'admin'),
            default => null,
        };
        if ($query === null) {
            return collect();
        }

        return $query->whereNotNull('phone')->where('phone', '!=', '')->get();
    }

    /** 픽업 재촉 — 그 차량 담당 영업 번호(있으면 1건). */
    public static function forVehicleSalesman(Vehicle $vehicle): array
    {
        $phone = trim((string) ($vehicle->salesman?->phone ?? ''));

        return $phone !== '' ? [$phone] : [];
    }

    /** phone 있는 사용자만 뽑아 중복 제거. */
    private static function phones($query): array
    {
        return $query->whereNotNull('phone')->where('phone', '!=', '')
            ->pluck('phone')->map(fn ($p) => trim((string) $p))
            ->filter()->unique()->values()->all();
    }

    /** 회사(set)별 기능설정 override — 콤마 구분 번호. 없으면 null(역할기반 fallback). */
    private static function override(string $group): ?array
    {
        $set = Setting::companyTemplateSet();
        $raw = (string) (Setting::get("alimtalk_recipients_{$group}_{$set}", '') ?: '');
        if (trim($raw) === '') {
            return null;
        }

        return collect(explode(',', $raw))
            ->map(fn ($p) => trim($p))->filter()->unique()->values()->all();
    }
}
