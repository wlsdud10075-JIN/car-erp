<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\ShippingRequest;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * board 포털 차량 행의 차대번호·브랜드/차종 (board 인계 2026-08-10).
 *
 * jin 요청 = "차량번호가 보이는 곳이면 차대번호와 브랜드/차종도 같이 보이게".
 * board 는 **표시만** 한다 — 응답에 없으면 아무것도 못 그린다.
 *
 * 🔒 이 테스트가 지키는 것 두 가지:
 *   ① **키 이름** — board 는 정확히 `vin`·`brand`·`model_type` 을 읽는다. 바뀌면 board 는
 *      에러도 없이 그냥 안 그린다(없는 필드 = degrade). 사람 눈으로는 절대 못 잡는다.
 *   ② **emit 지점 누락** — 컨트롤러가 **2개**라 하나만 고치면 절반만 된다(인계문서 경고).
 */
class BoardPortalVehicleMetaTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    /** board 가 읽는 키 — 이름이 계약이다. */
    private const KEYS = ['vin', 'brand', 'model_type'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
    }

    private function signedGet(string $path, array $query)
    {
        ksort($query);
        $ts = now()->timestamp;
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    /** 매입 완납 + 판매까지 채운 차 — 재고 4분류·미수·판매·선적에 모두 잡히게. */
    private function seedVehicle(Salesman $s, Buyer $b, string $vn): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => $vn, 'sales_channel' => 'export',
            'salesman_id' => $s->id, 'buyer_id' => $b->id,
            'currency' => 'USD', 'exchange_rate' => 1300,
            'purchase_date' => '2026-08-01', 'purchase_price' => 10_000_000,
            'sale_price' => 12_000, 'sale_date' => '2026-08-02',
            'brand' => '현대', 'model_type' => '그랜저 IG', 'nice_reg_vin' => 'KMHXX00000000001',
        ]);
        $v->purchaseBalancePayments()->create([
            'amount' => 10_000_000, 'payment_date' => '2026-08-02', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        return $v->fresh();
    }

    /** 응답 행 하나가 세 키를 **전부** 실제 값으로 들고 있는지. */
    private function assertMeta(array $row, string $where): void
    {
        foreach (self::KEYS as $k) {
            $this->assertArrayHasKey($k, $row, "{$where}: 키 '{$k}' 가 없다 — board 는 조용히 아무것도 안 그린다");
        }
        $this->assertSame('KMHXX00000000001', $row['vin'], $where);
        $this->assertSame('현대', $row['brand'], $where);
        $this->assertSame('그랜저 IG', $row['model_type'], $where);
    }

    /** 미수금 · 판매내역 · 재고(4분류 전부) — InternalPortalController 쪽. */
    public function test_portal_endpoints_carry_vehicle_meta(): void
    {
        $s = Salesman::create(['name' => 'S1', 'email' => 'meta@a.com', 'is_active' => true]);
        $b = Buyer::create(['name' => 'TOKYO', 'is_active' => true, 'salesman_id' => $s->id]);
        $this->seedVehicle($s, $b, '11가1111');

        foreach (['receivables', 'sales'] as $ep) {
            $res = $this->signedGet("/api/internal/board/{$ep}", ['salesman_email' => 'meta@a.com'])->assertOk();
            $this->assertSame(1, $res->json('count'), $ep);
            $this->assertMeta($res->json('data.0'), $ep);
        }

        // 재고는 분류마다 다른 scope 를 타므로 **4분류 전부** 확인한다 — 한 분류만 보면
        // 나머지가 다른 map 을 쓰게 되어도 안 잡힌다.
        $found = 0;
        foreach (['awaiting_payment', 'general', 'pre_ship', 'shipped_out'] as $cat) {
            $rows = $this->signedGet('/api/internal/board/inventory', [
                'salesman_email' => 'meta@a.com', 'category' => $cat,
            ])->assertOk()->json('data');

            foreach ($rows as $row) {
                $this->assertMeta($row, "inventory:{$cat}");
                $found++;
            }
        }
        $this->assertGreaterThan(0, $found, '재고 4분류 중 어디에도 안 잡히면 이 테스트가 아무것도 검증하지 못한다');
    }

    /** 선적요청 — ⚠️ **다른 컨트롤러**(ShippingRequestController). 여기가 인계문서가 경고한 "절반". */
    public function test_shipping_endpoints_carry_vehicle_meta(): void
    {
        $s = Salesman::create(['name' => 'S2', 'email' => 'ship@a.com', 'is_active' => true]);
        $b = Buyer::create(['name' => 'OSAKA', 'is_active' => true, 'salesman_id' => $s->id]);
        $v = $this->seedVehicle($s, $b, '22나2222');
        Vehicle::where('id', $v->id)->update(['progress_status_cache' => '판매완료']);   // 선적 가능 전제

        $res = $this->signedGet('/api/internal/board/shippable', ['salesman_email' => 'ship@a.com'])->assertOk();
        $this->assertSame(1, $res->json('count'));
        $this->assertMeta($res->json('data.0'), 'shippable');

        ShippingRequest::create([
            'batch_id' => 'bm1', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO',
            'requested_by_email' => 'ship@a.com', 'status' => 'requested', 'requested_at' => now(),
        ]);

        $res = $this->signedGet('/api/internal/board/bundles', ['salesman_email' => 'ship@a.com'])->assertOk();
        // 묶음 **안의 차량 배열**이 대상이다 — 묶음 자체가 아니라.
        $this->assertMeta($res->json('data.0.vehicles.0'), 'bundles.vehicles[]');
    }

    /** 값이 없으면 `null` — board 는 대시조차 안 그린다(각 필드 독립 degrade). */
    public function test_missing_values_are_null_not_empty_string(): void
    {
        $s = Salesman::create(['name' => 'S3', 'email' => 'bare@a.com', 'is_active' => true]);
        Vehicle::create([
            'vehicle_number' => '33다3333', 'sales_channel' => 'export', 'salesman_id' => $s->id,
            'sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 1300, 'sale_date' => '2026-08-02',
            'buyer_id' => Buyer::create(['name' => 'X', 'is_active' => true, 'salesman_id' => $s->id])->id,
        ]);

        $row = $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'bare@a.com'])
            ->assertOk()->json('data.0');

        foreach (self::KEYS as $k) {
            $this->assertArrayHasKey($k, $row);
            $this->assertNull($row[$k], "{$k} 는 빈 문자열이 아니라 null 이어야 한다");
        }
    }

    /**
     * 🧹 세 키를 각 emit 지점이 손으로 짜지 않고 `Vehicle::portalMeta()` 를 부르는지 정적 검사.
     *
     * 복제되면 탭마다 이름이 갈리고, 갈린 탭은 **에러 없이 그냥 안 그려진다**.
     * 그래서 기능 테스트만으로는 "새로 추가된 emit 지점"을 영원히 못 잡는다.
     */
    public function test_meta_keys_are_not_hand_written_in_controllers(): void
    {
        $dir = base_path('app/Http/Controllers/Api/Internal');
        foreach (glob($dir.'/*.php') as $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString(
                "'model_type' =>",
                $src,
                basename($path).' — 차량 메타를 손으로 짜지 말고 Vehicle::portalMeta() 를 쓸 것 '
                .'(키가 갈리면 board 는 에러 없이 안 그린다).'
            );
        }
    }

    /**
     * 🔒 §3 PII — 이 인계로 넓힌 건 **차량 식별자뿐**이다. 소유자·계좌가 딸려 나가면 안 된다.
     * `portalMeta` 에 필드를 얹다가 실수하는 걸 막는다.
     */
    public function test_meta_does_not_leak_owner_or_account(): void
    {
        $s = Salesman::create(['name' => 'S4', 'email' => 'pii@a.com', 'is_active' => true]);
        $b = Buyer::create(['name' => 'Y', 'is_active' => true, 'salesman_id' => $s->id]);
        $v = $this->seedVehicle($s, $b, '44라4444');
        $v->update([
            'nice_reg_owner_name' => '홍길동',
            'nice_reg_owner_addr' => '서울시 어딘가',
            'purchase_seller_holder' => '김판매',
            'purchase_seller_account' => '110-123-456789',
        ]);

        $body = $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'pii@a.com'])
            ->assertOk()->getContent();

        foreach (['홍길동', '서울시 어딘가', '김판매', '110-123-456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, '§3 화이트리스트 위반 — 소유자·계좌가 응답에 실렸다');
        }
        $this->assertStringContainsString('KMHXX00000000001', $body, 'VIN 은 허용(차량 식별자)');
    }
}
