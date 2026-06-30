<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Settlement;
use App\Models\Vehicle;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * board 영업 포털 ④ 재무 읽기 API (읽기전용).
 *
 * 권위 스펙 = docs/integration/board-portal-api.md §3·§4.
 * - 응답은 **명시 화이트리스트만** — RRN(nice_reg_owner_rrn)·계좌(purchase_seller_account)·
 *   마진(sales/vat/total_margin) 절대 미포함. toArray() 금지.
 * - accessor/cache 그대로 반환(raw SQL 재계산 금지 = drift). 환율0 외화 = unpaid_krw null.
 * - 본인격리 = SalesmanResolver::resolveActiveOrFail (퇴사자 403).
 */
class InternalPortalController extends Controller
{
    private function salesmanId(Request $request): int
    {
        return SalesmanResolver::resolveActiveOrFail((string) $request->query('salesman_email', ''))->id;
    }

    /** 영업 본인 담당 차량 base 쿼리 (IDOR 단일출처). */
    private function ownVehicles(int $salesmanId)
    {
        return Vehicle::query()->whereNull('deleted_at')->where('salesman_id', $salesmanId);
    }

    public function receivables(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = $this->ownVehicles($sid)->where('sale_price', '>', 0)->with('buyer')->get()
            ->map(fn (Vehicle $v) => [
                'vehicle_number' => $v->vehicle_number,
                'buyer' => $v->buyer?->name,
                'currency' => $v->currency,
                'exchange_rate' => $v->exchange_rate !== null ? (float) $v->exchange_rate : null,
                'sale_total' => (float) $v->sale_total_amount,
                'unpaid_krw' => $v->sale_unpaid_amount_krw_cache,   // null = 환율 미입력 (완납 아님)
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    public function sales(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = $this->ownVehicles($sid)->where('sale_price', '>', 0)->with('buyer')->get()
            ->map(fn (Vehicle $v) => [
                'vehicle_number' => $v->vehicle_number,
                'buyer' => $v->buyer?->name,
                'currency' => $v->currency,
                'sale_price' => (float) $v->sale_price,
                'sale_date' => $v->sale_date?->toDateString(),
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = $this->ownVehicles($sid)->where('purchase_price', '>', 0)->get()
            ->map(fn (Vehicle $v) => [
                'vehicle_number' => $v->vehicle_number,
                'purchase_price' => (float) $v->purchase_price,
                'cost_total' => $v->cost_total,
                'purchase_unpaid' => $v->purchase_unpaid_amount,
                'purchase_date' => $v->purchase_date?->toDateString(),
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = Settlement::query()->where('salesman_id', $sid)->with('vehicle')->get()
            ->map(fn (Settlement $s) => [
                'vehicle_number' => $s->vehicle?->vehicle_number,
                'status' => $s->settlement_status,
                'actual_payout' => $s->actual_payout,   // 실지급액 — 마진 raw 는 미포함
                'confirmed_at' => $s->confirmed_at?->toDateString(),
                'paid_at' => $s->paid_at?->toDateString(),   // 실제 지급일 — board 가 받은 月(5/6월) 기준으로 묶음
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * 바이어별 묶음 — 영업이 "이 바이어가 나에게 얼마 이득을 줬나"를 보는 뷰.
     *
     * - 판매(sale): 바이어별 판매금액 합(통화별). 바이어 = 판매측 개념(`buyer_id`).
     * - 정산(이득): 바이어 차량의 `actual_payout`(영업 실지급액) 합 = "나에게 준 이득".
     *   accessor 그대로 합산(환차·이월 반영). paid(확정)/전체 분리.
     * - 매입은 구입처(`purchase_from`) 기준이라 바이어 무관 → 미포함(설계 확정).
     */
    public function byBuyer(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);

        $byBuyer = [];

        // 판매금액 — 바이어 배정된(판매된) 본인 차량
        foreach ($this->ownVehicles($sid)->where('sale_price', '>', 0)->whereNotNull('buyer_id')->with('buyer')->get() as $v) {
            $bid = $v->buyer_id;
            $byBuyer[$bid] ??= $this->emptyBuyerRow($v->buyer?->name);
            $byBuyer[$bid]['vehicle_count']++;
            $cur = $v->currency ?: 'KRW';
            $byBuyer[$bid]['sales_by_currency'][$cur] = ($byBuyer[$bid]['sales_by_currency'][$cur] ?? 0) + (float) $v->sale_price;
        }

        // 정산 실지급액 — 본인 정산을 차량의 바이어로 귀속
        foreach (Settlement::query()->where('salesman_id', $sid)->with('vehicle.buyer')->get() as $s) {
            $bid = $s->vehicle?->buyer_id;
            if ($bid === null) {
                continue;
            }
            $byBuyer[$bid] ??= $this->emptyBuyerRow($s->vehicle->buyer?->name);
            $byBuyer[$bid]['payout_total_krw'] += (int) $s->actual_payout;
            if ($s->settlement_status === 'paid') {
                $byBuyer[$bid]['payout_paid_krw'] += (int) $s->actual_payout;
            }
        }

        $data = collect($byBuyer)
            ->map(fn (array $row, $bid) => ['buyer_id' => (int) $bid] + $row)
            ->sortByDesc('payout_total_krw')   // 이득 큰 바이어부터 (채권 TOP10 패턴)
            ->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /** 바이어 1행 초기값. payout: 전체(예상+확정) / paid(확정)만 분리. */
    private function emptyBuyerRow(?string $name): array
    {
        return [
            'buyer' => $name,
            'vehicle_count' => 0,
            'sales_by_currency' => [],
            'payout_total_krw' => 0,
            'payout_paid_krw' => 0,
        ];
    }

    /**
     * board 경매/구매 드로어용 — 영업 본인 바이어 목록 (드롭다운).
     * jin 2026-06-23: 전체 활성이 아니라 **영업 본인 바이어만**(IDOR 격리 유지). board 는
     * salesman_email 로 본인 바이어만 보고 신차에 지정. 응답 = 화이트리스트만(연락처·PII 금지).
     */
    public function buyers(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = Buyer::query()->where('salesman_id', $sid)->where('is_active', true)
            ->with('country')->orderBy('name')->get()
            ->map(fn (Buyer $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'country' => $b->country?->name,
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * board 드로어용 — 선택 바이어 하위 컨사이니 목록.
     * IDOR — 요청 buyer_id 가 본인 소유일 때만 반환(타 영업 바이어 컨사이니 열람 차단).
     */
    public function consignees(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $buyerId = (int) $request->query('buyer_id', 0);

        $ownsBuyer = $buyerId > 0
            && Buyer::where('id', $buyerId)->where('salesman_id', $sid)->exists();

        $data = $ownsBuyer
            ? Consignee::query()->where('buyer_id', $buyerId)->where('is_active', true)
                ->orderBy('name')->get()
                ->map(fn (Consignee $c) => ['id' => $c->id, 'name' => $c->name])->values()
            : collect();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    public function finance(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $vehicles = $this->ownVehicles($sid)->get();

        return response()->json([
            'unpaid_total_krw' => (int) $vehicles->sum(fn (Vehicle $v) => $v->sale_unpaid_amount_krw_cache ?? 0),
            'purchase_unpaid_total' => (int) $vehicles->where('purchase_price', '>', 0)->sum->purchase_unpaid_amount,
            'fx_missing_count' => $vehicles->where('sale_price', '>', 0)
                ->filter(fn (Vehicle $v) => $v->sale_unpaid_amount_krw_cache === null)->count(),
            'settlement_pending_count' => Settlement::where('salesman_id', $sid)
                ->whereIn('settlement_status', ['pending', 'calculating', 'confirmed'])->count(),
        ]);
    }
}
