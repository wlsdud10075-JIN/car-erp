<?php

namespace Tests\Feature;

use App\Models\Salesman;
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

    private function salesman(string $email, bool $active = true): Salesman
    {
        return Salesman::create(['name' => 'S'.Str::random(3), 'email' => $email, 'is_active' => $active]);
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
}
