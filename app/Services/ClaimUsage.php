<?php

namespace App\Services;

use App\Exceptions\ClaimUsageException;
use App\Models\AuditLog;
use App\Models\ReceivableHistory;
use App\Models\Vehicle;
use App\Models\VehicleClaim;
use App\Models\VehicleClaimUsage;
use Illuminate\Support\Facades\DB;

/**
 * 🧾 클레임 사용 — **쓰기는 여기 한 곳** (jin 2026-11-20, 설계 = docs/design/claim-management.md §11).
 *
 * 인정된 클레임(예 400 EUR)만큼 바이어가 다음 차 대금을 덜 보낸다 → 그 차의 미수를 「클레임」으로 턴다.
 * 판매탭 「클레임 사용」 칸(이 차에 쓴 **합계**)을 저장하면:
 *   ① 채권관리에 상계(offset) 줄 하나가 생기거나 바뀐다(`source = 'claim'`) — 미수는 그 줄이 줄인다(공식 무변경)
 *   ② 어느 클레임에서 얼마를 썼는지 `vehicle_claim_usages` 에 **오래된 클레임부터** 나눠 적는다.
 *
 * - 누적금(쓸 수 있는 돈) = 그 바이어의 **인정된** 클레임 전부(환불·적립 구분 없음) − 사용분, **통화별**.
 * - 한도 = 누적금, 그리고 **그 차의 남은 미수**(넘기면 과입금이 돼 「과입금 → 적립금·회사이익」으로 클레임이 수익이 된다).
 * - 부담 = 회사. 상계 줄은 판매환율로 「받은 것」이라 마진·담당자 지급·`company_net` 은 안 움직인다(손실로 표시되는 곳 없음).
 * - 정산이 마감된 차도 미수가 있으면 쓴다(jin «이미 정산 다 됐는데 미수는 없애야 되잖아»).
 * - 🚫 이 상계 줄은 채권관리에서 못 고친다(ReceivableHistory 모델 가드) — 여기서만.
 */
class ClaimUsage
{
    public const SOURCE = 'claim';

    /** 서비스가 쓰는 동안만 true — ReceivableHistory 의 클레임 줄 수정·삭제 가드를 연다. */
    public static bool $writing = false;

    /** 이 차에 쓴 클레임 합계. */
    public static function usedOn(int $vehicleId): float
    {
        return round((float) VehicleClaimUsage::where('vehicle_id', $vehicleId)->sum('amount'), 2);
    }

    /** 그 바이어·통화의 클레임 누적금(아직 안 쓴 돈). 클레임별 남은 돈을 0 아래로 안 내린다(board 가 뒤늦게 줄인 건). */
    public static function balance(?int $buyerId, ?string $currency): float
    {
        if (! $buyerId || ! $currency) {
            return 0.0;
        }

        return round(array_sum(array_column(self::openClaims($buyerId, $currency, false), 'remaining')), 2);
    }

