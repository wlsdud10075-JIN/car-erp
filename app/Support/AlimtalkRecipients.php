<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
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

    /**
     * 재무 확정자 — 보증금 선지급 '재무 확정 대기' 알림 수신 (2026-07-23).
     * role='재무' 사용자만(전화 있는). 없으면 빈 목록 → 관리가 /erp/transfers 에서 직접 확인.
     */
    public static function financeConfirmers(): array
    {
        return self::phones(
            User::query()->where('role', '재무')->whereNotIn('permission', ['super'])
        );
    }

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
