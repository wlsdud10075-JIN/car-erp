<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

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
        return max(0.0, min(1.0, (100 - self::lockRequiredPaidPct($lock)) / 100));
    }

    /** 채권 유예일 — super 조정값(회사별). 미설정 시 RECEIVABLE_GRACE_DEFAULT(10). 0 이상. */
    public static function graceDays(): int
    {
        return (int) max(0, self::get('receivable_grace_days_'.self::companyTemplateSet(), self::RECEIVABLE_GRACE_DEFAULT));
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
