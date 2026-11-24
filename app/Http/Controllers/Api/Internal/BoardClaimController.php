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
 * board → ERP 클레임 미러 (jin 2026-11-11, docs/design/claim-management.md §10 · 받는 쪽 스펙 = docs/integration/board-portal-api.md §15·§20).
 *
 *   POST /api/internal/board/claims  — board 결재선 최종 승인 뒤 클레임 담당자가 [ERP 로 보내기] 를 눌렀을 때.
 *   인증 = VerifyBoardReadHmac(본문까지 서명). 멱등 = board_claim_id. 같은 id 를 다시 보내면 revision 이 더 클 때만 갱신.
 *   금액 = 확정 금액 하나(confirmed_amount). 기각(declined) = 0 으로 받는다(횟수·타당성 지표용).
 *   바이어 = 본문 buyer_id → 없으면 그 차의 바이어 → 둘 다 없으면 422(ERP 에서 바이어를 먼저 정한다).
 *   🔒 판매탭 「클레임 사용」으로 이미 쓴 클레임은 더 높은 회차가 와도 바꾸지 않는다(409 used — jin 11-24).
 *   🚫 적립금·미수·정산에 손대지 않는다 — 기록만.
 *
 *   POST /api/internal/board/claims/bundle — 같은 바이어의 클레임 N건을 한 번에(jin 11-24, §20). 전부 받거나 전부 거절.
 *   묶음 표는 없다 — 행마다 board_bundle_id 를 남기고 합계는 화면이 더한다.
 */
class BoardClaimController extends Controller
{
    private const ITEM_RULES = [
        'board_claim_id' => ['required', 'integer', 'min:1'],
        'revision' => ['required', 'integer', 'min:1'],
        'vehicle_id' => ['nullable', 'integer'],
        'buyer_id' => ['nullable', 'integer'],
        'buyer_name' => ['nullable', 'string', 'max:120'],
        'vehicle_number' => ['nullable', 'string', 'max:30'],
        'vin' => ['nullable', 'string', 'max:30'],
        'confirmed_amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        'currency' => ['required', 'string', 'currency'],
        'compensation_type' => ['nullable', 'string', 'type'],
        'result' => ['required', 'string', 'result'],
        'result_reason' => ['nullable', 'string', 'max:5000'],
        'approved_at' => ['nullable', 'date'],
        'approved_by_name' => ['nullable', 'string', 'max:100'],
        'handler_name' => ['nullable', 'string', 'max:100'],
        'salesman_email' => ['nullable', 'string', 'max:150'],
        'buyer_claim_count' => ['nullable', 'integer', 'min:0'],
        'board_url' => ['nullable', 'string', 'max:300'],
    ];

    /** @return array<string, list<string>> */
    private static function itemRules(string $prefix = ''): array
    {
        $in = ['currency' => VehicleClaim::CURRENCIES, 'type' => VehicleClaim::TYPES, 'result' => VehicleClaim::RESULTS];
        $rules = [];
        foreach (self::ITEM_RULES as $k => $r) {
            $rules[$prefix.$k] = array_map(fn ($x) => isset($in[$x]) ? 'in:'.implode(',', $in[$x]) : $x, $r);
        }

        return $rules;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::itemRules());

        $buyer = $this->resolveBuyer($data);
        if (! $buyer) {
            return response()->json(['status' => 'rejected', 'message' => 'ERP 에서 이 차의 바이어를 먼저 정해 주세요(바이어를 알 수 없는 클레임은 받지 않습니다).'], 422);
        }
        $attrs = $this->attrs($data, $buyer);

