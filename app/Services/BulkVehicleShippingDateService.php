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
    /** 이 도구가 건드릴 수 있는 컬럼 — 그 외는 예외. 지정한 필드 밖으로 번지지 않게 봉인. */
    public const FIELDS = ['shipping_date', 'eta_date', 'vessel_name'];

    /** 날짜로 다룰 컬럼(8자리 정규화·형식 검사 대상). 나머지는 문자열 그대로. */
    private const DATE_FIELDS = ['shipping_date', 'eta_date'];

    /**
     * 대상 안의 기존 선박명 분포 — `['MV A' => 182, '' => 112]`. 빈 값은 `''` 키로 센다.
     *
     * 🧭 **왜 세는가** (jin 2026-08-12): 필터가 여러 배의 차를 함께 걷어왔는데 모르고 덮으면
     * **다른 배에 실린 차의 선박명이 통째로 날아간다**. 수백 대라 되돌리기도 어렵다.
     * 그래서 섞여 있으면 화면이 먼저 보여주고, 사람이 「그래도 덮기」를 눌러야 진행한다.
     *
     * ⚠️ **빈 값은 "다름" 으로 치지 않는다** — 처음 채우는 게 이 도구의 주 용도라,
     *    비어 있는 차가 섞였다고 매번 경고하면 경고가 무의미해진다(늘 뜨면 아무도 안 읽는다).
     *    판정은 `distinctCount()` 가 하고, 표시는 빈 값도 함께 보여준다(무엇이 바뀌는지 알아야 하므로).
     *
     * @return array<string, int> 선박명 => 대수 (대수 많은 순)
     */
    public function vesselBreakdown(Builder $query): array
    {
        $rows = $query->clone()
            ->selectRaw("COALESCE(NULLIF(TRIM(vessel_name), ''), '') as vsl, COUNT(*) as cnt")
            ->reorder()->groupBy('vsl')->pluck('cnt', 'vsl')->all();

        arsort($rows);

        return array_map('intval', $rows);
    }

    /** 실제로 값이 들어 있는 선박명 종류 수 — 2 이상이면 경고 대상. */
    public static function distinctCount(array $breakdown): int
    {
        return count(array_filter(
            $breakdown,
            fn ($cnt, $vsl) => trim((string) $vsl) !== '' && $cnt > 0,
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * @param  Builder  $query  대상 차량 쿼리(화면 필터를 서버에서 재도출한 것)
     * @param  array<string, string|null>  $values  FIELDS 중 채운 것만 — 빈 칸은 그대로 유지된다
     * @param  string  $reason  일괄 적용 사유(선박명 등) — 감사에 남는다
     * @return array{applied:int, unchanged:int, skipped:list<array{id:int,number:?string,reason:string}>}
     */
    public function apply(Builder $query, array $values, User $by, string $reason): array
    {
        if (! $by->canAccessClearance()) {
            throw new AuthorizationException('선적일·ETA·선박명 일괄 지정 권한 없음 (수출통관/관리 전용)');
        }

        $payload = [];
        foreach ($values as $column => $value) {
            if (! in_array($column, self::FIELDS, true)) {
                throw new InvalidArgumentException("일괄 지정 불가 컬럼: {$column}");
            }
            $value = trim((string) ($value ?? ''));
            // 빈 칸 = "안 건드림". 여기서 걸러야 "아무것도 안 쓰고 넘어가면 원래 값 유지" 가 성립한다.
            //   ⚠️ 그래서 이 도구로는 **값을 비울 수 없다**(빈 문자열이 지우기 신호가 되면 오조작 한 번에
            //      수백 대의 선박명이 날아간다). 지우는 건 차량 편집에서 개별로.
            if ($value === '') {
                continue;
            }
            if (in_array($column, self::DATE_FIELDS, true)) {
                // 8자리 타이핑(20260728)이 그대로 오면 Eloquent date 캐스트가 Unix 타임스탬프로 읽어 1970 이 된다.
                //   화면은 focusout 에서 정규화하지만(app.js), 수백 대를 바꾸는 입구라 서버에서도 막는다.
                $value = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $value);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new InvalidArgumentException("날짜 형식이 올바르지 않습니다: {$value}");
                }
            }
            $payload[$column] = $value;
        }
        if ($payload === []) {
            throw new InvalidArgumentException('선적일·ETA·선박명 중 최소 하나는 입력해야 합니다.');
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
                        // 날짜는 Carbon 이라 포맷해서, 선박명은 문자열 그대로 비교한다.
                        //   같으면 건너뛰어야 재적용해도 감사로그가 부풀지 않는다.
                        $current = in_array($column, self::DATE_FIELDS, true)
                            ? $vehicle->{$column}?->format('Y-m-d')
                            : (string) ($vehicle->{$column} ?? '');
                        if ((string) $current !== (string) $value) {
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
