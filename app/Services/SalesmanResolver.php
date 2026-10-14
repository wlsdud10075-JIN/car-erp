<?php

namespace App\Services;

use App\Models\Salesman;
use App\Models\User;

/**
 * 영업(Salesman) 이메일 매칭 단일 출처.
 *
 * 연동 B(purchase-sync) 수신·board 영업 포털 양쪽에서 재사용.
 * 매칭: car_erp_salesman_id 오버라이드 → Salesman.email → User.email→salesman.
 */
class SalesmanResolver
{
    public static function resolve(string $email, ?int $overrideId = null): ?Salesman
    {
        if ($overrideId !== null) {
            $salesman = Salesman::find($overrideId);
            if ($salesman) {
                return $salesman;
            }
        }

        // 🚫 영업만 — 검차직원·직원(관리/수출통관/재무 미러) 행이 board 포털 본인격리·차량 담당자로 꽂히면 안 된다(2026-10-14).
        $salesman = Salesman::where('email', $email)->sales()->first();
        if ($salesman) {
            return $salesman;
        }

        $viaUser = User::where('email', $email)->first()?->salesman;

        return $viaUser?->isSales() ? $viaUser : null;
    }

    /**
     * board 포털 IDOR 본인격리 단일 출처 — 이메일 매칭 + 재직(is_active) 검증.
     * 매칭 실패·퇴사(비활성) 시 403 (salesman 존재 여부 노출 방지 위해 404 아님).
     */
    public static function resolveActiveOrFail(string $email): Salesman
    {
        $salesman = self::resolve($email);
        abort_if($salesman === null || ! $salesman->is_active, 403, 'Forbidden');

        return $salesman;
    }
}
