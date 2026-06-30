<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\ShippingRequest;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BoardPortalApiTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
    }

    /**
     * 권위 스펙 §1 canonical 서명으로 GET 호출.
     * ⚠️ getJson 은 GET 에도 빈 JSON 바디([])를 보내 canonical 이 어긋남 → 실제 board 처럼 빈 바디 get() 사용.
     */
    private function signedGet(string $path, array $query, ?int $ts = null, ?string $nonce = null, array $headerOverride = [])
    {
        ksort($query);
        $ts ??= now()->timestamp;
        $nonce ??= (string) Str::uuid();
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";
        $sig = hash_hmac('sha256', $canonical, $this->secret);

        return $this->get($path.'?'.http_build_query($query), array_merge([
            'X-Board-Signature' => 'sha256='.$sig,
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => $nonce,
        ], $headerOverride));
    }

    private function signedPost(string $path, array $payload, ?string $nonce = null)
    {
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $nonce ??= (string) Str::uuid();
        $canonical = "POST\n".$path."?\n".$ts."\n".$body;
        $sig = hash_hmac('sha256', $canonical, $this->secret);

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.$sig, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce,
        ]);
    }

    private function salesman(string $email, bool $active = true): Salesman
    {
        return Salesman::create(['name' => 'S'.Str::random(3), 'email' => $email, 'is_active' => $active]);
    }

    private function exportVehicle(int $salesmanId, string $vn): Vehicle
    {
        return Vehicle::create(['vehicle_number' => $vn, 'sales_channel' => 'export', 'salesman_id' => $salesmanId]);
    }

    public function test_valid_signature_returns_own_receivables_only(): void
    {
        $me = $this->salesman('me@a.com');
        $other = $this->salesman('other@a.com');
        Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 1200]);
        Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export', 'salesman_id' => $other->id, 'sale_price' => 9999, 'currency' => 'USD', 'exchange_rate' => 1200]);

        $res = $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'me@a.com'])->assertOk();

        $res->assertJsonPath('count', 1);
        $res->assertJsonFragment(['vehicle_number' => '11가1111']);
        $res->assertJsonMissing(['vehicle_number' => '22나2222']);   // 타 영업 차 0건 (IDOR)
    }

    public function test_fx_missing_returns_null_krw_not_paid(): void
    {
        $me = $this->salesman('me@a.com');
        Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 500, 'currency' => 'USD', 'exchange_rate' => 0]);

        $res = $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'me@a.com'])->assertOk();
        $res->assertJsonPath('data.0.unpaid_krw', null);   // 환율0 = null (완납 아님)
        $res->assertJsonPath('data.0.currency', 'USD');
    }

    public function test_response_never_leaks_rrn_account_or_margin(): void
    {
        $me = $this->salesman('me@a.com');
        Vehicle::create(['vehicle_number' => '44라4444', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 1200, 'nice_reg_owner_rrn' => '900101-1234567']);

        $body = $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'me@a.com'])->assertOk()->getContent();
        foreach (['nice_reg_owner_rrn', '900101', 'purchase_seller_account', 'sales_margin', 'vat_margin', 'total_margin'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "응답에 $forbidden 노출 금지");
        }
    }

    public function test_inactive_salesman_forbidden(): void
    {
        $this->salesman('gone@a.com', active: false);
        $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'gone@a.com'])->assertStatus(403);
    }

    public function test_unknown_email_forbidden(): void
    {
        $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'nobody@a.com'])->assertStatus(403);
    }

    public function test_bad_signature_rejected(): void
    {
        $this->salesman('me@a.com');
        $this->get('/api/internal/board/receivables?salesman_email=me@a.com', [
            'X-Board-Signature' => 'sha256=deadbeef', 'X-Timestamp' => (string) now()->timestamp, 'X-Nonce' => (string) Str::uuid(),
        ])->assertStatus(401);
    }

    public function test_missing_secret_rejects_all(): void
    {
        config(['services.board_read.hmac_secret' => '']);
        $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'me@a.com'])->assertStatus(401);
    }

    public function test_stale_timestamp_rejected(): void
    {
        $this->salesman('me@a.com');
        $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'me@a.com'], ts: now()->timestamp - 600)
            ->assertStatus(401);   // 10분 전 = 윈도우(300초) 초과
    }

    public function test_replayed_nonce_rejected(): void
    {
        $this->salesman('me@a.com');
        $nonce = (string) Str::uuid();
        $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'me@a.com'], nonce: $nonce)->assertOk();
        $this->signedGet('/api/internal/board/receivables', ['salesman_email' => 'me@a.com'], nonce: $nonce)->assertStatus(401); // 같은 nonce 재사용
    }

    public function test_other_finance_endpoints_smoke(): void
    {
        $me = $this->salesman('me@a.com');
        Vehicle::create(['vehicle_number' => '55마5555', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 1200, 'purchase_price' => 800]);

        foreach (['finance', 'sales', 'purchases', 'settlements'] as $ep) {
            $this->signedGet("/api/internal/board/$ep", ['salesman_email' => 'me@a.com'])->assertOk();
        }
    }

    public function test_by_buyer_groups_sales_and_payout_scoped(): void
    {
        $me = $this->salesman('me@a.com');
        $other = $this->salesman('other@a.com');
        $tokyo = Buyer::create(['name' => 'TOKYO', 'is_active' => true, 'country_id' => null]);
        $osaka = Buyer::create(['name' => 'OSAKA', 'is_active' => true, 'country_id' => null]);

        // 내 차: TOKYO 2대(USD 1000+500) + OSAKA 1대
        $t1 = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 1200, 'buyer_id' => $tokyo->id]);
        Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 500, 'currency' => 'USD', 'exchange_rate' => 1200, 'buyer_id' => $tokyo->id]);
        Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 700, 'currency' => 'JPY', 'exchange_rate' => 9, 'buyer_id' => $osaka->id]);
        // 타 영업 차 — 같은 바이어라도 안 섞여야(IDOR)
        Vehicle::create(['vehicle_number' => '99자9999', 'sales_channel' => 'export', 'salesman_id' => $other->id, 'sale_price' => 9999, 'currency' => 'USD', 'exchange_rate' => 1200, 'buyer_id' => $tokyo->id]);

        // TOKYO 차 1대에 확정정산 — "나에게 준 이득"
        Settlement::create(['vehicle_id' => $t1->id, 'salesman_id' => $me->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 150000, 'settlement_status' => 'confirmed']);

        $res = $this->signedGet('/api/internal/board/by-buyer', ['salesman_email' => 'me@a.com'])->assertOk();

        $res->assertJsonPath('count', 2);   // TOKYO·OSAKA 2개 바이어
        // payout 큰 바이어부터 → TOKYO 선두
        $res->assertJsonPath('data.0.buyer', 'TOKYO');
        $res->assertJsonPath('data.0.vehicle_count', 2);
        $res->assertJsonPath('data.0.sales_by_currency.USD', 1500);   // 1000+500, 타 영업 9999 안 섞임
        $res->assertJsonPath('data.0.payout_total_krw', 150000);
        $this->assertStringNotContainsString('9999', $res->getContent());
    }

    // ── ③ 선적요청 ──────────────────────────────────────────

    public function test_shippable_returns_own_sale_done_only(): void
    {
        $me = $this->salesman('me@a.com');
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true, 'country_id' => null]);
        $v = $this->exportVehicle($me->id, '11가1111');
        $v->update(['buyer_id' => $buyer->id]);
        Vehicle::where('id', $v->id)->update(['progress_status_cache' => '판매완료']);   // 선적 가능 전제
        $this->exportVehicle($me->id, '99자9999');   // 판매완료 아님 → 제외

        $res = $this->signedGet('/api/internal/board/shippable', ['salesman_email' => 'me@a.com'])->assertOk();
        $res->assertJsonPath('count', 1);
        $res->assertJsonFragment(['vehicle_number' => '11가1111']);
        $res->assertJsonMissing(['vehicle_number' => '99자9999']);
    }

    public function test_store_creates_then_rerequest_updates_in_place(): void
    {
        $me = $this->salesman('me@a.com');
        $v = $this->exportVehicle($me->id, '22나2222');

        $this->signedPost('/api/internal/board/shipping-request', [
            'vehicle_ids' => [$v->id], 'shipping_method' => 'RORO', 'salesman_email' => 'me@a.com',
        ])->assertStatus(201)->assertJsonPath('created', [$v->id]);

        $this->assertDatabaseHas('shipping_requests', ['vehicle_id' => $v->id, 'status' => 'requested', 'shipping_method' => 'RORO']);
        $this->assertDatabaseHas('task_alarms', ['type' => 'shipping_requested', 'vehicle_id' => $v->id, 'target_role' => '수출통관']);

        $batchId = ShippingRequest::where('vehicle_id', $v->id)->value('batch_id');

        // 재요청 — 방식 정정(RORO→CONTAINER). 새 row 안 만들고 제자리 갱신, 같은 batch 유지.
        $this->signedPost('/api/internal/board/shipping-request', [
            'vehicle_ids' => [$v->id], 'shipping_method' => 'CONTAINER', 'salesman_email' => 'me@a.com',
        ])->assertStatus(201)->assertJsonPath('updated', [$v->id]);

        $this->assertSame(1, ShippingRequest::where('vehicle_id', $v->id)->count());
        $this->assertDatabaseHas('shipping_requests', ['vehicle_id' => $v->id, 'shipping_method' => 'CONTAINER', 'batch_id' => $batchId]);
    }

    public function test_store_skips_rerequest_when_in_progress(): void
    {
        $me = $this->salesman('me@a.com');
        $v = $this->exportVehicle($me->id, '33다3333');
        ShippingRequest::create([
            'batch_id' => 'b1', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO',
            'requested_by_email' => 'me@a.com', 'status' => 'in_progress', 'requested_at' => now(),
        ]);

        // 관리가 처리중(in_progress) → 재요청 skip, 갱신 안 됨
        $this->signedPost('/api/internal/board/shipping-request', [
            'vehicle_ids' => [$v->id], 'shipping_method' => 'CONTAINER', 'salesman_email' => 'me@a.com',
        ])->assertStatus(201)->assertJsonPath('skipped', [$v->id]);
        $this->assertDatabaseHas('shipping_requests', ['vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'status' => 'in_progress']);
    }

    public function test_shippable_excludes_open_bundle_vehicle_now_in_bundles(): void
    {
        // v2 (2026-06-30): 이미 open 묶음(requested/in_progress)에 든 차는 /shippable 에서 제외 → /bundles 가 담당.
        $me = $this->salesman('me@a.com');
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true, 'country_id' => null]);
        $v = $this->exportVehicle($me->id, '11가1111');
        $v->update(['buyer_id' => $buyer->id]);
        Vehicle::where('id', $v->id)->update(['progress_status_cache' => '판매완료']);
        ShippingRequest::create([
            'batch_id' => 'b1', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO',
            'requested_by_email' => 'me@a.com', 'status' => 'requested', 'requested_at' => now(),
        ]);

        // 새로 묶을 차 후보(shippable)서는 제외
        $this->signedGet('/api/internal/board/shippable', ['salesman_email' => 'me@a.com'])
            ->assertOk()->assertJsonPath('count', 0);

        // 대신 영속 묶음(/bundles)에 보임
        $res = $this->signedGet('/api/internal/board/bundles', ['salesman_email' => 'me@a.com'])->assertOk();
        $res->assertJsonPath('count', 1);
        $res->assertJsonPath('data.0.ship_status', 'requested');
        $res->assertJsonPath('data.0.shipping_method', 'RORO');
    }

    public function test_store_skips_other_salesman_vehicle(): void
    {
        $me = $this->salesman('me@a.com');
        $other = $this->salesman('other@a.com');
        $theirs = $this->exportVehicle($other->id, '33다3333');

        $this->signedPost('/api/internal/board/shipping-request', [
            'vehicle_ids' => [$theirs->id], 'shipping_method' => 'CONTAINER', 'salesman_email' => 'me@a.com',
        ])->assertStatus(201)->assertJsonPath('skipped', [$theirs->id]);   // IDOR — 타 영업 차 skip
        $this->assertDatabaseMissing('shipping_requests', ['vehicle_id' => $theirs->id]);
    }

    // ── ①② 서류 다운로드 ─────────────────────────────────────

    public function test_documents_streams_allowed_type_and_logs(): void
    {
        $me = $this->salesman('me@a.com');
        $v = $this->exportVehicle($me->id, '44라4444');

        $this->signedGet('/api/internal/board/documents/roro_invoice_packing', ['salesman_email' => 'me@a.com', 'ids' => (string) $v->id])
            ->assertOk();

        $this->assertDatabaseHas('document_access_logs', [
            'vehicle_id' => $v->id, 'document_type' => 'roro_invoice_packing', 'source' => 'board_api', 'actor_email' => 'me@a.com', 'user_id' => null,
        ]);
    }

    public function test_documents_rejects_rrn_bearing_type(): void
    {
        $me = $this->salesman('me@a.com');
        $v = $this->exportVehicle($me->id, '55마5555');
        // 말소서류 = RRN 포함 → board 차단
        $this->signedGet('/api/internal/board/documents/deregistration', ['salesman_email' => 'me@a.com', 'ids' => (string) $v->id])
            ->assertStatus(403);
    }

    public function test_documents_rejects_other_salesman_vehicle(): void
    {
        $me = $this->salesman('me@a.com');
        $other = $this->salesman('other@a.com');
        $theirs = $this->exportVehicle($other->id, '66바6666');

        $this->signedGet('/api/internal/board/documents/roro_contract', ['salesman_email' => 'me@a.com', 'ids' => (string) $theirs->id])
            ->assertStatus(403);
    }

    // ── 연동 B v3 — board 드로어 바이어/컨사이니 드롭다운 (영업 본인 스코프) ──────

    public function test_buyers_returns_own_active_buyers_only(): void
    {
        $me = $this->salesman('me@a.com');
        $other = $this->salesman('other@a.com');
        $mine = Buyer::create(['name' => 'Mine', 'salesman_id' => $me->id, 'is_active' => true]);
        Buyer::create(['name' => 'MyDead', 'salesman_id' => $me->id, 'is_active' => false]);
        Buyer::create(['name' => 'Theirs', 'salesman_id' => $other->id, 'is_active' => true]);

        $res = $this->signedGet('/api/internal/board/buyers', ['salesman_email' => 'me@a.com'])->assertOk();
        $res->assertJsonPath('count', 1);
        $res->assertJsonFragment(['id' => $mine->id, 'name' => 'Mine']);
        $res->assertJsonMissing(['name' => 'MyDead']);    // 비활성 제외
        $res->assertJsonMissing(['name' => 'Theirs']);    // 타 영업 제외 (IDOR)
    }

    public function test_buyers_response_has_no_pii(): void
    {
        $me = $this->salesman('me@a.com');
        Buyer::create([
            'name' => 'PII', 'salesman_id' => $me->id, 'is_active' => true,
            'contact_phone' => '010-1111-2222', 'contact_email' => 'secret@x.com', 'address' => '비밀주소',
        ]);

        $body = $this->signedGet('/api/internal/board/buyers', ['salesman_email' => 'me@a.com'])->assertOk()->getContent();
        foreach (['010-1111-2222', 'secret@x.com', '비밀주소'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "buyers 응답에 $forbidden 노출 금지");
        }
    }

    public function test_consignees_returns_active_under_owned_buyer(): void
    {
        $me = $this->salesman('me@a.com');
        $buyer = Buyer::create(['name' => 'B', 'salesman_id' => $me->id, 'is_active' => true]);
        $c = Consignee::create(['name' => 'C1', 'buyer_id' => $buyer->id, 'is_active' => true]);
        Consignee::create(['name' => 'Cdead', 'buyer_id' => $buyer->id, 'is_active' => false]);

        $res = $this->signedGet('/api/internal/board/consignees', ['salesman_email' => 'me@a.com', 'buyer_id' => (string) $buyer->id])->assertOk();
        $res->assertJsonPath('count', 1);
        $res->assertJsonFragment(['id' => $c->id, 'name' => 'C1']);
        $res->assertJsonMissing(['name' => 'Cdead']);
    }

    public function test_consignees_idor_other_salesman_buyer_returns_empty(): void
    {
        $me = $this->salesman('me@a.com');
        $other = $this->salesman('other@a.com');
        $theirBuyer = Buyer::create(['name' => 'TB', 'salesman_id' => $other->id, 'is_active' => true]);
        Consignee::create(['name' => 'TC', 'buyer_id' => $theirBuyer->id, 'is_active' => true]);

        // 본인 소유 아닌 buyer_id 로 조회 → 빈 목록 (타 영업 컨사이니 열람 차단).
        $res = $this->signedGet('/api/internal/board/consignees', ['salesman_email' => 'me@a.com', 'buyer_id' => (string) $theirBuyer->id])->assertOk();
        $res->assertJsonPath('count', 0);
    }

    // ── v2 선적·B/L 묶음 (2026-06-30 회의) ──────────────────────

    public function test_sync_creates_updates_cancels_and_locks(): void
    {
        $me = $this->salesman('me@a.com');
        $other = $this->salesman('other@a.com');
        $a = $this->exportVehicle($me->id, '11가1111');
        $b = $this->exportVehicle($me->id, '22나2222');
        $c = $this->exportVehicle($me->id, '33다3333');
        $theirs = $this->exportVehicle($other->id, '99자9999');

        // c = 관리 착수(in_progress) → sync 로 못 바꿈(locked)
        ShippingRequest::create(['batch_id' => 'bx', 'vehicle_id' => $c->id, 'shipping_method' => 'RORO', 'requested_by_email' => 'me@a.com', 'status' => 'in_progress', 'requested_at' => now()]);

        // 1차 sync — a,b 생성 / c 잠금 / theirs IDOR skip
        $res = $this->signedPost('/api/internal/board/shipping-requests/sync', [
            'salesman_email' => 'me@a.com',
            'bundles' => [['shipping_method' => 'RORO', 'vehicle_ids' => [$a->id, $b->id, $c->id, $theirs->id]]],
        ])->assertOk();
        $res->assertJsonCount(2, 'created');
        $res->assertJsonPath('locked.0', $c->id);
        $res->assertJsonPath('skipped.0', $theirs->id);
        $this->assertDatabaseHas('shipping_requests', ['vehicle_id' => $a->id, 'status' => 'requested']);
        $this->assertDatabaseMissing('shipping_requests', ['vehicle_id' => $theirs->id]);

        // 2차 sync — b 빠짐 → b 자동취소 / a 갱신(CONTAINER)
        $res2 = $this->signedPost('/api/internal/board/shipping-requests/sync', [
            'salesman_email' => 'me@a.com',
            'bundles' => [['shipping_method' => 'CONTAINER', 'vehicle_ids' => [$a->id]]],
        ])->assertOk();
        $res2->assertJsonPath('updated.0', $a->id);
        $res2->assertJsonPath('cancelled.0', $b->id);
        $this->assertDatabaseHas('shipping_requests', ['vehicle_id' => $b->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('shipping_requests', ['vehicle_id' => $a->id, 'shipping_method' => 'CONTAINER']);
    }

    public function test_bundle_fx_missing_is_not_fake_fully_paid(): void
    {
        // ⚠️ cash_audit 교훈 — 환율 미입력(cache null)을 0 완납으로 coerce 금지.
        $me = $this->salesman('me@a.com');
        $v = Vehicle::create(['vehicle_number' => '44라4444', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 500, 'currency' => 'USD', 'exchange_rate' => 0]);
        ShippingRequest::create(['batch_id' => 'bz', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'requested_by_email' => 'me@a.com', 'status' => 'requested', 'requested_at' => now()]);

        $res = $this->signedGet('/api/internal/board/bundles', ['salesman_email' => 'me@a.com'])->assertOk();
        $res->assertJsonPath('data.0.fx_missing_count', 1);
        $res->assertJsonPath('data.0.unpaid_total_krw', 0);
        $res->assertJsonPath('data.0.fully_paid', false);
    }

    public function test_bl_request_sets_status_and_blocks_other_salesman(): void
    {
        $me = $this->salesman('me@a.com');
        $this->salesman('other@a.com');
        $v = $this->exportVehicle($me->id, '55마5555');
        ShippingRequest::create(['batch_id' => 'bb', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'requested_by_email' => 'me@a.com', 'status' => 'in_progress', 'requested_at' => now()]);

        // 타 영업이 내 batch 에 bl-request → 403 (IDOR)
        $this->signedPost('/api/internal/board/bundles/bb/bl-request', ['salesman_email' => 'other@a.com', 'bl_type' => 'surrender'])
            ->assertStatus(403);

        // 본인 → bl_status=requested + bl_type + 관리 알람
        $this->signedPost('/api/internal/board/bundles/bb/bl-request', ['salesman_email' => 'me@a.com', 'bl_type' => 'surrender'])
            ->assertOk();
        $this->assertDatabaseHas('shipping_requests', ['batch_id' => 'bb', 'bl_status' => 'requested', 'bl_type' => 'surrender']);
        $this->assertDatabaseHas('task_alarms', ['type' => 'bl_requested', 'vehicle_id' => $v->id, 'target_role' => '관리']);
    }

    public function test_change_request_requires_own_in_progress(): void
    {
        $me = $this->salesman('me@a.com');
        $v = $this->exportVehicle($me->id, '66바6666');
        $sr = ShippingRequest::create(['batch_id' => 'bc', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'requested_by_email' => 'me@a.com', 'status' => 'requested', 'requested_at' => now()]);

        // requested(관리 미착수) → 변경요청 대상 아님 403
        $this->signedPost('/api/internal/board/shipping-requests/change-request', ['salesman_email' => 'me@a.com', 'vehicle_id' => $v->id, 'note' => 'x'])
            ->assertStatus(403);

        // in_progress → 플래그 + 관리 알람
        $sr->update(['status' => 'in_progress']);
        $this->signedPost('/api/internal/board/shipping-requests/change-request', ['salesman_email' => 'me@a.com', 'vehicle_id' => $v->id, 'note' => '바꿔주세요'])
            ->assertOk();
        $this->assertNotNull(ShippingRequest::find($sr->id)->change_requested_at);
        $this->assertDatabaseHas('task_alarms', ['type' => 'shipping_change_requested', 'vehicle_id' => $v->id, 'target_role' => '관리']);
    }

    public function test_bundle_surrender_unpaid_warning(): void
    {
        $me = $this->salesman('me@a.com');
        $v = Vehicle::create(['vehicle_number' => '77사7777', 'sales_channel' => 'export', 'salesman_id' => $me->id, 'sale_price' => 1000, 'currency' => 'USD', 'exchange_rate' => 1200]);
        ShippingRequest::create(['batch_id' => 'bw', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'bl_type' => 'surrender', 'bl_status' => 'requested', 'requested_by_email' => 'me@a.com', 'status' => 'in_progress', 'requested_at' => now()]);

        $res = $this->signedGet('/api/internal/board/bundles', ['salesman_email' => 'me@a.com'])->assertOk();
        $res->assertJsonPath('data.0.surrender_unpaid_warning', true);
    }

    public function test_bundles_returns_buyer_consignee_objects_with_options(): void
    {
        // board 선언형 sync 재전송용 — buyer/consignee 는 {id,name} 객체 + consignees 옵션 필수.
        $me = $this->salesman('me@a.com');
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true, 'country_id' => null, 'salesman_id' => $me->id]);
        $c1 = Consignee::create(['name' => 'C1', 'buyer_id' => $buyer->id, 'is_active' => true]);
        $v = $this->exportVehicle($me->id, '11가1111');
        $v->update(['buyer_id' => $buyer->id]);
        ShippingRequest::create(['batch_id' => 'bk', 'vehicle_id' => $v->id, 'buyer_id' => $buyer->id, 'consignee_id' => $c1->id, 'shipping_method' => 'RORO', 'requested_by_email' => 'me@a.com', 'status' => 'requested', 'requested_at' => now()]);

        $res = $this->signedGet('/api/internal/board/bundles', ['salesman_email' => 'me@a.com'])->assertOk();
        $res->assertJsonPath('data.0.buyer.id', $buyer->id);
        $res->assertJsonPath('data.0.buyer.name', 'TOKYO');
        $res->assertJsonPath('data.0.consignee.id', $c1->id);
        $res->assertJsonPath('data.0.consignees.0.id', $c1->id);   // 편집용 컨사이니 옵션
    }

    public function test_bl_cancel_resets_status_idor_and_blocks_after_issued(): void
    {
        $me = $this->salesman('me@a.com');
        $this->salesman('other@a.com');
        $v = $this->exportVehicle($me->id, '22나2222');
        ShippingRequest::create(['batch_id' => 'bd', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO', 'bl_type' => 'surrender', 'bl_status' => 'requested', 'requested_by_email' => 'me@a.com', 'status' => 'in_progress', 'requested_at' => now()]);

        // 타 영업 무름 → 403 (IDOR)
        $this->signedPost('/api/internal/board/bundles/bd/bl-cancel', ['salesman_email' => 'other@a.com'])->assertStatus(403);

        // 본인 → bl_status none (bl_type 유지)
        $this->signedPost('/api/internal/board/bundles/bd/bl-cancel', ['salesman_email' => 'me@a.com'])->assertOk();
        $this->assertDatabaseHas('shipping_requests', ['batch_id' => 'bd', 'bl_status' => 'none', 'bl_type' => 'surrender']);

        // 관리 발급(issued) 후 = 무름 불가 409
        ShippingRequest::where('batch_id', 'bd')->update(['bl_status' => 'issued']);
        $this->signedPost('/api/internal/board/bundles/bd/bl-cancel', ['salesman_email' => 'me@a.com'])->assertStatus(409);
    }
}
