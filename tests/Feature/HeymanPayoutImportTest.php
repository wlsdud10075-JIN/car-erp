<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExternalSettlementPay;
use App\Models\Salesman;
use App\Models\Setting;
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

    /** 연결 = 처음 한 번 직접 고름(자동 매칭 없음) → 적용하면 기억해 다음부터 미리 골라 둔다 (jin 2026-11-04). */
    public function test_first_pick_is_manual_and_remembered_after_apply(): void
    {
        config(['services.erp_peer.hmac_secret' => self::SECRET, 'services.erp_peer.heyman_url' => 'https://heyman.test']);
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $live = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true, 'phone' => '010-2111-4540']);
        Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true])->delete();   // 소프트 삭제된 옛 행 — 후보 아님
        $park = Salesman::create(['name' => '박병훈', 'type' => 'freelance', 'is_active' => true]);
        Http::fake(['heyman.test/*' => Http::response(['month' => '2026-10', 'batches' => [['id' => 5]], 'people' => [
            ['salesman_id' => 1, 'name' => '무사백', 'type' => 'employee', 'payout' => 2_240_619, 'phone' => '010-2111-4540'],
            ['salesman_id' => 9, 'name' => '박병훈', 'type' => 'freelance', 'payout' => 1_374_468, 'phone' => '010-4985-6986'],
        ]])]);
        $this->actingAs($admin);

        $c = Volt::test('erp.salesmen.index')->assertSee('data-heyman-import-button', false)->call('openHeymanImport');
        $this->assertSame(['무사백'], array_column($c->get('heymanRows'), 'name'), '프리랜서(박병훈)는 가져오지 않는다 — jin 11-04 «사내직원만»');
        $this->assertSame([''], array_values($c->get('heymanPick')), '처음엔 자동으로 고르지 않는다');
        $this->assertNotContains($live->id + 1, array_column($c->instance()->heymanCandidates(), 'id'), '지워진 옛 행은 후보 아님');
        $c->set('heymanPick.0', (string) $live->id);
        $this->assertTrue($c->get('heymanUse')[0], '고르면 넣기가 같이 켜진다');
        $c->call('applyHeymanImport');

        $this->assertSame(2_240_619, ExternalSettlementPay::heymanFor($live->id, '2026-10'));
        $this->assertNull(ExternalSettlementPay::heymanFor($park->id, '2026-10'), '프리랜서는 안 넣는다');
        $this->assertSame(['1' => $live->id], Setting::heymanPayoutLinks());
        $this->assertSame(1, AuditLog::where('column_name', 'heyman_pay_2026-10')->count());

        $again = Volt::test('erp.salesmen.index')->call('openHeymanImport');
        $this->assertSame([(string) $live->id], array_values($again->get('heymanPick')), '두 번째부터는 기억한 사람이 미리 골라진다');
        $again->call('applyHeymanImport');
        $this->assertSame(1, AuditLog::where('column_name', 'heyman_pay_2026-10')->count(), '같은 값은 다시 안 쓴다');
    }

    /** 공개 범위 「사내직원」 칸 밖 계정 — 버튼이 없고 열 수도 없다(금액이 보이고 후보가 비던 것, jin 11-04 제보). */
    public function test_people_outside_the_employee_column_cannot_import(): void
    {
        config(['services.erp_peer.hmac_secret' => self::SECRET, 'services.erp_peer.heyman_url' => 'https://heyman.test']);
        $outside = User::factory()->create(['permission' => 'manager', 'role' => '관리', 'email_verified_at' => now()]);
        Setting::query()->updateOrCreate(['key' => 'payroll_viewers'], ['value' => json_encode(['employee' => []]), 'type' => 'string']);
        Setting::flushRiskParamMemo();
        $this->actingAs($outside);

        Volt::test('erp.salesmen.index')->assertDontSee('data-heyman-import-button', false)
            ->call('openHeymanImport')->assertForbidden();
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
