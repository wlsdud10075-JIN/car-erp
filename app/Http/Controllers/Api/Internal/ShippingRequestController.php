<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * board 영업 포털 ③ 선적·B/L 묶음 (읽기 + 선언형 쓰기).
 *
 * 권위 스펙 = docs/integration/board-portal-api.md §5. 회의록 = docs/meetings/2026-06-30-bl-shipment-bundle-v2.md.
 * - 1 묶음(batch_id) = 1 선적 = 1 B/L = 1 오리지널/써랜더. 묶음은 batch_id 로 영속(B/L 단계까지 살아있음).
 * - 저장 = shipping_requests(멤버십, vehicle 단위). vehicles 컬럼 적재 금지(export_buyer_id = C4/C5 게이트 회귀).
 * - IDOR 단일출처 = vehicle.salesman_id == 해소 영업(SalesmanResolver). 모든 mutating 매번 재인가.
 * - 알람(jin 2026-06-30 분리): 선적요청=수출통관 / B/L요청·변경요청=관리. (TaskAlarm::scopeVisibleTo·canSeeAlarm 가 target_role 기준이라 관리 자동 가시.)
 */
class ShippingRequestController extends Controller
{
    private function salesman(Request $request)
    {
        return SalesmanResolver::resolveActiveOrFail((string) ($request->input('salesman_email') ?? $request->query('salesman_email', '')));
    }

