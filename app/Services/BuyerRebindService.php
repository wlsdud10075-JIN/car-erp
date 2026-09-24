<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\Consignee;
use App\Models\InterVehicleTransfer;
use App\Models\SavingsStatus;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 바이어 삭제 가드 + 이관 — 단일 출처 (jin 2026-09-23 «리스트에 넣어줘», 구현 2026-09-24).
 *
 * 왜: Buyer 는 SoftDeletes 라 지워도 `vehicles.buyer_id/export_buyer_id/bl_buyer_id` 가 휴지통 행을
 *     계속 가리킨다. 평범한 `belongsTo` 는 그걸 null 로 보여 화면·서류에서 바이어가 **빈칸**이 된다.
 *     실사고 = ssancarerp ATLAS #393(중복 등록 21분 뒤 삭제) → 무사백 29대 (SKILLS §8 #110).
 *
 * 규칙:
 *  - 돈(적립금·현금 원장·이체)이 붙은 바이어 = **삭제 불가**(이관도 안 한다 — 잔액은 바이어×통화 스냅샷이라 옮기면 깨진다.
 *    게다가 넷 다 SoftDeletes 없이 cascadeOnDelete 라 forceDelete 하면 복구 불가로 사라진다).
 *  - 차량이 붙은 바이어 = 이관 대상을 고르면 **차량 3컬럼 + 그 바이어의 컨사이니**를 통째로 넘기고 삭제.
 *    컨사이니를 같이 넘기는 이유 — 차량의 consignee_id 는 옛 바이어의 컨사이니를 가리키고 있어, 바이어만 옮기면
 *    차량 패널의 컨사이니 드롭다운(바이어별 로드)이 그 차의 컨사이니를 못 보여 준다.
 *  - 🚫 차량은 `save()` 하지 않는다 — `Vehicle::saving` 가드 사슬(H3·회계 락·chk_sale_required)이 옛 차량을 막는다.
 *    09-23 운영 재연결 스크립트와 같은 방식: 쿼리빌더 update + 컬럼별 `AuditLog::recordChange`. 캐시(receivable_risk 의
 *    바이어별 락 임계)는 05:00 `vehicles:rebuild-caches` 가 덮는다.
 *
 * 부르는 곳 = 바이어 화면 삭제 모달 · `buyers:check-dangling --rebind` (둘이 같은 코드를 타야 한다 — SKILLS §8 #44).
 */
final class BuyerRebindService
{
    /** 차량에서 바이어를 가리키는 컬럼 → 라벨 키(buyer.delete_gate.col_*) */
    public const VEHICLE_COLUMNS = ['buyer_id' => 'sale', 'export_buyer_id' => 'export', 'bl_buyer_id' => 'bl'];

    /** 차량에서 컨사이니를 가리키는 컬럼(점검 명령용) */
    public const CONSIGNEE_COLUMNS = ['consignee_id', 'export_consignee_id', 'bl_consignee_id'];

    /**
     * 참조 집계. 차량은 삭제된 차까지 센다(복원되면 다시 매달린다).
     *
     * @return array{vehicles: array<string,int>, consignees: int, money: array<string,int>}
     */
    public static function references(Buyer $buyer): array
    {
        $vehicles = [];
        foreach (array_keys(self::VEHICLE_COLUMNS) as $col) {
            $vehicles[$col] = Vehicle::withTrashed()->where($col, $buyer->id)->count();
        }
        $vehicles['total'] = self::vehiclesOf($buyer)->count();

        $money = [
            'savings' => SavingsStatus::where('buyer_id', $buyer->id)->count(),
            'cash_receipts' => BuyerCashReceipt::where('buyer_id', $buyer->id)->count(),
            'cash_fees' => BuyerCashFee::where('buyer_id', $buyer->id)->count(),
            'transfers' => InterVehicleTransfer::where('buyer_id', $buyer->id)->count(),
        ];
        $money['total'] = array_sum($money);

        return [
            'vehicles' => $vehicles,
            'consignees' => Consignee::withTrashed()->where('buyer_id', $buyer->id)->count(),
            'money' => $money,
        ];
    }

    /** 삭제를 막아야 하는 사유(사람용 문장). null = 지워도 된다. */
    public static function blockReason(Buyer $buyer): ?string
    {
        $r = self::references($buyer);

        if ($r['money']['total'] > 0) {
            return __('buyer.delete_gate.blocked_money', [
                'name' => $buyer->name,
                'savings' => $r['money']['savings'],
                'cash' => $r['money']['cash_receipts'] + $r['money']['cash_fees'],
                'transfers' => $r['money']['transfers'],
            ]);
        }

        if ($r['vehicles']['total'] > 0) {
            return __('buyer.delete_gate.blocked_vehicles', [
                'name' => $buyer->name,
                'count' => $r['vehicles']['total'],
                'sale' => $r['vehicles']['buyer_id'],
                'export' => $r['vehicles']['export_buyer_id'],
                'bl' => $r['vehicles']['bl_buyer_id'],
            ]);
        }

        return null;
    }

    /** 이관으로 풀 수 있는 상태인가 — 차량만 붙어 있고 돈은 없을 때. */
    public static function canRebind(Buyer $buyer): bool
    {
        $r = self::references($buyer);

        return $r['money']['total'] === 0 && $r['vehicles']['total'] > 0;
    }

    /**
     * 이관 계획(dry-run) 또는 실행.
     *
     * @return array{vehicles: list<array{id:int, number:string, columns:list<string>}>, consignees: int, applied: bool}
     */
    public static function rebind(Buyer $from, Buyer $to, bool $apply = false): array
    {
        if ($from->id === $to->id) {
            throw new \DomainException(__('buyer.delete_gate.same_target'));
        }
        if ($to->trashed()) {
            throw new \DomainException(__('buyer.delete_gate.target_deleted'));
        }
        if (self::references($from)['money']['total'] > 0) {
            throw new \DomainException(self::blockReason($from));
        }

        $vehicles = self::vehiclesOf($from)->orderBy('id')->get();
        $plan = [];
        foreach ($vehicles as $v) {
            $columns = [];
            foreach (array_keys(self::VEHICLE_COLUMNS) as $col) {
                if ((int) $v->{$col} === (int) $from->id) {
                    $columns[] = $col;
                }
            }
            $plan[] = ['id' => $v->id, 'number' => (string) $v->vehicle_number, 'columns' => $columns];
        }
        $consignees = Consignee::withTrashed()->where('buyer_id', $from->id)->count();

        if ($apply) {
            DB::transaction(function () use ($vehicles, $plan, $from, $to) {
                foreach ($vehicles as $i => $v) {
                    $update = array_fill_keys($plan[$i]['columns'], $to->id);
                    Vehicle::withTrashed()->whereKey($v->id)->update($update);   // 🚫 save() 금지 — 클래스 docblock
                    foreach ($update as $col => $new) {
                        AuditLog::recordChange($v, $col, $from->id, $new);
                    }
                }
                Consignee::withTrashed()->where('buyer_id', $from->id)->update(['buyer_id' => $to->id]);

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => Buyer::class,
                    'auditable_id' => $from->id,
                    'action' => 'buyer_rebound',
                    'column_name' => 'buyer_id',
                    'old_value' => (string) $from->id,
                    'new_value' => (string) $to->id.' ('.count($plan).' vehicles)',
                    'ip_address' => request()?->ip(),
                ]);
            });
        }

        return ['vehicles' => $plan, 'consignees' => $consignees, 'applied' => $apply];
    }

    /** 이 바이어를 가리키는 차량(3컬럼 중 하나라도, 삭제된 차 포함). */
    public static function vehiclesOf(Buyer $buyer): Builder
    {
        return Vehicle::withTrashed()->where(function (Builder $q) use ($buyer) {
            foreach (array_keys(self::VEHICLE_COLUMNS) as $col) {
                $q->orWhere($col, $buyer->id);
            }
        });
    }
}
