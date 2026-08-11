<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\ForwardingCompany;
use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
     * GET /shippable — 새로 묶을 차 후보 (export + 아직 어느 open 묶음에도 없음).
     * 기존 묶음(요청된 차)은 /bundles 가 담당(영속). open = requested/in_progress.
     *
     * 🔀 **후보 확대 (board 인계 2026-08-11 요청 ①)** — 구 조건 `progress_status_cache='판매완료'`
     *    는 **판매대금 완납**을 뜻해서, 미수가 남은 차는 화면에 아예 안 떴다. 영업은 돈이 들어오기 전에
     *    미리 묶어두고 서류를 준비하려 한다(jin). 그래서 **`sale_price > 0`**(판매중 + 판매완료)로 넓힌다.
     *
     * ⚠️ **"이미 떠난 차"는 구조로 배제한다 — 진행상태 라벨로 좁히지 않는다.**
     *    라벨을 쓰면 v3 grandfather(`수출통관중` 등)에서 조용히 빠진다(운항 상태에서 내린 판단과 같은 이유).
     *      - `bl_loading_location` 있음 = 반입(항구 스테이징) 시작 → 계획 단계가 아니다
     *      - `bl_document` 있음 = B/L 발급 = 거래완료
     *    v4 cascade 상 **`판매완료`는 이 둘이 모두 비어야만 도달**하므로, 이 조건은 종전 후보를 하나도
     *    떨어뜨리지 않는 **순수 확대**다(가드 = `BoardShippableScopeTest`). board 가 요청한
     *    "출고 후 차가 후보로 돌아오면 안 된다" 도 같은 뜻으로 충족된다 — 새로 돌아오는 차가 없다.
     *    🚫 `warehouse_out_date` 로 거르지 않는다: 운영에 **반입지 없이 출고일만 찍힌 차**가 많아
     *       (heymanerp 실측) 지금 보이던 후보가 대량으로 사라진다(SKILLS §8 #38 형태).
     *
     * 📊 **미수 필드 동봉 (요청 ②)** — 완납 차만 오던 시절엔 없어도 됐지만, 이제 영업이 어느 차가
     *    미완납인지 모른 채 묶게 된다. 이름은 `/sales`·`/receivables` 와 **같게** 쓴다(§8 #44).
     *    ⚠️ `unpaid_krw` 는 **null 을 그대로 흘린다** — 환율 미입력이라 완납 판정이 불가능하다는 뜻이고,
     *       0 으로 바꾸면 가짜 완납이 된다(§5-4 의 그 버그).
     */
    public function shippable(Request $request): JsonResponse
    {
        $sid = $this->salesman($request)->id;

        $inOpenBundle = ShippingRequest::whereIn('status', ShippingRequest::OPEN_STATUSES)
            ->pluck('vehicle_id')->unique()->all();

        $data = Vehicle::query()->whereNull('deleted_at')
            ->where('salesman_id', $sid)
            ->where('sales_channel', 'export')
            ->where('sale_price', '>', 0)
            ->whereNull('bl_loading_location')
            ->whereNull('bl_document')
            ->whereNotIn('id', $inOpenBundle)
            ->with('buyer.consignees')
            ->get()
            ->map(fn (Vehicle $v) => Vehicle::portalMeta($v) + [
                'vehicle_id' => $v->id,
                'vehicle_number' => $v->vehicle_number,
                'buyer' => $v->buyer ? ['id' => $v->buyer->id, 'name' => $v->buyer->name] : null,
                'consignees' => $v->buyer
                    ? $v->buyer->consignees->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()
                    : [],
                'currency' => $v->currency,
                'unpaid_krw' => $v->sale_unpaid_amount_krw_cache,   // null = 환율 미입력 (완납 아님)
                'unpaid_ratio' => $v->unpaid_ratio,                 // 0~1 | 0.0(완납) | null
                'fully_paid' => $v->sale_unpaid_amount_krw_cache !== null && $v->sale_unpaid_amount_krw_cache <= 0,
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * GET /forwarding-companies — 포워딩사 명부(활성만, `{id,name}`).
     *
     * board 는 **고르기만 한다 — 신규 생성 없음**(jin 2026-08-11). 명부에 없으면 ERP 에서 등록한다.
     * 오타·중복 등록이 지급 명부를 오염시키는 경로를 아예 안 만들기 위해서다.
     * ⚠️ 담당자·연락처·주소는 내보내지 않는다(§3 화이트리스트) — 드롭다운에 필요한 건 이름뿐이다.
     * 스코프 없음(회사 공용 명부) — 인증은 HMAC.
     */
    public function forwardingCompanies(): JsonResponse
    {
        $data = ForwardingCompany::query()->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ForwardingCompany $c) => ['id' => $c->id, 'name' => $c->name])
            ->values();

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
            ->with(['vehicle', 'buyer.consignees', 'consignee', 'forwardingCompany'])
            ->orderByDesc('id')
            ->get();

        $data = $rows->groupBy('batch_id')->map(function ($items) {
            $f = $items->first();
            $vehicles = $items->map->vehicle->filter()->values();
            $fin = $this->bundleFinance($vehicles);

            return array_merge([
                'batch_id' => (string) $f->batch_id,
                // board 선언형 sync 재전송용 — id 포함 객체 필수(이름만이면 buyer_id 몰라 자동취소 footgun)
                'buyer' => $f->buyer ? ['id' => $f->buyer->id, 'name' => $f->buyer->name] : null,
                'consignee' => $f->consignee ? ['id' => $f->consignee->id, 'name' => $f->consignee->name] : null,
                'consignees' => $f->buyer
                    ? $f->buyer->consignees->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()
                    : [],
                'shipping_method' => $f->shipping_method,
                // 묶음 속성 되돌려주기 — 없으면 board 가 묶음을 다시 열었을 때 칸이 비어 보인다(그리고
                //   그대로 재전송하면 값이 날아간다). buyer 와 같은 이유로 **id 포함 객체**로 준다.
                'forwarding_company' => $f->forwardingCompany
                    ? ['id' => $f->forwardingCompany->id, 'name' => $f->forwardingCompany->name]
                    : null,
                'transport_fee_usd_total' => $f->transport_fee_usd_total,
                'bl_type' => $f->bl_type,
                'bl_status' => $f->bl_status ?? ShippingRequest::BL_STATUS_NONE,
                'ship_status' => $this->bundleShipStatus($items),
                'change_requested' => $items->contains(fn ($r) => $r->change_requested_at !== null),
                'surrender_unpaid_warning' => $f->bl_type === ShippingRequest::BL_TYPE_SURRENDER && ! $fin['fully_paid'],
                // ⚠️ 묶음 **안의 차량 배열**에도 필요하다 — 여기가 board 묶음 pill·변경요청 행이다.
                //    `$r->vehicle` 은 null 일 수 있어 portalMeta 가 null-safe 다.
                'vehicles' => $items->map(fn ($r) => Vehicle::portalMeta($r->vehicle) + [
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
     * ⚠️ 빈 배열(bundles:[]) = 마지막 묶음 취소 = 본인 requested 전체 자동취소 (present·min:1 제거).
     */
    public function sync(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);

        $data = $request->validate([
            'bundles' => ['present', 'array'],
            'bundles.*.buyer_id' => ['nullable', 'integer'],
            'bundles.*.consignee_id' => ['nullable', 'integer'],
            'bundles.*.shipping_method' => ['required', 'in:RORO,CONTAINER'],
            'bundles.*.bl_type' => ['nullable', 'in:original,surrender'],
            // 포워딩사 (요청 ③) — **활성 명부에 있는 id 만.** board 가 드롭다운에서 고른다는 건 검증이
            //   아니다(§8 #28 assertCostCompanyValid 와 같은 이유). 여기서 422 로 끊어야 지급 명부가 안 더러워진다.
            'bundles.*.forwarding_company_id' => ['nullable', 'integer',
                Rule::exists('forwarding_companies', 'id')->where('is_active', true)->whereNull('deleted_at')],
            // 컨테이너 운임비 USD 총액 (요청 ④) — 아래에서 RORO 면 버린다(board 를 믿지 않는다).
            'bundles.*.transport_fee_usd_total' => ['nullable', 'integer', 'min:0', 'max:99999999'],
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

                // 컨테이너 묶음에서만 운임비를 받는다(jin 확정). RORO 면 board 가 안 보내지만 믿지 않는다.
                $freightTotal = $bundle['shipping_method'] === 'CONTAINER'
                    ? ($bundle['transport_fee_usd_total'] ?? null)
                    : null;
                $forwardingId = $bundle['forwarding_company_id'] ?? null;
                // 1/N 분모 = 이 묶음 **전체** 대수(이미 값이 있어 건너뛸 차도 포함) — ShippingRequest::splitFreightUsd 주석.
                $freightShare = ShippingRequest::splitFreightUsd($freightTotal, $bundle['vehicle_ids']);

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
                        'forwarding_company_id' => $forwardingId,
                        'transport_fee_usd_total' => $freightTotal,
                    ];

                    if ($ex && $ex->status === ShippingRequest::STATUS_REQUESTED) {
                        $forwardingChanged = (int) $ex->forwarding_company_id !== (int) $forwardingId;
                        $ex->update($attrs + ['requested_at' => now()]);
                        $this->applyBundleToVehicle($vid, $forwardingId, $forwardingChanged, $freightShare[$vid] ?? null);
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
                    $this->applyBundleToVehicle($vid, $forwardingId, true, $freightShare[$vid] ?? null);
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
     * POST /bundles/{batch}/bl-request 의 무름 — 영업이 B/L요청 오발송 시 되돌림(bl_status requested→none).
     * 이미 관리가 발급(issued)했으면 409(되돌릴 수 없음 — 관리에게 문의). IDOR — batch 의 모든 행이 본인 차.
     */
    public function blCancel(Request $request, string $batch): JsonResponse
    {
        $salesman = $this->salesman($request);

        $rows = ShippingRequest::where('batch_id', $batch)->with('vehicle')->get();
        $ownsAll = $rows->isNotEmpty() && $rows->every(fn ($r) => $r->vehicle && (int) $r->vehicle->salesman_id === (int) $salesman->id);
        abort_unless($ownsAll, 403);

        if ($rows->contains(fn ($r) => $r->bl_status === ShippingRequest::BL_STATUS_ISSUED)) {
            return response()->json(['ok' => false, 'reason' => 'already_issued'], 409);   // 관리 발급 후 = 무름 불가
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                $r->update(['bl_status' => ShippingRequest::BL_STATUS_NONE]);   // bl_type 은 유지(재요청 prefill)
            }
        });

        TaskAlarm::where('type', 'bl_requested')->whereIn('vehicle_id', $rows->pluck('vehicle_id'))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_reason' => 'bl_request_cancelled']);

        return response()->json(['ok' => true, 'batch_id' => $batch, 'count' => $rows->count()]);
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

    /**
     * 묶음 속성을 **차량 원장에 반영** — board 가 `vehicles` 컬럼에 쓰는 첫 사례라 규칙을 여기 한 곳에 둔다.
     *
     * 🔓 **왜 §5-0 「vehicles 적재 금지」에 안 걸리나**: 그 금지는 `export_buyer_id` 처럼
     *    **C4/C5 게이트 트리거**(`guardStageOrderForExport` 의 `$hasExportInput`)인 컬럼 얘기다.
     *    이 둘은 트리거가 아니고 `LEDGER_LOCK_FIELDS` 도 아니라 저장이 막히지 않는다(2026-08-12 실검증).
     *    또 C4/C5 는 `saving` 훅이 아니라 UI `save()` 에서만 호출된다 — API 저장은 안 탄다.
     *
     * 🧭 **포워딩사 = "바뀌었을 때만" 밀어 넣는다**(오버라이트 아님).
     *    sync 는 선언형이라 저장할 때마다 전체가 다시 온다. 매번 덮으면 **관리가 ERP 에서 고친 값이
     *    다음 sync 때 조용히 되돌아간다.** 그래서 묶음에 적힌 값이 실제로 달라졌을 때만 반영한다
     *    — 영업의 변경은 그대로 반영되고, 관리의 정정은 살아남는다.
     *    ⚠️ 채우는 순간 그 차가 관리 할 일 큐 `forwarding_missing` 에서 빠진다(jin: 그래도 된다).
     *       대신 값 변경이 감사로그에 남아 "누가 골랐나" 를 추적할 수 있다(AUDITED_COLUMNS 등록).
     *
     * 💵 **운임비 = 비어 있을 때만**(jin 확정). 이미 값이 있으면 건너뛴다 — 위와 같은 이유이고,
     *    그래서 합계가 총액과 안 맞을 수 있다(의도된 결과, `splitFreightUsd` 주석).
     *    NULL 과 0 을 **둘 다 빈 것으로** 본다(0 을 '입력된 값' 으로 보면 영영 안 채워진다).
     *
     * ⚠️ 모델 `update()` 를 쓴다 — raw update 면 감사·캐시 훅이 안 뜬다(SKILLS §8 #43).
     */
    private function applyBundleToVehicle(int $vehicleId, ?int $forwardingId, bool $forwardingChanged, ?int $freightShare): void
    {
        $vehicle = Vehicle::find($vehicleId);
        if (! $vehicle) {
            return;
        }

        $attrs = [];
        if ($forwardingChanged && $forwardingId) {
            $attrs['forwarding_company_id'] = $forwardingId;
        }
        if ($freightShare && ! $vehicle->transport_fee_usd) {
            $attrs['transport_fee_usd'] = $freightShare;
        }

        if ($attrs !== []) {
            $vehicle->update($attrs);
        }
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
