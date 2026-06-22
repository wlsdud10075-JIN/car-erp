<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 큐 21 — 차량 본체 Ledger 영향 필드 잠금 해제 서비스.
 *
 * 회의록 docs/meetings/2026-05-18-vehicle-ledger-field-lock.md + 2026-06-22 jin override:
 *   ① 권한 = super/admin(전체) + role '관리'(본인 팀 차량만, User::canUnlockLedger)
 *      ※ 2026-06-22 jin: 환율·판매가 정정이 비일비재 → 2026-05-18 "admin/super 전용"을 완화.
 *   ② 사유 = 10자 이상 필수
 *   ③ 토큰 = cache 1회 소비 → 저장 1회 완료 즉시 자동 재잠금 (race window 0)
 *   ④ AuditLog = ledger_field_unlocked + new_value=reason 기록
 *
 * 사용:
 *   app(VehicleLedgerUnlockService::class)->unlock($vehicle, auth()->user(), $reason);
 *   → 직후 Vehicle::saving 훅이 cache token 1회 pull 소비 → 통과
 *   → 다음 변경 시도 시 다시 잠금 (토큰 소비됨)
 *
 * 토큰 5분 안전 만료: admin/super가 [잠금 해제] 후 다른 일 보다 잊어버린 경우 백업.
 * 실제 정상 흐름에선 즉시 저장 → 토큰 0초에 소비 → 만료 무관.
 */
class VehicleLedgerUnlockService
{
    /** Cache 토큰 안전 만료 (저장 1회 후엔 즉시 소비되므로 5분은 백업용). */
    public const TOKEN_SAFETY_TTL_MINUTES = 5;

    /** 사유 최소 길이 (사용자 결정 2026-05-18). */
    public const MIN_REASON_LENGTH = 10;

    /**
     * 차량 본체 Ledger 잠금 해제 토큰 발급.
     *
     * @throws AuthorizationException super/admin 또는 본인 팀 관리(role='관리') 아닐 때
     * @throws DomainException 사유 10자 미만 또는 confirmed 잔금 없을 때
     */
    public function unlock(Vehicle $vehicle, User $by, string $reason): void
    {
        // 권한 = super/admin(전체) + role '관리'(본인 팀 차량만, canScopeVehicle). 스코프 체크가
        // IDOR 방지의 단일 enforcement — editingId 는 클라이언트 주입 가능하므로 서비스가 최종 게이트.
        if (! $by->canUnlockLedger($vehicle)) {
            throw new AuthorizationException('잠금 해제 권한 없음 (super/admin 또는 본인 팀 관리 전용)');
        }

        $reasonTrimmed = trim($reason);
        if (mb_strlen($reasonTrimmed) < self::MIN_REASON_LENGTH) {
            throw new DomainException('잠금 해제 사유는 '.self::MIN_REASON_LENGTH.'자 이상 필수');
        }

        if (! $vehicle->exists) {
            throw new DomainException('신규 차량은 잠금 대상 외 (자유 입력 가능)');
        }

        if (! $vehicle->hasConfirmedPaymentLock()) {
            throw new DomainException('재무 확정 잔금이 없는 차량은 잠금 해제할 필요가 없습니다');
        }

        DB::transaction(function () use ($vehicle, $by, $reasonTrimmed) {
            Cache::put(
                Vehicle::ledgerUnlockCacheKey($vehicle->id),
                [
                    'unlocked_by' => $by->id,
                    'reason' => $reasonTrimmed,
                    'issued_at' => now()->toIso8601String(),
                ],
                now()->addMinutes(self::TOKEN_SAFETY_TTL_MINUTES),
            );

            // AuditLog — column_name='unlock_reason' + new_value=reason 으로 사유 보존
            AuditLog::create([
                'user_id' => $by->id,
                'approval_request_id' => null,
                'auditable_type' => Vehicle::class,
                'auditable_id' => $vehicle->id,
                'action' => 'ledger_field_unlocked',
                'column_name' => 'unlock_reason',
                'old_value' => null,
                'new_value' => $reasonTrimmed,
                'ip_address' => request()?->ip(),
            ]);
        });
    }

    /**
     * 활성 unlock 토큰 존재 여부 (UI에서 readonly 분기용).
     * 토큰 소비는 Vehicle::consumeLedgerUnlockToken (cache pull)에서만.
     */
    public function hasActiveToken(Vehicle $vehicle): bool
    {
        if (! $vehicle->exists) {
            return false;
        }

        return Cache::has(Vehicle::ledgerUnlockCacheKey($vehicle->id));
    }
}
