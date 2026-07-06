<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;

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
    /** 대표(회사 최고관리자) 번호들. */
    public static function admins(): array
    {
        return self::override('admin') ?? self::phones(
            User::query()->where('permission', 'admin')
        );
    }

    /** 관리 role 번호들. */
    public static function managers(): array
    {
        return self::override('manager') ?? self::phones(
            User::query()->where('role', '관리')
        );
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
