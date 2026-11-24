<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BoardPurchaseLockAllowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🧾 재고매입(바이어 미정) 차에 board 가 바이어 + 판매 금액 지정 (jin 2026-11-24, board-portal-api.md §19).
 *
 * 지키는 것: ①일반재고 → 선적전 재고(바이어·판매 6칸·판매일 = 오늘·바이어 미정 해제·감사 출처 행)
 *            ②락 걸린 바이어 = 남은 승인 대수 없으면 422 purchase_locked(차는 그대로), 있으면 통과 + 1대 차감
 *            ③이미 바이어 있음·매입취소·남의 바이어·남의 컨사이니·외화인데 환율 없음 = 422 사유
 *            ④남의 차·없는 차·지운 차 = 403(존재 비노출).
 */
class BoardAssignBuyerTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    private Salesman $sm;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        $this->sm = Salesman::create(['name' => '김영업', 'email' => 'sales@car-erp.test', 'type' => 'freelance', 'is_active' => true]);
    }

    private function assign(int $vehicleId, array $payload)
    {
        $path = '/api/internal/board/vehicles/'.$vehicleId.'/assign-buyer';
        $body = json_encode($payload);
        $ts = now()->timestamp;

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', "POST\n".$path."?\n".$ts."\n".$body, $this->secret),
            'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    /** 일반재고 차 — 매입 완납 · 바이어 미정 · 판매가 없음. */
    private function stockCar(?Salesman $sm = null): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => '12가'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'salesman_id' => ($sm ?? $this->sm)->id,
            'purchase_price' => 10_000_000, 'selling_fee' => 0, 'purchase_date' => now()->subMonth()->toDateString(), 'buyer_undecided' => true,
        ]);
        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            $v->purchaseBalancePayments()->create(['amount' => 10_000_000, 'type' => 'down',
                'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now()]);
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }

        return $v->fresh();
    }

    private function buyer(?Salesman $sm = null): Buyer
    {
        return Buyer::create(['name' => 'BUYER '.++$this->n, 'is_active' => true, 'salesman_id' => ($sm ?? $this->sm)->id]);
    }

    /** 미수가 쌓여 매입 등록 락이 걸린 바이어(BoardPurchaseLockRequestTest 와 같은 모양). */
    private function lockedBuyer(): Buyer
    {
        $b = $this->buyer();
        Vehicle::create([
            'vehicle_number' => 'LK'.++$this->n.'가1234', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $this->sm->id, 'buyer_id' => $b->id, 'purchase_date' => '2026-11-01', 'purchase_price' => 20_000_000,
            'sale_price' => 30_000_000, 'sale_date' => '2026-11-02',
        ])->refreshCaches();

        return $b;
    }

    private function grant(Buyer $b, int $count): ApprovalRequest
    {
        return ApprovalRequest::create([
            'action_type' => BoardPurchaseLockAllowance::TYPE, 'target_type' => Buyer::class, 'target_id' => $b->id,
            'status' => ApprovalRequest::STATUS_APPROVED, 'decided_at' => now(), 'reason' => '다음 주 입금 예정',
            'payload' => ['requested_count' => $count, 'approved_count' => $count, 'used' => []],
        ]);
    }

    private function body(Buyer $b, array $extra = []): array
    {
        return array_merge([
            'salesman_email' => 'sales@car-erp.test', 'buyer_id' => $b->id,
            'sale_price' => 9000, 'sale_currency' => 'USD', 'sale_exchange_rate' => 1400,
            'transport_fee' => 1500, 'transport_fee_usd' => 1500, 'usd_exchange_rate' => 1400, 'board_listing_id' => 77,
        ], $extra);
    }

    public function test_assigning_moves_a_general_stock_car_to_pre_shipping_stock(): void
    {
        $user = User::factory()->create(['email' => 'sales@car-erp.test', 'permission' => 'user', 'role' => '영업']);
        $car = $this->stockCar();
        $b = $this->buyer();
        $c = Consignee::create(['buyer_id' => $b->id, 'name' => 'CONS', 'is_active' => true]);
        $this->assertTrue(Vehicle::generalStock()->whereKey($car->id)->exists(), '전제: 일반재고');

        $res = $this->assign($car->id, $this->body($b, ['consignee_id' => $c->id]))->assertOk();
        $res->assertJsonPath('buyer_id', $b->id)->assertJsonPath('consignee_id', $c->id)
            ->assertJsonPath('sale_date', now()->toDateString())->assertJsonPath('purchase_lock.locked', false);

        $car->refresh();
        $this->assertSame($b->id, (int) $car->buyer_id);
        $this->assertFalse((bool) $car->buyer_undecided, '바이어가 붙으면 바이어 미정이 풀린다');
        $this->assertSame('USD', $car->currency);
        $this->assertEquals(9000, (float) $car->sale_price);
        $this->assertEquals(1400, (float) $car->exchange_rate);
        $this->assertEquals(1500, (float) $car->transport_fee);
        $this->assertSame('판매중', $car->progress_status_cache);
        $this->assertFalse(Vehicle::generalStock()->whereKey($car->id)->exists(), '일반재고에서 빠진다');
        $this->assertTrue(Vehicle::preShippingStock()->whereKey($car->id)->exists(), '선적전 재고로 간다');

        $src = AuditLog::where('auditable_id', $car->id)->where('action', 'board_buyer_assigned')->first();
        $this->assertNotNull($src);
        $this->assertSame($user->id, $src->user_id, '행위자 = 영업 이메일의 User(§8 #56)');
        $this->assertSame($user->id, AuditLog::where('auditable_id', $car->id)->where('column_name', 'buyer_id')->where('action', 'updated')->value('user_id'));
    }

    public function test_a_locked_buyer_is_refused_without_a_live_grant_and_consumes_one_with_it(): void
    {
        $car = $this->stockCar();
        $b = $this->lockedBuyer();

        $this->assign($car->id, $this->body($b))->assertStatus(422)
            ->assertJsonPath('error', 'purchase_locked')->assertJsonPath('purchase_lock.remaining', 0);
        $this->assertNull($car->fresh()->buyer_id, '거절되면 차는 그대로');

        $grant = $this->grant($b, 2);
        $this->assign($car->id, $this->body($b))->assertOk()
            ->assertJsonPath('purchase_lock.locked', true)->assertJsonPath('purchase_lock.consumed', true)->assertJsonPath('purchase_lock.remaining', 1);
        $this->assertSame(1, BoardPurchaseLockAllowance::usedCount($grant->fresh()));
        $this->assertSame($car->id, $grant->fresh()->payload['used'][0]['vehicle_id']);
        $this->assertSame(77, $grant->fresh()->payload['used'][0]['listing_id']);
    }

    /** 락이 아닌 바이어도 살아 있는 승인이 있으면 1대 쓴다 — §17 「단순 카운트」(구매확정과 같다). */
    public function test_an_unlocked_buyer_with_a_live_grant_still_uses_one(): void
    {
        $car = $this->stockCar();
        $b = $this->buyer();
        $grant = $this->grant($b, 3);

        $this->assign($car->id, $this->body($b))->assertOk()
            ->assertJsonPath('purchase_lock.locked', false)->assertJsonPath('purchase_lock.consumed', true)->assertJsonPath('purchase_lock.remaining', 2);
        $this->assertSame(1, BoardPurchaseLockAllowance::usedCount($grant->fresh()));
    }

    public function test_refusal_reasons(): void
    {
        $other = Salesman::create(['name' => '남영업', 'email' => 'other@car-erp.test', 'type' => 'freelance', 'is_active' => true]);
        $b = $this->buyer();

        $has = $this->stockCar();
        $has->update(['buyer_id' => $this->buyer()->id]);
        $this->assign($has->id, $this->body($b))->assertStatus(422)->assertJsonPath('error', 'already_has_buyer');

        $cancelled = $this->stockCar();
        $cancelled->forceFill(['cancel_status' => 'cancelled'])->saveQuietly();
        $this->assign($cancelled->id, $this->body($b))->assertStatus(422)->assertJsonPath('error', 'purchase_cancelled');

        $car = $this->stockCar();
        $this->assign($car->id, $this->body($this->buyer($other)))->assertStatus(422)->assertJsonPath('error', 'invalid_buyer');
        $theirCons = Consignee::create(['buyer_id' => $this->buyer()->id, 'name' => 'X', 'is_active' => true]);
        $this->assign($car->id, $this->body($b, ['consignee_id' => $theirCons->id]))->assertStatus(422)->assertJsonPath('error', 'invalid_consignee');
        $this->assign($car->id, $this->body($b, ['sale_exchange_rate' => null]))->assertStatus(422)->assertJsonPath('error', 'missing_exchange_rate');
        $this->assign($car->id, $this->body($b, ['sale_price' => 0]))->assertStatus(422);
        $this->assertNull($car->fresh()->buyer_id);

        // 원화는 환율을 안 보내도 1 로 들어간다.
        $this->assign($car->id, $this->body($b, ['sale_currency' => 'KRW', 'sale_price' => 15_000_000, 'sale_exchange_rate' => null]))->assertOk();
        $this->assertEquals(1, (float) $car->fresh()->exchange_rate);
    }

    /** IDOR — 남의 차·없는 차·지운 차 = 403(§2). */
    public function test_scope(): void
    {
        $other = Salesman::create(['name' => '남영업', 'email' => 'other@car-erp.test', 'type' => 'freelance', 'is_active' => true]);
        $b = $this->buyer();
        $theirs = $this->stockCar($other);
        $gone = $this->stockCar();
        $gone->delete();

        $this->assign($theirs->id, $this->body($b))->assertForbidden();
        $this->assign(999999, $this->body($b))->assertForbidden();
        $this->assign($gone->id, $this->body($b))->assertForbidden();
        $this->assertNull($theirs->fresh()->buyer_id);
    }
}
