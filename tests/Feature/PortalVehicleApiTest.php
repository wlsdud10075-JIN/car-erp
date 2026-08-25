<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\ForwardingCompany;
use App\Models\Port;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ssancar.com 바이어 포털 읽기 API (2026-08-25).
 *
 * 가장 중요한 것은 **안 나가는 것**이다 — 소유자 PII·원가·마진·바이어 한도.
 * 그 검사는 응답 JSON 문자열을 통째로 훑는다(키 이름이 바뀌어도 값이 새면 잡히게).
 */
class PortalVehicleApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'portal-test-secret';

    private const PATH = '/api/internal/portal/vehicles';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        config([
            'services.ssancar_portal.hmac_secret' => self::SECRET,
            'services.ssancar_portal.source' => 'heymanerp',
        ]);
    }

    private function signed(array $query = [], ?string $secret = null): TestResponse
    {
        $secret ??= self::SECRET;
        $ts = now()->timestamp;
        ksort($query);
        $canonical = "GET\n".self::PATH.'?'.http_build_query($query)."\n".$ts."\n";

        // ⚠️ `getJson()` 을 쓰지 말 것 — GET 인데도 **body 에 `[]` 를 넣어** 서명 대상이 달라진다.
        //    body 가 canonical 의 마지막 항이라 조용히 401 이 된다(기존 board 테스트도 `get()` 을 쓴다).
        return $this->get(self::PATH.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ]);
    }

    private function seedVehicle(array $attrs = []): Vehicle
    {
        $sm = Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']);
        $buyer = Buyer::create(['name' => 'PORTAL BUYER '.uniqid(), 'is_active' => true, 'salesman_id' => $sm->id]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '88가'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'salesman_id' => $sm->id,
            'nice_reg_vin' => 'WBAJD9100JWC11399',
            'brand' => 'BMW',
            'model_type' => '530I',
        ], $attrs));
    }

    // ── 인증 ────────────────────────────────────────────────────────────

    public function test_unsigned_request_is_rejected(): void
    {
        $this->get(self::PATH)->assertStatus(401);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->signed([], 'wrong-secret')->assertStatus(401);
    }

    /** 🔒 fail-closed — 시크릿을 넣기 전에 배포해도 열리지 않는다. */
    public function test_missing_secret_closes_the_channel(): void
    {
        config(['services.ssancar_portal.hmac_secret' => '']);
        $this->signed()->assertStatus(401);
    }

    public function test_nonce_cannot_be_reused(): void
    {
        $ts = now()->timestamp;
        $canonical = "GET\n".self::PATH."?\n".$ts."\n";
        $headers = [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, self::SECRET),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => 'fixed-nonce',
            'Accept' => 'application/json',
        ];

        $this->get(self::PATH.'?', $headers)->assertOk();
        $this->get(self::PATH.'?', $headers)->assertStatus(401);
    }

    /** ⚠️ board 와 nonce 네임스페이스가 겹치면 한 채널이 다른 채널을 막는다. */
    public function test_nonce_namespace_is_separate_from_board(): void
    {
        Cache::put('board_read_nonce:shared', 1, 300);

        $ts = now()->timestamp;
        $canonical = "GET\n".self::PATH."?\n".$ts."\n";

        $this->withHeaders([
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, self::SECRET),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => 'shared',
            'Accept' => 'application/json',
        ])->get(self::PATH.'?')->assertOk();
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $ts = now()->subMinutes(10)->timestamp;
        $canonical = "GET\n".self::PATH."?\n".$ts."\n";

        $this->withHeaders([
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, self::SECRET),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->get(self::PATH.'?')->assertStatus(401);
    }

    // ── 스코프 ──────────────────────────────────────────────────────────

    /** 🚨 바이어 미정(투기 매입)은 어느 바이어에게도 발행하지 않는다 — IDOR 경계다. */
    public function test_vehicles_without_a_buyer_are_never_published(): void
    {
        $with = $this->seedVehicle();
        $without = Vehicle::create(['vehicle_number' => '88나1111', 'sales_channel' => 'export']);

        $ids = collect($this->signed()->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($with->id));
        $this->assertFalse($ids->contains($without->id));
    }

    // ── 유출 방지 (이 파일의 존재 이유) ──────────────────────────────────

    /**
     * 🔒 금지 값이 응답 어디에도 없어야 한다. **키가 아니라 값**으로 검사한다 —
     * 키 이름을 바꿔 담아도 잡히게. 암호화 컬럼은 속성 접근만으로 평문이 되므로 특히 중요하다.
     */
    public function test_forbidden_values_never_appear_in_the_payload(): void
    {
        $v = $this->seedVehicle([
            'nice_reg_owner_name' => '홍길동테스트',
            'nice_reg_owner_addr' => '서울시 어딘가 999',
            'nice_reg_owner_rrn' => '900101-1234567',
            'purchase_price' => 12_345_678,
            'selling_fee' => 777_777,
            'cost_towing' => 333_333,
            'sale_price' => 44_444,
            'transport_fee' => 55_555,
            'memo_sale' => '내부메모누출테스트',
        ]);
        DB::table('vehicles')->where('id', $v->id)->update(['sale_unpaid_amount_krw_cache' => 98_765_432]);

        $json = $this->signed()->assertOk()->getContent();

        foreach ([
            '홍길동테스트', '서울시 어딘가 999', '1234567', '내부메모누출테스트',
            '12345678', '777777', '333333', '98765432',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "금지 값이 응답에 있다: {$forbidden}");
        }

        foreach ([
            'purchase_price', 'selling_fee', 'cost_towing', 'sale_price', 'transport_fee',
            'nice_reg_owner_name', 'nice_reg_owner_addr', 'nice_reg_owner_rrn',
            'sale_unpaid_amount_krw_cache', 'unsecured_limit_krw', 'memo_sale',
        ] as $key) {
            $this->assertStringNotContainsString($key, $json, "금지 키가 응답에 있다: {$key}");
        }
    }

    // ── 응답 계약 ───────────────────────────────────────────────────────

    public function test_response_envelope_matches_the_agreed_spec(): void
    {
        $this->seedVehicle();

        $res = $this->signed()->assertOk()
            ->assertJsonStructure(['generated_at', 'source', 'count', 'complete', 'data']);

        $this->assertSame('heymanerp', $res->json('source'));
        $this->assertTrue($res->json('complete'));
        $this->assertSame(1, $res->json('count'));
        // ISO8601 + 오프셋 — 오프셋이 없으면 파서가 로컬로 읽어 9시간 어긋난다.
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $res->json('generated_at'));
    }

    /** 🚨 source 는 서버별 설정이다 — 두 회사가 같은 값을 주면 사이트가 한쪽을 전량 삭제로 읽는다. */
    public function test_source_comes_from_config_not_a_hardcoded_value(): void
    {
        $this->seedVehicle();
        config(['services.ssancar_portal.source' => 'ssancarerp']);

        $this->assertSame('ssancarerp', $this->signed()->assertOk()->json('source'));
    }

    public function test_discharge_port_is_resolved_to_a_name(): void
    {
        $port = Port::create(['name' => 'DURRES', 'type' => 'discharge', 'is_active' => true]);
        $this->seedVehicle(['discharge_port_id' => $port->id]);

        $row = $this->signed()->assertOk()->json('data.0');

        $this->assertSame('DURRES', $row['discharge_port']);
        $this->assertArrayNotHasKey('discharge_port_id', $row, 'id 를 주면 사이트가 ports 사본을 두게 된다');
    }

    /** 번호만 있고 문서가 없는 차가 실측 14대 — 「발급됨」은 문서로 판정한다. */
    public function test_bl_flag_uses_the_document_not_the_number(): void
    {
        $this->seedVehicle(['bl_number' => 'CIGSINDU2603HY01']);

        $row = $this->signed()->assertOk()->json('data.0');

        $this->assertSame('CIGSINDU2603HY01', $row['bl_number']);
        $this->assertFalse($row['has_bl_document'], '번호만으로 발급됐다고 하면 없는 서류를 주장한다');
        $this->assertArrayNotHasKey('bl_document', $row, '파일 경로는 필요 없다');
    }

    /**
     * 🚨 `$v->departed()` 는 쿼리 스코프라 인스턴스에서 부르면 Builder 가 돌아온다.
     * SQL 스코프와 PHP 판정이 두 벌이므로 일치를 강제한다.
     */
    public function test_is_departed_agrees_with_the_scope(): void
    {
        $out = $this->seedVehicle(['warehouse_out_date' => now()->subDays(3)->toDateString()]);
        $bl = $this->seedVehicle(['bl_document' => 'vehicles/1/bl.pdf']);
        $neither = $this->seedVehicle();

        $scoped = Vehicle::query()->departed()->pluck('id');

        foreach ([$out, $bl, $neither] as $v) {
            $this->assertSame(
                $scoped->contains($v->id),
                $v->fresh()->isDeparted(),
                "차량 {$v->id} 에서 스코프와 인스턴스 판정이 갈린다"
            );
        }
    }

    /** 추적 링크는 ERP 가 판정해서 발행한다 — 사이트가 조건을 다시 보면 갈린다. */
    public function test_tracking_url_is_published_only_when_open(): void
    {
        $fc = ForwardingCompany::create([
            'name' => 'CIG', 'is_active' => true,
            'tracking_url_template' => 'https://www.cigbooking.com/track/{VIN}',
        ]);
        $open = $this->seedVehicle(['forwarding_company_id' => $fc->id, 'shipping_date' => now()->subDays(5)->toDateString()]);
        $early = $this->seedVehicle(['forwarding_company_id' => $fc->id, 'shipping_date' => now()->toDateString()]);

        $rows = collect($this->signed()->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('https://www.cigbooking.com/track/WBAJD9100JWC11399', $rows[$open->id]['tracking_url']);
        $this->assertNull($rows[$early->id]['tracking_url'], '출항 당일은 아직 안 열린다');
    }
}
