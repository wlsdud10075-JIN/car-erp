<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * board 재고 3분류 API (인계 2026-08-09) — `erp/inventory` 화면의 미러.
 *
 * 왜 만들었나: board 「매입내역」이 `purchases()`(매입가>0 **전량**)라 필터도 페이징도 없이
 * 영업이 평생 매입한 차가 매번 통째로 왔다. 단조증가라 절대 안 줄어든다.
 * 재고는 `inStock()` 이라 유한하고(영업당 20~50대), 누적되는 꼬리는 `shipped_out` 으로 빠진다.
 *
 * 🔒 이 테스트가 지키는 핵심 = **화면과 분류 정의가 갈리지 않는 것.**
 *    갈리는 순간 "ERP엔 재고인데 board엔 없다"가 되고, 그건 사람이 눈으로는 못 잡는다.
 */
class BoardInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        DB::statement('PRAGMA foreign_keys = OFF');
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

    private function salesman(string $email): Salesman
    {
        return Salesman::create(['name' => 'S'.Str::random(3), 'email' => $email, 'is_active' => true]);
    }

    /** 매입 완납(=입고) 차량. $paid=false 면 미완납 → 재고에 안 잡힌다. */
    private function vehicle(int $sid, array $attrs = [], bool $paid = true): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'INV'.++$this->counter.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $sid,
            'purchase_date' => '2026-08-01', 'purchase_price' => 1_000_000,
        ], $attrs));
        $v->purchaseBalancePayments()->create([
            'amount' => $paid ? 1_000_000 : 400_000,
            'payment_date' => '2026-08-02', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        return $v->fresh();
    }

    /**
     * 🔒 화면 ↔ API 가 같은 집합을 본다. 조건을 옮겨 적으면 갈리므로 scope 를 공유해야 한다.
     * `inStock()` 은 출고일뿐 아니라 **매입완납·거래완료**까지 보는 복합 조건이라 특히 그렇다.
     */
    public function test_categories_match_the_screen_scopes(): void
    {
        $sm = $this->salesman('me@a.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);

        $general = $this->vehicle($sm->id);                                          // 미판매 재고
        $preShip = $this->vehicle($sm->id, ['sale_price' => 5000, 'buyer_id' => $buyer->id, 'sale_date' => '2026-08-03']);
        $shipped = $this->vehicle($sm->id, ['warehouse_out_date' => '2026-08-05']);   // 출고 완료
        $unpaid = $this->vehicle($sm->id, [], paid: false);                           // 매입 미완납 = 입고 전

        foreach (['general', 'pre_ship', 'shipped_out'] as $cat) {
            $api = collect($this->signedGet('/api/internal/board/inventory',
                ['salesman_email' => 'me@a.com', 'category' => $cat])->assertOk()->json('data'))
                ->pluck('vehicle_id')->sort()->values()->all();

            $screen = Vehicle::query()->where('salesman_id', $sm->id)
                ->when($cat === 'general', fn ($q) => $q->generalStock())
                ->when($cat === 'pre_ship', fn ($q) => $q->preShippingStock())
                ->when($cat === 'shipped_out', fn ($q) => $q->whereNotNull('warehouse_out_date'))
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($screen, $api, "category={$cat} 이 화면 scope 와 다른 집합을 돌려준다");
        }

        // 매입 미완납은 어느 재고에도 안 잡힌다(입고 전).
        $all = collect(['general', 'pre_ship', 'shipped_out'])
            ->flatMap(fn ($c) => collect($this->signedGet('/api/internal/board/inventory',
                ['salesman_email' => 'me@a.com', 'category' => $c])->json('data'))->pluck('vehicle_id'))
            ->all();
        $this->assertNotContains($unpaid->id, $all, '매입 미완납 차량이 재고로 나왔다');
        $this->assertContains($general->id, $all);
        $this->assertContains($preShip->id, $all);
        $this->assertContains($shipped->id, $all);
    }

    public function test_scoped_to_own_vehicles(): void
    {
        $mine = $this->salesman('mine@a.com');
        $theirs = $this->salesman('theirs@a.com');
        $m = $this->vehicle($mine->id);
        $t = $this->vehicle($theirs->id);

        $res = $this->signedGet('/api/internal/board/inventory', ['salesman_email' => 'mine@a.com', 'category' => 'general']);

        $ids = collect($res->assertOk()->json('data'))->pluck('vehicle_id')->all();
        $this->assertSame([$m->id], $ids);
        $this->assertNotContains($t->id, $ids);
    }

    /** 🚫 §3 — 마진·PII 가 새면 안 된다. */
    public function test_response_has_no_margin_or_pii(): void
    {
        $sm = $this->salesman('me@a.com');
        $this->vehicle($sm->id, [
            'nice_reg_owner_name' => '김혜진',
            'nice_reg_owner_rrn' => '880717-1234567',
            'purchase_seller_account' => '123-456-789',
        ]);

        $flat = json_encode($this->signedGet('/api/internal/board/inventory',
            ['salesman_email' => 'me@a.com', 'category' => 'general'])->json(), JSON_UNESCAPED_UNICODE);

        foreach (['margin', '김혜진', '880717', '123-456-789', 'owner_rrn', 'seller_account'] as $leak) {
            $this->assertStringNotContainsString($leak, $flat, "응답에 {$leak} 가 샜다(§3)");
        }
    }

    /** shipped_out 만 누적되므로 거기만 자른다 — board 는 최근 30건 + [더 보기]. */
    public function test_shipped_out_paginates_and_reports_total(): void
    {
        $sm = $this->salesman('me@a.com');
        foreach (range(1, 5) as $i) {
            $this->vehicle($sm->id, ['warehouse_out_date' => '2026-08-0'.$i]);
        }

        $first = $this->signedGet('/api/internal/board/inventory',
            ['salesman_email' => 'me@a.com', 'category' => 'shipped_out', 'limit' => 2])->assertOk();

        $first->assertJsonPath('total', 5)->assertJsonCount(2, 'data');

        $next = $this->signedGet('/api/internal/board/inventory',
            ['salesman_email' => 'me@a.com', 'category' => 'shipped_out', 'limit' => 2, 'offset' => 2])->assertOk();

        $this->assertNotSame(
            collect($first->json('data'))->pluck('vehicle_id')->all(),
            collect($next->json('data'))->pluck('vehicle_id')->all(),
            'offset 이 안 먹어 같은 페이지가 두 번 왔다'
        );

        // 최근 출고순
        $dates = collect($first->json('data'))->pluck('warehouse_out_date')->all();
        $this->assertSame($dates, collect($dates)->sortDesc()->values()->all());
    }

    public function test_search_is_delegated_to_erp(): void
    {
        $sm = $this->salesman('me@a.com');
        $hit = $this->vehicle($sm->id, ['vehicle_number' => '77하7777']);
        $miss = $this->vehicle($sm->id, ['vehicle_number' => '11가1111']);

        $ids = collect($this->signedGet('/api/internal/board/inventory',
            ['salesman_email' => 'me@a.com', 'category' => 'general', 'search' => '7777'])
            ->assertOk()->json('data'))->pluck('vehicle_id')->all();

        $this->assertSame([$hit->id], $ids);
        $this->assertNotContains($miss->id, $ids);
    }

    public function test_invalid_category_is_rejected(): void
    {
        $this->salesman('me@a.com');

        $this->signedGet('/api/internal/board/inventory', ['salesman_email' => 'me@a.com', 'category' => 'everything'])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_category');
    }

    /** 요청 ② — 판매내역에 진행상태 + 서버측 제외 필터. */
    public function test_sales_exposes_progress_and_filters_server_side(): void
    {
        $sm = $this->salesman('me@a.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $sold = $this->vehicle($sm->id, ['sale_price' => 5000, 'buyer_id' => $buyer->id, 'sale_date' => '2026-08-03']);
        // ⚠️ progress_status_cache 를 직접 넣어도 Vehicle::saving 이 재계산해 덮는다.
        //    실제로 거래완료가 되는 조건(B/L 발급 = v4 cascade 1번)을 만들어야 한다.
        $done = $this->vehicle($sm->id, [
            'sale_price' => 7000, 'buyer_id' => $buyer->id, 'sale_date' => '2026-08-03',
            'bl_document' => 'bl.pdf',
        ]);
        $this->assertSame('거래완료', $done->progress_status_cache, '전제: 이 차량이 거래완료여야 한다');

        $all = $this->signedGet('/api/internal/board/sales', ['salesman_email' => 'me@a.com'])->assertOk();
        $this->assertNotNull($all->json('data.0.progress_status'), '진행상태가 안 실렸다');

        $filtered = $this->signedGet('/api/internal/board/sales',
            ['salesman_email' => 'me@a.com', 'exclude_status' => '거래완료'])->assertOk();

        $ids = collect($filtered->json('data'))->pluck('vehicle_id')->all();
        $this->assertContains($sold->id, $ids);
        $this->assertNotContains($done->id, $ids, '거래완료가 서버에서 안 걸러졌다 — board 가 받아놓고 감추면 트래픽이 그대로다');
    }

    /** ④ shipped_out 은 유일하게 누적되는 축 — 필터·정렬이 걸리는 컬럼에 인덱스가 있어야 한다. */
    public function test_warehouse_out_date_is_indexed(): void
    {
        $this->assertTrue(
            Schema::hasColumn('vehicles', 'warehouse_out_date'),
            'warehouse_out_date 컬럼이 없다'
        );

        $migration = collect(glob(database_path('migrations/*.php')))
            ->first(fn ($p) => str_contains(file_get_contents($p), "index('warehouse_out_date')"));

        $this->assertNotNull($migration, 'warehouse_out_date 인덱스 마이그레이션이 없다 — shipped_out 이 풀스캔이다');
    }

    /**
     * 🔒 지급대기 ↔ 재고는 **배타적**이고, 합치면 "출고 전 매입차 전체"가 된다.
     * board 가 [입금요청]을 보낼 대상이 정확히 지급대기 집합이라, 여기가 비면 버튼을 달 곳이 없다.
     */
    public function test_awaiting_payment_is_exclusive_with_stock(): void
    {
        $sm = $this->salesman('me@a.com');
        $unpaid = $this->vehicle($sm->id, [], paid: false);   // 매입 대금 남음 = 입고 전
        $paid = $this->vehicle($sm->id);                      // 완납 = 재고

        $awaiting = collect($this->signedGet('/api/internal/board/inventory',
            ['salesman_email' => 'me@a.com', 'category' => 'awaiting_payment'])->assertOk()->json('data'))
            ->pluck('vehicle_id')->all();

        $this->assertSame([$unpaid->id], $awaiting, '지급대기에 미완납 차가 안 잡힌다 — board 가 입금요청을 못 보낸다');

        $inStock = collect(['general', 'pre_ship'])->flatMap(fn ($c) => collect(
            $this->signedGet('/api/internal/board/inventory', ['salesman_email' => 'me@a.com', 'category' => $c])->json('data')
        )->pluck('vehicle_id'))->all();

        $this->assertContains($paid->id, $inStock);
        $this->assertNotContains($unpaid->id, $inStock, '같은 차가 지급대기와 재고에 동시에 있다');

        // 화면 scope 와 같은 집합인가
        $screen = Vehicle::query()->where('salesman_id', $sm->id)->awaitingPurchasePayment()->pluck('id')->all();
        $this->assertSame($screen, $awaiting, '지급대기가 화면 scope 와 다른 집합이다');
    }

    /** 지급을 마치면 지급대기에서 빠져 재고로 넘어간다 — 사람이 옮기지 않는다. */
    public function test_moves_to_stock_once_fully_paid(): void
    {
        $sm = $this->salesman('me@a.com');
        $v = $this->vehicle($sm->id, [], paid: false);

        $ids = fn (string $cat) => collect($this->signedGet('/api/internal/board/inventory',
            ['salesman_email' => 'me@a.com', 'category' => $cat])->json('data'))->pluck('vehicle_id')->all();

        $this->assertContains($v->id, $ids('awaiting_payment'));
        $this->assertNotContains($v->id, $ids('general'));

        // 잔액 지급 확정
        $v->purchaseBalancePayments()->create([
            'amount' => 600_000, 'payment_date' => '2026-08-03', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        $this->assertNotContains($v->id, $ids('awaiting_payment'), '완납했는데 지급대기에 남았다');
        $this->assertContains($v->id, $ids('general'), '완납했는데 재고로 안 넘어갔다');
    }

    /** 출고된 차는 지급대기에 안 뜬다 — 이미 나간 차에 입금요청을 보낼 일은 없다. */
    public function test_shipped_out_vehicle_is_not_awaiting_payment(): void
    {
        $sm = $this->salesman('me@a.com');
        $v = $this->vehicle($sm->id, ['warehouse_out_date' => '2026-08-05'], paid: false);

        $ids = collect($this->signedGet('/api/internal/board/inventory',
            ['salesman_email' => 'me@a.com', 'category' => 'awaiting_payment'])->json('data'))->pluck('vehicle_id')->all();

        $this->assertNotContains($v->id, $ids);
    }

    /** 🔒 미지급 SQL 식은 단일 출처 — 복사되면 화면마다 숫자가 갈린다. */
    public function test_purchase_unpaid_expression_is_single_source(): void
    {
        $src = file_get_contents(base_path('app/Models/Vehicle.php'));

        $this->assertSame(
            1,
            substr_count($src, 'CAST(purchase_price AS SIGNED)'),
            '매입 미지급 식이 복제됐다 — Vehicle::purchaseUnpaidRawExpr() 하나만 써야 한다'
        );
    }
}