        return DB::transaction(function () use ($data, $attrs) {
            $claim = VehicleClaim::where('board_claim_id', $data['board_claim_id'])->lockForUpdate()->first();
            if ($block = $this->blocker($claim, $attrs['revision'])) {
                return response()->json(['erp_claim_id' => $claim->id] + $block, 409);
            }
            if ($claim && $claim->revision === $attrs['revision']) {
                return response()->json(['erp_claim_id' => $claim->id, 'status' => 'unchanged']);   // 같은 회차 재전송 = 멱등
            }
            [$claim, $status] = $this->upsert($claim, $data['board_claim_id'], $attrs);

            return response()->json(['erp_claim_id' => $claim->id, 'status' => $status], $status === 'created' ? 201 : 200);
        });
    }

    public function bundle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'board_bundle_id' => ['required', 'string', 'max:40'],
            'buyer_id' => ['required', 'integer'],
            'currency' => ['required', 'string', 'in:'.implode(',', VehicleClaim::CURRENCIES)],
            'total' => ['required', 'numeric', 'min:0'],
            'requested_by_email' => ['nullable', 'string', 'max:150'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
        ] + self::itemRules('items.*.'));

        $bundleId = $data['board_bundle_id'];
        $reject = fn (string $msg, int $code = 422, array $extra = []) => response()->json($extra + ['board_bundle_id' => $bundleId, 'status' => 'rejected', 'message' => $msg], $code);

        $buyer = Buyer::find($data['buyer_id']);
        if (! $buyer) {
            return $reject('ERP 에 없는 바이어입니다.');
        }
        $ids = array_column($data['items'], 'board_claim_id');
        if (count($ids) !== count(array_unique($ids))) {
            return $reject('같은 클레임이 묶음에 두 번 들어 있습니다.');
        }

        $prepared = [];
        $sum = 0.0;
        foreach ($data['items'] as $i => $item) {
            $item['buyer_id'] ??= $buyer->id;
            $itemBuyer = $this->resolveBuyer($item);
            if (! $itemBuyer || $itemBuyer->id !== $buyer->id) {
                return $reject("항목 {$i}(클레임 {$item['board_claim_id']})의 바이어가 묶음 바이어와 다릅니다.", 422, ['board_claim_id' => $item['board_claim_id']]);
            }
            if ($item['currency'] !== $data['currency']) {
                return $reject("항목 {$i}(클레임 {$item['board_claim_id']})의 통화가 묶음 통화와 다릅니다.", 422, ['board_claim_id' => $item['board_claim_id']]);
            }
            $attrs = $this->attrs($item, $buyer) + ['board_bundle_id' => $bundleId];
            $sum += (float) $attrs['confirmed_amount'];
            $prepared[] = [$item['board_claim_id'], $attrs];
        }
        if (abs($sum - (float) $data['total']) > 0.005) {
            return $reject('묶음 합계('.$data['total'].')가 항목 합('.round($sum, 2).')과 다릅니다.');
        }

        return DB::transaction(function () use ($prepared, $ids, $bundleId, $data, $reject) {
            $existing = VehicleClaim::whereIn('board_claim_id', $ids)->lockForUpdate()->get()->keyBy('board_claim_id');

            $members = VehicleClaim::where('board_bundle_id', $bundleId)->pluck('board_claim_id')->all();
            if ($members && (array_diff($members, $ids) || array_diff($ids, $members))) {
                return $reject('같은 묶음 id 로 이미 받은 묶음과 항목 구성이 다릅니다.', 409, ['status' => 'bundle_mismatch']);
            }
            foreach ($prepared as [$boardId, $attrs]) {
                $claim = $existing->get($boardId);
                if ($claim && $claim->board_bundle_id !== null && $claim->board_bundle_id !== $bundleId) {
                    return $reject("클레임 {$boardId} 은 이미 다른 묶음({$claim->board_bundle_id})에 들어 있습니다.", 409, ['status' => 'other_bundle', 'board_claim_id' => $boardId]);
                }
                if ($block = $this->blocker($claim, $attrs['revision'])) {
                    return $reject("클레임 {$boardId}: ".$block['message'], 409, ['status' => $block['status'], 'board_claim_id' => $boardId]);
                }
            }

            $items = [];
            foreach ($prepared as [$boardId, $attrs]) {
                $claim = $existing->get($boardId);
                if ($claim && $claim->revision === $attrs['revision']) {
                    $status = 'unchanged';
                    if ($claim->board_bundle_id === null) {   // 단건으로 먼저 받은 같은 회차 = 묶음에 연결만
                        $claim->update(['board_bundle_id' => $bundleId]);
                        $this->audit($claim, 'linked '.$bundleId, $data['requested_by_email'] ?? null);
                        $status = 'linked';
                    }
                } else {
                    [$claim, $status] = $this->upsert($claim, $boardId, $attrs, $data['requested_by_email'] ?? null);
                }
                $items[] = ['board_claim_id' => $boardId, 'erp_claim_id' => $claim->id, 'status' => $status];
            }

            $created = collect($items)->contains(fn ($i) => $i['status'] === 'created');

            return response()->json(['board_bundle_id' => $bundleId, 'status' => 'accepted', 'items' => $items], $created ? 201 : 200);
        });
    }

    private function resolveBuyer(array $data): ?Buyer
    {
        $vehicle = isset($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
        $buyer = isset($data['buyer_id']) ? Buyer::find($data['buyer_id']) : null;   // 지워진 바이어는 find 가 null
        if (! $buyer && $vehicle?->buyer_id) {
            $buyer = Buyer::find($vehicle->buyer_id);
        }

        return $buyer;
    }

    private function attrs(array $data, Buyer $buyer): array
    {
        $vehicle = isset($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;

        return [
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
    }

    /** 받으면 안 되는 재전송 — 더 옛 회차 · 이미 판매탭에서 쓴 클레임의 새 회차. */
    private function blocker(?VehicleClaim $claim, int $revision): ?array
    {
        if (! $claim) {
            return null;
        }
        if ($claim->revision > $revision) {
            return ['status' => 'stale', 'message' => "ERP 에 더 새 회차({$claim->revision})가 있습니다."];
        }
        if ($claim->revision < $revision && $claim->usages()->exists()) {
            return ['status' => 'used', 'message' => 'ERP 판매탭에서 이미 미수에 쓴 클레임이라 금액·결과를 바꿀 수 없습니다.'];
        }

        return null;
    }

    /** @return array{0: VehicleClaim, 1: string} */
    private function upsert(?VehicleClaim $claim, int $boardId, array $attrs, ?string $requestedBy = null): array
    {
        $status = $claim ? 'updated' : 'created';
        $claim = $claim ? tap($claim)->update($attrs) : VehicleClaim::create($attrs + ['board_claim_id' => $boardId]);
        $this->audit($claim, $attrs['result'].' '.$attrs['currency'].' '.$attrs['confirmed_amount'].' r'.$attrs['revision'].(isset($attrs['board_bundle_id']) ? ' '.$attrs['board_bundle_id'] : ''), $requestedBy, $attrs['handler_name']);

        return [$claim, $status];
    }

    private function audit(VehicleClaim $claim, string $new, ?string $requestedBy, ?string $handler = null): void
    {
        AuditLog::create([
            'user_id' => null,
            'auditable_type' => VehicleClaim::class, 'auditable_id' => $claim->id,
            'action' => 'board_claim_received', 'column_name' => 'confirmed_amount',
            'old_value' => (string) ($handler ?? $requestedBy ?? $claim->handler_name ?? ''), 'new_value' => $new,
            'ip_address' => request()->ip(),
        ]);
    }
}
