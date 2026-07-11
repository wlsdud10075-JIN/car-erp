<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\Documents\PdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * board 포털 → 판매계약서 전자서명 발급 API (POST /api/internal/board/signing-requests).
 * HMAC(POST 바디 canonical 포함) + salesman_id 본인격리 + SigningSessionService 재사용.
 */
class BoardSigningRequestTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        config(['services.board_read.hmac_secret' => $this->secret]);
        Storage::fake(config('filesystems.vehicle_docs_disk'));

        $this->app->instance(PdfConverter::class, new class extends PdfConverter
        {
            public function fromSpreadsheet(Spreadsheet $spreadsheet): string
            {
                return "%PDF-1.4\n% test\n";
            }
        });
    }

    private function signedPost(string $path, array $payload)
    {
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $canonical = "POST\n".$path."?\n".$ts."\n".$body;
        $sig = hash_hmac('sha256', $canonical, $this->secret);

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.$sig, 'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function exportVehicle(int $salesmanId, int $buyerId, string $vn): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $vn, 'sales_channel' => 'export', 'salesman_id' => $salesmanId,
            'buyer_id' => $buyerId, 'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => '2026-06-01', 'sale_price' => 5000, 'purchase_date' => '2026-06-01',
        ]);
    }

    public function test_board_issues_signing_session_for_own_vehicles(): void
    {
        $me = Salesman::create(['name' => 'ME', 'email' => 'me@a.com', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'TOKYO', 'contact_email' => 'b@t.jp', 'is_active' => true]);
        $v = $this->exportVehicle($me->id, $buyer->id, '11가1111');

        $res = $this->signedPost('/api/internal/board/signing-requests', [
            'salesman_email' => 'me@a.com',
            'vehicle_ids' => [$v->id],
        ]);

        $res->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('buyer.name', 'TOKYO');
        $this->assertStringContainsString('/sign/', $res->json('signed_url'));
        $this->assertDatabaseHas('signed_contracts', ['buyer_id' => $buyer->id, 'status' => 'pending']);
    }

    public function test_board_cannot_issue_for_other_salesman_vehicle(): void
    {
        $me = Salesman::create(['name' => 'ME', 'email' => 'me@a.com', 'is_active' => true]);
        $other = Salesman::create(['name' => 'OTHER', 'email' => 'other@a.com', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $v = $this->exportVehicle($other->id, $buyer->id, '22나2222');

        $this->signedPost('/api/internal/board/signing-requests', [
            'salesman_email' => 'me@a.com',
            'vehicle_ids' => [$v->id],
        ])->assertStatus(403);

        $this->assertDatabaseCount('signed_contracts', 0);
    }

    public function test_bad_hmac_rejected(): void
    {
        $me = Salesman::create(['name' => 'ME', 'email' => 'me@a.com', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $v = $this->exportVehicle($me->id, $buyer->id, '33다3333');

        $this->postJson('/api/internal/board/signing-requests', [
            'salesman_email' => 'me@a.com', 'vehicle_ids' => [$v->id],
        ], [
            'X-Board-Signature' => 'sha256=deadbeef', 'X-Timestamp' => (string) now()->timestamp, 'X-Nonce' => (string) Str::uuid(),
        ])->assertStatus(401);
    }
}
