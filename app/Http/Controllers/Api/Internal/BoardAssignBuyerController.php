<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BoardPurchaseLockAllowance;
use App\Services\PurchaseRegistrationGate;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 🧾 재고매입(바이어 미정) 차에 board 가 바이어 + 판매 금액을 지정해 보낸다 (jin 2026-11-24). 권위 = board-portal-api.md §19.
 *
 * 왜 전용 경로인가: purchase-sync 재전송(fillEmptyFields)으로 `buyer_id` 를 채우는 길엔 매입 등록 락 검사가 없다
 * (purchase-sync-receiver.md 「재고매입」 — 그래서 board 는 재고매입 차엔 바이어를 안 실었다). 그 길을 넓히지 않고,
 * 락 판정을 **ERP 가 직접** 하는 좁은 문을 따로 낸다.
 *
 * 규칙 (jin 2026-11-24 board 세션 결정 + 이 세션 초안 승인):
 *  - 바이어와 판매 금액을 **함께** 받는다 → 일반재고 → 선적전 재고. 판매일 = 받은 날(신규 purchase-sync 와 같은 판단).
 *  - 락 = 구매확정과 똑같이: 락 걸린 바이어는 남은 승인 대수(§17)가 있을 때만 통과, 통과하면 1대 차감.
 *    락이 아닌 바이어도 살아 있는 승인이 있으면 1대 차감(§17 「단순 카운트」 — 새로 바이어가 붙은 차 1대 = 1대).
 *  - 이미 바이어가 있는 차·매입취소 차·정산이 확정된 차는 거절(사유 코드). 바이어·컨사이니는 그 영업의 것만.
 *  - 스코프 = §2 본인 차만(남의 차·없는 차·지운 차 = 403, 존재 비노출). super `scope=all` 은 안 본다(쓰기).
 *  - 행위자 = `requested_by_email` 의 User, 없으면 `salesman_email` 의 User(§8 #56).
 * 🚫 판정식을 옮겨 적지 않는다 — 락은 PurchaseRegistrationGate, 대수는 BoardPurchaseLockAllowance 만 쓴다.
 */
class BoardAssignBuyerController extends Controller
{
    private const SALE_CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'];

    public function __invoke(Request $request, int $id): JsonResponse
    {
        $email = (string) $request->input('salesman_email', '');
        $salesman = SalesmanResolver::resolveActiveOrFail($email);

        $data = $request->validate([
            'buyer_id' => ['required', 'integer'],
            'consignee_id' => ['nullable', 'integer'],
            'sale_price' => ['required', 'numeric', 'gt:0'],
            'sale_currency' => ['required', 'string', Rule::in(self::SALE_CURRENCIES)],
            'sale_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'transport_fee' => ['nullable', 'numeric', 'min:0'],
            'transport_fee_usd' => ['nullable', 'numeric', 'min:0'],
            'usd_exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'board_listing_id' => ['nullable', 'integer'],
            'requested_by_email' => ['nullable', 'string', 'max:255'],
        ]);

        // chk_sale_required — 판매가 > 0 이면 환율 > 0 필수. 원화는 1 로 고정(화면 저장과 같다).
        $rate = $data['sale_currency'] === 'KRW' ? 1.0 : (float) ($data['sale_exchange_rate'] ?? 0);
        if ($rate <= 0) {
            return $this->reject('missing_exchange_rate');
        }

        return DB::transaction(function () use ($request, $id, $salesman, $data, $rate, $email) {
            $vehicle = Vehicle::query()->whereNull('deleted_at')->where('salesman_id', $salesman->id)
                ->whereKey($id)->lockForUpdate()->first();
            abort_if($vehicle === null, 403);

            if ($vehicle->buyer_id !== null) {
                return $this->reject('already_has_buyer');
            }
            if ($vehicle->isPurchaseCancelled()) {
                return $this->reject('purchase_cancelled');
            }
            if ($vehicle->hasClosedSecondarySettlement()) {
                return $this->reject('ledger_closed');
            }
            if ($vehicle->settlements()->whereIn('settlement_status', ['confirmed', 'paid'])->exists()) {
                return $this->reject('settlement_locked');
            }

            $buyer = Buyer::where('id', (int) $data['buyer_id'])->where('salesman_id', $salesman->id)->where('is_active', true)->first();
            if (! $buyer) {
                return $this->reject('invalid_buyer');
            }
            $consigneeId = null;
            if (! empty($data['consignee_id'])) {
                $consigneeId = Consignee::where('id', (int) $data['consignee_id'])->where('buyer_id', $buyer->id)
                    ->where('is_active', true)->value('id');
                if ($consigneeId === null) {
                    return $this->reject('invalid_consignee');
                }
            }

            // 🔒 매입 등록 락 — 구매확정과 같은 판정. 락이면 남은 승인 대수가 있어야 통과.
            $locked = (bool) (PurchaseRegistrationGate::forBuyer($buyer)['locked'] ?? false);
            if ($locked && BoardPurchaseLockAllowance::liveGrants([$buyer->id], lock: true)->isEmpty()) {
                return $this->reject('purchase_locked', ['purchase_lock' => ['locked' => true, 'consumed' => false, 'remaining' => 0]]);
            }

            $vehicle->buyer_id = $buyer->id;
            $vehicle->consignee_id = $consigneeId;
            $vehicle->sale_price = (float) $data['sale_price'];
            $vehicle->currency = $data['sale_currency'];
            $vehicle->exchange_rate = $rate;
            $vehicle->sale_date = now()->toDateString();
            // 운임비 = 판매통화 기준(board 가 환산해 보냄). USD 메모 2칸은 따로. 0 은 「안 보냄」.
            if ((float) ($data['transport_fee'] ?? 0) > 0) {
                $vehicle->transport_fee = (float) $data['transport_fee'];
            }
            if ((float) ($data['transport_fee_usd'] ?? 0) > 0) {
                $vehicle->transport_fee_usd = (int) round((float) $data['transport_fee_usd']);
            }
            if ((float) ($data['usd_exchange_rate'] ?? 0) > 0) {
                $vehicle->usd_exchange_rate = (float) $data['usd_exchange_rate'];
            }

            $actorEmail = trim((string) ($data['requested_by_email'] ?? '')) ?: $email;
            $userId = User::where('email', $actorEmail)->value('id') ?? User::where('email', $email)->value('id');
            AuditLog::actingAs($userId, fn () => $vehicle->save());   // buyer_undecided 는 Vehicle::saving 이 내린다

            $updated = array_values(array_filter(
                ['buyer_id', 'consignee_id', 'sale_price', 'currency', 'exchange_rate', 'sale_date', 'transport_fee', 'transport_fee_usd', 'usd_exchange_rate', 'buyer_undecided'],
                fn (string $c) => $vehicle->wasChanged($c),
            ));

            AuditLog::create([
                'user_id' => $userId,
                'auditable_type' => Vehicle::class,
                'auditable_id' => $vehicle->id,
                'action' => 'board_buyer_assigned',
                'column_name' => 'buyer_id',
                'old_value' => null,
                'new_value' => $buyer->name.' · '.$data['sale_currency'].' '.number_format((float) $data['sale_price'], 2),
                'ip_address' => $request->ip(),
            ]);

            $consume = BoardPurchaseLockAllowance::consume($buyer->id, $vehicle, isset($data['board_listing_id']) ? (int) $data['board_listing_id'] : null);

            return response()->json([
                'vehicle_id' => $vehicle->id,
                'buyer_id' => $vehicle->buyer_id,
                'consignee_id' => $vehicle->consignee_id,
                'sale_date' => $vehicle->sale_date?->toDateString(),
                'updated' => $updated,
                'purchase_lock' => ['locked' => $locked, 'consumed' => $consume['consumed'], 'remaining' => $consume['remaining']],
            ]);
        });
    }

    private function reject(string $error, array $extra = []): JsonResponse
    {
        return response()->json(['error' => $error] + $extra, 422);
    }
}
