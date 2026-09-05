<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashReceipt;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * 바이어 정산현황 4단계 — 화면·엑셀·(추후)포털이 **같은 숫자**를 쓰게 하는 단일 출처.
 * 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🚫 조건을 화면에 옮겨 적지 말 것. 화면 필터와 엑셀이 갈리면
 *    「화면엔 3대인데 엑셀엔 300대」가 된다 — 에러 없이 조용히(SKILLS §9).
 *
 * ⚠️ 「정산」이라는 말은 이 ERP 에서 **담당자 지급**을 뜻한다. 이 클래스는 그것과 무관하다 —
 *    바이어와의 대금(입금·미수) 현황이다. 그래서 코드 이름은 account 를 쓴다.
 */
class BuyerAccountService
{
    /** 묶음 축 — 전부 사람이 손으로 넣는 차량 컬럼이다(전용 테이블 없음). */
    public const AXES = [
        'container' => 'container_number',
        'declaration' => 'export_declaration_number',
        'bl' => 'bl_number',
        'vessel' => 'vessel_name',
    ];

    /** 미수로 보는 하한 — 외화 소수 잔차를 미수로 세지 않는다(미수 accessor 의 스냅과 같은 취지). */
    public const EPSILON = 0.005;

    /**
     * 그 바이어의 **미수가 남은 차량**. 미수는 accessor 라 SQL 로 못 거른다 —
     * 바이어 범위로 좁혀 읽고 PHP 에서 판정한다(한 바이어면 수십 행).
     *
     * 🚫 검색 조건을 여기 옮겨 적지 말 것 — `Vehicle::scopeSearchAny` 단일 출처를 그대로 쓴다.
     *    차량관리와 같은 검색이어야 「거기선 찾히는데 여기선 안 찾히는」 형태가 안 생긴다.
     *
     * @return Collection<int, Vehicle>
     */
    public function unpaidVehicles(Buyer $buyer, ?string $search = '', ?string $vin = ''): Collection
    {
        return $buyer->vehicles()
            ->where('sale_price', '>', 0)
            ->searchAny($search, $vin)
            ->with(['finalPayments', 'receivableHistories'])
            ->orderBy('vehicle_number')
            ->get()
            ->filter(fn (Vehicle $v) => $v->sale_unpaid_amount > self::EPSILON)
            ->values();
    }

    /**
     * 현금 사용 내역 — **입금 1건 → 어느 차에 얼마**. 이 기능의 원래 요구가 이것이다
     * (jin: "이 10,000 eur 를 어떻게 사용했는지 투명하게 볼 수 있고 추적할 수 있으며").
     *
     * 🚨 **검색으로 거르지 않는다.** 이건 현금 원장이라, 일부만 보여주면 「남은 현금」과
     *    더해도 안 맞는 표가 된다. 위 미수 차량 표(검색 대상)와는 성격이 다르다.
     *
     * ⚠️ 여기 나오는 차량은 **미수 차량 표에 없을 수 있다** — 현금으로 완납된 차가 그렇다.
     *    그게 이 표가 따로 필요한 이유다.
     *
     * @return Collection<int, BuyerCashReceipt>
     */
    public function cashUsage(Buyer $buyer): Collection
    {
        return BuyerCashReceipt::where('buyer_id', $buyer->id)
            ->with([
                'allocations' => fn ($q) => $q->orderBy('id'),
                'allocations.vehicle:id,vehicle_number,nice_reg_vin',
                'allocations.finalPayment:id,payment_date',
            ])
            ->fifo()
            ->get();
    }

    /**
     * 통화별 현금 — 받은 / 쓴 / 남은. 남은 값은 `BuyerCashReceipt::balanceFor` 와 **같은 뺄셈**이다
     * (게이트가 그걸 쓰므로, 여기서 다른 식을 쓰면 화면과 차단 판정이 갈린다).
     *
     * @return array<string, array{received:float, allocated:float, remaining:float}>
     */
    public function cashByCurrency(Buyer $buyer): array
    {
        $received = BuyerCashReceipt::where('buyer_id', $buyer->id)
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $allocated = BuyerCashAllocation::query()
            ->join('buyer_cash_receipts as r', 'r.id', '=', 'buyer_cash_allocations.receipt_id')
            ->where('r.buyer_id', $buyer->id)
            ->selectRaw('r.currency as currency, SUM(buyer_cash_allocations.amount) as total')
            ->groupBy('r.currency')
            ->pluck('total', 'currency');

        $out = [];
        foreach ($received->keys()->merge($allocated->keys())->unique() as $currency) {
            $in = (float) ($received[$currency] ?? 0);
            $used = (float) ($allocated[$currency] ?? 0);
            $out[$currency] = [
                'received' => round($in, 2),
                'allocated' => round($used, 2),
                'remaining' => round($in - $used, 2),
            ];
        }
        ksort($out);

        return $out;
    }

    /**
     * 묶음별 남은 금액. 축은 전부 손입력 문자열이라 **앞뒤 공백만 정규화**하고
     * **값이 정확히 같은 것끼리만** 묶는다.
     *
     * 🚫 비슷한 값을 합치려 들지 말 것 — 컨테이너번호엔 자체 관리코드가 섞여 있어
     *    (`6.06_G RORO 11-27_10` 류, SKILLS §8 #71-C) 표기가 갈리는 건 **데이터의 성질**이다.
     *
     * 통화가 섞이면 더하면 안 되므로 **(묶음 × 통화)** 로 나눈다.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     * @return list<array{key:string, currency:string, count:int, unpaid:float, vehicles:list<string>}>
     */
    public function groupsBy(string $axis, Collection $vehicles): array
    {
        $column = self::AXES[$axis] ?? self::AXES['container'];

        $rows = [];
        foreach ($vehicles as $vehicle) {
            $key = trim((string) ($vehicle->{$column} ?? ''));
            $bucket = ($key === '' ? '' : $key).'|'.$vehicle->currency;
            $rows[$bucket] ??= [
                'key' => $key,          // '' = 미지정 (화면이 라벨을 붙인다)
                'currency' => $vehicle->currency,
                'count' => 0,
                'unpaid' => 0.0,
                'vehicles' => [],
            ];
            $rows[$bucket]['count']++;
            $rows[$bucket]['unpaid'] = round($rows[$bucket]['unpaid'] + $vehicle->sale_unpaid_amount, 2);
            $rows[$bucket]['vehicles'][] = (string) $vehicle->vehicle_number;
        }

        // 미지정은 항상 마지막 — 위쪽은 남은 금액 큰 순(실무자가 큰 것부터 본다).
        uasort($rows, function ($a, $b) {
            if (($a['key'] === '') !== ($b['key'] === '')) {
                return $a['key'] === '' ? 1 : -1;
            }

            return $b['unpaid'] <=> $a['unpaid'];
        });

        return array_values($rows);
    }
}
