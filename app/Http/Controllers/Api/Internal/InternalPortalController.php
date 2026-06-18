<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
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
            ])->values();

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
