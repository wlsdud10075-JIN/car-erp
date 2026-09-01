<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ForwardingCompany;
use App\Models\Port;
use App\Models\ReceivableHistory;
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

        // ⚠️ `sale_price`·`transport_fee` 는 v1.12 C-1 로 **`unpaid_components` 안에서 발행된다** —
        //    여기서 빠진 것은 허용이 아니라 **위치 이동**이다. 평탄한 최상위 칸으로 올라오면
        //    아래 test_unpaid_materials_stay_inside_components 가 잡는다.
        // 🔒 **신용도·한도·게이트는 바이어에게 안 나간다**(jin 2026-08-26 — 내부 전용).
        //    「데이터가 채워지면 열린다」가 아니다. 열려면 jin 이 다시 연다.
        //    ⚠️ 나중에 «바이어 단위» 엔드포인트를 새로 만들면 이 결정이 자동으로 안 따라온다 —
        //       그때 화이트리스트를 처음부터 다시 세울 것.
        foreach ([
            'purchase_price', 'selling_fee', 'cost_towing',
            'nice_reg_owner_name', 'nice_reg_owner_addr', 'nice_reg_owner_rrn',
            'sale_unpaid_amount_krw_cache', 'memo_sale',
            'unsecured_limit_krw', 'lock_shipping_entry_pct', 'lock_purchase_registration_pct',
            'credit_score', 'credit_grade', 'available_krw', 'limit_krw', 'deposit_pct',
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

    // ── C-1 미수 발행 ───────────────────────────────────────────────────

    /**
     * 🔑 **이 테스트가 C-1 계약의 본체다.**
     *
     * ssancar v1.11 §17-1 은 구성을 「4항」으로 하자고 했다. 항을 세는 방식으로는 안 닫힌다 —
     * ERP 미수 공식의 항은 8개이고, 늘어날 수 있다. 항 수 대신 **닫힘**을 강제한다.
     */
    public function test_components_always_close_to_the_published_balance(): void
    {
        // 8항이 전부 살아 있는 차 — 부대비용 3종 + TAX D/C + 회수이력 + 적립금 + 스냅 잔차.
        $v = $this->seedVehicle([
            'currency' => 'EUR', 'exchange_rate' => 1400,
            'sale_date' => now()->subMonths(2)->toDateString(),
            'sale_price' => 20000, 'transport_fee' => 1500, 'sale_other_costs' => 300,
            'commission' => 250, 'auto_loading' => 120, 'tax_dc' => 400,
            'savings_used' => 800,
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 9000,
            'payment_date' => now()->subMonth()->toDateString(), 'confirmed_at' => now()->subMonth(),
        ]);
        // ⚠️ 확정 안 된 잔금은 **세면 안 된다**(Draft). 닫힘이 깨지면 여기서 잡힌다.
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5000,
            'payment_date' => now()->toDateString(),
        ]);
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'cash', 'amount' => 1200,
            'collected_at' => now()->subWeek()->toDateString(),
        ]);
        // 미러 행 — savings_used 와 같은 돈이라 **두 번 빼면 안 된다**(SKILLS §13 정정).
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'savings', 'amount' => 800,
            'collected_at' => now()->subWeek()->toDateString(),
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        $this->assertCloses($row, '미수 > 1 (평범한 미납 차)');
        // ERP 단일 출처와도 같은 값이어야 한다(포털이 자기 공식을 갖지 않는다).
        $this->assertEqualsWithDelta((float) $v->fresh()->sale_unpaid_amount, $row['unpaid_amount'], 0.01);
    }

    /**
     * 🔑 닫힘은 **세 갈래 전부**에서 성립해야 한다. 위 테스트는 그중 하나(미수 > 1)만 밟는다.
     *
     *   미수 > 1     unpaid = 미수 · overpaid = 0
     *   0 < 미수 < 1  ★완납 스냅★ unpaid = 0 인데 components 합은 그 잔차다 → paid 가 흡수한다
     *   미수 < 0      unpaid = 0 · overpaid = −미수  → 항등식이 overpaid 를 **빼서** 닫는다
     *
     * 가운데(스냅)와 아래(과입금)가 안 닫히면 «합계는 0 인데 줄을 더하면 0 이 아닌» 화면이 된다.
     */
    public function test_closure_holds_on_the_snap_and_overpaid_branches(): void
    {
        // ① 완납 스냅 — 외화 소수점 잔차 0.34 (SKILLS §13 의 그 예시)
        $snap = $this->seedVehicle([
            'currency' => 'EUR', 'exchange_rate' => 1400,
            'sale_date' => now()->subMonth()->toDateString(), 'sale_price' => 8397.34,
        ]);
        FinalPayment::create([
            'vehicle_id' => $snap->id, 'type' => 'balance', 'amount' => 8397,
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
        ]);

        // ② 과입금 — 적립금까지 섞어서(둘이 함께 있을 때가 가장 안 닫히기 쉽다)
        $over = $this->seedVehicle([
            'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => now()->subMonth()->toDateString(),
            'sale_price' => 10000, 'transport_fee' => 500, 'tax_dc' => 200,
            'savings_used' => 300,
        ]);
        FinalPayment::create([
            'vehicle_id' => $over->id, 'type' => 'balance', 'amount' => 11000,
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
        ]);

        $rows = collect($this->signed()->assertOk()->json('data'))->keyBy('id');

        // ⚠️ 먼저 «정말 스냅 갈래를 밟았는가» 를 확인한다 — sale_price 가 정수로 잘리면
        //    잔차가 0 이 되어 이 테스트가 **아무것도 검사하지 않은 채 통과**한다.
        $this->assertGreaterThan(0.0, (float) $snap->fresh()->sale_price - 8397, '전제가 깨졌다: 소수 잔차가 저장되지 않았다');

        $this->assertCloses($rows[$snap->id], '완납 스냅 (0 < 미수 < 1)');
        $this->assertEqualsWithDelta(0.0, $rows[$snap->id]['unpaid_amount'], 0.01, '잔차는 완납으로 스냅돼야 한다');
        $this->assertTrue($rows[$snap->id]['fully_paid']);
        // 스냅 잔차는 paid 가 흡수한다 — 실제 받은 돈(8397)보다 그만큼 크다.
        $this->assertGreaterThan(8397.0, $rows[$snap->id]['unpaid_components']['paid']);

        $this->assertCloses($rows[$over->id], '과입금 (미수 < 0)');
        $this->assertGreaterThan(0, $rows[$over->id]['overpaid_amount']);
        $this->assertEqualsWithDelta(0.0, $rows[$over->id]['unpaid_amount'], 0.01);
    }

    /** 닫힘 항등식 — 이 계약이 깨지면 바이어 화면의 뺄셈이 헤드라인과 안 맞는다. */
    private function assertCloses(array $row, string $branch): void
    {
        $c = $row['unpaid_components'];
        $sum = $c['sale_price'] + $c['transport_fee'] + $c['other_charges']
            - $c['paid'] - $c['savings_used'] - $c['written_off'];

        $this->assertLessThan(
            1.0,
            abs($sum - ($row['unpaid_amount'] - $row['overpaid_amount'])),
            "닫힘 항등식이 깨졌다 [{$branch}] — 줄을 더한 값과 헤드라인이 다르다"
        );
    }

    /** 과입금은 눌러서 0 으로 보내고, 원본은 따로 준다 — 섞으면 바이어 합계가 남의 미수를 상쇄한다. */
    public function test_overpayment_is_pressed_to_zero_and_reported_separately(): void
    {
        $v = $this->seedVehicle([
            'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => now()->subMonth()->toDateString(), 'sale_price' => 10000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10500,
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        $this->assertEqualsWithDelta(0.0, $row['unpaid_amount'], 0.01);
        $this->assertEqualsWithDelta(500.0, $row['overpaid_amount'], 0.01);
        $this->assertTrue($row['fully_paid']);
    }

    /** 판매 전 차는 미수라는 개념이 없다 — 0 을 보내면 사이트가 「완납」으로 그린다. */
    public function test_unsold_vehicle_publishes_null_not_zero(): void
    {
        $v = $this->seedVehicle(['purchase_price' => 5_000_000]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        $this->assertNull($row['unpaid_amount']);
        $this->assertNull($row['unpaid_components']);
        $this->assertNull($row['currency']);
        $this->assertFalse($row['fully_paid']);
    }

    /** 🚨 원화 환산은 하지 않는다(Q11) — 다중통화 바이어가 실재하고, 환산은 시점 문제가 붙는다. */
    public function test_amounts_are_published_in_the_buyer_currency_only(): void
    {
        $v = $this->seedVehicle([
            'currency' => 'EUR', 'exchange_rate' => 1500,
            'sale_date' => now()->subMonth()->toDateString(), 'sale_price' => 20000,
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        $this->assertSame('EUR', $row['currency']);
        $this->assertEqualsWithDelta(20000.0, $row['unpaid_components']['sale_price'], 0.01, '원화로 환산돼 나오면 안 된다');
        $this->assertArrayNotHasKey('unpaid_amount_krw', $row);

        // 🔧 2026-09-01 — 이 테스트의 목적은 「금액을 바이어 통화로만 발행한다」이지
        //    「환율 칸이 없다」가 아니었다. 포인트 적립용으로 `sale_exchange_rate` 가 열리면서
        //    옛 단언(`assertArrayNotHasKey('exchange_rate')`)은 **키 이름이 달라 그냥 통과**해
        //    아무것도 검사하지 않게 됐다(SKILLS §8 #66). 트리거를 갈고 목적을 되살린다.
        //    ⇒ 환율은 있어도 되지만, **환산된 금액**은 어디에도 있으면 안 된다.
        // ⚠️ JSON 은 `1500.0` 을 `1500` 으로 직렬화한다(JSON_PRESERVE_ZERO_FRACTION 없음) —
        //    받는 쪽은 int 로 올 수 있으니 숫자로 다룰 것. 그래서 여기서도 동등 비교다.
        $this->assertEqualsWithDelta(1500.0, (float) $row['sale_exchange_rate'], 0.0001, '판매 계약 환율은 원문 그대로');
        foreach ($row['unpaid_components'] as $key => $amount) {
            $this->assertLessThan(
                1_000_000,
                abs((float) $amount),
                "구성 {$key} 가 원화로 환산된 크기다 — 통화 발행 계약 위반"
            );
        }
    }

    /**
     * 💱 포인트 적립용 판매 계약 환율 (jin 2026-09-01).
     *
     * 🚫 실효 입금환율(`settlement_exchange_rate`)을 보내면 안 된다 — 입금이 들어올 때마다
     *    값이 변해 포인트가 소급으로 흔들리고, 과입금 차량에선 초과분이 환율로 둔갑한다
     *    (운영 실측 119더5727 판매 1,710 → 실효 1,877.20, +9.78%).
     */
    public function test_sale_exchange_rate_is_the_contract_rate_not_the_effective_one(): void
    {
        $v = $this->seedVehicle([
            'currency' => 'EUR', 'exchange_rate' => 1710,
            'sale_date' => now()->subMonth()->toDateString(), 'sale_price' => 20000,
        ]);
        // 과입금 — 실효환율이라면 이 초과분이 환율을 밀어 올린다.
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 22_000,
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        $this->assertEqualsWithDelta(1710.0, (float) $row['sale_exchange_rate'], 0.0001);
        $this->assertGreaterThan(1710.0, (float) $v->fresh()->settlement_exchange_rate, '전제 확인 — 실효환율은 실제로 밀려 올라간다');
    }

    /** 판매 전 차량은 환율도 `null` 이다 — 0 을 보내면 곱해서 0 원 포인트가 된다. */
    public function test_unsold_vehicle_publishes_null_exchange_rate(): void
    {
        $v = $this->seedVehicle(['sale_price' => 0, 'exchange_rate' => 0]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        $this->assertNull($row['sale_exchange_rate']);
    }

    // ── C-3 레벨3 승급 ──────────────────────────────────────────────────

    /**
     * 🚨 `progress_status_cache === '판매완료'` 로 세면 틀린다 —
     *    완납한 차가 선적되면 상태가 위로 올라가 그 문자열에서 빠진다(v4 cascade).
     */
    public function test_fully_paid_survives_the_vehicle_moving_past_sold(): void
    {
        $v = $this->seedVehicle([
            'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => now()->subMonths(3)->toDateString(), 'sale_price' => 10000,
            'bl_loading_location' => '평택항',
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10000,
            'payment_date' => now()->subMonths(2)->toDateString(), 'confirmed_at' => now()->subMonths(2),
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        $this->assertNotSame('판매완료', $row['progress_status_cache'], '전제가 깨졌다 — 이 차는 이미 선적 단계다');
        $this->assertTrue($row['fully_paid'], '상태 문자열이 아니라 미수로 판정해야 한다');
    }

    /**
     * B-2 가 거부했던 두 칸이 C-1 로 돌아왔다. 거부 사유(*"미러에 있으면 계산하고 싶어진다"*)는
     * 그대로 유효하므로 **`unpaid_components` 밖으로 새지 않는지**를 따로 지킨다.
     */
    public function test_unpaid_materials_stay_inside_components(): void
    {
        $v = $this->seedVehicle([
            'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => now()->subMonth()->toDateString(),
            'sale_price' => 10000, 'transport_fee' => 900,
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);

        foreach (['sale_price', 'transport_fee', 'other_charges', 'paid', 'savings_used', 'written_off'] as $k) {
            $this->assertArrayNotHasKey($k, $row, "미수 재료가 최상위로 새어 나왔다: {$k}");
            $this->assertArrayHasKey($k, $row['unpaid_components']);
        }
        $this->assertSame($v->id, $row['id']);
    }

    /**
     * 🚨 **손실처리(`write_off`)는 「바이어가 낸 돈」이 아니다** — 회사가 포기한 채권이다(jin 2026-08-25).
     *    `paid` 에 섞으면 «당신이 낸 돈» 이 실제보다 커져, 바이어가 자기 송금 기록과 대조하다 어긋난다.
     */
    public function test_written_off_debt_is_never_counted_as_money_the_buyer_paid(): void
    {
        $v = $this->seedVehicle([
            'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => now()->subMonths(3)->toDateString(), 'sale_price' => 10000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 6000,
            'payment_date' => now()->subMonths(2)->toDateString(), 'confirmed_at' => now()->subMonths(2),
        ]);
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'cash', 'amount' => 1000,
            'collected_at' => now()->subMonth()->toDateString(),
        ]);
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'write_off', 'amount' => 3000,
            'collected_at' => now()->subWeek()->toDateString(),
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $v->id);
        $c = $row['unpaid_components'];

        // 실제로 들어온 돈 = 확정 잔금 6,000 + 현금 회수 1,000
        $this->assertEqualsWithDelta(7000.0, $c['paid'], 0.01, '손실처리액이 「낸 돈」에 섞였다');
        $this->assertEqualsWithDelta(3000.0, $c['written_off'], 0.01);
        // ERP 미수 단일 출처는 손실처리를 빼므로 잔금 0 이다 — 그래도 항등식은 닫힌다.
        $this->assertCloses($row, '손실처리 보유');
        $this->assertEqualsWithDelta((float) $v->fresh()->sale_unpaid_amount, $row['unpaid_amount'], 0.01);
    }
}
