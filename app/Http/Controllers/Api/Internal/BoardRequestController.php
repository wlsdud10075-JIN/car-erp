<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\BoardRequest;
use App\Models\Vehicle;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * board → erp 요청·확인 신호 (카톡 대체). 권위 스펙 = docs/integration/board-portal-api.md §11.
 *
 * board 영업이 보내는 건 **두 마디뿐**이다 — "이 차 입금해주세요"(purchase_payment) /
 * "이 바이어 차 N대 대금 넣었으니 확인해주세요"(sale_payment_confirm).
 *
 * 🚫 **금액을 받지 않는다**(§11-2). board 가 보내도 validate 화이트리스트에 없어 버려진다.
 *    매입 지급액·판매 N잔금 기입은 전부 erp 관리 이상의 일이다.
 * - IDOR = vehicle.salesman_id == 해소 영업(SalesmanResolver). 남의 차는 **전량 거부 대신 부분 skip**
 *   — 한 대 때문에 묶음 전체가 죽으면 영업이 원인을 못 찾고 카톡으로 돌아간다.
 * - 멱등 = `BoardRequest::open()` 단일 지점(같은 차+type 에 open 이 있으면 null).
 */
class BoardRequestController extends Controller
{
    private function salesman(Request $request)
    {
        return SalesmanResolver::resolveActiveOrFail(
            (string) ($request->input('salesman_email') ?? $request->query('salesman_email', ''))
        );
    }

    /**
     * POST /requests — 신호 보내기.
     *
     * purchase_payment 는 단위가 차량 1대라 vehicle_ids 를 여러 개 주면 **각각 별개 묶음**이 된다.
     * sale_payment_confirm 은 바이어 1명 + N대가 한 묶음(batch_id 공유).
     */
    public function store(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', BoardRequest::TYPES)],
            'vehicle_ids' => ['required', 'array', 'min:1'],
            'vehicle_ids.*' => ['integer'],
            'buyer_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $isSaleConfirm = $data['type'] === BoardRequest::TYPE_SALE_PAYMENT_CONFIRM;
        $buyerId = $data['buyer_id'] ?? null;

        if ($isSaleConfirm && ! $buyerId) {
            return response()->json([
                'error' => 'buyer_required',
                'message' => '판매대금확인은 바이어를 지정해야 합니다.',
            ], 422);
        }

        // 본인 차량만 (IDOR). 남의 차·없는 차는 아래에서 skipped 로 응답.
        $vehicles = Vehicle::whereIn('id', $data['vehicle_ids'])
            ->where('salesman_id', $salesman->id)
            ->get()->keyBy('id');

        // 오배치 방지 — 한 묶음에 서로 다른 바이어의 차를 담지 못한다(§11-3).
        if ($isSaleConfirm) {
            $mismatch = $vehicles->filter(fn (Vehicle $v) => (int) $v->buyer_id !== (int) $buyerId);
            if ($mismatch->isNotEmpty()) {
                return response()->json([
                    'error' => 'buyer_mismatch',
                    'message' => '지정한 바이어의 차량이 아닙니다: '.$mismatch->pluck('vehicle_number')->implode(', '),
                ], 422);
            }
        }

        $batchId = (string) Str::uuid();
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($data, $vehicles, $salesman, $buyerId, $isSaleConfirm, $batchId, &$created, &$skipped) {
            foreach ($data['vehicle_ids'] as $vid) {
                $vehicle = $vehicles->get($vid);
                if (! $vehicle) {
                    $skipped[] = ['vehicle_id' => (int) $vid, 'reason' => 'forbidden'];

                    continue;
                }

                $row = BoardRequest::open(
                    vehicleId: $vehicle->id,
                    type: $data['type'],
                    requestedByEmail: (string) $salesman->email,
                    buyerId: $isSaleConfirm ? $buyerId : null,
                    // 입금요청은 1대 = 1묶음이라 차량마다 새 uuid. 판매대금확인만 batch 를 공유한다.
                    batchId: $isSaleConfirm ? $batchId : null,
                    note: $data['note'] ?? null,
                );

                if ($row === null) {
                    $skipped[] = ['vehicle_number' => $vehicle->vehicle_number, 'reason' => 'already_open'];

                    continue;
                }

                $created[] = $vehicle->vehicle_number;
            }
        });

        return response()->json([
            'batch_id' => $isSaleConfirm ? $batchId : null,
            'created' => $created,
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * GET /requests — 상태 폴링(board 칩 갱신).
     * 금액·마진·PII 없음(§3 화이트리스트) — 차량번호·상태·시각뿐.
     */
    public function index(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);
        $status = (string) $request->query('status', 'open');

        $rows = BoardRequest::query()
            ->whereHas('vehicle', fn ($q) => $q->where('salesman_id', $salesman->id))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['vehicle:id,vehicle_number', 'buyer:id,name'])
            ->orderByDesc('id')
            ->get();

        $data = $rows->groupBy('batch_id')->map(function ($lines) {
            $head = $lines->first();

            return [
                'batch_id' => $head->batch_id,
                'type' => $head->type,
                'status' => BoardRequest::batchStatus($lines),
                'buyer_name' => $head->buyer?->name,
                'requested_at' => $head->requested_at?->toIso8601String(),
                'vehicles' => $lines->map(fn (BoardRequest $r) => [
                    'vehicle_number' => $r->vehicle?->vehicle_number,
                    'status' => $r->status,
                    'confirmed_at' => $r->confirmed_at?->toIso8601String(),
                ])->values(),
            ];
        })->values();

        return response()->json(['count' => $data->count(), 'requests' => $data]);
    }

    /**
     * POST /requests/{batch}/cancel — 오클릭 무름.
     * open 라인만 취소하고 이미 done 인 라인은 남긴다(회신 기록 보존).
     */
    public function cancel(Request $request, string $batchId): JsonResponse
    {
        $salesman = $this->salesman($request);

        $lines = BoardRequest::where('batch_id', $batchId)
            ->whereHas('vehicle', fn ($q) => $q->where('salesman_id', $salesman->id))
            ->open()->get();

        if ($lines->isEmpty()) {
            return response()->json([
                'error' => 'nothing_to_cancel',
                'message' => '취소할 열린 요청이 없습니다.',
            ], 409);
        }

        // bulk update 는 모델 이벤트가 안 뜬다(SKILLS §2) — 건별 update 로 정상 경로 유지.
        foreach ($lines as $line) {
            $line->update(['status' => BoardRequest::STATUS_CANCELLED]);
        }

        return response()->json(['ok' => true, 'cancelled' => $lines->count()]);
    }
}
