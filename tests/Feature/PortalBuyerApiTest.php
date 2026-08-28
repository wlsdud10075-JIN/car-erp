<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Country;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ssancar.com 바이어 명부 읽기 API (2026-08-28).
 *
 * 차량 엔드포인트와 인증·봉투를 공유한다. 가장 중요한 것은 두 가지다:
 *   ① **안 나가는 것** — 내부 신용 판단(한도·락·메모·여권번호). 응답 문자열을 통째로 훑는다.
 *   ② **소프트삭제·비활성도 행이 온다** — 빼면 사이트에 「미러에 없는 buyer_id 를 가리키는 차량」이 남는다.
 */
class PortalBuyerApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'portal-test-secret';

    private const PATH = '/api/internal/portal/buyers';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        config([
            'services.ssancar_portal.hmac_secret' => self::SECRET,
            'services.ssancar_portal.source' => 'heymanerp',
        ]);
    }

    private function signed(?string $secret = null, ?string $nonce = null): TestResponse
    {
        $secret ??= self::SECRET;
        $ts = now()->timestamp;
        // ⚠️ `getJson()` 을 쓰지 말 것 — GET 인데도 body 에 `[]` 를 넣어 서명 대상이 달라진다.
        $canonical = "GET\n".self::PATH."?\n".$ts."\n";

        return $this->get(self::PATH, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => $nonce ?? (string) Str::uuid(),
            'Accept' => 'application/json',
        ]);
    }

    private function buyer(array $attrs = []): Buyer
    {
        return Buyer::create(array_merge([
            'name' => 'Test Buyer',
            'is_active' => true,
        ], $attrs));
    }

    // ── 봉투 ──────────────────────────────────────────────

    public function test_envelope_matches_the_vehicle_endpoint(): void
    {
        $this->buyer(['name' => 'Alpha']);
        $this->buyer(['name' => 'Beta']);

        $res = $this->signed()->assertOk();
        $json = $res->json();

        $this->assertSame('heymanerp', $json['source']);
        $this->assertTrue($json['complete']);
        $this->assertNotEmpty($json['generated_at']);
        // count 는 **직렬화한 배열과 같아야 한다** — 별도 count 쿼리를 쓰면 사이트 게이트를 스스로 밟는다.
        $this->assertSame(count($json['data']), $json['count']);
        $this->assertSame(2, $json['count']);
    }

    public function test_row_shape(): void
    {
        $country = Country::create(['name' => '코소보', 'code' => 'XK']);
        $b = $this->buyer([
            'name' => 'R.S.H ',                       // 끝 공백 — 실데이터에 있다
            'contact_email' => 'rsh@example.com',
            'country_id' => $country->id,
        ]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $b->id);

        $this->assertSame('R.S.H ', $row['name'], '원본 이름은 그대로 — 사람이 보는 값이다.');
        $this->assertSame('R.S.H', $row['name_trimmed'], '매칭 키는 우리가 만든다 — 사이트가 TRIM 하면 키를 사이트가 만드는 셈이다.');
        $this->assertSame('rsh@example.com', $row['contact_email']);
        $this->assertSame('코소보', $row['country'], '국가는 id 가 아니라 이름으로 — 사이트에 사본을 두면 갈린다.');
        $this->assertTrue($row['is_active']);
        $this->assertFalse($row['erp_deleted']);
        $this->assertSame(0, $row['vehicle_count']);
        $this->assertNull($row['salesman_name'], '담당자 미지정이면 null — 🚫 빈 문자열로 눕히면 「없음」과 「모름」이 섞인다.');
    }

    /**
     * 영업담당자 **이름** 한 칸 (2026-08-28 jin 승인 — ssancar 3차 패킷).
     *
     * 🔑 그쪽의 유일한 담당자 소스가 2026-06-22 스냅샷이라 두 달치 재배정이 빠져 있었다.
     *    계정 비밀번호를 담당자별로 갈라 전달해야 해서 필요해졌다. 쓰는 자리는 관리자 화면이다.
     * 🚨 이 테스트가 잡는 진짜 회귀는 **조용한 null** 이다 — `select(COLUMNS)` 에서
     *    `salesman_id` 가 빠지면 `belongsTo` 매칭이 실패해 전 행이 null 이 되는데,
     *    예외도 경고도 없다(`country_id` 가 COLUMNS 에 있는 이유와 같다).
     */
    public function test_salesman_name_ships_but_never_the_id(): void
    {
        $sm = Salesman::create(['name' => '이원호', 'is_active' => true]);
        $b = $this->buyer(['name' => 'NICK', 'salesman_id' => $sm->id]);

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $b->id);

        $this->assertSame('이원호', $row['salesman_name']);
        $this->assertArrayNotHasKey('salesman_id', $row, 'id 는 계속 닫힌다 — 사전이 두 벌이 되면 그게 낡는다.');
    }

    /**
     * 담당자가 **소프트삭제**되면 null 이다 — ERP 바이어 화면이 「미지정」을 그리는 것과 같은 값.
     * 🚫 `withTrashed` 를 붙이지 말 것: 포털이 ERP 화면보다 더 많이 보여주게 된다.
     */
    public function test_soft_deleted_salesman_reads_as_null(): void
    {
        $sm = Salesman::create(['name' => '퇴사자', 'is_active' => true]);
        $b = $this->buyer(['name' => 'Orphaned', 'salesman_id' => $sm->id]);
        $sm->delete();

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $b->id);

        $this->assertNull($row['salesman_name']);
    }

    // ── 🚨 되돌아가면 차량이 고아가 되는 자리 ───────────────

    public function test_soft_deleted_and_inactive_buyers_still_ship(): void
    {
        $alive = $this->buyer(['name' => 'Alive']);
        $inactive = $this->buyer(['name' => 'Inactive', 'is_active' => false]);
        $gone = $this->buyer(['name' => 'Gone']);
        $gone->delete();

        $data = collect($this->signed()->assertOk()->json('data'))->keyBy('id');

        $this->assertCount(3, $data, '소프트삭제·비활성도 **행이 와야 한다** — 빼면 그 바이어의 차량이 미러에서 고아가 된다.');
        $this->assertFalse($data[$alive->id]['erp_deleted']);
        $this->assertTrue($data[$inactive->id]['is_active'] === false, '비활성은 제외 사유가 아니라 플래그다.');
        $this->assertTrue($data[$gone->id]['erp_deleted'], '삭제는 행 제외가 아니라 플래그다.');
    }

    public function test_vehicle_count_matches_what_the_vehicle_endpoint_ships(): void
    {
        $salesman = Salesman::create(['name' => '테스트', 'type' => 'freelance', 'is_active' => true]);
        $b = $this->buyer(['name' => 'Counted']);

        // 살아있는 차 2대 + 소프트삭제 1대 → 차량 엔드포인트는 2대만 발행한다.
        foreach (['11가1111', '22나2222', '33다3333'] as $i => $plate) {
            $v = Vehicle::create([
                'vehicle_number' => $plate,
                'sales_channel' => 'export',
                'buyer_id' => $b->id,
                'salesman_id' => $salesman->id,
                'purchase_price' => 1000,
            ]);
            if ($i === 2) {
                $v->delete();
            }
        }

        $row = collect($this->signed()->assertOk()->json('data'))->firstWhere('id', $b->id);
        $this->assertSame(2, $row['vehicle_count'], '소프트삭제 차량은 세지 않는다 — 차량 엔드포인트가 발행하는 집합과 같아야 대조가 의미를 갖는다.');
    }

    // ── 🔒 안 나가는 것 ───────────────────────────────────

    public function test_internal_credit_fields_never_leave(): void
    {
        $this->buyer([
            'name' => 'Secret Holder',
            'contact_email' => 'x@example.com',
            'memo' => '내부메모-절대노출금지',
            'passport_id' => 'PASSPORT-PLAINTEXT-1234',
            'unsecured_limit_krw' => 5_000_000,
        ]);

        $body = $this->signed()->assertOk()->getContent();

        // 키 이름이 바뀌어도 **값이 새면** 잡히게 문자열을 통째로 훑는다.
        foreach ([
            'unsecured_limit_krw', 'lock_shipping_entry_pct', 'lock_purchase_registration_pct',
            // ⚠️ `salesman_id` 는 **계속 닫혀 있다**. 2026-08-28 에 연 것은 `salesman_name`(이름)뿐이고,
            //    그건 이 문자열의 부분문자열이 아니라 이 단언과 충돌하지 않는다
            //    — 열린 범위는 `test_salesman_name_ships_but_never_the_id` 가 양성으로 못박는다.
            'memo', 'passport_id', 'salesman_id', 'is_inherited', 'inherited_from_salesman_id',
            '내부메모-절대노출금지', 'PASSPORT-PLAINTEXT-1234', '5000000',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "응답에 `{$forbidden}` 이 새면 안 된다.");
        }
    }

    public function test_no_derived_aggregate_the_site_did_not_ask_for(): void
    {
        // 2026-08-28 2차 회신 — 사이트가 **자동 매칭을 안 해서** 이 값을 안 받기로 했다.
        // 분모가 우리에게 있는 파생값은 어긋나는 날 물어볼 데가 없다.
        $this->buyer(['name' => 'A', 'contact_email' => 'same@example.com']);
        $this->buyer(['name' => 'B', 'contact_email' => 'same@example.com']);

        $this->assertStringNotContainsString('email_buyer_count', $this->signed()->assertOk()->getContent());
    }

    // ── 인증 ─────────────────────────────────────────────

    public function test_rejects_bad_signature_and_missing_secret(): void
    {
        $this->buyer();

        $this->signed('wrong-secret')->assertStatus(401);

        config(['services.ssancar_portal.hmac_secret' => null]);
        $this->signed()->assertStatus(401);
    }

    public function test_rejects_replayed_nonce(): void
    {
        $this->buyer();
        $nonce = (string) Str::uuid();

        $this->signed(null, $nonce)->assertOk();
        $this->signed(null, $nonce)->assertStatus(401);
    }
}
