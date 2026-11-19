<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Services\BoardPurchaseLockAllowance as Allowance;
use App\Services\PurchaseRegistrationGate;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 🔐 board 발 매입 락 해제 요청 — 권위 = docs/integration/board-portal-api.md §17 (jin 2026-11-19).
 *
 * board 가 락 걸린 바이어로 「N대 더 등록」을 요청 → ERP 승인큐(관리 이상) → 승인된 대수만큼 board 구매확정.
 * 여기는 요청 접수와 상태 조회만 한다. 승인은 승인큐 화면, 차감은 purchase-sync(새 차량).
 * 🚫 금액을 받지 않는다 — 판단은 「이 바이어로 몇 대를 더 등록하게 할 것인가」 하나다.
 */
class PurchaseLockRequestController extends Controller
{
    /**
     * 누구 명의인가 — 영업 본인(그 사람 바이어만) 또는 ERP super 의 `scope=all`(전 바이어).
     * `/buyers` 와 같은 규칙이다(InternalPortalController::superScopeAll). 영업·관리가 scope=all 을 붙여도 403.
     *
     * @return array{0: ?Salesman, 1: bool}
     */
    private function actor(Request $request): array
    {
        $email = (string) $request->input('salesman_email', '');
        if ((string) $request->input('scope', '') === 'all') {
            $user = User::where('email', $email)->first();
            abort_unless($user !== null && $user->isSuperAdmin(), 403, 'Forbidden');

            return [null, true];
        }

        // board 는 super 가 누를 때 POST 에 scope=all 을 안 붙인다(11-19 board 회신) — 영업 매핑이 없고 그 이메일이
        //   이 회사 ERP 의 super 계정이면 전 바이어로 본다. 판정 주체는 여전히 ERP 다(board 의 「super 다」를 믿는 게 아니다).
        $salesman = SalesmanResolver::resolve($email);
        if ($salesman === null || ! $salesman->is_active) {
            abort_unless((bool) User::where('email', $email)->first()?->isSuperAdmin(), 403, 'Forbidden');

            return [null, true];
        }

        return [$salesman, false];
    }

    private function buyerOrFail(int $buyerId, ?Salesman $salesman, bool $all): Buyer
    {
        $buyer = Buyer::query()->where('is_active', true)
            ->when(! $all, fn ($q) => $q->where('salesman_id', $salesman->id))
            ->find($buyerId);
        abort_if($buyer === null, 404, 'Not found');   // 남의 바이어·없는 바이어를 구분해 주지 않는다

        return $buyer;
    }

    /** POST /purchase-lock-requests */
    public function store(Request $request): JsonResponse
    {
        [$salesman, $all] = $this->actor($request);

        $data = $request->validate([
            'buyer_id' => ['required', 'integer'],
            'requested_count' => ['required', 'integer', 'min:1', 'max:'.Allowance::MAX_COUNT],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'requested_by_name' => ['required', 'string', 'max:50'],
            'requested_by_email' => ['required', 'email', 'max:255'],
            'purchase_listing_id' => ['nullable', 'integer'],
            'vehicle_number' => ['nullable', 'string', 'max:255'],
            'vehicle_label' => ['nullable', 'string', 'max:100'],
        ]);

        $buyer = $this->buyerOrFail((int) $data['buyer_id'], $salesman, $all);

        // 락이 아닌 바이어는 요청할 일이 없다 — 판정은 단일 출처(§8 #44).
        if (! PurchaseRegistrationGate::forBuyer($buyer)['locked']) {
            return response()->json(['error' => 'not_locked'], 409);
        }

        // 같은 바이어에 대기 건이 있으면 그 건이 결정될 때까지 새 요청을 받지 않는다. 바이어 행을 잠가 동시 요청을 한 줄로 세운다.
        $result = DB::transaction(function () use ($buyer, $salesman, $data) {
            Buyer::query()->whereKey($buyer->id)->lockForUpdate()->first();
            if ($pending = Allowance::pendingFor($buyer->id)) {
                return ['pending' => $pending];
            }

            $req = ApprovalRequest::create([
                // 영업은 ERP 계정이 없을 수 있다 — 그러면 비우고 payload 의 이름으로 보여 준다.
                'requester_id' => $salesman?->user_id,
                'target_type' => Buyer::class,
                'target_id' => $buyer->id,
                'action_type' => Allowance::TYPE,
                'status' => ApprovalRequest::STATUS_PENDING,
                'reason' => $data['reason'],
                'payload' => [
                    'requested_count' => (int) $data['requested_count'],
                    'used' => [],
                    'buyer_name' => $buyer->name,
                    'salesman_id' => $salesman?->id,
                    'salesman_name' => $salesman?->name,
                    'requested_by_name' => $data['requested_by_name'],
                    'requested_by_email' => mb_strtolower($data['requested_by_email']),
                    'purchase_listing_id' => $data['purchase_listing_id'] ?? null,
                    'vehicle_number' => $data['vehicle_number'] ?? null,
                    'vehicle_label' => $data['vehicle_label'] ?? null,
                ],
            ]);

            AuditLog::create([
                'user_id' => $salesman?->user_id,
                'approval_request_id' => $req->id,
                'auditable_type' => Buyer::class,
                'auditable_id' => $buyer->id,
                'action' => 'board_purchase_lock_requested',
                'column_name' => 'count:'.(int) $data['requested_count'],
                'old_value' => null,
                'new_value' => $data['requested_by_name'].' · '.$data['reason'],
            ]);

            return ['created' => $req];
        });

        if (isset($result['pending'])) {
            return response()->json(['error' => 'already_pending', 'request' => Allowance::present($result['pending'])], 409);
        }

        return response()->json([
            'id' => $result['created']->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_count' => (int) $data['requested_count'],
        ], 201);
    }

