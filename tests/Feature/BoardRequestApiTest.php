<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BoardRequest;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
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

    public function test_purchase_request_creates_one_request_per_vehicle(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v1 = $this->vehicle($sm->id, '11가1111');
        $v2 = $this->vehicle($sm->id, '22나2222');

        $res = $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_BALANCE,
            'vehicle_ids' => [$v1->id, $v2->id],
            'amount_krw' => 3_000_000,
        ]);

        $res->assertStatus(201)->assertJsonCount(2, 'created');

        // 매입 신호는 차량 1대가 단위 — 묶음이 따로 논다.
        $batches = BoardRequest::pluck('batch_id')->unique();
        $this->assertCount(2, $batches, '매입 신호 2건이 한 묶음으로 합쳐졌다 — 단위는 차량 1대다');
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
            'type' => BoardRequest::TYPE_PURCHASE_BALANCE,
            'vehicle_ids' => [$ok->id, $notMine->id],
            'amount_krw' => 3_000_000,
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
            'type' => BoardRequest::TYPE_PURCHASE_BALANCE,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 3_000_000,
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

    // ── 금액 (2026-08-11 §11-2 개정) ──────────────────────────────────────────

    /** 계약금·잔금은 금액을 싣는다. 저장은 `amount_krw` **하나뿐**. */
    public function test_purchase_deposit_and_balance_store_the_amount(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        foreach ([
            BoardRequest::TYPE_PURCHASE_DEPOSIT => 3_000_000,
            BoardRequest::TYPE_PURCHASE_BALANCE => 7_500_000,
        ] as $type => $amount) {
            $this->signedPost('/api/internal/board/requests', [
                'salesman_email' => 'a@ex.com',
                'type' => $type,
                'vehicle_ids' => [$v->id],
                'amount_krw' => $amount,
            ])->assertStatus(201)->assertJsonCount(1, 'created');

            $this->assertSame(
                $amount,
                BoardRequest::query()->where('type', $type)->value('amount_krw'),
                "{$type} 요청 금액이 저장되지 않았다 — 받는 사람이 얼마를 보낼지 알 수 없다"
            );
        }
    }

    /**
     * 🚫 **금액은 표시 전용이다** — 회계에 흘러들면 안 된다(§11-5 흡수 금지).
     * board 가 금액을 보내도 매입 미지급·잔금 행은 그대로여야 한다.
     */
    public function test_amount_never_touches_accounting(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        $v->update(['purchase_date' => '2026-08-01', 'purchase_price' => 10_000_000]);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 3_000_000,
        ])->assertStatus(201);

        $this->assertSame(0, $v->purchaseBalancePayments()->count(), '신호가 매입 잔금 행을 만들었다(§11-5)');
        $this->assertSame(
            10_000_000,
            (int) $v->fresh()->purchase_unpaid_amount,
            '요청 금액이 매입 미지급에 반영됐다 — 신호는 회계에 쓰지 않는다'
        );
    }

    /** 금액이 이 기능의 전부다 — 비면 조용히 null 로 넘기지 않고 거절한다. */
    public function test_amount_is_required_for_the_two_purchase_types(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        foreach ([BoardRequest::TYPE_PURCHASE_DEPOSIT, BoardRequest::TYPE_PURCHASE_BALANCE] as $type) {
            $this->signedPost('/api/internal/board/requests', [
                'salesman_email' => 'a@ex.com',
                'type' => $type,
                'vehicle_ids' => [$v->id],
            ])->assertStatus(422)->assertJsonPath('error', 'amount_required');
        }

        $this->assertSame(0, BoardRequest::count(), '금액 없는 요청이 만들어졌다');
    }

    /** 금액칸이 없는 type 에 금액이 딸려오면 저장하지 않는다(표시 자리가 없어 유령 데이터가 된다). */
    public function test_amount_is_dropped_for_types_that_do_not_carry_it(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v = $this->vehicle($sm->id, '11가1111', $buyer->id);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_SALE_PAYMENT_CONFIRM,
            'buyer_id' => $buyer->id,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 4_500_000,
        ])->assertStatus(201);

        $this->assertNull(BoardRequest::first()->amount_krw);
    }

    /**
     * 🛑 **구 `purchase_payment` 는 이제 거절한다** (2026-08-12). 계약금/매입잔금으로 쪼개졌고
     * board 신버전 전환이 운영 데이터로 확인됐다(heymanerp: 08-10 이후 구 신호 수신 0).
     *
     * ⚠️ 메시지에 **무엇으로 바꿔 보내야 하는지**가 들어 있어야 한다 — `in:` 기본 문구
     * ("selected type is invalid")만으로는 board 쪽에서 원인을 못 찾는다.
     */
    public function test_retired_purchase_payment_is_rejected_with_a_useful_message(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $res = $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_PAYMENT,
            'vehicle_ids' => [$v->id],
        ]);

        $res->assertStatus(422)->assertJsonPath('error', 'type_retired');
        $this->assertStringContainsString('purchase_balance', $res->json('message'));
        $this->assertSame(0, BoardRequest::count(), '폐기된 type 으로 신호가 만들어졌다');
    }

    /**
     * 💡 **금액을 고쳐 다시 보내면 갱신된다** (jin 2026-08-11) — 300만을 350만으로 정정했는데
     * 옛 금액이 남으면 받는 사람이 틀린 돈을 보낸다. 행은 새로 안 만든다(중복 뱃지 없음 = 멱등 유지).
     */
    public function test_resending_a_different_amount_updates_it_in_place(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $send = fn (int $amount) => $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => $amount,
        ]);

        $send(3_000_000)->assertStatus(201);
        $res = $send(3_500_000)->assertStatus(201);

        $this->assertSame(1, BoardRequest::count(), '정정 재전송이 행을 새로 만들었다 — 뱃지가 두 개 뜬다');
        $this->assertSame(3_500_000, BoardRequest::first()->amount_krw, '고쳐 보낸 금액이 반영되지 않았다');

        // board 는 created·skipped 만 읽는다 — 갱신분이 created 에 있어야 "0건 전송"으로 안 보인다.
        $res->assertJsonPath('created', ['11가1111'])
            ->assertJsonPath('updated', ['11가1111'])
            ->assertJsonCount(0, 'skipped');
    }

    /** 같은 금액 재전송(오클릭)은 갱신도 알림도 없다 — 종전대로 already_open. */
    public function test_resending_the_same_amount_is_still_skipped(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $payload = [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 3_000_000,
        ];
        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);

        $this->signedPost('/api/internal/board/requests', $payload)
            ->assertStatus(201)
            ->assertJsonCount(0, 'created')
            ->assertJsonCount(0, 'updated')
            ->assertJsonPath('skipped.0.reason', 'already_open');
    }

    /** 금액을 안 받는 type 은 갱신 대상이 아니다 — 종전대로 skip. */
    public function test_resend_does_not_update_types_without_an_amount(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v->update(['buyer_id' => $buyer->id]);
        $payload = [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_SALE_PAYMENT_CONFIRM,   // 판매대금확인 — 금액칸 없음
            'buyer_id' => $buyer->id,
            'vehicle_ids' => [$v->id],
        ];
        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);

        $this->signedPost('/api/internal/board/requests', $payload + ['amount_krw' => 9_000_000])
            ->assertStatus(201)
            ->assertJsonPath('skipped.0.reason', 'already_open');

        $this->assertNull(BoardRequest::first()->amount_krw);
    }

    /** 확인된(done) 요청은 갱신 대상이 아니다 — 재전송하면 새 요청이 열려야 한다. */
    public function test_resend_after_confirmation_opens_a_new_request(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $payload = [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 3_000_000,
        ];
        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);
        BoardRequest::first()->markDone();

        $this->signedPost('/api/internal/board/requests', ['amount_krw' => 500_000] + $payload)
            ->assertStatus(201)->assertJsonCount(1, 'created')->assertJsonCount(0, 'updated');

        $this->assertSame(2, BoardRequest::count());
    }

    /** 응답에 금액이 실려야 한다 — board 는 전송 후 입력칸을 비우므로 여기가 유일한 확인처다. */
    public function test_index_returns_the_requested_amount(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT, 'a@ex.com', amountKrw: 3_000_000);

        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com'])
            ->assertOk()
            ->assertJsonPath('requests.0.type', BoardRequest::TYPE_PURCHASE_DEPOSIT)
            ->assertJsonPath('requests.0.amount_krw', 3_000_000)
            ->assertJsonPath('requests.0.vehicles.0.amount_krw', 3_000_000);
    }

    public function test_index_returns_batch_status_without_money(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v1 = $this->vehicle($sm->id, '11가1111', $buyer->id);
        $v2 = $this->vehicle($sm->id, '22나2222', $buyer->id);
        $batch = 'b-1';
        $r1 = BoardRequest::raise($v1->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
        BoardRequest::raise($v2->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
        $r1->markDone();

        $res = $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'all']);

        $res->assertOk()
            ->assertJsonPath('requests.0.status', 'partial')     // 2대 중 1대 = 부분확인
            ->assertJsonPath('requests.0.buyer_name', 'ABC')
            ->assertJsonCount(2, 'requests.0.vehicles');

        // ⚠️ 'amount' 는 이제 화이트리스트 안이다(`amount_krw`, 2026-08-11 §11-2 개정) — 뺀 건 그 때문이다.
        //    나머지는 그대로 금지: 마진·RRN·매입가는 board 에 흘러선 안 된다.
        $body = $res->json();
        $flat = json_encode($body, JSON_UNESCAPED_UNICODE);
        foreach (['margin', 'rrn', 'purchase_price', 'sale_price'] as $leak) {
            $this->assertStringNotContainsString($leak, $flat, "응답에 {$leak} 가 샜다(§3 화이트리스트)");
        }
    }

    /** 거절(2026-10-12) — 라인·묶음에 reject_reason·resolved_at·resolved_by 가 실리고, confirmed_at 은 null 그대로. status=rejected 로 걸러진다. */
    public function test_index_exposes_rejection_fields(): void
    {
        $sm = $this->salesman('a@ex.com');
        $user = User::factory()->create(['permission' => 'admin', 'name' => '재무담당', 'email_verified_at' => now()]);
        $v = $this->vehicle($sm->id, '11가1111');
        $r = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'a@ex.com', amountKrw: 2_000_000);
        $r->markRejected($user, '바이어 입금 전');

        $res = $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'all']);
        $res->assertOk()
            ->assertJsonPath('requests.0.status', 'rejected')
            ->assertJsonPath('requests.0.reject_reason', '바이어 입금 전')
            ->assertJsonPath('requests.0.resolved_by', '재무담당')
            ->assertJsonPath('requests.0.vehicles.0.status', 'rejected')
            ->assertJsonPath('requests.0.vehicles.0.reject_reason', '바이어 입금 전')
            ->assertJsonPath('requests.0.vehicles.0.confirmed_at', null);
        $this->assertNotNull($res->json('requests.0.resolved_at'));
        $this->assertSame($res->json('requests.0.resolved_at'), $res->json('requests.0.vehicles.0.resolved_at'));

        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'rejected'])
            ->assertOk()->assertJsonCount(1, 'requests');
        // 기본(open) 폴링엔 안 보인다 — 라인 단위 필터(종전 동작). board 는 status=all 로 받는다.
        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com'])
            ->assertOk()->assertJsonCount(0, 'requests');
    }

    /** since=(ISO8601, updated_at >= since, 포함) — status 와 독립. 없으면 전량. 깨진 값은 422. */
    public function test_index_since_filters_by_updated_at(): void
    {
        $sm = $this->salesman('a@ex.com');
        $old = BoardRequest::raise($this->vehicle($sm->id, '11가1111')->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'a@ex.com', amountKrw: 1);
        $mid = BoardRequest::raise($this->vehicle($sm->id, '22나2222')->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'a@ex.com', amountKrw: 1);
        $new = BoardRequest::raise($this->vehicle($sm->id, '33다3333')->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'a@ex.com', amountKrw: 1);
        BoardRequest::whereKey($old->id)->update(['updated_at' => '2026-10-01 09:00:00']);
        BoardRequest::whereKey($mid->id)->update(['updated_at' => '2026-10-05 09:00:00']);
        BoardRequest::whereKey($new->id)->update(['updated_at' => '2026-10-10 09:00:00']);

        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'all', 'since' => '2026-10-05T09:00:00+09:00'])
            ->assertOk()->assertJsonCount(2, 'requests');   // 포함 경계 — mid 가 들어온다
        // 같은 순간을 Z 로 보내도 같은 결과(서버가 app.timezone 으로 맞춘다) — 2026-10-05T09:00+09:00 = 2026-10-05T00:00Z
        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'all', 'since' => '2026-10-05T00:00:00Z'])
            ->assertOk()->assertJsonCount(2, 'requests');
        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'all'])
            ->assertOk()->assertJsonCount(3, 'requests');   // since 없으면 전량(구 board)
        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'all', 'since' => 'yesterday-ish'])
            ->assertStatus(422)->assertJsonPath('error', 'since_invalid');
    }

    public function test_index_is_scoped_to_own_vehicles(): void
    {
        $mine = $this->salesman('mine@ex.com');
        $theirs = $this->salesman('theirs@ex.com');
        BoardRequest::raise($this->vehicle($mine->id, '11가1111')->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'mine@ex.com');
        BoardRequest::raise($this->vehicle($theirs->id, '99하9999')->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'theirs@ex.com');

        $res = $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'mine@ex.com']);

        $res->assertOk()->assertJsonCount(1, 'requests');
        $this->assertStringNotContainsString('99하9999', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_cancel_closes_open_lines_only(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $batch = 'b-cancel';
        $done = BoardRequest::raise($this->vehicle($sm->id, '11가1111', $buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
        $open = BoardRequest::raise($this->vehicle($sm->id, '22나2222', $buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch);
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
            'type' => BoardRequest::TYPE_PURCHASE_BALANCE,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 3_000_000,
        ])->assertStatus(401);

        $this->assertSame(0, BoardRequest::count());
    }

    // ── 🔁 재전송 (jin 2026-10-27, board 세션 §11 합의안 M1~M4) ──────────────────────

    private function sendDeposit(Vehicle $v, int $amount, bool $resend = false)
    {
        $payload = [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => $amount,
        ];
        if ($resend) {
            $payload['resend'] = true;
        }

        return $this->signedPost('/api/internal/board/requests', $payload);
    }

    /** 같은 금액 + resend=true → 행은 그대로, 회차 +1, created 와 resent 에 담긴다(updated 는 비어 있다). */
    public function test_resend_flag_bumps_the_count_and_is_reported(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        $this->sendDeposit($v, 3_000_000)->assertStatus(201);

        $this->sendDeposit($v, 3_000_000, resend: true)
            ->assertStatus(201)
            ->assertJsonPath('created', ['11가1111'])
            ->assertJsonPath('resent', ['11가1111'])
            ->assertJsonCount(0, 'updated')
            ->assertJsonCount(0, 'skipped');

        $this->assertSame(1, BoardRequest::count(), '재전송이 행을 새로 만들었다 — 뱃지가 두 개 뜬다');
        $row = BoardRequest::first();
        $this->assertSame(1, $row->resent_count);
        $this->assertNotNull($row->last_resent_at);
        $this->assertSame(3_000_000, $row->amount_krw);
    }

    /** 금액 다름 + resend=true → 금액 갱신 + 회차 +1 (updated·resent 둘 다). */
    public function test_resend_with_a_new_amount_updates_and_counts(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        $this->sendDeposit($v, 3_000_000)->assertStatus(201);

        $this->sendDeposit($v, 3_500_000, resend: true)
            ->assertStatus(201)
            ->assertJsonPath('updated', ['11가1111'])
            ->assertJsonPath('resent', ['11가1111']);

        $row = BoardRequest::first();
        $this->assertSame(3_500_000, $row->amount_krw);
        $this->assertSame(1, $row->resent_count);
    }

    /** 행당 5회. 6회째는 skipped(resend_limit) — 행 무변경·알림 없음. */
    public function test_resend_limit_rejects_the_sixth(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        $this->sendDeposit($v, 3_000_000)->assertStatus(201);
        for ($i = 1; $i <= BoardRequest::RESEND_LIMIT; $i++) {
            $this->sendDeposit($v, 3_000_000, resend: true)->assertStatus(201)->assertJsonPath('resent', ['11가1111']);
        }
        $this->assertSame(BoardRequest::RESEND_LIMIT, BoardRequest::first()->resent_count);

        $this->sendDeposit($v, 3_000_000, resend: true)
            ->assertStatus(201)
            ->assertJsonCount(0, 'created')
            ->assertJsonCount(0, 'resent')
            ->assertJsonPath('skipped.0.reason', 'resend_limit');
        $this->assertSame(BoardRequest::RESEND_LIMIT, BoardRequest::first()->resent_count, '6회째가 회차를 올렸다');
        $this->assertSame(1, BoardRequest::count());
    }

    /** resend=true 인데 열린 행이 없으면 플래그를 무시하고 새 요청(stale 칩으로 눌러도 영업이 두 번 안 누르게). */
    public function test_resend_flag_without_an_open_request_creates_a_new_one(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');

        $this->sendDeposit($v, 3_000_000, resend: true)
            ->assertStatus(201)
            ->assertJsonPath('created', ['11가1111'])
            ->assertJsonCount(0, 'resent');
        $this->assertSame(0, BoardRequest::first()->resent_count);
    }

    /** 판매대금확인엔 재전송이 없다(M4) — 플래그를 떼고 종전 already_open. 422 가 아니다. */
    public function test_resend_flag_is_ignored_for_sale_confirm(): void
    {
        $sm = $this->salesman('a@ex.com');
        $buyer = Buyer::create(['name' => 'DONI', 'is_active' => true]);
        $v = $this->vehicle($sm->id, '11가1111', $buyer->id);
        $payload = ['salesman_email' => 'a@ex.com', 'type' => BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'vehicle_ids' => [$v->id], 'buyer_id' => $buyer->id];
        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);

        $this->signedPost('/api/internal/board/requests', $payload + ['resend' => true])
            ->assertStatus(201)
            ->assertJsonCount(0, 'resent')
            ->assertJsonPath('skipped.0.reason', 'already_open');
        $this->assertSame(0, BoardRequest::first()->resent_count);
    }

    /** GET 라인·head 에 resent_count·last_resent_at, head 에 resend_limit(M3 — board 는 분모를 여기서 읽는다). */
    public function test_index_exposes_resend_fields(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        $this->sendDeposit($v, 3_000_000)->assertStatus(201);
        $this->sendDeposit($v, 3_000_000, resend: true)->assertStatus(201);

        $this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'open'])
            ->assertOk()
            ->assertJsonPath('requests.0.resent_count', 1)
            ->assertJsonPath('requests.0.resend_limit', BoardRequest::RESEND_LIMIT)
            ->assertJsonPath('requests.0.vehicles.0.resent_count', 1);
        $this->assertNotNull($this->signedGet('/api/internal/board/requests', ['salesman_email' => 'a@ex.com', 'status' => 'open'])->json('requests.0.last_resent_at'));
    }

    /** 감사로그 board_request_resent — new_value = 회차. 연동 경로라 행위자는 빈다. */
    public function test_resend_writes_an_audit_row(): void
    {
        $sm = $this->salesman('a@ex.com');
        $v = $this->vehicle($sm->id, '11가1111');
        $this->sendDeposit($v, 3_000_000)->assertStatus(201);
        $this->sendDeposit($v, 3_000_000, resend: true)->assertStatus(201);

        $log = AuditLog::where('action', 'board_request_resent')->first();
        $this->assertNotNull($log);
        $this->assertSame('1', $log->new_value);
        $this->assertSame(BoardRequest::first()->id, (int) $log->auditable_id);
    }
}
