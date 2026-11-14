<?php

namespace App\Services;

use App\Models\AuditLog;
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
 *   ② 스코프: fleet(canCorrectSecondaryCosts 전체 — 재무 이상) / team(canUnlockLedger 본인 팀). 팀 밖·미존재 차량은 skip 리포트.
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
     * @param  bool  $fleetWide  true=탁송비(재무 이상, 전체) / false=면허비(canUnlockLedger 팀)
     * @param  bool  $accumulate  true=**기존 금액에 더한다**(보험료 — 달마다 갱신분이 쌓이는 비용, jin 2026-11-02 «덮어쓰기 아니고 + 더해야»)
     *                            / false=덮어쓴다(탁송비·면허비 — 건당 1회 비용). 더하기는 같은 파일을 두 번 올리면 두 배가 되므로
     *                            호출측(명세서 기입)이 파일 지문으로 재업로드를 막는다.
     * @return array{applied:int, unchanged:int, skipped:list<array{id:int,number:?string,reason:string}>}
     */
    public function apply(string $column, array $amounts, User $by, string $reason, bool $fleetWide, bool $accumulate = false): array
    {
        if (! in_array($column, Vehicle::BULK_COST_FIELDS, true)) {
            throw new InvalidArgumentException("일괄 기입 불가 컬럼: {$column} (비용 컬럼만 허용)");
        }

        // fleet 모드는 전역 권한 1회 검사. team 모드는 차량별로 검사(아래 루프).
        // 🔀 2026-11-14 (jin) — 재무 이상(재무·관리·업무관리자·최고관리자). 2차 정산 비용 기입은 재무 일이다.
        if ($fleetWide && ! $by->canCorrectSecondaryCosts()) {
            throw new AuthorizationException('비용 일괄 기입 권한 없음 (재무·관리 이상 전용)');
        }

        $applied = 0;
        $unchanged = 0;
        $skipped = [];

        DB::transaction(function () use ($column, $amounts, $by, $reason, $fleetWide, $accumulate, &$applied, &$unchanged, &$skipped) {
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
                //   더하기 모드는 「더할 금액 0」이 변화 없음이다.
                $delta = (int) round((float) $amount);
                $newValue = $accumulate ? (int) $vehicle->{$column} + $delta : $delta;
                if ((int) $vehicle->{$column} === $newValue) {
                    $unchanged++;

                    continue;
                }

                // 정산 락 개편(2026-07-24) — 마감(closed) 전 차량은 회계컬럼 락이 없어 토큰 불필요(직접 update).
                //   (closed 는 위에서 skip.) 값 변경(old→new)은 Vehicle updated 훅 recordChange 가 자동 감사하고,
                //   일괄 기입 출처(명세서 사유)는 아래 bulk_cost_applied 감사로 별도 보존.
                $vehicle->update([$column => $newValue]);
                AuditLog::create([
                    'user_id' => $by->id,
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $vehicle->id,
                    'action' => 'bulk_cost_applied',
                    'column_name' => $column,
                    'old_value' => null,
                    'new_value' => $reason,
                    'ip_address' => request()?->ip(),
                ]);
                $applied++;
            }
        });

        return ['applied' => $applied, 'unchanged' => $unchanged, 'skipped' => $skipped];
    }
}
