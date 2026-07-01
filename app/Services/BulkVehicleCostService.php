<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 2차 정산 비용 일괄 기입 — 면허비 묶음 n/1 · 탁송비 명세서 매칭 공용 뒷단.
 *
 * 흐름 (차량별, 트랜잭션):
 *   ① 대상 컬럼이 Vehicle::BULK_COST_FIELDS(비용 9개) 인지 검증 — 민감필드 봉인.
 *   ② 스코프: fleet(canApprove 전체) / team(canUnlockLedger 본인 팀). 팀 밖·미존재 차량은 skip 리포트.
 *   ③ 잠긴 차량(hasConfirmedPaymentLock)이면 잠금해제 토큰 자동 발급(사유 1회) → update → saving 훅이 토큰 소비 → 즉시 재잠금.
 *   ④ 안 잠긴 차량은 토큰 없이 그냥 update.
 *   ⑤ 필드 변경(old→new)은 Vehicle booted updated 훅의 recordChange 가 AuditLog 로 자동 기록.
 *
 * 정산 마진은 computed 라 cost 변경 시 정산처리 화면 총마진·정산액·실지급 자동 재계산.
 * (2차 최종 확정(closed)은 별도 수동 — closeSecondarySettlement.)
 */
class BulkVehicleCostService
{
    public function __construct(private VehicleLedgerUnlockService $unlockService) {}

    /**
     * @param  string  $column  Vehicle::BULK_COST_FIELDS 중 하나 (예: cost_towing / cost_license)
     * @param  array<int, int|float>  $amounts  [vehicleId => 금액] (원, 음수 불가)
     * @param  bool  $fleetWide  true=탁송비(canApprove 전체) / false=면허비(canUnlockLedger 팀)
     * @return array{applied:int, unchanged:int, skipped:list<array{id:int,number:?string,reason:string}>}
     */
    public function apply(string $column, array $amounts, User $by, string $reason, bool $fleetWide): array
    {
        if (! in_array($column, Vehicle::BULK_COST_FIELDS, true)) {
            throw new InvalidArgumentException("일괄 기입 불가 컬럼: {$column} (비용 컬럼만 허용)");
        }

        // fleet 모드는 전역 권한 1회 검사. team 모드는 차량별로 검사(아래 루프).
        if ($fleetWide && ! $by->canApprove()) {
            throw new AuthorizationException('비용 일괄 기입 권한 없음 (관리/admin 전용)');
        }

        $applied = 0;
        $unchanged = 0;
        $skipped = [];

        DB::transaction(function () use ($column, $amounts, $by, $reason, $fleetWide, &$applied, &$unchanged, &$skipped) {
            foreach ($amounts as $vehicleId => $amount) {
                $vehicle = Vehicle::find($vehicleId);
                if (! $vehicle) {
                    $skipped[] = ['id' => (int) $vehicleId, 'number' => null, 'reason' => 'not_found'];

                    continue;
                }

                // team 모드 — 본인 팀 차량만 (fleet 은 위에서 canApprove 통과).
                if (! $fleetWide && ! $by->canUnlockLedger($vehicle)) {
                    $skipped[] = ['id' => $vehicle->id, 'number' => $vehicle->vehicle_number, 'reason' => 'no_scope'];

                    continue;
                }

                // 2차 정산 마감(closed) 차량 보호 — 정산 마무리된 건 재업로드로 소급 변경 안 함.
                //   (필요 시 개별 [🔓 잠금 해제]로만 정정 — 일괄 도구는 마감 건을 절대 안 건드림.)
                if ($vehicle->settlements()->where('secondary_status', 'closed')->exists()) {
                    $skipped[] = ['id' => $vehicle->id, 'number' => $vehicle->vehicle_number, 'reason' => 'settlement_closed'];

                    continue;
                }

                // 이미 같은 값이면 건드리지 않음 — 재업로드 시 잠금해제 토큰·감사로그 중복 방지.
                $newValue = (int) round((float) $amount);
                if ((int) $vehicle->{$column} === $newValue) {
                    $unchanged++;

                    continue;
                }

                // 잠긴 차량만 토큰 발급 (안 잠긴 차량은 saving 가드가 자유 통과).
                if ($vehicle->hasConfirmedPaymentLock()) {
                    $this->unlockService->unlockForCostBulk($vehicle, $by, $reason, $fleetWide);
                }

                $vehicle->update([$column => $newValue]);
                $applied++;
            }
        });

        return ['applied' => $applied, 'unchanged' => $unchanged, 'skipped' => $skipped];
    }
}
