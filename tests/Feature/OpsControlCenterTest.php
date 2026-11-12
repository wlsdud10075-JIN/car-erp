<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyErpPeerHmac;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Ops\OpsHub;
use App\Services\Ops\OpsSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🛰️ 관제센터 2단계 (jin 11-12) — jin 단독(시스템관리자 + OPS_OWNER_EMAILS) · 서명 읽기 · 이상 감지 · 다른 서버가 죽어도 버팀 · 돈 숫자 없음.
 */
class OpsControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ops-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ops.hmac_secret' => self::SECRET, 'services.ops.owner_emails' => ['jin@example.com']]);
        config(['services.ops.sites' => json_encode([
            ['key' => 'ssancar-erp', 'name' => 'ssancarerp', 'kind' => 'erp', 'self' => true, 'host' => 'heymancar.com'],
            ['key' => 'heyman-erp', 'name' => 'heymanerp', 'kind' => 'erp', 'url' => 'https://heysellcar.test', 'host' => 'heysellcar.com'],
            ['key' => 'ssancar-board', 'name' => 'ssancarboard', 'kind' => 'board', 'url' => 'https://board.test', 'host' => 'board.heymancar.com'],
            ['key' => 'gpu', 'name' => '회사 GPU PC', 'kind' => 'gpu', 'notes' => ['Tailscale 100.110.133.112'], 'ssh' => 'ssh SSANCAR@100.110.133.112'],
        ])]);
        Cache::flush();
    }

    private function signed(string $path = '/api/internal/ops/summary', ?string $secret = null): array
    {
        $ts = now()->timestamp;

        return ['X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(),
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', VerifyErpPeerHmac::canonical('GET', $path, [], $ts), $secret ?? self::SECRET)];
    }

    public function test_only_the_owner_super_account_opens_it(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $jin = User::factory()->create(['permission' => 'super', 'email' => 'JIN@example.com', 'email_verified_at' => now()]);
        $otherSuper = User::factory()->create(['permission' => 'super', 'email' => 'other@example.com', 'email_verified_at' => now()]);
        $adminWithEmail = User::factory()->create(['permission' => 'admin', 'email' => 'jin2@example.com', 'email_verified_at' => now()]);
        config(['services.ops.owner_emails' => ['jin@example.com', 'jin2@example.com']]);

        $this->actingAs($jin)->get('/admin/control')->assertOk()->assertSee('CONTROL CENTER');
        $this->actingAs($otherSuper)->get('/admin/control')->assertNotFound();       // super 라도 목록 밖이면 없는 페이지
        $this->actingAs($adminWithEmail)->get('/admin/control')->assertForbidden();  // super 가 아니면 라우트 단계에서 막힘

        $this->assertStringContainsString('admin/control', $this->actingAs($jin)->get('/admin/settings')->getContent());
        $this->assertStringNotContainsString('admin/control', $this->actingAs($otherSuper)->get('/admin/settings')->getContent());

        config(['services.ops.owner_emails' => []]);
        $this->assertFalse($jin->fresh()->isOpsOwner(), '목록이 비면 아무도 못 연다');
    }

    public function test_summary_api_needs_the_signature(): void
    {
        $this->getJson('/api/internal/ops/summary')->assertStatus(401);
        $this->withHeaders($this->signed(secret: 'wrong') + ['Accept' => 'application/json'])->get('/api/internal/ops/summary')->assertStatus(401);

        $res = $this->withHeaders($this->signed() + ['Accept' => 'application/json'])->get('/api/internal/ops/summary')->assertOk();
        $this->assertSame(['generated_at', 'company', 'server', 'routines', 'health', 'links', 'anomalies'], array_keys($res->json()));

        config(['services.ops.hmac_secret' => '']);
        $this->withHeaders($this->signed() + ['Accept' => 'application/json'])->get('/api/internal/ops/summary')->assertStatus(401);   // 시크릿 비면 fail-closed
    }

    public function test_summary_carries_no_money_figures(): void
    {
        $json = json_encode(OpsSummary::collect(), JSON_UNESCAPED_UNICODE);
        foreach (['receivable', 'unpaid', 'sale_price', 'purchase_price', 'payout', 'margin'] as $word) {
            $this->assertStringNotContainsString($word, $json, "요약에 돈 칸({$word})이 실리면 안 된다");
        }
    }

    public function test_the_63bu3056_shape_is_flagged_as_a_unit_error(): void
    {
        Vehicle::create(['vehicle_number' => '63부3056', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1530,
            'sale_price' => 13_798_204, 'sale_date' => '2026-11-09', 'buyer_undecided' => true, 'purchase_price' => 13_800_000]);
        Vehicle::create(['vehicle_number' => '정상차', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1493,
            'sale_price' => 9_080, 'sale_date' => '2026-11-09', 'buyer_undecided' => true, 'purchase_price' => 13_800_000]);

        $hit = collect(OpsSummary::anomalies())->firstWhere('kind', '판매가 단위 의심');
        $this->assertNotNull($hit);
        $this->assertSame(3, $hit['level']);
        $this->assertSame(1, $hit['count']);
        $this->assertStringContainsString('63부3056', $hit['text']);
    }

    public function test_hub_survives_dead_and_unconnected_sites(): void
    {
        Http::fake([
            'heysellcar.test/*' => fn () => throw new ConnectionException('timed out'),
            'board.test/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
        $by = collect(OpsHub::collect())->keyBy('site.key');

        $this->assertTrue($by['ssancar-erp']['ok']);
        $this->assertArrayHasKey('server', $by['ssancar-erp']['data']);
        $this->assertFalse($by['heyman-erp']['ok']);
        $this->assertStringContainsString('응답 없음', $by['heyman-erp']['error']);
        $this->assertSame('미연결(요약 API 없음)', $by['ssancar-board']['error']);
        $this->assertSame('미설치', $by['gpu']['error']);
    }

    public function test_hub_signature_is_accepted_by_the_receiving_side(): void
    {
        $sent = null;
        Http::fake(function ($request) use (&$sent) {
            $sent = $request;

            return Http::response(OpsSummary::collect());
        });
        config(['services.ops.sites' => json_encode([['key' => 'heyman-erp', 'name' => 'heymanerp', 'kind' => 'erp', 'url' => 'https://heysellcar.test']])]);
        $this->assertTrue(OpsHub::collect()[0]['ok']);

        // 허브가 붙인 헤더를 그대로 받는 쪽 라우트에 넣으면 통과해야 한다(두 쪽이 같은 정규 문자열)
        $headers = collect(['X-Timestamp', 'X-Nonce', 'X-Board-Signature'])->mapWithKeys(fn ($h) => [$h => $sent->header($h)[0]])->all();
        $this->withHeaders($headers + ['Accept' => 'application/json'])->get('/api/internal/ops/summary')->assertOk();   // getJson 은 빈 본문 [] 을 실어 서명 대상이 달라진다 — 허브처럼 본문 없이
    }

    public function test_page_shows_sites_anomalies_and_version_mismatch(): void
    {
        $peer = OpsSummary::collect();
        $peer['server']['version'] = 'deadbeef';   // 3사 버전이 어긋남
        Http::fake(['heysellcar.test/*' => Http::response($peer), 'board.test/*' => Http::response([], 404)]);
        $jin = User::factory()->create(['permission' => 'super', 'email' => 'jin@example.com', 'email_verified_at' => now()]);

        $html = Volt::actingAs($jin)->test('admin.control')->html();
        foreach (['ssancarerp', 'heymanerp', 'ssancarboard', '회사 GPU PC', 'heymancar.com 열기', 'ssh SSANCAR@100.110.133.112', 'Tailscale 100.110.133.112', '버전 어긋남', '미연결'] as $txt) {
            $this->assertStringContainsString($txt, $html);
        }

        $c = Volt::actingAs($jin)->test('admin.control')->call('pickSite', 'heyman-erp');
        $this->assertSame('heyman-erp', $c->get('siteFilter'));
    }
}
