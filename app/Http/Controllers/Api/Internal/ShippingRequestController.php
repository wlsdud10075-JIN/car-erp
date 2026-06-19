<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * board 영업 포털 ③ 선적요청 (읽기 + 가벼운 쓰기).
 * 권위 = docs/integration/board-portal-api.md §5.
 * - 선적가능 차 = 판매완료 + export + open 요청 없음 (본인 차만).
 * - 요청 수신 → shipping_requests 적재(vehicles 컬럼 불변) + TaskAlarm shipping_requested 즉시 발동(수출통관).
 */
class ShippingRequestController extends Controller
{
    /** GET — 본인 선적가능 차 + 바이어 + 컨사이니 목록. */
    public function shippable(Request $request): JsonResponse
    {
        $sid = SalesmanResolver::resolveActiveOrFail((string) $request->query('salesman_email', ''))->id;

        $openVehicleIds = ShippingRequest::where('status', ShippingRequest::STATUS_REQUESTED)->pluck('vehicle_id');

        $data = Vehicle::query()->whereNull('deleted_at')
            ->where('salesman_id', $sid)
            ->where('sales_channel', 'export')
            ->where('progress_status_cache', '판매완료')
            ->whereNotIn('id', $openVehicleIds)
            ->with('buyer.consignees')
            ->get()
            ->map(fn (Vehicle $v) => [
                'vehicle_id' => $v->id,
                'vehicle_number' => $v->vehicle_number,
                'buyer' => $v->buyer ? ['id' => $v->buyer->id, 'name' => $v->buyer->name] : null,
                'consignees' => $v->buyer
                    ? $v->buyer->consignees->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()
                    : [],
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /** POST — 선적요청 수신(멱등). 본인 차만, open 요청 있으면 skip. */
    public function store(Request $request): JsonResponse
    {
        $salesman = SalesmanResolver::resolveActiveOrFail((string) ($request->input('salesman_email') ?? ''));

        $v = $request->validate([
            'vehicle_ids' => ['required', 'array', 'min:1'],
            'vehicle_ids.*' => ['integer'],
            'buyer_id' => ['nullable', 'integer'],
            'consignee_id' => ['nullable', 'integer'],
            'shipping_method' => ['required', 'in:RORO,CONTAINER'],
        ]);

        $created = [];
        $skipped = [];
        foreach ($v['vehicle_ids'] as $vid) {
            // IDOR — 본인 차만
            $vehicle = Vehicle::where('id', $vid)->where('salesman_id', $salesman->id)->first();
            if (! $vehicle) {
                $skipped[] = $vid;

                continue;
            }
            // 멱등 — open 요청 있으면 skip
            if (ShippingRequest::where('vehicle_id', $vid)->where('status', ShippingRequest::STATUS_REQUESTED)->exists()) {
                $skipped[] = $vid;

                continue;
            }

            ShippingRequest::create([
                'vehicle_id' => $vid,
                'buyer_id' => $v['buyer_id'] ?? null,
                'consignee_id' => $v['consignee_id'] ?? null,
                'shipping_method' => $v['shipping_method'],
                'requested_by_email' => $salesman->email,
                'status' => ShippingRequest::STATUS_REQUESTED,
                'requested_at' => now(),
            ]);

            // 알람 — 수출통관에게 즉시 발동 (기존 알람 UI 재사용, scan 불필요)
            $alarm = TaskAlarm::firstOrNew([
                'type' => 'shipping_requested', 'vehicle_id' => $vehicle->id, 'resolved_at' => null,
            ]);
            $alarm->target_role = '수출통관';
            $alarm->due_date = now();
            $alarm->message_meta = TaskAlarm::sanitizeMeta([
                'vehicle_number' => $vehicle->vehicle_number,
                'shipping_method' => $v['shipping_method'],
            ]);
            $alarm->save();

            $created[] = $vid;
        }

        return response()->json(['created' => $created, 'skipped' => $skipped], 201);
    }
}
