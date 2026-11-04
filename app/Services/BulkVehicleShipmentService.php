<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleShipment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * 서류 발송(EMS · DHL) 일괄 기입 — 「명세서 기입」의 발송비판 (jin 2026-08-31).
 *
 * 한 발송(등기번호 · 운송장번호)이 N 대를 덮으므로 총액을 N 으로 나눠 차량마다 행을 만든다.
 * 나머지 원은 첫 차량이 받는다(면허비 n/1 선례 — 합이 총액과 정확히 같아야 한다).
 *
 * 🔑 미리보기와 실행이 같은 함수를 쓴다 — apply() 가 plan() 을 다시 부른다.
 *    갈리면 「목록엔 기입된다고 떴는데 안 들어가는」 행이 남는다(SKILLS §8 #67 · #44).
 *
 * 🔁 같은 번호를 다시 기입하면 그 번호의 배정을 통째로 바꾼다(누적 아님).
 *    누적으로 만들면 같은 명세를 두 번 넣었을 때 이중청구가 된다. 대신 대상에서 빠진 차량은
 *    「이 번호에서 제외」로 미리보기에 줄마다 보여준다 — 조용히 지우지 않는다.
 */
class BulkVehicleShipmentService
{
    /**
     * 무엇이 어떻게 될지 — 화면 미리보기와 실제 기입이 공유하는 단일 판정.
     *
     * @param  list<int>  $vehicleIds
     * @return array{
     *     targets: list<array{id:int, number:?string, fee:int, current:?int, state:string}>,
     *     removed: list<array{id:int, number:?string, fee:int}>,
     *     skipped: list<array{id:int, number:?string, reason:string}>,
     *     total: int, per_unit: int
     * }
     */
    public function plan(string $carrier, ?string $trackingNo, array $vehicleIds, int $totalFee, User $by): array
    {
        $carrier = VehicleShipment::normalizeCarrier($carrier);
        $trackingNo = VehicleShipment::normalizeTrackingNo($trackingNo);

        $targets = [];
        $skipped = [];
        $eligible = [];
        $textOnly = [];   // 2차 정산 마감 차량 — 번호·날짜만, 금액 0 (jin 2026-11-04)

        foreach (array_values(array_unique(array_map('intval', $vehicleIds))) as $id) {
            $vehicle = Vehicle::find($id);
            if (! $vehicle) {
                $skipped[] = ['id' => $id, 'number' => null, 'reason' => 'not_found'];

                continue;
            }
            if (! $by->canScopeVehicle($vehicle)) {
                $skipped[] = ['id' => $id, 'number' => $vehicle->vehicle_number, 'reason' => 'no_scope'];

                continue;
            }
            // 2차 정산 마감 차량 — 금액은 안 건드리고 번호·날짜·비고만 남긴다(jin 2026-11-04 «텍스트는 기입, 금액은 다른 차량들에게 n/1»).
            //   서류는 3·6개월 뒤에도 나간다. 지급이 끝난 달의 숫자는 그대로다.
            if ($vehicle->hasClosedSecondarySettlement()) {
                $textOnly[] = $vehicle;

                continue;
            }
            $eligible[] = $vehicle;
        }

        // N/1 — 나머지 원은 첫 차량이 받는다(합 = 총액 보장).
        $n = count($eligible);
        $per = $n > 0 ? intdiv(max(0, $totalFee), $n) : 0;
        $remainder = $n > 0 ? max(0, $totalFee) - ($per * $n) : 0;

        foreach ($eligible as $i => $vehicle) {
            $fee = $per + ($i === 0 ? $remainder : 0);
            $existing = $this->existingRow($vehicle, $carrier, $trackingNo);
            $targets[] = [
                'id' => $vehicle->id,
                'number' => $vehicle->vehicle_number,
                'fee' => $fee,
                'current' => $existing?->fee,
                'state' => $existing === null ? 'new' : ((int) $existing->fee === $fee ? 'unchanged' : 'changed'),
            ];
        }
        // 마감 차량 = 금액 없는 줄(기존 줄이 있으면 그 금액 그대로 — 금액은 못 바꾼다).
        foreach ($textOnly as $vehicle) {
            $existing = $this->existingRow($vehicle, $carrier, $trackingNo);
            $targets[] = [
                'id' => $vehicle->id,
                'number' => $vehicle->vehicle_number,
                'fee' => $existing !== null ? (int) $existing->fee : 0,
                'current' => $existing?->fee,
                'state' => 'text_only',
            ];
        }

        // 같은 번호를 달고 있는데 이번 대상에 없는 차량 = 이 발송에서 빠진다.
        $removed = [];
        if ($trackingNo !== null) {
            $keepIds = array_column($targets, 'id');
            VehicleShipment::query()
                ->where('carrier', $carrier)->where('tracking_no', $trackingNo)
                ->when($keepIds !== [], fn ($q) => $q->whereNotIn('vehicle_id', $keepIds))
                ->with('vehicle')
                ->get()
                ->each(function (VehicleShipment $s) use (&$removed) {
                    // 마감 차량의 금액 있는 줄은 빼지 않는다 — 지급 끝난 금액이다(모델 가드도 막는다).
                    if ((int) $s->fee !== 0 && $s->vehicle?->hasClosedSecondarySettlement()) {
                        return;
                    }
                    $removed[] = ['id' => $s->vehicle_id, 'number' => $s->vehicle?->vehicle_number, 'fee' => (int) $s->fee];
                });
        }

        // 금액은 있는데 나눌(마감 안 된) 차량이 없다 — 그 금액이 어디에도 안 들어간다. 기입 전에 멈춘다.
        $noFeeTarget = $totalFee > 0 && $eligible === [];

        return [
            'targets' => $targets,
            'removed' => $removed,
            'skipped' => $skipped,
            'total' => max(0, $totalFee),
            'per_unit' => $per,
            'text_only' => count($textOnly),
            'no_fee_target' => $noFeeTarget,
        ];
    }

    /**
     * 실제 기입 — 판정은 plan() 이 한다(미리보기와 절대 안 갈린다).
     *
     * @param  list<int>  $vehicleIds
     * @return array{applied:int, unchanged:int, removed:int, skipped:list<array{id:int,number:?string,reason:string}>}
     */
    public function apply(
        string $carrier,
        ?string $trackingNo,
        array $vehicleIds,
        int $totalFee,
        ?string $sentDate,
        User $by,
        string $reason,
        ?string $note = null,
    ): array {
        if (! $by->canApprove()) {
            throw new AuthorizationException('발송 내역 일괄 기입 권한 없음 (관리/admin 전용)');
        }

        $plan = $this->plan($carrier, $trackingNo, $vehicleIds, $totalFee, $by);
        if ($plan['no_fee_target']) {
            throw new \DomainException('금액을 나눌 차량이 없습니다 — 고른 차량이 전부 2차 정산 마감이라 금액은 넣을 수 없습니다(금액 0 으로 번호만 넣으세요).');
        }
        $carrier = VehicleShipment::normalizeCarrier($carrier);
        $trackingNo = VehicleShipment::normalizeTrackingNo($trackingNo);
        $sentDate = self::normalizeDate($sentDate);

        $applied = 0;
        $unchanged = 0;
        $removed = 0;

        DB::transaction(function () use ($plan, $carrier, $trackingNo, $sentDate, $note, $reason, $by, &$applied, &$unchanged, &$removed) {
            foreach ($plan['removed'] as $row) {
                VehicleShipment::query()
                    ->where('carrier', $carrier)->where('tracking_no', $trackingNo)
                    ->where('vehicle_id', $row['id'])
                    ->get()->each->delete();
                $removed++;
            }

            foreach ($plan['targets'] as $row) {
                $vehicle = Vehicle::find($row['id']);
                if (! $vehicle) {
                    continue;
                }
                $existing = $this->existingRow($vehicle, $carrier, $trackingNo);

                if ($existing !== null) {
                    // 값이 같으면 손대지 않는다 — 재기입 때 감사로그가 무의미하게 쌓이지 않게.
                    //   마감 차량(text_only)은 금액을 안 건드리고 날짜·비고만.
                    $newFee = $row['state'] === 'text_only' ? (int) $existing->fee : $row['fee'];
                    $newNote = $note ?? $existing->note;
                    if ((int) $existing->fee === $newFee
                        && (string) $existing->sent_date?->format('Y-m-d') === (string) $sentDate
                        && (string) $existing->note === (string) $newNote) {
                        $unchanged++;

                        continue;
                    }
                    $existing->fee = $newFee;
                    $existing->sent_date = $sentDate;
                    $existing->note = $newNote;
                    $existing->save();
                } else {
                    $vehicle->shipments()->create([
                        'carrier' => $carrier,
                        'tracking_no' => $trackingNo,
                        'fee' => $row['fee'],
                        'sent_date' => $sentDate,
                        'note' => $note,
                    ]);
                }
                $applied++;
            }

            // 일괄의 「출처」는 차량별 감사로그로는 안 남는다(금액 변화만 남는다) → 한 줄로 따로 보존.
            AuditLog::create([
                'user_id' => $by->id,
                'auditable_type' => Vehicle::class,
                'auditable_id' => $plan['targets'][0]['id'] ?? 0,
                'action' => 'bulk_shipment_applied',
                'column_name' => $carrier === VehicleShipment::CARRIER_EMS ? 'ems_tracking_no_cache' : 'dhl_tracking_no_cache',
                'old_value' => null,
                'new_value' => mb_substr($reason, 0, 500),
                'ip_address' => request()?->ip(),
            ]);
        });

        return ['applied' => $applied, 'unchanged' => $unchanged, 'removed' => $removed, 'skipped' => $plan['skipped']];
    }

    private function existingRow(Vehicle $vehicle, string $carrier, ?string $trackingNo): ?VehicleShipment
    {
        if ($trackingNo === null) {
            return null;
        }

        return $vehicle->shipments()
            ->where('carrier', $carrier)->where('tracking_no', $trackingNo)
            ->first();
    }

    /**
     * 8 자리(20260801)를 서버에서 정규화한다 — 화면(app.js)만 믿으면 안 된다.
     * 그대로 새면 Eloquent date 캐스트가 Unix 타임스탬프로 읽어 1970 년이 저장된다(SKILLS §14).
     */
    public static function normalizeDate(?string $raw): ?string
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{8}$/', $v)) {
            $v = substr($v, 0, 4).'-'.substr($v, 4, 2).'-'.substr($v, 6, 2);
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
