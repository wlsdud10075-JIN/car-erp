<?php

namespace App\Services;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Log;

/**
 * 🧱 일반재고 인원당 상한 2억 — board 발 재고매입 차단 (jin 2026-11-20
 * «인원별 재고 금액은 최대 2억 … 넘기는 차량이 생길 때 BOARD 에서 재고매입 체크해도 넘어올 수 없게»).
 *
 * 단일 출처: board 가 미리 묻는 조회(GeneralStockCapController)와 purchase-sync 수신 거절이 **둘 다 check()** 를 쓴다.
 * 권위 = docs/integration/purchase-sync-receiver.md 「일반재고 상한」 · board-portal-api.md §18.
 *
 * - 세는 것 = 그 담당자의 일반재고(Vehicle::scopeGeneralStock — 매입 완납 · 출고 전 · 바이어 미정 · 판매가 없음) 매입가 합.
 *   재고 화면 「일반재고」 카드와 같은 숫자다. 🚫 조건을 옮겨 적지 말 것.
 * - 🚫 지급 전(지급대기) 차는 안 센다(jin 결정) — 그래서 지급 전인 재고매입이 연달아 오면 전부 통과한다. 알고 둔 구멍이다.
 * - 판정 = 기존 합 + 이번 차 매입가 **>** 상한(정확히 2억은 통과).
 * - 담당자를 못 찾으면 누구 한도인지 알 수 없어 적용하지 않는다(통과).
 * - ERP 화면에서 직접 등록·바이어 제거는 막지 않는다 — board 발 신규 등록만.
 */
class GeneralStockCap
{
    public const ERROR = 'general_stock_cap_exceeded';

    public static function cap(): int
    {
        return Vehicle::GENERAL_STOCK_PRICE_CAP;
    }

    /** @return array{count:int, amount:int} */
    public static function usedBy(int $salesmanId): array
    {
        $row = Vehicle::query()->generalStock()->where('vehicles.salesman_id', $salesmanId)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(purchase_price), 0) as amount')->first();

        return ['count' => (int) $row->cnt, 'amount' => (int) round((float) $row->amount)];
    }

    /**
     * @return array{applies:bool, blocked:bool, salesman_id:?int, salesman_name:?string, cap:int, used:int, count:int, incoming:int, remaining:int}
     */
    public static function check(?int $salesmanId, int $incoming): array
    {
        $cap = self::cap();
        $incoming = max(0, $incoming);
        if ($salesmanId === null) {
            return ['applies' => false, 'blocked' => false, 'salesman_id' => null, 'salesman_name' => null,
                'cap' => $cap, 'used' => 0, 'count' => 0, 'incoming' => $incoming, 'remaining' => $cap];
        }
        $used = self::usedBy($salesmanId);

        return [
            'applies' => true,
            'blocked' => $cap < $used['amount'] + $incoming,
            'salesman_id' => $salesmanId,
            'salesman_name' => Salesman::query()->whereKey($salesmanId)->value('name'),
            'cap' => $cap,
            'used' => $used['amount'],
            'count' => $used['count'],
            'incoming' => $incoming,
            'remaining' => max(0, $cap - $used['amount']),
        ];
    }

    /**
     * purchase-sync 와 같은 담당자 매칭 — car_erp_salesman_id 명시 우선 → salesman_email.
     * 매칭: Salesman.email 직접 → User.email → user.salesman. 못 찾으면 null(수동 배정 대기).
     * 🚫 영업만(sales) — 관리직 「직원」 미러 행이 차량 담당자로 꽂히지 않게(2026-10-14, SalesmanResolver 와 같은 규칙).
     */
    public static function resolveSalesmanId(?int $overrideId, string $email): ?int
    {
        if ($overrideId !== null) {
            $salesman = Salesman::find($overrideId);
            if ($salesman) {
                return $salesman->id;
            }
        }

        $salesman = Salesman::where('email', $email)->sales()->first();
        if ($salesman) {
            return $salesman->id;
        }

        $user = User::where('email', $email)->first();
        if ($user && $user->salesman?->isSales()) {
            return $user->salesman->id;
        }

        Log::info('[purchase-sync] 영업 매칭 실패 — 수동 배정 대기', ['email' => $email]);

        return null;
    }
}
