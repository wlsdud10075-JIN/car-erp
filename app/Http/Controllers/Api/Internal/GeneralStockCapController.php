<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\GeneralStockCap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🧱 일반재고 인원당 상한 조회 — board 가 「재고매입」을 체크할 때 미리 묻는다 (jin 2026-11-20).
 * 권위 = docs/integration/board-portal-api.md §18. 판정은 GeneralStockCap::check() 하나 — purchase-sync 수신 거절과 같은 답이 나온다.
 *
 * GET /general-stock-cap?salesman_email=&car_erp_salesman_id=&incoming=
 *   담당자 매칭은 purchase-sync 와 같다(car_erp_salesman_id 우선 → salesman_email). incoming = 이번 차 매입가(원, 없으면 0).
 */
class GeneralStockCapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'salesman_email' => ['required', 'email'],
            'car_erp_salesman_id' => ['nullable', 'integer'],
            'incoming' => ['nullable', 'integer', 'min:0'],
        ]);

        $salesmanId = GeneralStockCap::resolveSalesmanId(
            isset($data['car_erp_salesman_id']) ? (int) $data['car_erp_salesman_id'] : null,
            $data['salesman_email'],
        );

        return response()->json(GeneralStockCap::check($salesmanId, (int) ($data['incoming'] ?? 0)));
    }
}
