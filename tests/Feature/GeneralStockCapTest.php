<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\GeneralStockCap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🧱 일반재고 인원당 상한 2억 — board 발 재고매입 차단 (jin 2026-11-20).
 *
 * 지키는 것: ①기존 일반재고 합 + 이번 매입가 > 2억이면 purchase-sync 가 409 로 거절, 차량·NICE 호출 없음
 *            ②정확히 2억은 통과 ③바이어가 있거나 판매가가 있는 차는 안 막는다 ④이미 있는 차의 재전송은 200 그대로
 *            ⑤지급 전 차·바이어 지정 차는 합계에 안 센다(jin 결정) ⑥담당자를 못 찾으면 적용 안 함
 *            ⑦board 사전 조회의 답 = 수신 거절 판정(같은 함수).
 */
class GeneralStockCapTest extends TestCase
{
    use RefreshDatabase;

    private const SYNC_SECRET = 'test-shared-hmac-secret';

    private const READ_SECRET = 'test-board-read-secret';

    private const EMAIL = 'sales@car-erp.test';

    private Salesman $sm;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.purchase_sync.hmac_secret', self::SYNC_SECRET);
        config()->set('services.board_read.hmac_secret', self::READ_SECRET);
        config()->set('services.nice.provide_url', '');
        config()->set('services.nice.provide_token', '');
        config()->set('filesystems.purchase_sync_inbound_disk', config('filesystems.vehicle_docs_disk'));
        $this->sm = Salesman::create(['name' => '김영업', 'email' => self::EMAIL, 'type' => 'freelance', 'is_active' => true]);
    }

    /** 일반재고에 잡히는 차 — 매입 완납 · 바이어 없음 · 판매가 없음. */
    private function stock(string $number, int $price, bool $paid = true, ?int $buyerId = null, ?Salesman $sm = null): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => $number, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => ($sm ?? $this->sm)->id, 'purchase_price' => $price, 'selling_fee' => 0,
            'purchase_date' => now()->subMonth()->toDateString(), 'buyer_id' => $buyerId,
        ]);
        if ($paid) {
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                $v->purchaseBalancePayments()->create(['amount' => $price, 'type' => 'down',
                    'payment_date' => now()->subDay()->toDateString(), 'confirmed_at' => now()]);
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }
        }

        return $v->fresh();
    }

    private function sync(array $overrides = [])
    {
        $payload = array_merge([
            'contract_version' => 5, 'vehicle_number' => '99재1234', 'owner_name' => '홍길동', 'source' => 'auction',
            'salesman_email' => self::EMAIL, 'purchase_price_krw' => 60_000_000, 'buyer_undecided' => true,
        ], $overrides);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/api/internal/purchase-sync', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BOARD_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, self::SYNC_SECRET),
        ], $body);
    }

    private function ask(array $query)
    {
        $path = '/api/internal/board/general-stock-cap';
        ksort($query);
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n", self::READ_SECRET);

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.$sig, 'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    public function test_stock_purchase_over_the_cap_is_rejected_and_creates_nothing(): void
    {
        Http::fake();
        $this->stock('11가1001', 150_000_000);

        $res = $this->sync(['purchase_price_krw' => 60_000_000]);   // 1.5억 + 0.6억 = 2.1억

        $res->assertStatus(409)
            ->assertJsonPath('error', GeneralStockCap::ERROR)
            ->assertJsonPath('general_stock_cap.cap', 200_000_000)
            ->assertJsonPath('general_stock_cap.used', 150_000_000)
            ->assertJsonPath('general_stock_cap.incoming', 60_000_000)
            ->assertJsonPath('general_stock_cap.remaining', 50_000_000)
            ->assertJsonPath('general_stock_cap.salesman_id', $this->sm->id);
        $this->assertDatabaseMissing('vehicles', ['vehicle_number' => '99재1234']);
        Http::assertNothingSent();
    }

    public function test_exactly_at_the_cap_passes(): void
    {
        $this->stock('11가1001', 150_000_000);

        $this->sync(['purchase_price_krw' => 50_000_000])->assertStatus(201);   // 딱 2억
        $this->assertDatabaseHas('vehicles', ['vehicle_number' => '99재1234']);
    }

    public function test_a_car_with_a_buyer_or_a_sale_price_is_not_blocked(): void
    {
        $this->stock('11가1001', 190_000_000);
        $buyer = Buyer::create(['name' => 'CAP BUYER', 'is_active' => true, 'salesman_id' => $this->sm->id]);

        $this->sync(['vehicle_number' => '99재2001', 'buyer_id' => $buyer->id, 'buyer_undecided' => false])->assertStatus(201);
        // 바이어 미정 판매(판매가만 있음) — 일반재고가 아니라 선적전 재고가 될 차
        $this->sync(['vehicle_number' => '99재2002', 'sale_price' => 70_000_000, 'sale_currency' => 'KRW', 'sale_exchange_rate' => 1])
            ->assertStatus(201);
    }

    public function test_resending_an_existing_stock_car_is_never_blocked(): void
    {
        $this->sync(['purchase_price_krw' => 60_000_000])->assertStatus(201);
        $this->stock('11가1001', 190_000_000);   // 그 뒤 한도가 찼다

        $this->sync(['purchase_price_krw' => 60_000_000])->assertStatus(200);
    }

    public function test_only_paid_buyerless_stock_of_that_salesman_counts(): void
    {
        $other = Salesman::create(['name' => '이영업', 'email' => 'other@car-erp.test', 'type' => 'freelance', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'CAP BUYER', 'is_active' => true]);
        $this->stock('11가1001', 100_000_000);                              // 센다
        $this->stock('11가1002', 190_000_000, false);                       // 지급 전 — 안 센다 (jin 결정, 알고 둔 구멍)
        $this->stock('11가1003', 190_000_000, true, $buyer->id);            // 바이어 지정 — 선적전 재고라 안 센다
        $this->stock('11가1004', 190_000_000, true, null, $other);          // 다른 담당자

        $this->assertSame(['count' => 1, 'amount' => 100_000_000], GeneralStockCap::usedBy($this->sm->id));
        $this->sync(['purchase_price_krw' => 100_000_000])->assertStatus(201);
    }

    public function test_unresolved_salesman_is_not_capped(): void
    {
        $this->stock('11가1001', 190_000_000);

        $this->sync(['salesman_email' => 'nobody@car-erp.test', 'purchase_price_krw' => 60_000_000])->assertStatus(201);
        $this->assertNull(Vehicle::where('vehicle_number', '99재1234')->value('salesman_id'));
    }

    public function test_precheck_gives_the_same_answer_as_the_reject(): void
    {
        $this->stock('11가1001', 150_000_000);

        $over = $this->ask(['salesman_email' => self::EMAIL, 'incoming' => 60_000_000])->assertOk()->json();
        $this->assertTrue($over['applies']);
        $this->assertTrue($over['blocked']);
        $this->assertSame(['cap' => 200_000_000, 'used' => 150_000_000, 'count' => 1, 'remaining' => 50_000_000],
            array_intersect_key($over, array_flip(['cap', 'used', 'count', 'remaining'])));
        $this->assertSame($over, $this->sync(['purchase_price_krw' => 60_000_000])->assertStatus(409)->json('general_stock_cap'));

        $this->ask(['salesman_email' => self::EMAIL, 'incoming' => 50_000_000])->assertOk()->assertJsonPath('blocked', false);
        // incoming 없이 물으면 남은 한도만 알려 준다
        $this->ask(['salesman_email' => self::EMAIL])->assertOk()->assertJsonPath('blocked', false)->assertJsonPath('remaining', 50_000_000);
        // 담당자 명시(대리 등록) — purchase-sync 와 같은 우선순위
        $other = Salesman::create(['name' => '이영업', 'email' => 'other@car-erp.test', 'type' => 'freelance', 'is_active' => true]);
        $this->ask(['salesman_email' => self::EMAIL, 'car_erp_salesman_id' => $other->id, 'incoming' => 60_000_000])
            ->assertOk()->assertJsonPath('salesman_id', $other->id)->assertJsonPath('blocked', false);
        // 못 찾는 담당자 — 적용 안 함
        $this->ask(['salesman_email' => 'nobody@car-erp.test', 'incoming' => 999_000_000])
            ->assertOk()->assertJsonPath('applies', false)->assertJsonPath('blocked', false);
        // 서명 없으면 거절
        $this->get('/api/internal/board/general-stock-cap?salesman_email='.self::EMAIL)->assertStatus(401);
    }
}
