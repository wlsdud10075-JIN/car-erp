<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 선적일·ETA 일괄 지정 (jin 2026-07-28) — 한 선박에 실린 수백 대를 한 번에 처리.
 *
 * 배경: 선박 1척에 300대를 배정해도 실제로는 280대만 실린다. 포워더 자료로 실린 차를 추려
 * (차량목록 필터 → 「건수만」 → 엑셀 대조) 그 조건에 걸린 차량 전체에 같은 날짜를 찍는다.
 *
 * 대상은 **호출부가 넘긴 쿼리로 서버에서 다시 도출**한다. 클라이언트가 보낸 ID 목록을 믿지 않는다
 * (SKILLS §8 #26 — 수백 건 변경이라 IDOR 피해가 크다). 차량별로 canScopeVehicle 재인가.
 *
 * 흐름 (차량별, 트랜잭션):
 *   ① 스코프 밖 차량은 skip 리포트.
 *   ② 값이 이미 같으면 unchanged — 재적용해도 감사로그가 부풀지 않는다.
 *   ③ update → Vehicle updated 훅 recordChange 가 컬럼별 old→new 를 자동 감사
 *      (shipping_date·eta_date 는 AUDITED_COLUMNS 등재).
 *   ④ 일괄 출처(선박명 등 사유)는 bulk_shipping_date_applied 로 별도 보존.
 *
 * 선적일·ETA 는 진행상태 v4 cascade 에 안 들어가므로 progress_status_cache 는 바뀌지 않는다.
 * 그래도 모델 update 를 쓰는 이유는 감사·캐시 훅을 정상 경로로 태우기 위함이다(bulk update 는 훅이 안 뜬다).
 */
class BulkVehicleShippingDateService
{
    /** 이 도구가 건드릴 수 있는 컬럼 — 그 외는 예외. 날짜 외 필드로 번지지 않게 봉인. */
    public const FIELDS = ['shipping_date', 'eta_date'];

    /**
     * @param  Builder  $query  대상 차량 쿼리(화면 필터를 서버에서 재도출한 것)
     * @param  array<string, string|null>  $dates  ['shipping_date' => 'Y-m-d', 'eta_date' => 'Y-m-d'] 중 채운 것만
     * @param  string  $reason  일괄 적용 사유(선박명 등) — 감사에 남는다
     * @return array{applied:int, unchanged:int, skipped:list<array{id:int,number:?string,reason:string}>}
     */
    public function apply(Builder $query, array $dates, User $by, string $reason): array
    {
        if (! $by->canAccessClearance()) {
            throw new AuthorizationException('선적일·ETA 일괄 지정 권한 없음 (수출통관/관리 전용)');
        }

        $payload = [];
        foreach ($dates as $column => $value) {
            if (! in_array($column, self::FIELDS, true)) {
                throw new InvalidArgumentException("일괄 지정 불가 컬럼: {$column}");
            }
            $value = trim((string) ($value ?? ''));
            if ($value === '') {
                continue;
            }
            // 8자리 타이핑(20260728)이 그대로 오면 Eloquent date 캐스트가 Unix 타임스탬프로 읽어 1970 이 된다.
            //   화면은 focusout 에서 정규화하지만(app.js), 수백 대를 바꾸는 입구라 서버에서도 막는다.
            $value = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $value);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new InvalidArgumentException("날짜 형식이 올바르지 않습니다: {$value}");
            }
            $payload[$column] = $value;
        }
        if ($payload === []) {
            throw new InvalidArgumentException('선적일·ETA 중 최소 하나는 입력해야 합니다.');
        }

        $applied = 0;
        $unchanged = 0;
        $skipped = [];

        DB::transaction(function () use ($query, $payload, $by, $reason, &$applied, &$unchanged, &$skipped) {
            // chunkById — 수백~수천 대여도 메모리 안전. update 가 정렬 대상 컬럼을 안 건드려서 재방문 없음.
            $query->clone()->chunkById(200, function ($vehicles) use ($payload, $by, $reason, &$applied, &$unchanged, &$skipped) {
                foreach ($vehicles as $vehicle) {
                    if (! $by->canScopeVehicle($vehicle)) {
                        $skipped[] = ['id' => $vehicle->id, 'number' => $vehicle->vehicle_number, 'reason' => 'no_scope'];

                        continue;
                    }

                    $diff = [];
                    foreach ($payload as $column => $value) {
                        $current = $vehicle->{$column}?->format('Y-m-d');
                        if ($current !== $value) {
                            $diff[$column] = $value;
                        }
                    }
                    if ($diff === []) {
                        $unchanged++;

                        continue;
                    }

                    $vehicle->update($diff);
                    AuditLog::create([
                        'user_id' => $by->id,
                        'auditable_type' => Vehicle::class,
                        'auditable_id' => $vehicle->id,
                        'action' => 'bulk_shipping_date_applied',
                        'column_name' => implode(',', array_keys($diff)),
                        'old_value' => null,
                        'new_value' => $reason,
                        'ip_address' => request()?->ip(),
                    ]);
                    $applied++;
                }
            });
        });

        return ['applied' => $applied, 'unchanged' => $unchanged, 'skipped' => $skipped];
    }
}
