<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FinalPayment;
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

        $this->putToken($vehicle, $by, $reasonTrimmed);
    }

    /**
     * 2차 정산 비용 일괄 기입 전용 잠금 해제 (면허비 묶음 n/1 · 탁송비 명세서).
     *
     * 단일 [🔓 잠금 해제] 버튼(unlock)과 분리:
     *   - $fleetWide=false (면허비): 권한 = canUnlockLedger(본인 팀 차량만) — 단일 버튼과 동일 팀 스코프.
     *   - $fleetWide=true  (탁송비): 권한 = canApprove(관리/admin 전체) — 위카 명세서가 전체 차량 1장이라 필요.
     *
     * ⚠️ fleet-wide 여도 호출측(BulkVehicleCostService)이 Vehicle::BULK_COST_FIELDS(비용 9개)만 기입하도록 강제 →
     *    전체 권한이어도 판매가·환율 등 민감필드는 못 건드림. 사유·필드변경 모두 AuditLog 기록.
     *
     * 잠긴 차량(hasConfirmedPaymentLock)일 때만 호출 — 안 잠긴 차량은 토큰 불필요(호출측이 판단).
     *
     * @throws AuthorizationException 권한 없을 때
     * @throws DomainException 사유 10자 미만
     */
    public function unlockForCostBulk(Vehicle $vehicle, User $by, string $reason, bool $fleetWide): void
    {
        $authorized = $fleetWide ? $by->canApprove() : $by->canUnlockLedger($vehicle);
        if (! $authorized) {
            throw new AuthorizationException($fleetWide
                ? '비용 일괄 기입 권한 없음 (관리/admin 전용)'
                : '잠금 해제 권한 없음 (super/admin 또는 본인 팀 관리 전용)');
        }

        $reasonTrimmed = trim($reason);
        if (mb_strlen($reasonTrimmed) < self::MIN_REASON_LENGTH) {
            throw new DomainException('잠금 해제 사유는 '.self::MIN_REASON_LENGTH.'자 이상 필수');
        }

        $this->putToken($vehicle, $by, $reasonTrimmed);
    }

    /**
     * 선행결함2 (2026-07-06, c-2) — 재무확정 잔금(FinalPayment) 개별 정정 잠금해제.
     *
     * 정정 대상 = 금액·환율·날짜(FinalPayment::AUDITED_LEDGER_COLUMNS). 확정 해제·이체 링크는 대상 아님.
     * 권한 = canApprove(관리/admin/super, jin 2026-07-06). 사유 10자 이상.
     * 토큰 1회 소비 = FinalPayment::updating 이 consumeLedgerUnlockToken 으로 pull → 저장 1회 통과 후 재잠금.
     * AuditLog(ledger_field_unlocked, auditable=FinalPayment)로 누가·언제·왜. 실제 old→new 는 FinalPayment::updated 훅.
     *
     * @throws AuthorizationException canApprove 아닐 때
     * @throws DomainException 사유 10자 미만 또는 신규 잔금
     */
    public function unlockForFinalPayment(FinalPayment $payment, User $by, string $reason): void
    {
        if (! $by->canApprove()) {
            throw new AuthorizationException('잔금 잠금 해제 권한 없음 (관리/admin 전용)');
        }

        $reasonTrimmed = trim($reason);
        if (mb_strlen($reasonTrimmed) < self::MIN_REASON_LENGTH) {
            throw new DomainException('잠금 해제 사유는 '.self::MIN_REASON_LENGTH.'자 이상 필수');
        }

        if (! $payment->exists) {
            throw new DomainException('신규 잔금은 잠금 대상 외 (자유 입력 가능)');
        }

        DB::transaction(function () use ($payment, $by, $reasonTrimmed) {
            Cache::put(
                FinalPayment::ledgerUnlockCacheKey($payment->id),
                [
                    'unlocked_by' => $by->id,
                    'reason' => $reasonTrimmed,
                    'issued_at' => now()->toIso8601String(),
                ],
                now()->addMinutes(self::TOKEN_SAFETY_TTL_MINUTES),
            );

            AuditLog::create([
                'user_id' => $by->id,
                'approval_request_id' => null,
                'auditable_type' => FinalPayment::class,
                'auditable_id' => $payment->id,
                'action' => 'ledger_field_unlocked',
                'column_name' => 'unlock_reason',
                'old_value' => null,
                'new_value' => $reasonTrimmed,
                'ip_address' => request()?->ip(),
            ]);
        });
    }

    /**
     * unlock 토큰 발급 + AuditLog 기록 (단일/일괄 공통 단일 출처).
     * 저장 1회 후 Vehicle::consumeLedgerUnlockToken(cache pull)로 소비 → 즉시 재잠금.
     */
    private function putToken(Vehicle $vehicle, User $by, string $reasonTrimmed): void
    {
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
