<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 통관 체크 일괄 (jin 2026-11-16 A안) — 선택 N대에 **파일 없이** `is_export_cleared` 만 켠다.
 *
 * 🧭 실무 순서 = 수출신고서 업로드 → 체크빌 → 통관 체크. 신고서 일괄 업로드 모달의 「통관 체크도 함께」는
 *    첫 단계에 붙어 있어(10-20 부터 기본 꺼짐) 체크빌까지 끝낸 뒤 **체크만** 켤 길이 없었다.
 *
 * 규칙 (jin):
 *   - 수출신고서가 **없는** 차는 제외(사유 표시) — 단건 패널도 그 조합엔 확인 모달을 띄운다.
 *   - 체크빌이 없는 차는 **경고만** — 막지 않는다.
 *   - 이미 체크된 차는 건드리지 않는다.
 *
 * 저장은 모델 update — 단건 패널과 같은 훅(cascade·캐시·감사·C4/C5 게이트)을 탄다. 게이트에 걸린 차는
 * 그 차만 건너뛰고 사유를 돌려준다(차량별 트랜잭션). 대상 ID 는 사람의 선택이지만 차량별 `canScopeVehicle`
 * 재인가는 반드시 한다(SKILLS §8 #26). 미리보기와 실행이 같은 판정을 쓴다(§8 #67).
 */
class BulkExportClearedService
{
    /**
     * @return array{targets: list<array{id:int,number:string,no_checkbill:bool}>, no_doc: list<string>, already: list<string>, no_scope: list<string>}
     */
    public function preview(array $vehicleIds, User $by): array
    {
        $this->authorize($by);

        $out = ['targets' => [], 'no_doc' => [], 'already' => [], 'no_scope' => []];
        foreach ($this->vehicles($vehicleIds) as $v) {
            $state = self::classify($v, $by);
            if ($state === 'target') {
                $out['targets'][] = [
                    'id' => $v->id,
                    'number' => (string) $v->vehicle_number,
                    'no_checkbill' => blank($v->checkbill_document),
                ];
            } else {
                $out[$state][] = (string) $v->vehicle_number;
            }
        }

        return $out;
    }

    /**
     * @return array{applied:int, skipped: list<array{id:int,number:string,reason:string,message?:string}>}
     */
    public function apply(array $vehicleIds, User $by, string $reason): array
    {
        $this->authorize($by);

        $vehicles = $this->vehicles($vehicleIds);
        if ($vehicles->isEmpty()) {
            throw new InvalidArgumentException('대상 차량이 없습니다.');
        }

        $applied = 0;
        $skipped = [];
        foreach ($vehicles as $v) {
            $state = self::classify($v, $by);
            if ($state !== 'target') {
                $skipped[] = ['id' => $v->id, 'number' => (string) $v->vehicle_number, 'reason' => $state];

                continue;
            }
            try {
                DB::transaction(function () use ($v, $by, $reason) {
                    $v->update(['is_export_cleared' => true]);
                    AuditLog::create([
                        'user_id' => $by->id,
                        'auditable_type' => Vehicle::class,
                        'auditable_id' => $v->id,
                        'action' => 'bulk_export_cleared',
                        'column_name' => 'is_export_cleared',
                        'old_value' => blank($v->checkbill_document) ? 'no_checkbill' : null,
                        'new_value' => $reason,
                        'ip_address' => request()?->ip(),
                    ]);
                });
                $applied++;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $v->id, 'number' => (string) $v->vehicle_number, 'reason' => 'error', 'message' => $e->getMessage()];
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /** target · no_doc · already · no_scope — 미리보기와 실행의 단일 판정. */
    private static function classify(Vehicle $v, User $by): string
    {
        if (! $by->canScopeVehicle($v)) {
            return 'no_scope';
        }
        if ($v->is_export_cleared) {
            return 'already';
        }
        if (blank($v->export_declaration_document)) {
            return 'no_doc';
        }

        return 'target';
    }

    private function vehicles(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? collect() : Vehicle::whereIn('id', $ids)->orderBy('vehicle_number')->get();
    }

    private function authorize(User $by): void
    {
        if (! $by->canAccessClearance()) {
            throw new AuthorizationException('통관 체크 일괄 권한 없음');
        }
    }
}
