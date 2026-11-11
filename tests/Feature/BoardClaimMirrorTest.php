<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