    /**
     * GET /shippable — 새로 묶을 차 후보만 (판매완료 + export + 아직 어느 open 묶음에도 없음).
     * 기존 묶음(요청된 차)은 /bundles 가 담당(영속). open = requested/in_progress.
     */
    public function shippable(Request $request): JsonResponse
    {
        $sid = $this->salesman($request)->id;

        $inOpenBundle = ShippingRequest::whereIn('status', ShippingRequest::OPEN_STATUSES)
            ->pluck('vehicle_id')->unique()->all();

        $data = Vehicle::query()->whereNull('deleted_at')
            ->where('salesman_id', $sid)
            ->where('sales_channel', 'export')
            ->where('progress_status_cache', '판매완료')
            ->whereNotIn('id', $inOpenBundle)
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

    /**
     * GET /bundles — 영업 본인 묶음 전체(전 상태, 안 사라짐) + 재무 집계.
     * 묶음 미수(unpaid_total_krw·fx_missing_count·fully_paid·unpaid_ratio) = car-erp 권위 계산(board 표시만).
     */
    public function bundles(Request $request): JsonResponse
    {
        $sid = $this->salesman($request)->id;

        $rows = ShippingRequest::query()
            ->where('status', '!=', ShippingRequest::STATUS_CANCELLED)
            ->whereHas('vehicle', fn ($q) => $q->where('salesman_id', $sid)->whereNull('deleted_at'))
            ->with(['vehicle', 'buyer', 'consignee'])
            ->orderByDesc('id')
            ->get();

        $data = $rows->groupBy('batch_id')->map(function ($items) {
            $f = $items->first();
            $vehicles = $items->map->vehicle->filter()->values();
            $fin = $this->bundleFinance($vehicles);

            return array_merge([
                'batch_id' => (string) $f->batch_id,
                'buyer' => $f->buyer?->name,
                'consignee' => $f->consignee?->name,
                'shipping_method' => $f->shipping_method,
                'bl_type' => $f->bl_type,
                'bl_status' => $f->bl_status ?? ShippingRequest::BL_STATUS_NONE,
                'ship_status' => $this->bundleShipStatus($items),
                'change_requested' => $items->contains(fn ($r) => $r->change_requested_at !== null),
                'surrender_unpaid_warning' => $f->bl_type === ShippingRequest::BL_TYPE_SURRENDER && ! $fin['fully_paid'],
                'vehicles' => $items->map(fn ($r) => [
                    'vehicle_id' => $r->vehicle_id,
                    'vehicle_number' => $r->vehicle?->vehicle_number ?? ('#'.$r->vehicle_id),
                    'status' => $r->status,
                ])->values(),
            ], $fin);
        })->sortByDesc(fn ($b) => $b['batch_id'])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * POST /shipping-requests/sync — 선언형 재동기화. board 가 "원하는 묶음 전체(desired)" 전송 → diff.
     * ⚠️ 부분 전송 = 빠진 requested 차 자동취소(footgun). board 는 반드시 전체 desired 전송.
     * diff: 생성 / 갱신(requested) / 자동취소(requested·desired 미포함) / 잠금(in_progress).
     */
    public function sync(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);

        $data = $request->validate([
            'bundles' => ['required', 'array', 'min:1'],
            'bundles.*.buyer_id' => ['nullable', 'integer'],
            'bundles.*.consignee_id' => ['nullable', 'integer'],
            'bundles.*.shipping_method' => ['required', 'in:RORO,CONTAINER'],
            'bundles.*.bl_type' => ['nullable', 'in:original,surrender'],
            'bundles.*.vehicle_ids' => ['required', 'array', 'min:1'],
            'bundles.*.vehicle_ids.*' => ['integer'],
        ]);

        $created = [];
        $updated = [];
        $cancelled = [];
        $skipped = [];
        $locked = [];

        DB::transaction(function () use ($salesman, $data, &$created, &$updated, &$cancelled, &$skipped, &$locked) {
            // 본인 차 open(requested/in_progress) 현재 행 — 트랜잭션 잠금(in_progress 전환 race 차단)
            $current = ShippingRequest::whereIn('status', ShippingRequest::OPEN_STATUSES)
                ->whereHas('vehicle', fn ($q) => $q->where('salesman_id', $salesman->id))
                ->lockForUpdate()->get()->keyBy('vehicle_id');

            $desired = [];   // 이번 sync 에서 살아남을 본인 차 vehicle_id

            foreach ($data['bundles'] as $bundle) {
                // 이 bundle 의 batch_id — 멤버 중 기존 requested 행이 있으면 재사용(안정 묶음 유지), 없으면 새 uuid
                $batchId = null;
                foreach ($bundle['vehicle_ids'] as $vid) {
                    $ex = $current->get($vid);
                    if ($ex && $ex->status === ShippingRequest::STATUS_REQUESTED) {
                        $batchId = $ex->batch_id;
                        break;
                    }
                }
                $batchId ??= (string) Str::uuid();

                foreach ($bundle['vehicle_ids'] as $vid) {
                    // IDOR — 본인 차만(매번 재인가)
                    $owns = Vehicle::where('id', $vid)->where('salesman_id', $salesman->id)->whereNull('deleted_at')->exists();
                    if (! $owns) {
                        $skipped[] = $vid;

                        continue;
                    }
                    $desired[] = $vid;

                    $ex = $current->get($vid);
                    if ($ex && $ex->status === ShippingRequest::STATUS_IN_PROGRESS) {
                        $locked[] = $vid;   // 관리 착수 — board sync 로 못 바꿈(변경요청만)

                        continue;
                    }

                    $attrs = [
                        'batch_id' => $batchId,
                        'buyer_id' => $bundle['buyer_id'] ?? null,
                        'consignee_id' => $bundle['consignee_id'] ?? null,
                        'shipping_method' => $bundle['shipping_method'],
                        'bl_type' => $bundle['bl_type'] ?? null,
                    ];

                    if ($ex && $ex->status === ShippingRequest::STATUS_REQUESTED) {
                        $ex->update($attrs + ['requested_at' => now()]);
                        $this->fireShippingAlarm($ex->vehicle ?? Vehicle::find($vid), $bundle['shipping_method']);
                        $updated[] = $vid;

                        continue;
                    }

                    ShippingRequest::create($attrs + [
                        'vehicle_id' => $vid,
                        'bl_status' => ShippingRequest::BL_STATUS_NONE,
                        'requested_by_email' => $salesman->email,
                        'status' => ShippingRequest::STATUS_REQUESTED,
                        'requested_at' => now(),
                    ]);
                    $this->fireShippingAlarm(Vehicle::find($vid), $bundle['shipping_method']);
                    $created[] = $vid;
                }
            }

            // 자동취소 — 본인 open 'requested' 행 중 desired 에 없는 것 (in_progress 는 자동취소 안 함)
            foreach ($current as $vid => $ex) {
                if ($ex->status === ShippingRequest::STATUS_REQUESTED && ! in_array($vid, $desired, true)) {
                    $ex->update(['status' => ShippingRequest::STATUS_CANCELLED, 'processed_at' => now()]);
                    $this->resolveShippingAlarm($vid);
                    $cancelled[] = $vid;
                }
            }
        });

        return response()->json(compact('created', 'updated', 'cancelled', 'skipped', 'locked'), 200);
    }

    /**
     * POST /bundles/{batch}/bl-request — 기존 묶음 B/L요청(오리지널/써랜더 확정) → bl_status='requested' + 관리 알람.
     * IDOR — batch 의 모든 행이 본인 차여야(불일치 403). 같은 묶음을 재사용(별도 요청 시스템 아님).
     */
    public function blRequest(Request $request, string $batch): JsonResponse
    {
        $salesman = $this->salesman($request);
        $data = $request->validate(['bl_type' => ['required', 'in:original,surrender']]);

        $rows = ShippingRequest::where('batch_id', $batch)->with('vehicle')->get();
        $ownsAll = $rows->isNotEmpty() && $rows->every(fn ($r) => $r->vehicle && (int) $r->vehicle->salesman_id === (int) $salesman->id);
        abort_unless($ownsAll, 403);

        DB::transaction(function () use ($rows, $data) {
            foreach ($rows as $r) {
                $r->update(['bl_type' => $data['bl_type'], 'bl_status' => ShippingRequest::BL_STATUS_REQUESTED]);
            }
        });

        $this->fireBlAlarm($rows->first()->vehicle, $data['bl_type']);

        return response()->json(['ok' => true, 'batch_id' => $batch, 'bl_type' => $data['bl_type'], 'count' => $rows->count()]);
    }

    /**
     * POST /shipping-requests/change-request — in_progress(관리 착수) 차의 명시적 변경/취소 요청.
     * 자동적용 안 함 — 관리가 화면에서 수락/거절. omission 으로 추론 금지(명시 액션만).
     */
    public function changeRequest(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = ShippingRequest::where('vehicle_id', $data['vehicle_id'])
            ->where('status', ShippingRequest::STATUS_IN_PROGRESS)
            ->whereHas('vehicle', fn ($q) => $q->where('salesman_id', $salesman->id))
            ->orderByDesc('id')->first();
        abort_if($row === null, 403);   // 본인 in_progress 묶음 아님(IDOR·상태 불일치)

        $row->update([
            'change_requested_at' => now(),
            'change_request_meta' => ['note' => $data['note'] ?? null, 'requested_by' => $salesman->email],
        ]);
        $this->fireChangeAlarm($row->vehicle);

        return response()->json(['ok' => true, 'vehicle_id' => $data['vehicle_id']]);
    }

    /**
     * @deprecated v1 단발 선적요청 (board 미가동, v2 sync 로 교체 예정). board sync client 배포 후 제거.
     * POST /shipping-request — 본인 차만. 재요청 = 제자리 갱신.
     */
    public function store(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);

        $v = $request->validate([
            'vehicle_ids' => ['required', 'array', 'min:1'],
            'vehicle_ids.*' => ['integer'],
            'buyer_id' => ['nullable', 'integer'],
            'consignee_id' => ['nullable', 'integer'],
            'shipping_method' => ['required', 'in:RORO,CONTAINER'],
        ]);

        $batchId = (string) Str::uuid();
        $created = [];
        $updated = [];
        $skipped = [];
        foreach ($v['vehicle_ids'] as $vid) {
            $vehicle = Vehicle::where('id', $vid)->where('salesman_id', $salesman->id)->first();
            if (! $vehicle) {
                $skipped[] = $vid;

                continue;
            }

            $existing = ShippingRequest::where('vehicle_id', $vid)
                ->whereIn('status', ShippingRequest::OPEN_STATUSES)
                ->orderByDesc('id')->first();

            if ($existing && $existing->status === ShippingRequest::STATUS_IN_PROGRESS) {
                $skipped[] = $vid;

                continue;
            }

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

    /** 선적요청 알람 — 수출통관(현행 유지). 관리도 scopeVisibleTo 로 가시. */
    private function fireShippingAlarm(?Vehicle $vehicle, string $method): void
    {
        if (! $vehicle) {
            return;
        }
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

    /** B/L요청 알람 — 관리(jin 2026-06-30 분리). */
    private function fireBlAlarm(?Vehicle $vehicle, string $blType): void
    {
        if (! $vehicle) {
            return;
        }
        $alarm = TaskAlarm::firstOrNew([
            'type' => 'bl_requested', 'vehicle_id' => $vehicle->id, 'resolved_at' => null,
        ]);
        $alarm->target_role = '관리';
        $alarm->due_date = now();
        $alarm->message_meta = TaskAlarm::sanitizeMeta([
            'vehicle_number' => $vehicle->vehicle_number,
            'bl_type' => $blType,
        ]);
        $alarm->save();
    }

    /** 변경요청 알람 — 관리. */
    private function fireChangeAlarm(?Vehicle $vehicle): void
    {
        if (! $vehicle) {
            return;
        }
        $alarm = TaskAlarm::firstOrNew([
            'type' => 'shipping_change_requested', 'vehicle_id' => $vehicle->id, 'resolved_at' => null,
        ]);
        $alarm->target_role = '관리';
        $alarm->due_date = now();
        $alarm->message_meta = TaskAlarm::sanitizeMeta(['vehicle_number' => $vehicle->vehicle_number]);
        $alarm->save();
    }

    /** 자동취소 시 연동 shipping_requested 알람 resolve. */
    private function resolveShippingAlarm(int $vehicleId): void
    {
        TaskAlarm::where('type', 'shipping_requested')->where('vehicle_id', $vehicleId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_reason' => 'shipping_cancelled']);
    }

    /**
     * 묶음 재무 집계 — 단일출처(SKILLS §13), accessor/cache 만. NULL(환율 미입력) 제외.
     * fully_paid 는 fx_missing 0 일 때만 true(가짜 완납 방지, cash_audit 교훈).
     */
    private function bundleFinance($vehicles): array
    {
        return ShippingRequest::financeForVehicles($vehicles);
    }

    /** 묶음 선적단계 — in_progress > requested > done 우선. */
    private function bundleShipStatus($items): string
    {
        $statuses = $items->pluck('status');
        foreach ([ShippingRequest::STATUS_IN_PROGRESS, ShippingRequest::STATUS_REQUESTED, ShippingRequest::STATUS_DONE] as $s) {
            if ($statuses->contains($s)) {
                return $s;
            }
        }

        return (string) ($statuses->first() ?? ShippingRequest::STATUS_REQUESTED);
    }
}
