<?php

namespace Tests\Feature;

use App\Console\Commands\AssistantHealthCheck;
use App\Http\Middleware\VerifyErpPeerHmac;
use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Ops\OpsHub;
use App\Services\Ops\OpsRoutines;
use App\Services\Ops\OpsSignature;
use App\Services\Ops\OpsSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            ['key' => 'gpu', 'name' => '회사 GPU PC', 'kind' => 'etc', 'probe' => 'assistant', 'notes' => ['Tailscale 100.110.133.112'], 'ssh' => 'ssh SSANCAR@100.110.133.112'],
            ['key' => 'ssancar-web', 'name' => 'ssancar.com', 'kind' => 'etc', 'url' => 'https://ssancar.test', 'path' => '/_ops/summary.php', 'host' => 'ssancar.com'],
        ])]);
        Cache::flush();
        Storage::fake('local');   // 이 PC 의 brain.json(세컨드 브레인)이 끼어들지 않게
    }

    private function signed(string $path = '/api/internal/ops/summary', ?string $secret = null): array
    {
        return OpsSignature::headers('GET', $path, [], '', $secret ?? self::SECRET);
    }

    /** v1(nonce 밖) 서명 — 11-12 전환 뒤 거부돼야 한다 */
    private function signedV1(string $path = '/api/internal/ops/summary'): array
    {
        $ts = now()->timestamp;

        return ['X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(),
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', VerifyErpPeerHmac::canonical('GET', $path, [], $ts), self::SECRET)];
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
        $this->withHeaders($this->signedV1() + ['Accept' => 'application/json'])->get('/api/internal/ops/summary')->assertStatus(401);   // v1(nonce 밖)은 11-12 전환 뒤 거부

        // v2 = nonce 가 서명 안(jin 11-12) — nonce 만 바꾼 재전송은 서명이 안 맞아 401
        $h = $this->signed();
        $h['X-Nonce'] = (string) Str::uuid();
        $this->withHeaders($h + ['Accept' => 'application/json'])->get('/api/internal/ops/summary')->assertStatus(401);

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

    public function test_alimtalk_failure_count_drops_when_acknowledged(): void
    {
        $failed = AlimtalkLog::create(['template_code' => 'erp_daily_summary', 'phone' => '01000000000', 'status' => 'failed', 'error' => 'K108']);
        $this->assertSame(1, OpsSummary::links()['alimtalk_failed_today']);

        $failed->forceFill(['acknowledged_at' => now()])->save();
        $this->assertSame(0, OpsSummary::links()['alimtalk_failed_today'], '확인 처리한 실패는 관제센터 숫자에서 빠진다(jin 11-13)');
    }

    public function test_dangling_consignee_skips_excel_imported_vehicles_but_dangling_buyer_does_not(): void
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $con = Consignee::create(['buyer_id' => $buyer->id, 'name' => '0.5', 'is_active' => true]);
        $deadBuyer = Buyer::create(['name' => 'AP Bilar ehf.', 'is_active' => true]);
        $base = ['sales_channel' => 'export', 'currency' => 'USD', 'purchase_price' => 1_000_000, 'buyer_id' => $buyer->id, 'consignee_id' => $con->id];
        Vehicle::create(['vehicle_number' => '적재차', 'imported_at' => now()] + $base);
        Vehicle::create(['vehicle_number' => '손입력차'] + $base);
        $liveCon = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'B Trading', 'is_active' => true]);
        Vehicle::create(['vehicle_number' => '수출칸정상차', 'export_consignee_id' => $liveCon->id] + $base);   // 판매 쪽만 끊김 — 서류에 쓰는 칸이 살아 있어 손볼 게 없다(jin 11-13)
        Vehicle::create(['vehicle_number' => '적재차바이어끊김', 'imported_at' => now(), 'buyer_id' => $deadBuyer->id] + $base);
        $con->delete();
        $deadBuyer->delete();

        $hits = collect(OpsSummary::anomalies())->where('kind', '끊긴 참조')->values();
        $byText = $hits->mapWithKeys(fn ($h) => [Str::contains($h['text'], '컨사이니') ? 'con' : 'buyer' => $h['count']]);
        $this->assertSame(1, $byText['con'], '적재 차(imported_at)는 끊긴 컨사이니에서 뺀다 — 손입력 차 1대만');
        $this->assertSame(1, $byText['buyer'], '끊긴 바이어는 적재 차여도 센다(돈에 걸린다)');
        $this->assertStringContainsString('엑셀 적재 차 · 수출 컨사이니가 정상인 차 제외', $hits->first(fn ($h) => Str::contains($h['text'], '컨사이니'))['text'] ?? '');
        // 어느 차인지 같이 보인다(jin 11-13 «주의 뜬 차량이 뭔지 알 수 있게») — 대수만 말하면 찾으러 갈 수가 없다.
        $this->assertStringContainsString('— 손입력차', $hits->first(fn ($h) => Str::contains($h['text'], '컨사이니'))['text']);
        $this->assertStringNotContainsString('수출칸정상차', $hits->first(fn ($h) => Str::contains($h['text'], '컨사이니'))['text']);
        $this->assertStringNotContainsString('적재차,', $hits->first(fn ($h) => Str::contains($h['text'], '컨사이니'))['text']);
        $this->assertStringContainsString('— 적재차바이어끊김', $hits->first(fn ($h) => Str::contains($h['text'], '바이어'))['text']);
    }

    public function test_mark_imported_finds_minute_bursts_and_stamps_them_only_with_apply(): void
    {
        $base = ['sales_channel' => 'export', 'currency' => 'USD', 'purchase_price' => 1_000_000, 'buyer_undecided' => true];
        foreach (range(1, 3) as $i) {   // 같은 분에 3대 = 적재 묶음
            $v = Vehicle::create(['vehicle_number' => "적재{$i}"] + $base);
            DB::table('vehicles')->where('id', $v->id)->update(['created_at' => '2026-08-28 13:48:3'.$i]);
        }
        $hand = Vehicle::create(['vehicle_number' => '손입력'] + $base);
        DB::table('vehicles')->where('id', $hand->id)->update(['created_at' => '2026-09-17 10:00:00']);

        $this->artisan('vehicles:mark-imported', ['--min' => 3])->expectsOutputToContain('dry-run — 3대')->assertExitCode(0);
        $this->assertSame(0, Vehicle::whereNotNull('imported_at')->count(), 'dry-run 은 안 찍는다');

        $this->artisan('vehicles:mark-imported', ['--min' => 3, '--apply' => true])->expectsOutputToContain('3대 imported_at 표식')->assertExitCode(0);
        $this->assertSame(3, Vehicle::whereNotNull('imported_at')->count());
        $this->assertNull($hand->fresh()->imported_at, '혼자 만들어진 차는 안 찍는다');
        $this->assertSame('2026-08-28 13:48:31', Vehicle::where('vehicle_number', '적재1')->value('imported_at')?->format('Y-m-d H:i:s'), 'imported_at = created_at');
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
            'ssancar.test/_ops/summary.php' => Http::response(['server' => ['version' => '2026-11-12'], 'anomalies' => []]),
        ]);
        $by = collect(OpsHub::collect())->keyBy('site.key');
        $this->assertTrue($by['ssancar-web']['ok'], '경로가 다른 사이트(그누보드)는 path 로 읽는다');
        $this->assertSame('2026-11-12', $by['ssancar-web']['data']['server']['version']);

        $this->assertTrue($by['ssancar-erp']['ok']);
        $this->assertArrayHasKey('server', $by['ssancar-erp']['data']);
        $this->assertFalse($by['heyman-erp']['ok']);
        $this->assertStringContainsString('응답 없음', $by['heyman-erp']['error']);
        $this->assertSame('미연결(요약 API 없음)', $by['ssancar-board']['error']);
        $this->assertStringContainsString('감시 기록 없음', $by['gpu']['error']);   // probe=assistant 인데 캐시가 없다

        Cache::put(AssistantHealthCheck::CACHE_KEY, ['checked_at' => time() - 60, 'ollama_ok' => false, 'ollama_down_since' => time() - 3600, 'index_mtime' => time() - 7200, 'problems' => []], 1800);
        $gpu = collect(OpsHub::collect(true))->keyBy('site.key')['gpu'];
        $this->assertTrue($gpu['ok']);
        $this->assertSame('GPU PC 응답 없음', $gpu['data']['anomalies'][0]['kind']);
        $this->assertFalse($gpu['data']['health'][0]['ok']);
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
        Http::fake(['heysellcar.test/*' => Http::response($peer), 'board.test/*' => Http::response([], 404), 'ssancar.test/*' => Http::response([], 500)]);
        $jin = User::factory()->create(['permission' => 'super', 'email' => 'jin@example.com', 'email_verified_at' => now()]);
        Cache::put(AssistantHealthCheck::CACHE_KEY, ['checked_at' => time() - 60, 'ollama_ok' => true, 'ollama_down_since' => null, 'index_mtime' => time() - 7200, 'problems' => []], 1800);

        $html = Volt::actingAs($jin)->test('admin.control')->html();
        foreach (['ssancarerp', 'heymanerp', 'ssancarboard', '회사 GPU PC', 'ssancar.com', 'heymancar.com 열기', 'ssh SSANCAR@100.110.133.112', 'Tailscale 100.110.133.112', '버전 어긋남', '미연결', 'HTTP 500', 'data-ops-notes="gpu"', 'Ollama 응답 정상'] as $txt) {
            $this->assertStringContainsString($txt, $html);
        }
        $etc = substr($html, strpos($html, 'data-ops-row="ETC"'));   // ETC 줄 = 머리칸 하나 + GPU PC·ssancar.com 카드
        $this->assertStringContainsString('data-ops-site="gpu"', $etc, 'GPU PC 는 ETC 줄');
        $this->assertStringContainsString('data-ops-site="ssancar-web"', $etc, 'ssancar.com 도 ETC 줄');
        $this->assertSame(3, substr_count($html, 'data-ops-row='), '줄 = ERP · BOARD · ETC');
        $gpu = substr($etc, strpos($etc, 'data-ops-site="gpu"'));
        $this->assertLessThan(strpos($gpu, 'data-ops-notes="gpu"'), strpos($gpu, '<details'), 'GPU PC 메모·점검도 접힘 안 — 카드 높이 통일(jin 11-12)');
        $this->assertStringContainsString('Ollama 응답 정상 · 확인', substr($gpu, 0, strpos($gpu, '<details')), '접힌 머리줄엔 Ollama 응답·확인 시각 한 줄');
        $this->assertSame(2, substr_count($html, 'data-ops-chip="gpu"'), '칩 = 보드 접힘 줄 + ETC 줄 접힘 자리, 두 군데(jin 11-12 「접어도 다 보이게」)');
        $this->assertStringContainsString('data-ops-sites-toggle', $html);
        $this->assertSame(3, substr_count($html, 'data-ops-fold='), 'ERP·BOARD·ETC 줄마다 접기 버튼');
        $this->assertStringContainsString('Ollama 정상', substr($html, strpos($html, 'data-ops-board-strip')), 'GPU 칩은 Ollama 판정');
        $this->assertStringNotContainsString('요약 API 없음 — 메모만</span>', substr($html, strpos($html, 'data-ops-anomalies')), 'GPU PC(메모만)는 이상 감지에 안 오른다');

        $c = Volt::actingAs($jin)->test('admin.control')->call('pickSite', 'heyman-erp');
        $this->assertSame('heyman-erp', $c->get('siteFilter'));
    }

    public function test_every_scheduled_command_has_a_korean_routine_label(): void
    {
        $src = (string) file_get_contents(base_path('routes/console.php'));
        preg_match_all("/Schedule::command\('([a-z0-9:_\-]+)'/", $src, $m);
        $commands = $m[1];
        if (preg_match("/foreach \(\[([^\]]+)\] as \\\$daily\)/", $src, $f)) {   // alimtalk:{$daily} 묶음
            foreach (array_map(fn ($x) => trim($x, " '"), explode(',', $f[1])) as $d) {
                $commands[] = 'alimtalk:'.$d;
            }
        }
        $known = array_merge(OpsRoutines::HIDDEN, ...array_map(fn ($g) => $g['commands'], array_values(OpsRoutines::GROUPS)));
        $missing = array_values(array_diff(array_unique($commands), $known));
        $this->assertSame([], $missing, 'routes/console.php 의 커맨드가 OpsRoutines::GROUPS 에 없다(한글 이름 없이 영어로 뜬다)');
        $this->assertNotEmpty($commands);
    }

    public function test_routine_table_groups_commands_in_korean(): void
    {
        $peer = OpsSummary::collect();
        $peer['routines'] = [
            ['command' => 'db:backup', 'expression' => '0 3 * * *', 'status' => 'ok', 'started_at' => '2026-11-12T03:00:00+09:00', 'finished_at' => '2026-11-12T03:00:40+09:00', 'duration_ms' => 40000, 'error' => null, 'last_ok_at' => '2026-11-12T03:00:40+09:00'],
            ['command' => 'alimtalk:pickup', 'expression' => '0 9 * * 1-5', 'status' => 'failed', 'started_at' => '2026-11-12T09:00:00+09:00', 'finished_at' => '2026-11-12T09:00:03+09:00', 'duration_ms' => 3000, 'error' => 'BizM 401', 'last_ok_at' => null],
            ['command' => 'alimtalk:daily-summary', 'expression' => '0 9 * * 1-5', 'status' => 'ok', 'started_at' => '2026-11-12T09:00:00+09:00', 'finished_at' => '2026-11-12T09:00:05+09:00', 'duration_ms' => 5000, 'error' => null, 'last_ok_at' => '2026-11-12T09:00:05+09:00'],
            ['command' => 'new:thing', 'expression' => '0 1 * * *', 'status' => 'ok', 'started_at' => '2026-11-12T01:00:00+09:00', 'finished_at' => '2026-11-12T01:00:01+09:00', 'duration_ms' => 1000, 'error' => null, 'last_ok_at' => null],
        ];
        Http::fake(['heysellcar.test/*' => Http::response($peer), 'board.test/*' => Http::response(['server' => ['version' => 'b1'], 'anomalies' => []]), 'ssancar.test/*' => Http::response([], 404)]);
        $jin = User::factory()->create(['permission' => 'super', 'email' => 'jin@example.com', 'email_verified_at' => now()]);

        $html = Volt::actingAs($jin)->test('admin.control')->html();
        $t = substr($html, strpos($html, 'data-ops-routines'));
        $this->assertStringContainsString('DB 백업', $t);
        $this->assertStringContainsString('알림톡 정기 발송 9종(평일)', $t);
        $this->assertStringContainsString('성공 11/12 03:00', $t);
        $this->assertStringContainsString('실패 1/2 · 11/12 09:00', $t, '묶음 안 하나라도 실패면 실패');
        $this->assertStringContainsString('BizM 401', $t, '이유는 title 로');
        $this->assertStringContainsString('new:thing (이름 미등록)', $t, '묶음에 없는 새 커맨드도 숨기지 않는다');
        $this->assertStringContainsString('세컨드 브레인 목록 올리기', $t);
        $this->assertStringNotContainsString('inspire', $t);
    }
}
