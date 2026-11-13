<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    /**
     * 설정이 바뀌면 이 값을 메모해 둔 모델의 캐시를 버린다.
     *
     * 🚨 `Settlement` 이 회사 프로파일(karaba 여부)과 정산 파라미터를 **요청 단위로 메모**한다
     *    (정산 목록이 전 정산을 순회하는데 행마다 `settings` 를 조회하던 것을 없애려고).
     *    기능설정에서 값을 바꾼 **같은 요청 안에서** 다시 읽으면 옛 값이 나오므로 여기서 버린다.
     * ⚠️ 테스트의 `RefreshDatabase` 롤백은 **모델 이벤트를 안 태운다** — 그건 `Tests\TestCase::setUp`
     *    이 따로 버린다. 둘 다 있어야 한다.
     */
    protected static function booted(): void
    {
        $forget = function () {
            Settlement::flushParamMemo();
            self::flushRiskParamMemo();
        };
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * 💰 **채권 위험도 파라미터 요청 단위 메모** (jin 2026-09-14 개편과 함께).
     *
     * 🚨 왜 필요한가 — 위험도 계산이 차량마다 설정을 **최대 3번** 조회한다
     *    (게이트 임계 · 오래됨 일수 · 유예일). `Setting::get()` 은 캐시가 없어 매번 쿼리다.
     *    야간 재계산(`vehicles:rebuild-caches`)이 ssancarerp **4,700대**를 도므로 그대로 두면
     *    조회가 수천 건 늘어난다. 개편 전에는 유예일 하나뿐이라 티가 안 났다.
     * 🧭 `Settlement::flushParamMemo()` 와 **같은 패턴**이다(위 booted 참조) — 새로 만든 게 아니라
     *    이 레포가 이미 같은 문제를 푼 방식을 따랐다.
     * ⚠️ 기능설정에서 값을 바꾼 **같은 요청 안에서** 다시 읽으면 옛 값이 나오므로 booted 에서 버린다.
     *    ⚠️ 테스트의 `RefreshDatabase` 롤백은 모델 이벤트를 안 태운다 — `Tests\TestCase::setUp` 도 버려야 한다.
     */
    private static array $riskParamMemo = [];

    /** B2B 파트너스 토글 메모(요청 단위) — 같은 flush 를 탄다. */
    private static ?bool $b2bMemo = null;

    private static ?bool $domesticPayoutMemo = null;

    public static function flushRiskParamMemo(): void
    {
        self::$riskParamMemo = [];
        self::$b2bMemo = null;
        self::$domesticPayoutMemo = null;
    }

    private static function riskParam(string $key, callable $resolve): int|float
    {
        return self::$riskParamMemo[$key] ??= $resolve();
    }

    /**
     * 돈 흐름 락 토글 — 기능설정 "락 관제". lock 키(접미 없음) => 기본값(bool).
     * 회사별 {set} 접미로 저장(알림톡 패턴). 값 seed 불필요 — 여기 기본값이 단일 출처.
     *   #1 매입 등록 · #3 선적 진입(C5) · #4 B/L(G1) = 현행 유지 ON.
     *   #2 매입 지급 = 신규(대표 상의 대상), 기본 OFF(dormant — 진이 켜야 발동).
     */
    public const LOCK_DEFAULTS = [
        'purchase_registration' => true,
        'purchase_payment' => false,
        'shipping_entry' => true,
        'bl_issue' => true,
    ];

    /**
     * 락별 "필요 입금률(%)" 기본값 (jin 2026-07-20 — super 가 기능설정에서 조정).
     * 값 = "락이 풀리려면 최소 몇 % 입금돼야 하는가". 게이트는 미수율 cutoff = (100-필요%)/100 로 판정.
     *   매입 등록·지급·선적 진입 = 50%(미수 cutoff 0.5) / B/L = 100%(미수 cutoff 0 = 완납).
     * 회사별 {set} 접미로 저장(lock_threshold_{lock}_{set}). 미설정 시 아래 기본값.
     */
    public const LOCK_PAID_DEFAULTS = [
        'purchase_registration' => 50,
        'purchase_payment' => 50,
        'shipping_entry' => 50,
        'bl_issue' => 100,
    ];

    /** 채권 유예일 기본값(선적 전 미수 유예 — Vehicle::RECEIVABLE_GRACE_DAYS 와 동기). super 조정 가능. */
    public const RECEIVABLE_GRACE_DEFAULT = 10;

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            default => $setting->value,
        };
    }

    /**
     * 돈 흐름 락 활성 여부(단일 출처). 게이트 코드는 이 헬퍼로만 판단 → UI 토글과 항상 일치.
     * 현재 회사(companyTemplateSet) 기준. 미설정 시 LOCK_DEFAULTS.
     */
    public static function lockEnabled(string $lock): bool
    {
        $default = self::LOCK_DEFAULTS[$lock] ?? false;

        return (bool) self::get('lock_'.$lock.'_'.self::companyTemplateSet(), $default);
    }

    /**
     * 락별 "필요 입금률(%)" — super 가 기능설정에서 조정한 값(회사별). 미설정 시 LOCK_PAID_DEFAULTS.
     * 0~100 로 클램프. 게이트 표시(입금 X% 필요)·역변환 공용.
     */
    public static function lockRequiredPaidPct(string $lock): int
    {
        $default = self::LOCK_PAID_DEFAULTS[$lock] ?? 50;

        return (int) max(0, min(100, self::get('lock_threshold_'.$lock.'_'.self::companyTemplateSet(), $default)));
    }

    /**
     * 락 미수율 cutoff (단일 출처). 게이트 코드는 이 헬퍼로만 판단 → UI 수치와 항상 일치.
     * 반환값 = 이 값 "초과" 미수율이면 차단. 필요 입금률 P% → cutoff = (100-P)/100.
     *   예) 필요 50% → 0.5 초과 차단 / 필요 100% → 0 초과 차단(완납) / 필요 70% → 0.3 초과 차단.
     */
    public static function lockThreshold(string $lock): float
    {
        return (float) self::riskParam('lock:'.$lock, fn () => max(0.0, min(1.0, (100 - self::lockRequiredPaidPct($lock)) / 100)));
    }

    /** 채권 유예일 — super 조정값(회사별). 미설정 시 RECEIVABLE_GRACE_DEFAULT(10). 0 이상. */
    public static function graceDays(): int
    {
        return (int) self::riskParam('grace', fn () => max(0, (int) self::get('receivable_grace_days_'.self::companyTemplateSet(), self::RECEIVABLE_GRACE_DEFAULT)));
    }

    /**
     * 🕰️ **「팔렸는데 아직 안 나갔다」가 며칠 넘으면 위험인가** (jin 2026-09-14). 기본 90일.
     *
     * jin: *「보통 배를 타기 전이나 선적이 되면 돈을 거의 다 주는 형태」* ⇒ 미출고가 오래 지속되는
     * 것 자체가 비정상이다. 기준일은 **판매일**이다 — 후보를 실측해 고른 결과다:
     *   · 등록일 = 2026-08-28 일괄 적재로 **전부 최근**(싼카 최장 17일) → 못 쓴다
     *   · 매입 완납일 = 확정 잔금 행이 있어야 생겨 **절반이 빈다**(싼카 152/289) → 빈 차가 조용히 빠진다
     *   · 매입일 ≈ 판매일 (같은 날 기록이 heymanerp 89% · ssancarerp 76%) — 다를 땐 판매일이 더 늦어 보수적
     * 90일 근거 = 싼카 미출고 미수 289대의 **중앙값 33일**, 90일 초과는 상위 **7%**(20대)뿐.
     *
     * 🚫 숫자를 코드에 박지 말 것 — 회사마다 다르고, 몇 달 써 보고 조정할 값이다(§8 #60).
     */
    public const RECEIVABLE_STALE_DEFAULT = 90;

    public static function receivableStaleDays(): int
    {
        return (int) self::riskParam('stale', fn () => max(1, (int) self::get('receivable_stale_days_'.self::companyTemplateSet(), self::RECEIVABLE_STALE_DEFAULT)));
    }

    /**
     * 무담보 한도 기능 on/off (jin 2026-08-10) — 회사마다 쓰는 곳·안 쓰는 곳이 있다.
     *
     * ⚠️ 기본값 **true** 다. 이미 배포된 기능이라 false 로 두면 켜둔 회사의 락이 조용히 풀린다.
     *   끄면 무담보 판정·표시가 통째로 빠지고 **기존 미수율 게이트만** 남는다
     *   (매입 락 자체를 끄는 `lock_purchase_registration` 과는 다르다 — 그건 미수율 게이트까지 끈다).
     */
    public static function unsecuredLimitEnabled(): bool
    {
        return (bool) self::get('unsecured_limit_enabled_'.self::companyTemplateSet(), true);
    }

    /**
     * 바이어 현금 원장 on/off (jin 2026-09-04) — 회사별. 기획 = docs/design/buyer-cash-ledger.md
     *
     * ⚠️ 기본값 **false** 다. 신규 기능이라 켠 회사에서만 동작한다.
     *   `unsecuredLimitEnabled()` 가 true 인 것과 반대인 이유 = 그건 이미 배포돼 쓰이던 기능이라
     *   false 로 두면 켜둔 회사의 락이 조용히 풀리기 때문이다. 이건 아무도 안 쓰던 기능이다.
     *
     * 🚨 **켜는 순간 판매잔금 입력이 현금 잔액 안으로 제한된다**(2단계 게이트). 그래서 회사는
     *   「바이어에게 미배분 현금이 없는 날」에 켠다(jin) — 전환 마이그레이션이 없는 이유다.
     */
    public static function buyerCashEnabled(): bool
    {
        return (bool) self::get('buyer_cash_enabled_'.self::companyTemplateSet(), false);
    }

    /**
     * 🤝 B2B 파트너스(관리대상·계약서·DP/FD 뱃지·필터·메뉴) on/off — **회사별**(jin 2026-11-02 «각 회사별로 켜고 끄게»).
     *   키 = `b2b_partners_enabled_{회사}` — 서버(회사)마다 자기 DB 라 자연히 회사별이다. 기본 **꺼짐**(새 기능 관례, 무담보 한도 주석 참조).
     *   끄면 화면·필터·메뉴만 숨는다 — 저장된 값(관리대상·deal_type·계약서)은 남고 다시 켜면 그대로 보인다. 포털 2단계 칸은 별개(항상).
     *   시스템관리자만 바꾼다(기능설정). 요청 단위 메모 — 목록 행마다(뱃지 5곳) 부르므로. 저장 훅·flushRiskParamMemo() 가 비운다.
     */
    public static function b2bPartnersEnabled(): bool
    {
        return self::$b2bMemo ??= (bool) self::get('b2b_partners_enabled_'.self::companyTemplateSet(), false);
    }

    /**
     * 📱 카톡자동화 「내 카톡 할 일」 on/off — **회사별, 기본 꺼짐**(jin 2026-11-10 «3사 공통, 시스템사용자 기능설정에서 on/off»).
     *   키 = `kakao_assistant_enabled_{회사}`. 끄면 메뉴·페이지·수집기 API(403) 가 막힌다. 저장된 메시지는 남는다.
     */
    public static function kakaoAssistantEnabled(): bool
    {
        return (bool) self::get('kakao_assistant_enabled_'.self::companyTemplateSet(), false);
    }

    /** 읽기 1회에 분류할 새 메시지 상한 — 넘으면 거부(수집기 버그로 수만 줄이 오면 400번 호출을 막는다). §8 #60: N 은 설정. */
    public static function kakaoMaxNewPerRead(): int
    {
        return max(50, (int) self::get('kakao_max_new_per_read', 500));
    }

    /** 사용자 한 명의 하루 분류 비용 상한(원). 넘으면 그날은 거부. 실측 50건 읽기 ≈ 36~48원. */
    public static function kakaoDailyCostCapKrw(): int
    {
        return max(100, (int) self::get('kakao_daily_cost_cap_krw', 2000));
    }

    /**
     * 카톡 읽기 범위(일) — 복사본에서 가장 최근 글 기준 이 일수 안의 글만 저장·분류한다(jin 2026-11-11 «실무자는 실시간으로 보니 놓친 것 확인은 3일 전후, 금액이 너무 크다»).
     * 에이전트는 종전대로 7일 치를 복사해 보내도 ERP 가 잘라 낸다(첫 읽기 비용 ≈ 220원 → 95원/방). 에이전트 재배포 없이 회사별 조절.
     */
    public static function kakaoReadDays(): int
    {
        return max(1, (int) self::get('kakao_read_days', 3));
    }

    /**
     * 「내 카톡 할 일」이 보는 기간(일) — 읽기 범위(kakaoReadDays)와 같은 3일.
     * 🔀 2026-11-13 (jin) 7 → 3 — 이 화면은 «그날 일한 것 중 오늘 내가 놓친 게 없나»를 보는 용도이고, 금요일 → 월요일이 3일이다.
     *    4일 넘게 놓친 것은 화면이 붙들 일이 아니다(그 3일 동안 계속 떠 있었다).
     */
    public static function kakaoWindowDays(): int
    {
        return max(1, (int) self::get('kakao_window_days', 3));
    }

    /** 내 일이 이 시간(h)을 넘기면 「지금 할 일」로 올린다(경과시간만으론 안 올린다는 §5 원칙의 예외 = 하루 묵은 요청). */
    public static function kakaoUrgentHours(): int
    {
        return max(1, (int) self::get('kakao_urgent_hours', 24));
    }

    /** 내 일이 이 일수를 넘기면 「놓쳤을 가능성」. 미룸 시각이 지난 것도 같은 묶음. */
    public static function kakaoMissedDays(): int
    {
        return max(1, (int) self::get('kakao_missed_days', 3));
    }

    /**
     * 🧾 내수직원 월정산(별도 결재) on/off — **회사별**(jin 2026-11-03). 키 = `domestic_payout_enabled_{회사}`.
     *   기본값 = ssancarerp(system) 켬 · heymanerp·karabaerp 끔. 끄면 내수 기능이 통째로 숨는다
     *   (사내직원관리 내수 탭·평가수수료 업로드·내수직원 월정산 메뉴·사용자관리 「내수」 역할). 켜면 내수직원은 사내직원 월정산에서 빠지고
     *   「내수직원 합계」 한 줄로만 보인다 — 지급은 내수직원 월정산(kind=domestic)의 결재로.
     */
    public static function domesticPayoutEnabled(): bool
    {
        $set = self::companyTemplateSet();

        return self::$domesticPayoutMemo ??= (bool) self::get('domestic_payout_enabled_'.$set, $set === 'system');
    }

    /**
     * 🔒 급여 항목 공개 범위(jin 2026-11-04) — [칸 키 => [user id, …]] (칸 = Salesman::PAYROLL_VIEW_CATEGORIES, 내수직원 제외 — 그건 아래 키).
     * **한 번도 저장 안 했으면 null** = 종전대로 급여 입력 권한자(관리 이상 + 재무) 모두 본다 — 배포 직후 재무의 급여 입력이 갑자기 막히지 않게.
     */
    public static function payrollViewers(): ?array
    {
        $raw = self::get('payroll_viewers');
        if ($raw === null || $raw === '') {
            return null;
        }
        $map = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($map)) {
            return null;
        }

        return array_map(fn ($ids) => array_values(array_unique(array_map('intval', (array) $ids))), $map);
    }

    /**
     * 🔗 헤이맨 정산 가져오기 연결 기억(jin 2026-11-04 «최초 매칭만 한 번 하면 그 뒤로는 계속 유지») — [heymanerp salesman_id => 이 회사 salesman_id].
     * 자동 매칭은 안 한다(jin «매칭하지 말고 드롭박스에서 고르자») — 처음 한 번 고르고 적용하면 여기에 남는다.
     */
    public static function heymanPayoutLinks(): array
    {
        $raw = self::get('heyman_payout_links');
        $map = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_map('intval', $map);
    }

    /**
     * 사내직원 월정산 볼 사람(user id) — jin 2026-11-05 «내수처럼 지정한 사람만». null = 한 번도 저장 안 함 → 종전(관리 이상 전원).
     * 기능설정 공개 범위 표의 「사내직원 월정산」 칸이 쓴다.
     */
    public static function staffPayoutViewerIds(): ?array
    {
        $raw = self::get('staff_payout_viewers');
        if ($raw === null || $raw === '') {
            return null;
        }
        $ids = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : null;
    }

    /**
     * 📱 카톡자동화(내 카톡 할 일)를 쓰는 사람(user id) — jin 2026-11-10 «공개 범위 표에서 공개하는 인원에게만».
     * null = 한 번도 저장 안 함 → 종전(회사 토글이 켜져 있으면 로그인하는 전원). 기능설정 공개 범위 표의 「카톡자동화」 칸이 쓴다.
     */
    public static function kakaoViewerIds(): ?array
    {
        $raw = self::get('kakao_viewers');
        if ($raw === null || $raw === '') {
            return null;
        }
        $ids = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : null;
    }

    /** 내수직원 월정산을 볼 수 있게 시스템관리자가 지정한 사용자 id 목록(기능설정). 시스템관리자는 지정 없이 본다. */
    public static function domesticPayoutViewerIds(): array
    {
        $raw = self::get('domestic_payout_viewers', '[]');
        $ids = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * 서류 양식 세트(=회사) 단일 출처. 기능설정 토글(company_template_set) 우선,
     * 미설정 시 .env COMPANY_TEMPLATE_SET(config) fallback. 값: system(SSANCAR)/heyman/karaba.
     */
    public static function companyTemplateSet(): string
    {
        return static::get('company_template_set') ?: config('company.template_set', 'system');
    }

    /**
     * 회사 프로파일 = companyTemplateSet() (서버 1개 = 회사 1개). 값: system(ssancar)/heyman/karaba.
     * karaba 전용 커스터마이징(매입 UI·정산 등) 게이팅 단일 출처. heyman/ssancar는 공통 동작.
     */
    public static function isKaraba(): bool
    {
        return static::companyTemplateSet() === 'karaba';
    }
}
