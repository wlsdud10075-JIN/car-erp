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
 * board 영업이 보내는 말은 세 가지다 — "이 차 계약금 N원 보내주세요"(purchase_deposit) /
 * "이 차 잔금 N원 보내주세요"(purchase_balance) /
 * "이 바이어 차 N대 대금 넣었으니 확인해주세요"(sale_payment_confirm).
 *
 * 💰 **금액을 받는다 (2026-08-11 개정, §11-2).** 매입 2종은 `amount_krw` 필수 — 받는 사람이 얼마를
 *    보내야 하는지 모르면 신호가 일을 못 끝낸다. 🚫 다만 **표시 전용**이라 회계 컬럼엔 안 쓴다(§11-5).
 * ⚠️ 구 `purchase_payment` 는 deprecated 지만 **계속 수신한다** — board 운영(master)이 아직 그걸
 *    보내는 구버전이고 ERP 가 먼저 배포된다. 여기서 튕기면 board 운영의 입금요청이 통째로 죽는다.
 * - IDOR = vehicle.salesman_id == 해소 영업(SalesmanResolver). 남의 차는 **전량 거부 대신 부분 skip**
 *   — 한 대 때문에 묶음 전체가 죽으면 영업이 원인을 못 찾고 카톡으로 돌아간다.
 * - 멱등 = `BoardRequest::raise()` 단일 지점(같은 차+type 에 open 이 있으면 null).
 *   ⚠️ 멱등키가 `(vehicle_id, type)` 이라 **type 이 갈려야** 계약금이 열린 채로 잔금 요청이 통과한다.
 *   하나의 type 에 하위구분(subtype)을 얹는 설계는 여기서 조용히 skip 돼 작동하지 않는다.
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
     * 매입 신호(계약금·잔금·구 입금요청)는 단위가 차량 1대라 vehicle_ids 를 여러 개 주면
     * **각각 별개 묶음**이 된다. sale_payment_confirm 만 바이어 1명 + N대가 한 묶음(batch_id 공유).
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
            // 표시 전용 금액. 상한 = 1조 미만(오타 방어). 회계엔 안 쓰지만 화면에 그대로 뜨므로
            // 말도 안 되는 자릿수가 들어오면 목록이 깨진다.
            'amount_krw' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $isSaleConfirm = $data['type'] === BoardRequest::TYPE_SALE_PAYMENT_CONFIRM;
        $buyerId = $data['buyer_id'] ?? null;
        $amountKrw = $data['amount_krw'] ?? null;

        if ($isSaleConfirm && ! $buyerId) {
            return response()->json([
                'error' => 'buyer_required',
                'message' => '판매대금확인은 바이어를 지정해야 합니다.',
            ], 422);
        }

        // 금액을 받는 type 은 금액이 **이 기능의 전부**다. 비면 받는 사람이 얼마를 보낼지 알 수 없어
        // 신호가 카톡으로 되돌아간다 ⇒ 조용히 null 로 넘기지 말고 여기서 거절한다.
        if ((BoardRequest::meta($data['type'])['amount'] ?? false) && ! $amountKrw) {
            return response()->json([
                'error' => 'amount_required',
                'message' => '요청 금액을 입력해야 합니다.',
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

        DB::transaction(function () use ($data, $vehicles, $salesman, $buyerId, $amountKrw, $isSaleConfirm, $batchId, &$created, &$skipped) {
            foreach ($data['vehicle_ids'] as $vid) {
                $vehicle = $vehicles->get($vid);
                if (! $vehicle) {
                    $skipped[] = ['vehicle_id' => (int) $vid, 'reason' => 'forbidden'];

                    continue;
                }

                $row = BoardRequest::raise(
                    vehicleId: $vehicle->id,
                    type: $data['type'],
                    requestedByEmail: (string) $salesman->email,
                    buyerId: $isSaleConfirm ? $buyerId : null,
                    // 입금요청은 1대 = 1묶음이라 차량마다 새 uuid. 판매대금확인만 batch 를 공유한다.
                    batchId: $isSaleConfirm ? $batchId : null,
                    note: $data['note'] ?? null,
                    amountKrw: $amountKrw,
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
     *
     * 마진·PII 없음(§3 화이트리스트) — 차량번호·상태·시각 + **요청 금액**뿐.
     * ⚠️ `amount_krw` 는 반드시 실어 준다 — board 는 전송 성공 시 입력칸을 비우므로, 응답에 금액이
     *    없으면 **요청한 본인이 "얼마 요청했지?" 를 어디에서도 볼 수 없다**(금액이 이 기능의 전부인데).
     *    board 가 자기 화면에 저장해 두게 하는 건 판정 지점이 둘로 갈리는 길이라 하지 않는다.
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
                // 매입 신호는 1대 = 1묶음이라 묶음 금액 = 그 한 줄의 금액. 판매대금확인은 null.
                // 라인에도 같은 값을 싣는다 — board 가 어느 쪽을 읽든 같은 숫자가 나오게.
                'amount_krw' => $head->amount_krw,
                'requested_at' => $head->requested_at?->toIso8601String(),
                'vehicles' => $lines->map(fn (BoardRequest $r) => [
                    'vehicle_number' => $r->vehicle?->vehicle_number,
                    'status' => $r->status,
                    'amount_krw' => $r->amount_krw,
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
            $line->resolveTaskAlarm('cancelled');   // 취소한 일이 벨에 계속 남지 않게
        }

        return response()->json(['ok' => true, 'cancelled' => $lines->count()]);
    }
}