    /**
     * 이 차의 「클레임 사용」 합계를 $total 로 맞춘다. 값이 같으면 아무것도 안 한다.
     *
     * @throws ClaimUsageException
     */
    public static function setForVehicle(Vehicle $vehicle, float $total, ?int $userId): void
    {
        $total = round($total, 2);
        if ($total < 0) {
            throw new ClaimUsageException(__('vehicle.valmsg.claim_negative'));
        }

        DB::transaction(function () use ($vehicle, $total, $userId) {
            $current = self::usedOn($vehicle->id);
            if (abs($total - $current) < 0.005) {
                return;
            }
            if ($total > 0 && ! $vehicle->buyer_id) {
                throw new ClaimUsageException(__('vehicle.valmsg.claim_no_buyer'));
            }

            $row = ReceivableHistory::where('vehicle_id', $vehicle->id)->where('source', self::SOURCE)->lockForUpdate()->first();

            // 늘릴 때만 — 그 차의 남은 미수까지만. (미수에는 지금 줄이 이미 빠져 있다 → 늘어나는 몫만 견준다)
            $increase = $total - $current;
            if ($increase > 0) {
                $unpaid = max(0.0, (float) $vehicle->fresh()->sale_unpaid_amount);
                if ($increase > $unpaid + 0.009) {
                    throw new ClaimUsageException(__('vehicle.valmsg.claim_over_unpaid', [
                        'unpaid' => number_format($unpaid, 2), 'use' => number_format($increase, 2), 'currency' => $vehicle->currency,
                    ]));
                }
            }

            // 이 차 몫을 일단 풀고(잔액으로 되돌리고) 잠근 클레임에서 오래된 것부터 다시 나눈다.
            VehicleClaimUsage::where('vehicle_id', $vehicle->id)->delete();
            $claims = $total > 0 ? self::openClaims((int) $vehicle->buyer_id, (string) $vehicle->currency, true) : [];
            $available = round(array_sum(array_column($claims, 'remaining')), 2);
            if ($total > $available + 0.009) {
                throw new ClaimUsageException(__('vehicle.valmsg.claim_over_balance', [
                    'balance' => number_format($available, 2), 'use' => number_format($total, 2), 'currency' => $vehicle->currency,
                ]));
            }

            self::$writing = true;
            try {
                if ($total <= 0) {
                    $row?->delete();
                } else {
                    $note = __('vehicle.claim_use_note');
                    if ($row) {
                        $row->update(['amount' => $total]);
                    } else {
                        $row = new ReceivableHistory([
                            'vehicle_id' => $vehicle->id, 'collected_at' => today(), 'collector_id' => $userId,
                            'method' => 'offset', 'amount' => $total, 'note' => $note,
                        ]);
                        $row->source = self::SOURCE;
                        $row->save();
                    }
                    $left = $total;
                    foreach ($claims as $c) {
                        if ($left <= 0.004) {
                            break;
                        }
                        $take = round(min($left, $c['remaining']), 2);
                        VehicleClaimUsage::create([
                            'vehicle_claim_id' => $c['id'], 'vehicle_id' => $vehicle->id,
                            'receivable_history_id' => $row->id, 'amount' => $take, 'created_by' => $userId,
                        ]);
                        $left = round($left - $take, 2);
                    }
                }
            } finally {
                self::$writing = false;
            }

            AuditLog::create([
                'user_id' => $userId,
                'auditable_type' => Vehicle::class,
                'auditable_id' => $vehicle->id,
                'action' => 'claim_used',
                'column_name' => 'claim_used',
                'old_value' => number_format($current, 2, '.', ''),
                'new_value' => number_format($total, 2, '.', '').' '.$vehicle->currency,
            ]);
        });
    }

    /**
     * 인정된 클레임 중 남은 돈이 있는 것 — 오래된 것부터(결재일, 없으면 받은 날, 그다음 id).
     *
     * @return list<array{id:int, remaining:float}>
     */
    private static function openClaims(int $buyerId, string $currency, bool $lock): array
    {
        $claims = VehicleClaim::query()->where('buyer_id', $buyerId)->where('currency', $currency)->where('result', 'accepted')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderByRaw('COALESCE(approved_at, created_at)')->orderBy('id')
            ->get(['id', 'confirmed_amount']);
        $used = VehicleClaimUsage::whereIn('vehicle_claim_id', $claims->pluck('id'))
            ->selectRaw('vehicle_claim_id, SUM(amount) as used')->groupBy('vehicle_claim_id')->pluck('used', 'vehicle_claim_id');

        $out = [];
        foreach ($claims as $c) {
            $remaining = round((float) $c->confirmed_amount - (float) ($used[$c->id] ?? 0), 2);
            if ($remaining > 0.004) {
                $out[] = ['id' => (int) $c->id, 'remaining' => $remaining];
            }
        }

        return $out;
    }
}
