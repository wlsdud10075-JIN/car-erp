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
