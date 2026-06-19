<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        // 요청해도 목록 유지 — 사라짐은 관리가 선적/통관 진행해 progress 가 '판매완료' 벗어날 때(자연소멸).
        // 차별 최신 open(요청됨/진행중) 요청 → board 가 뱃지+재요청 prefill. done 은 progress 이동이라 제외.
        $openByVehicle = ShippingRequest::whereIn('status', [ShippingRequest::STATUS_REQUESTED, ShippingRequest::STATUS_IN_PROGRESS])
            ->orderByDesc('id')->get()->groupBy('vehicle_id');

        $data = Vehicle::query()->whereNull('deleted_at')
            ->where('salesman_id', $sid)
            ->where('sales_channel', 'export')
            ->where('progress_status_cache', '판매완료')
            ->with('buyer.consignees')
            ->get()
            ->map(function (Vehicle $v) use ($openByVehicle) {
                $req = $openByVehicle->get($v->id)?->first();

                return [
                    'vehicle_id' => $v->id,
                    'vehicle_number' => $v->vehicle_number,
                    'buyer' => $v->buyer ? ['id' => $v->buyer->id, 'name' => $v->buyer->name] : null,
                    'consignees' => $v->buyer
                        ? $v->buyer->consignees->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()
                        : [],
                    'shipping_status' => $req?->status ?? 'none',          // none | requested | in_progress
                    'requested_method' => $req?->shipping_method,          // 재요청 prefill (없으면 null)
                    'requested_consignee_id' => $req?->consignee_id,
                ];
            })->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * POST — 선적요청 수신. 본인 차만.
     * 재요청(open 'requested' 존재) = **제자리 갱신**(consignee/method 정정, batch_id·status 유지).
     * 'in_progress'(관리 처리중)면 skip. 응답 created/updated/skipped 구분.
     */
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

        $batchId = (string) Str::uuid();   // 신규 묶음 = 1 POST = 1 배치 (재요청 갱신건은 기존 batch 유지)
        $created = [];
        $updated = [];
        $skipped = [];
        foreach ($v['vehicle_ids'] as $vid) {
            // IDOR — 본인 차만
            $vehicle = Vehicle::where('id', $vid)->where('salesman_id', $salesman->id)->first();
            if (! $vehicle) {
                $skipped[] = $vid;

                continue;
            }

            $existing = ShippingRequest::where('vehicle_id', $vid)
                ->whereIn('status', [ShippingRequest::STATUS_REQUESTED, ShippingRequest::STATUS_IN_PROGRESS])
                ->orderByDesc('id')->first();

            // 이미 관리가 처리중(in_progress) = 갱신 불가, skip
            if ($existing && $existing->status === ShippingRequest::STATUS_IN_PROGRESS) {
                $skipped[] = $vid;

                continue;
            }

            // 재요청 — 기존 'requested' 제자리 갱신 (batch_id·status 유지, 배치 정합)
            if ($existing) {
                $existing->update([
                    'buyer_id' => $v['buyer_id'] ?? null,
                    'consignee_id' => $v['consignee_id'] ?? null,
                    'shipping_method' => $v['shipping_method'],
                    'requested_at' => now(),
                ]);
                $this->fireShippingAlarm($vehicle, $v['shipping_method']);
                $updated[] = $vid;

                continue;
            }

            ShippingRequest::create([
                'batch_id' => $batchId,
                'vehicle_id' => $vid,
                'buyer_id' => $v['buyer_id'] ?? null,
                'consignee_id' => $v['consignee_id'] ?? null,
                'shipping_method' => $v['shipping_method'],
                'requested_by_email' => $salesman->email,
                'status' => ShippingRequest::STATUS_REQUESTED,
                'requested_at' => now(),
            ]);
            $this->fireShippingAlarm($vehicle, $v['shipping_method']);
            $created[] = $vid;
        }

        return response()->json(['created' => $created, 'updated' => $updated, 'skipped' => $skipped], 201);
    }

    /** 수출통관에게 shipping_requested 알람 즉시 발동/갱신 (기존 알람 UI 재사용, scan 불필요). */
    private function fireShippingAlarm(Vehicle $vehicle, string $method): void
    {
        $alarm = TaskAlarm::firstOrNew([
            'type' => 'shipping_requested', 'vehicle_id' => $vehicle->id, 'resolved_at' => null,
        ]);
        $alarm->target_role = '수출통관';
        $alarm->due_date = now();
        $alarm->message_meta = TaskAlarm::sanitizeMeta([
            'vehicle_number' => $vehicle->vehicle_number,
            'shipping_method' => $method,
        ]);
        $alarm->save();
    }
}
