<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * board → ERP 클레임 미러 (jin 2026-11-11, docs/design/claim-management.md §10).
 * 확정 금액 하나 · 기각 = 0원 · 바이어 = 본문 → 그 차 → 없으면 422 · 멱등 board_claim_id + revision · 바이어 「클레임」 탭.
 */
class BoardClaimMirrorTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
    }

    private function sendClaim(array $payload)
    {
        $path = '/api/internal/board/claims';
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', "POST\n".$path."?\n".$ts."\n".$body, $this->secret);

        return $this->postJson($path, $payload, ['X-Board-Signature' => 'sha256='.$sig, 'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid()]);
    }

    private function fixture(): array
    {
        $s = Salesman::create(['name' => '담당A', 'is_active' => true, 'type' => 'employee']);
        $buyer = Buyer::create(['name' => 'VILLA KOHA', 'is_active' => true, 'salesman_id' => $s->id]);
        $vehicle = Vehicle::create(['vehicle_number' => '07버5628', 'sales_channel' => 'export', 'salesman_id' => $s->id, 'buyer_id' => $buyer->id]);

        return [$buyer, $vehicle];
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'board_claim_id' => 77, 'revision' => 1, 'vehicle_id' => null, 'buyer_id' => null, 'buyer_name' => 'VILLA KOHA',
            'vehicle_number' => '07버5628', 'vin' => 'SALCA2BN3HH682215', 'confirmed_amount' => 500, 'currency' => 'EUR',
            'compensation_type' => 'credit', 'result' => 'accepted', 'result_reason' => 'oil pan 누유 · injectors', 'approved_at' => '2026-11-11 10:00:00',
            'approved_by_name' => '대표', 'handler_name' => 'LORENA LEE', 'salesman_email' => 'musa@x.com', 'buyer_claim_count' => 3,
        ], $over);
    }

    public function test_unsigned_request_is_rejected(): void
    {
        $this->postJson('/api/internal/board/claims', $this->payload())->assertStatus(401);
        $this->assertSame(0, VehicleClaim::count());
    }

    public function test_accepted_claim_is_stored_with_buyer_from_the_vehicle_and_audited(): void
    {
        [$buyer, $vehicle] = $this->fixture();
        $res = $this->sendClaim($this->payload(['vehicle_id' => $vehicle->id]))->assertStatus(201)->assertJsonPath('status', 'created');

        $c = VehicleClaim::firstOrFail();
        $this->assertSame($res->json('erp_claim_id'), $c->id);
        $this->assertSame($buyer->id, $c->buyer_id);   // 본문엔 buyer_id 없음 → 그 차의 바이어
        $this->assertSame('500.00', $c->confirmed_amount);
        $this->assertSame(3, $c->board_buyer_claim_count);
        $this->assertSame(1, AuditLog::where('action', 'board_claim_received')->count());
    }

    public function test_declined_is_stored_as_zero(): void
    {
        [$buyer] = $this->fixture();
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'result' => 'declined', 'confirmed_amount' => 1500]))->assertStatus(201);
        $this->assertSame('0.00', VehicleClaim::first()->confirmed_amount);   // jin 11-11 «0원은 기각»

        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'board_claim_id' => 99, 'compensation_type' => null]))->assertStatus(201);   // board 담당자가 방식을 안 고르면 null
        $this->assertNull(VehicleClaim::where('board_claim_id', 99)->value('compensation_type'));
    }

    public function test_no_buyer_anywhere_is_422_and_nothing_is_stored(): void
    {
        $s = Salesman::create(['name' => '담당A', 'is_active' => true, 'type' => 'employee']);
        $orphan = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'salesman_id' => $s->id]);
        $res = $this->sendClaim($this->payload(['vehicle_id' => $orphan->id]))->assertStatus(422);
        $this->assertStringContainsString('바이어를 먼저 정해 주세요', $res->json('message'));
        $this->assertSame(0, VehicleClaim::count());
    }

    public function test_resend_updates_only_on_a_newer_revision(): void
    {
        [$buyer] = $this->fixture();
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id]))->assertStatus(201);
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'confirmed_amount' => 999]))->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame('500.00', VehicleClaim::first()->confirmed_amount);

        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'revision' => 2, 'confirmed_amount' => 700]))->assertOk()->assertJsonPath('status', 'updated');
        $this->assertSame('700.00', VehicleClaim::first()->confirmed_amount);
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'revision' => 1, 'confirmed_amount' => 500]))->assertStatus(409)->assertJsonPath('status', 'stale');
        $this->assertSame(1, VehicleClaim::count());
    }

    /** 판매탭 「클레임 사용」 흔적만 만든다(서비스 경로는 ClaimUsageTest 가 본다). */
    private function markUsed(VehicleClaim $claim, Vehicle $vehicle, float $amount): void
    {
        $uid = User::factory()->create()->id;
        $rh = DB::table('receivable_histories')->insertGetId(['vehicle_id' => $vehicle->id, 'collected_at' => now()->toDateString(), 'collector_id' => $uid, 'method' => 'offset', 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vehicle_claim_usages')->insert(['vehicle_claim_id' => $claim->id, 'vehicle_id' => $vehicle->id, 'receivable_history_id' => $rh, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** 🔒 판매탭에서 이미 쓴 클레임은 새 회차로 못 바꾼다(jin 11-24) — 쓴 돈이 확정 금액보다 커지는 걸 막는다. */
    public function test_a_used_claim_is_not_overwritten_by_a_newer_revision(): void
    {
        [$buyer, $vehicle] = $this->fixture();
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id]))->assertStatus(201);
        $this->markUsed(VehicleClaim::first(), $vehicle, 400);

        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'revision' => 2, 'confirmed_amount' => 300]))->assertStatus(409)->assertJsonPath('status', 'used');
        $this->assertSame('500.00', VehicleClaim::first()->confirmed_amount);
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id]))->assertOk()->assertJsonPath('status', 'unchanged');   // 같은 회차 재전송은 그대로 멱등
    }

    private function sendBundle(array $payload)
    {
        $path = '/api/internal/board/claims/bundle';
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', "POST\n".$path."?\n".$ts."\n".$body, $this->secret);

        return $this->postJson($path, $payload, ['X-Board-Signature' => 'sha256='.$sig, 'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid()]);
    }

    private function bundle(Buyer $buyer, array $items, array $over = []): array
    {
        $total = collect($items)->sum(fn ($i) => ($i['result'] ?? 'accepted') === 'declined' ? 0 : $i['confirmed_amount']);

        return array_merge(['board_bundle_id' => 'ssancarboard-B12', 'buyer_id' => $buyer->id, 'currency' => 'EUR', 'total' => $total, 'requested_by_email' => 'lorena@x.com', 'items' => $items], $over);
    }

    /** 📦 묶음(jin 11-24, §20) — 전부 받고, 이미 단건으로 받은 같은 회차는 연결, 같은 묶음 재전송은 멱등. */
    public function test_bundle_stores_all_items_links_a_single_and_is_idempotent(): void
    {
        [$buyer, $vehicle] = $this->fixture();
        $other = Vehicle::create(['vehicle_number' => '12가3456', 'sales_channel' => 'export', 'salesman_id' => $vehicle->salesman_id, 'buyer_id' => $buyer->id]);
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'board_claim_id' => 81, 'confirmed_amount' => 200]))->assertStatus(201);   // 먼저 단건으로 보낸 것

        $items = [
            $this->payload(['board_claim_id' => 81, 'confirmed_amount' => 200]),
            $this->payload(['board_claim_id' => 82, 'vehicle_id' => $other->id, 'vehicle_number' => '12가3456', 'confirmed_amount' => 300, 'salesman_email' => 'other@x.com']),
        ];
        $res = $this->sendBundle($this->bundle($buyer, $items))->assertStatus(201)->assertJsonPath('status', 'accepted');
        $this->assertSame(['linked', 'created'], array_column($res->json('items'), 'status'));
        $this->assertSame(2, VehicleClaim::where('board_bundle_id', 'ssancarboard-B12')->count());
        $this->assertSame($buyer->id, VehicleClaim::where('board_claim_id', 82)->value('buyer_id'));

        $again = $this->sendBundle($this->bundle($buyer, $items))->assertOk();
        $this->assertSame(['unchanged', 'unchanged'], array_column($again->json('items'), 'status'));
        $this->assertSame(2, VehicleClaim::count());

        // 같은 묶음 id 로 구성이 다르면 거절
        $this->sendBundle($this->bundle($buyer, [$items[0]]))->assertStatus(409)->assertJsonPath('status', 'bundle_mismatch');

        // 바이어 드로어 — 머리 줄 하나(합계 500) + 펼치면 차량별
        $this->actingAs(User::factory()->create(['permission' => 'admin']));
        $page = Volt::test('erp.buyers.index')->call('openEdit', $buyer->id);
        $groups = $page->instance()->buyerClaims['groups'];
        $this->assertCount(1, $groups);
        $this->assertSame(500.0, $groups[0]['total']);
        $page->assertSee('data-claim-bundle="ssancarboard-B12"', false)->assertSee('EUR 500.00')->assertSee('12가3456')->assertSee(__('buyer.claims.bundle', ['n' => 2]));
    }

    /** 전부 받거나 전부 거절 — 한 건이라도 걸리면 아무것도 안 남는다. */
    public function test_bundle_is_all_or_nothing(): void
    {
        [$buyer, $vehicle] = $this->fixture();
        $otherBuyer = Buyer::create(['name' => 'OTHER', 'is_active' => true]);
        $foreign = Vehicle::create(['vehicle_number' => '99하9999', 'sales_channel' => 'export', 'salesman_id' => $vehicle->salesman_id, 'buyer_id' => $otherBuyer->id]);
        $ok = $this->payload(['board_claim_id' => 91, 'confirmed_amount' => 100]);

        // 다른 바이어 차
        $this->sendBundle($this->bundle($buyer, [$ok, $this->payload(['board_claim_id' => 92, 'vehicle_id' => $foreign->id, 'buyer_id' => $otherBuyer->id])]))->assertStatus(422);
        // 다른 통화
        $this->sendBundle($this->bundle($buyer, [$ok, $this->payload(['board_claim_id' => 93, 'currency' => 'USD'])]))->assertStatus(422);
        // 합계 불일치
        $this->sendBundle($this->bundle($buyer, [$ok], ['total' => 999]))->assertStatus(422);
        $this->assertSame(0, VehicleClaim::count());

        // 다른 묶음에 이미 든 건
        $this->sendBundle($this->bundle($buyer, [$ok], ['board_bundle_id' => 'ssancarboard-B1']))->assertStatus(201);
        $this->sendBundle($this->bundle($buyer, [$ok, $this->payload(['board_claim_id' => 94, 'confirmed_amount' => 50])]))->assertStatus(409)->assertJsonPath('status', 'other_bundle');
        $this->assertSame(1, VehicleClaim::count(), '94 도 안 들어간다');

        // 이미 쓴 클레임의 새 회차가 묶음에 있으면 전체 거절
        $this->markUsed(VehicleClaim::first(), $vehicle, 50);
        $this->sendBundle($this->bundle($buyer, [$this->payload(['board_claim_id' => 91, 'revision' => 2, 'confirmed_amount' => 10])], ['board_bundle_id' => 'ssancarboard-B1']))->assertStatus(409)->assertJsonPath('status', 'used');
        $this->assertSame('100.00', VehicleClaim::first()->confirmed_amount);
    }

    public function test_buyer_drawer_claims_tab_shows_count_results_and_sums(): void
    {
        [$buyer] = $this->fixture();
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id]))->assertStatus(201);
        $this->sendClaim($this->payload(['buyer_id' => $buyer->id, 'board_claim_id' => 78, 'result' => 'declined', 'result_reason' => '검수 영상 없음']))->assertStatus(201);

        $this->actingAs(User::factory()->create(['permission' => 'admin']));
        $page = Volt::test('erp.buyers.index')->call('openEdit', $buyer->id);
        $cl = $page->instance()->buyerClaims;
        $this->assertSame(2, $cl['count']);
        $this->assertSame(1, $cl['accepted']);
        $this->assertSame(1, $cl['declined']);
        $this->assertSame(['EUR' => 500.0], $cl['sums']);
        $page->assertSee('클레임')->assertSee('검수 영상 없음')->assertSee('EUR 500.00');
    }
}
