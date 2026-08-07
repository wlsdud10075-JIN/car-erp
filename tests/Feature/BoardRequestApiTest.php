<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * board↔erp 요청·확인 신호 B단계 — API. 권위 = docs/integration/board-portal-api.md §11.
 *
 * 지키는 것:
 *   - IDOR: 남의 차는 skipped(forbidden). **부분 성공** — 한 대 때문에 묶음 전체가 죽지 않는다
 *   - 멱등: 재전송해도 신호가 안 쌓인다
 *   - 오배치: 다른 바이어 차를 한 묶음에 담으면 422
 *   - 🚫 금액: board 가 보내도 저장되지 않는다(§11-2)
 *   - 응답에 PII·마진 없음(§3)
 */
class BoardRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
    }

    private function signedPost(string $path, array $payload)
    {
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $canonical = "POST\n".$path."?\n".$ts."\n".$body;

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
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

    private function vehicle(int $salesmanId, string $vn, ?int $buyerId = null): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $vn, 'sales_channel' => 'export',
            'salesman_id' => $salesmanId, 'buyer_id' => $buyerId,
        ]);
    }

    public function test_purchase_payment_creates_one_request_per_vehicle(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v1 = $this->vehicle($sm->id, '11가1111');
        $v2 = $this->vehicle($sm->id, '22나2222');

        $res = $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_PAYMENT,
            'vehicle_ids' => [$v1->id, $v2->id],
        ]);

        $res->assertStatus(201)->assertJsonCount(2, 'created');

        // 입금요청은 차량 1대가 단위 — 묶음이 따로 논다.
        $batches = BoardRequest::pluck('batch_id')->unique();
        $this->assertCount(2, $batches, '입금요청 2건이 한 묶음으로 합쳐졌다 — 단위는 차량 1대다');
    }

    public function test_sale_confirm_shares_one_batch(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v1 = $this->vehicle($sm->id, '11가1111', $buyer->id);
        $v2 = $this->vehicle($sm->id, '22나2222', $buyer->id);

        $res = $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_SALE_PAYMENT_CONFIRM,
            'buyer_id' => $buyer->id,
            'vehicle_ids' => [$v1->id, $v2->id],
        ]);

        $res->assertStatus(201)->assertJsonCount(2, 'created');
        $this->assertCount(1, BoardRequest::pluck('batch_id')->unique(), '판매대금확인 N대는 한 묶음이어야 한다');
    }

    public function test_other_salesman_vehicle_is_skipped_not_fatal(): void
    {
        $mine = $this->salesman('mine@ex.com');
        $theirs = $this->salesman('theirs@ex.com');
        $ok = $this->vehicle($mine->id, '11가1111');
        $notMine = $this->vehicle($theirs->id, '99하9999');

        $res = $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'mine@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_PAYMENT,
            'vehicle_ids' => [$ok->id, $notMine->id],
        ]);

        // 부분 성공 — 남의 차 한 대 때문에 내 차까지 죽으면 원인을 못 찾고 카톡으로 돌아간다.
        $res->assertStatus(201)
            ->assertJsonPath('created', ['11가1111'])
            ->assertJsonPath('skipped.0.reason', 'forbidden');

        $this->assertSame(0, BoardRequest::where('vehicle_id', $notMine->id)->count());
    }

    public function test_resend_is_idempotent(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        $payload = [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_PAYMENT,
            'vehicle_ids' => [$v->id],
        ];

        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);
        $this->signedPost('/api/internal/board/requests', $payload)
            ->assertStatus(201)
            ->assertJsonPath('created', [])
            ->assertJsonPath('skipped.0.reason', 'already_open');

        $this->assertSame(1, BoardRequest::count());
    }

    public function test_sale_confirm_rejects_other_buyers_vehicle(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyerA = Buyer::create(['name' => 'A', 'is_active' => true]);
        $buyerB = Buyer::create(['name' => 'B', 'is_active' => true]);
        $v1 = $this->vehicle($sm->id, '11가1111', $buyerA->id);
        $v2 = $this->vehicle($sm->id, '22나2222', $buyerB->id);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_SALE_PAYMENT_CONFIRM,
            'buyer_id' => $buyerA->id,
            'vehicle_ids' => [$v1->id, $v2->id],
        ])->assertStatus(422)->assertJsonPath('error', 'buyer_mismatch');

        $this->assertSame(0, BoardRequest::count(), '오배치는 한 건도 만들지 않는다(전량 거부)');
    }

    public function test_sale_confirm_requires_buyer(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_SALE_PAYMENT_CONFIRM,
            'vehicle_ids' => [$v->id],
        ])->assertStatus(422)->assertJsonPath('error', 'buyer_required');
    }

    /** 🚫 §11-2 — board 가 금액을 보내도 저장되지 않는다. */
    public function test_amount_from_board_is_discarded(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_PAYMENT,
            'vehicle_ids' => [$v->id],
            'amount' => 4_500_000,
            'sale_price' => 9_999,
        ])->assertStatus(201);

        $row = BoardRequest::first()->getAttributes();
        foreach (['amount', 'sale_price'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "board 가 보낸 {$key} 이 저장됐다 — 신호에 금액을 싣지 않는다(§11-2)");
        }
    }

    public function test_index_returns_batch_status_without_money(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v1 = $this->vehicle($sm->id, '11가1111', $buyer->id);
        $v2 = $this->vehicle($sm->id, '22나2222', $buyer->id);
        $batch = 'b-1';
        $r1 = BoardRequest::open($v1->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
        BoardRequest::open($v2->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
        $r1->markDone();

        $res = $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'all']);

        $res->assertOk()
            ->assertJsonPath('requests.0.status', 'partial')     // 2대 중 1대 = 부분확인
            ->assertJsonPath('requests.0.buyer_name', 'ABC')
            ->assertJsonCount(2, 'requests.0.vehicles');

        $body = $res->json();
        $flat = json_encode($body, JSON_UNESCAPED_UNICODE);
        foreach (['margin', 'rrn', 'purchase_price', 'amount'] as $leak) {
            $this->assertStringNotContainsString($leak, $flat, "응답에 {$leak} 가 샜다(§3 화이트리스트)");
        }
    }

    public function test_index_is_scoped_to_own_vehicles(): void
    {
        $mine = $this->salesman('mine@ex.com');
        $theirs = $this->salesman('theirs@ex.com');
        BoardRequest::open($this->vehicle($mine->id, '11가1111')->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'mine@ex.com');
        BoardRequest::open($this->vehicle($theirs->id, '99하9999')->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'theirs@ex.com');

        $res = $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'mine@ex.com']);

        $res->assertOk()->assertJsonCount(1, 'requests');
        $this->assertStringNotContainsString('99하9999', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_cancel_closes_open_lines_only(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $batch = 'b-cancel';
        $done = BoardRequest::open($this->vehicle($sm->id, '11가1111', $buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
        $open = BoardRequest::open($this->vehicle($sm->id, '22나2222', $buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
        $done->markDone();

        $this->signedPost("/api/internal/board/requests/{$batch}/cancel", ['salesman_email' => 'a@ex.com'])
            ->assertOk()->assertJsonPath('cancelled', 1);

        // 이미 확인된 라인은 회신 기록이라 남는다.
        $this->assertSame(BoardRequest::STATUS_DONE, $done->fresh()->status);
        $this->assertSame(BoardRequest::STATUS_CANCELLED, $open->fresh()->status);

        $this->signedPost("/api/internal/board/requests/{$batch}/cancel", ['salesman_email' => 'a@ex.com'])
            ->assertStatus(409);
    }

    public function test_unsigned_request_is_rejected(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $this->postJson('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_PAYMENT,
            'vehicle_ids' => [$v->id],
        ])->assertStatus(401);

        $this->assertSame(0, BoardRequest::count());
    }
}
