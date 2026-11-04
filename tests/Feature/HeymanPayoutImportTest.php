<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExternalSettlementPay;
use App\Models\Salesman;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Services\Payout\HeymanPayoutClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔗 헤이맨 정산 가져오기 (jin 2026-11-04 «heyman 정산 끝났는데 ssancar 로 왜 미러가 안 되지» → (나) 가져오기).
 *
 * 받는 쪽(heymanerp) = 승인된 kind=month 월정산의 사람별 실지급(박제) · HMAC(ERP_PEER_HMAC_SECRET, 없으면 401).
 * 보내는 쪽(ssancarerp) = 사내직원관리 버튼 → 미리보기(전화번호 → 유일한 이름 → 수동) → 체크한 줄만 writeHeyman.
 */
class HeymanPayoutImportTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'peer-test-secret';

    private function batch(string $kind, string $status, array $people): SettlementPayoutBatch
    {
        $u = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        return SettlementPayoutBatch::create([
            'kind' => $kind, 'month' => '2026-10', 'submitter_id' => $u->id, 'submitter_rank' => 2, 'current_level' => 3,
            'status' => $status, 'total_payout' => 0, 'settlement_count' => 0, 'submitted_at' => now(), 'decided_at' => now(),
            'breakdown_snapshot' => ['month' => '2026-10', 'people' => $people, 'totals' => []],
        ]);
    }

    /** 보내는 쪽 클라이언트가 만든 요청을 그대로 받아 받는 쪽 미들웨어에 다시 보낸다 — 헤더·정규 문자열이 갈리면 여기서 401. */
    private function signedHeaders(string $month): array
    {
        config(['services.erp_peer.hmac_secret' => self::SECRET, 'services.erp_peer.heyman_url' => 'https://heyman.test']);
        $captured = null;
        Http::fake(function ($req) use (&$captured) {
            $captured = $req;

            return Http::response(['month' => '2026-10', 'batches' => [], 'people' => []]);
        });
        HeymanPayoutClient::fetch($month);

        return [
            'X-Timestamp' => $captured->header('X-Timestamp')[0],
            'X-Nonce' => $captured->header('X-Nonce')[0],
            'X-Board-Signature' => $captured->header('X-Board-Signature')[0],
        ];
    }

    public function test_endpoint_is_closed_without_secret_and_rejects_bad_signatures(): void
    {
        config(['services.erp_peer.hmac_secret' => '']);
        $this->getJson('/api/internal/peer/payouts?month=2026-10')->assertStatus(401);

        config(['services.erp_peer.hmac_secret' => self::SECRET]);
        $this->withHeaders(['X-Timestamp' => (string) now()->timestamp, 'X-Nonce' => 'n1', 'X-Board-Signature' => 'sha256=bad'])
            ->getJson('/api/internal/peer/payouts?month=2026-10')->assertStatus(401);
    }

    public function test_client_signature_round_trips_and_only_approved_month_batches_count(): void
    {
        $musa = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true, 'phone' => '010-2111-4540']);
        $this->batch('month', 'approved', [['salesman_id' => $musa->id, 'name' => '무사백', 'type' => 'employee', 'payout' => 2_240_619]]);
        $this->batch('month', 'pending', [['salesman_id' => $musa->id, 'name' => '무사백', 'type' => 'employee', 'payout' => 999]]);
        $this->batch('leaver', 'approved', [['salesman_id' => $musa->id, 'name' => '무사백', 'type' => 'employee', 'payout' => 777]]);

        $headers = $this->signedHeaders('2026-10');
        $res = $this->withHeaders($headers + ['Accept' => 'application/json'])->get('/api/internal/peer/payouts?month=2026-10')->assertOk();   // getJson 은 GET 에도 본문 [] 을 붙여 서명이 갈린다 — 실제 클라이언트처럼 본문 없이
        $this->assertSame([['salesman_id' => $musa->id, 'name' => '무사백', 'type' => 'employee', 'payout' => 2_240_619, 'phone' => '010-2111-4540']], $res->json('people'));
        $this->withHeaders($headers)->get('/api/internal/peer/payouts?month=2026-10')->assertStatus(401);   // 같은 nonce 재사용 거부
    }

    public function test_import_matches_by_phone_then_unique_name_and_writes_only_changes(): void
    {
        config(['services.erp_peer.hmac_secret' => self::SECRET, 'services.erp_peer.heyman_url' => 'https://heyman.test']);
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $live = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true, 'phone' => '01021114540']);
        Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true])->delete();   // 소프트 삭제된 옛 행 — 후보 아님
        $park = Salesman::create(['name' => '박병훈', 'type' => 'freelance', 'is_active' => true]);
        Salesman::create(['name' => '조하', 'type' => 'employee', 'is_active' => true]);
        Salesman::create(['name' => '조하', 'type' => 'employee', 'is_active' => true]);   // 살아 있는 동명이인 → 자동 연결 안 함
        Http::fake(['heyman.test/*' => Http::response(['month' => '2026-10', 'batches' => [['id' => 5]], 'people' => [
            ['salesman_id' => 1, 'name' => '무사백', 'type' => 'employee', 'payout' => 2_240_619, 'phone' => '010-2111-4540'],
            ['salesman_id' => 9, 'name' => '박병훈', 'type' => 'freelance', 'payout' => 1_374_468, 'phone' => '010-4985-6986'],
            ['salesman_id' => 6, 'name' => '조하', 'type' => 'employee', 'payout' => 50_000, 'phone' => null],
        ]])]);
        $this->actingAs($admin);

        $c = Volt::test('erp.salesmen.index')->assertSee('data-heyman-import-button', false)
            ->call('openHeymanImport')->set('heymanMonth', '2026-10');
        $this->assertSame([(string) $live->id, (string) $park->id, ''], array_values($c->get('heymanPick')), '전화 → 유일한 이름 → 동명이인은 비움');
        $c->set('heymanUse.1', false)->call('applyHeymanImport');   // 박병훈은 체크 해제

        $this->assertSame(2_240_619, ExternalSettlementPay::heymanFor($live->id, '2026-10'));
        $this->assertNull(ExternalSettlementPay::heymanFor($park->id, '2026-10'), '체크 안 한 줄은 안 넣는다');
        $logs = AuditLog::where('column_name', 'heyman_pay_2026-10')->count();
        $this->assertSame(1, $logs);

        Volt::test('erp.salesmen.index')->call('openHeymanImport')->set('heymanMonth', '2026-10')->set('heymanUse.1', false)->call('applyHeymanImport');
        $this->assertSame(1, AuditLog::where('column_name', 'heyman_pay_2026-10')->count(), '같은 값은 다시 안 쓴다');
    }

    /** ⚠️ 승인 전에 누르면 — 「결재 중(누구 차례)」/「아직 안 올라옴」을 갈라 경고하고 아무것도 안 넣는다 (jin 2026-11-04). */
    public function test_warns_when_heyman_month_is_pending_or_missing(): void
    {
        $musa = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);
        $this->batch('month', 'pending', [['salesman_id' => $musa->id, 'name' => '무사백', 'type' => 'employee', 'payout' => 999]]);
        $headers = $this->signedHeaders('2026-10');
        $res = $this->withHeaders($headers)->get('/api/internal/peer/payouts?month=2026-10')->assertOk();
        $this->assertSame([], $res->json('people'), '결재 중 배치의 금액은 안 내보낸다');
        $this->assertCount(1, $res->json('pending'));

        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        Http::swap(new Factory);   // 위 signedHeaders() 의 가짜 응답이 먼저 잡히지 않게
        Http::fake(fn ($req) => str_contains($req->url(), 'month=2026-10')
            ? Http::response(['month' => '2026-10', 'batches' => [], 'pending' => [['id' => 7, 'waiting_for' => '대표 · 조태신']], 'people' => []])
            : Http::response(['month' => '2026-09', 'batches' => [], 'pending' => [], 'people' => []]));

        $c = Volt::test('erp.salesmen.index')->call('openHeymanImport')->set('heymanMonth', '2026-10');
        $this->assertStringContainsString('결재 중', (string) $c->get('heymanError'));
        $this->assertStringContainsString('대표 · 조태신', (string) $c->get('heymanError'));
        $c->call('applyHeymanImport');
        $this->assertSame(0, ExternalSettlementPay::count(), '아무것도 안 넣는다');

        $c->set('heymanMonth', '2026-09');
        $this->assertStringContainsString('올라오지 않았습니다', (string) $c->get('heymanError'));
    }

    public function test_button_is_hidden_without_config(): void
    {
        config(['services.erp_peer.hmac_secret' => '', 'services.erp_peer.heyman_url' => '']);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]));
        Volt::test('erp.salesmen.index')->assertDontSee('data-heyman-import-button', false);
    }
}
