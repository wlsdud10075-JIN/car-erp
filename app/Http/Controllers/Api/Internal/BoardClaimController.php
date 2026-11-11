<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Vehicle;
use App\Models\VehicleClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * board → ERP 클레임 미러 (jin 2026-11-11, docs/design/claim-management.md §10 · 받는 쪽 스펙 = docs/integration/board-portal-api.md).
 *
 *   POST /api/internal/board/claims  — board 결재선 최종 승인 뒤 클레임 담당자가 [ERP 로 보내기] 를 눌렀을 때.
 *   인증 = VerifyBoardReadHmac(본문까지 서명). 멱등 = board_claim_id. 같은 id 를 다시 보내면 revision 이 더 클 때만 갱신.
 *   금액 = 확정 금액 하나(confirmed_amount). 기각(declined) = 0 으로 받는다(횟수·타당성 지표용).
 *   바이어 = 본문 buyer_id → 없으면 그 차의 바이어 → 둘 다 없으면 422(ERP 에서 바이어를 먼저 정한다).
 *   🚫 적립금·미수·정산에 손대지 않는다 — 기록만.
 */
class BoardClaimController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'board_claim_id' => ['required', 'integer', 'min:1'],
            'revision' => ['required', 'integer', 'min:1'],
            'vehicle_id' => ['nullable', 'integer'],
            'buyer_id' => ['nullable', 'integer'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'vin' => ['nullable', 'string', 'max:30'],
            'confirmed_amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['required', 'string', 'in:'.implode(',', VehicleClaim::CURRENCIES)],
            'compensation_type' => ['nullable', 'string', 'in:'.implode(',', VehicleClaim::TYPES)],
            'result' => ['required', 'string', 'in:'.implode(',', VehicleClaim::RESULTS)],
            'result_reason' => ['nullable', 'string', 'max:5000'],
            'approved_at' => ['nullable', 'date'],
            'approved_by_name' => ['nullable', 'string', 'max:100'],
            'handler_name' => ['nullable', 'string', 'max:100'],
            'salesman_email' => ['nullable', 'string', 'max:150'],
            'buyer_claim_count' => ['nullable', 'integer', 'min:0'],
            'board_url' => ['nullable', 'string', 'max:300'],
        ]);

        $vehicle = isset($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
        $buyer = isset($data['buyer_id']) ? Buyer::find($data['buyer_id']) : null;   // 지워진 바이어는 find 가 null
        if (! $buyer && $vehicle?->buyer_id) {
            $buyer = Buyer::find($vehicle->buyer_id);
        }
        if (! $buyer) {
            return response()->json(['status' => 'rejected', 'message' => 'ERP 에서 이 차의 바이어를 먼저 정해 주세요(바이어를 알 수 없는 클레임은 받지 않습니다).'], 422);
        }

        $attrs = [
            'source' => 'board',
            'revision' => (int) $data['revision'],
            'vehicle_id' => $vehicle?->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $data['buyer_name'] ?? $buyer->name,
            'vehicle_number' => $data['vehicle_number'] ?? $vehicle?->vehicle_number,
            'vin' => $data['vin'] ?? null,
            'confirmed_amount' => $data['result'] === 'declined' ? 0 : $data['confirmed_amount'],   // 기각 = 0원(jin 11-11)
            'currency' => $data['currency'],
            'compensation_type' => $data['compensation_type'] ?? null,
            'result' => $data['result'],
            'result_reason' => $data['result_reason'] ?? null,
            'approved_at' => $data['approved_at'] ?? null,
            'approved_by_name' => $data['approved_by_name'] ?? null,
            'handler_name' => $data['handler_name'] ?? null,
            'salesman_email' => $data['salesman_email'] ?? null,
            'board_buyer_claim_count' => $data['buyer_claim_count'] ?? null,
            'board_url' => $data['board_url'] ?? null,
        ];

        return DB::transaction(function () use ($data, $attrs) {
            $claim = VehicleClaim::where('board_claim_id', $data['board_claim_id'])->lockForUpdate()->first();
            if ($claim && $claim->revision > $attrs['revision']) {
                return response()->json(['erp_claim_id' => $claim->id, 'status' => 'stale', 'message' => "ERP 에 더 새 회차({$claim->revision})가 있습니다."], 409);
            }
            if ($claim && $claim->revision === $attrs['revision']) {
                return response()->json(['erp_claim_id' => $claim->id, 'status' => 'unchanged']);   // 같은 회차 재전송 = 멱등
            }
            $status = $claim ? 'updated' : 'created';
            $claim = $claim ? tap($claim)->update($attrs) : VehicleClaim::create($attrs + ['board_claim_id' => $data['board_claim_id']]);

            AuditLog::create([
                'user_id' => null,
                'auditable_type' => VehicleClaim::class, 'auditable_id' => $claim->id,
                'action' => 'board_claim_received', 'column_name' => 'confirmed_amount',
                'old_value' => (string) ($attrs['handler_name'] ?? ''), 'new_value' => $attrs['result'].' '.$attrs['currency'].' '.$attrs['confirmed_amount'].' r'.$attrs['revision'],
                'ip_address' => request()->ip(),
            ]);

            return response()->json(['erp_claim_id' => $claim->id, 'status' => $status], $status === 'created' ? 201 : 200);
        });
    }
}