    /**
     * POST /purchase-lock-requests/{id}/cancel — 대기 중인 요청을 board 에서 거둔다 (jin 2026-11-19 «3대를 30대로 잘못하면 그쪽에서도 취소»).
     * 결정(승인·거절)된 건은 못 거둔다(409). 범위 = 요청과 같다(그 영업의 바이어, super 전체). 거둔 뒤엔 바로 다시 요청할 수 있다.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        [$salesman, $all] = $this->actor($request);
        $data = $request->validate(['cancelled_by_name' => ['nullable', 'string', 'max:50']]);

        $req = ApprovalRequest::query()->where('action_type', Allowance::TYPE)->where('target_type', Buyer::class)->find($id);
        abort_if($req === null, 404, 'Not found');
        $buyer = $this->buyerOrFail((int) $req->target_id, $salesman, $all);   // 남의 바이어 건은 404

        $done = DB::transaction(function () use ($req, $buyer, $salesman, $data) {
            $row = ApprovalRequest::query()->whereKey($req->id)->lockForUpdate()->first();
            if ($row->status !== ApprovalRequest::STATUS_PENDING) {
                return null;   // 그 사이 승인·거절됐다
            }
            $row->update(['status' => ApprovalRequest::STATUS_CANCELLED, 'decided_at' => now()]);
            AuditLog::create([
                'user_id' => $salesman?->user_id,
                'approval_request_id' => $row->id,
                'auditable_type' => Buyer::class,
                'auditable_id' => $buyer->id,
                'action' => 'board_purchase_lock_cancelled',
                'column_name' => 'count:'.(int) ($row->payload['requested_count'] ?? 0),
                'old_value' => null,
                'new_value' => $data['cancelled_by_name'] ?? ($salesman?->name),
            ]);

            return $row;
        });

        if ($done === null) {
            return response()->json(['error' => 'already_decided', 'request' => Allowance::present($req->fresh('approver'))], 409);
        }

        return response()->json(['id' => $done->id, 'status' => ApprovalRequest::STATUS_CANCELLED]);
    }

    /**
     * GET /purchase-lock-requests
     *   ?buyer_id=  → 그 바이어의 남은 대수 + 최근 요청 한 건(구매확정 직전 재조회)
     *   buyer_id 없이 ?since= → 그 뒤로 결정된(승인·거절) 건 목록(board 종·웹푸시)
     */
    public function index(Request $request): JsonResponse
    {
        [$salesman, $all] = $this->actor($request);

        if ($request->filled('buyer_id')) {
            $buyer = $this->buyerOrFail((int) $request->query('buyer_id'), $salesman, $all);
            $latest = Allowance::latestFor($buyer->id);

            return response()->json([
                'buyer_id' => $buyer->id,
                'allowance' => Allowance::summary($buyer->id),
                'latest' => $latest ? Allowance::present($latest) : null,
            ]);
        }

        try {
            $since = $request->filled('since') ? Carbon::parse((string) $request->query('since')) : now()->subDays(7);
        } catch (\Throwable) {
            abort(422, 'Invalid since');
        }

        $buyers = Buyer::query()->when(! $all, fn ($q) => $q->where('salesman_id', $salesman->id))->pluck('name', 'id');
        $rows = ApprovalRequest::query()->where('action_type', Allowance::TYPE)->where('target_type', Buyer::class)
            ->whereIn('target_id', $buyers->keys())
            ->whereIn('status', [ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_REJECTED])
            ->where('decided_at', '>=', $since)
            ->with('approver:id,name')
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->limit(100)->get();

        return response()->json([
            'data' => $rows->map(fn (ApprovalRequest $r) => Allowance::present($r) + [
                'buyer_id' => (int) $r->target_id,
                'buyer_name' => $buyers[$r->target_id] ?? null,
            ])->values(),
        ]);
    }
}
