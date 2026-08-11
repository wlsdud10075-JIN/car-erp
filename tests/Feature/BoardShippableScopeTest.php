<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 선적 계획 — 후보 확대 · 미수 동봉 · 포워딩사 · 컨테이너 운임비 1/N (board 인계 2026-08-11).
 *
 * 권위 = docs/integration/board-portal-api.md §5. 요청서 = board `meetings/handoff-carerp-shippable-scope.md`.
 *
 * 🛟 **이 파일이 지키는 제1 불변식 = 후보 확대가 「순수 확대」라는 것.**
 *    구 조건(`판매완료`)에 걸리던 차가 새 조건에서 하나라도 빠지면 실패한다.
 *    조용히 좁히는 사고(SKILLS §8 #38)는 목록이 비는 게 아니라 **줄어드는** 형태라 눈에 안 띈다.
 */
class BoardShippableScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function signedGet(string $path, array $query = [])
    {
        ksort($query);
        $ts = now()->timestamp;
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function signedPost(string $path, array $payload)
    {
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $canonical = "POST\n".$path."?\n".$ts."\n".$body;

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function salesman(string $email = 'ship@ex.com'): Salesman
    {
        return Salesman::create([
            'name' => '선적영업 '.$email, 'email' => $email, 'is_active' => true,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
        ]);
    }

    private function vehicle(Salesman $s, array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => 'SHP-'.++$this->counter,
            'salesman_id' => $s->id,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
        ], $attrs));
    }

    /** 구 조건이 뽑던 집합 — 회귀 판정의 기준선. */
    private function legacyCandidateIds(int $salesmanId): array
    {
        return Vehicle::query()->whereNull('deleted_at')
            ->where('salesman_id', $salesmanId)
            ->where('sales_channel', 'export')
            ->where('progress_status_cache', '판매완료')
            ->pluck('id')->sort()->values()->all();
    }

    private function shippableIds(Salesman $s): array
    {
        return collect($this->signedGet('/api/internal/board/shippable', ['salesman_email' => $s->email])
            ->assertOk()->json('data'))->pluck('vehicle_id')->sort()->values()->all();
    }

    /**
     * 🛟 **순수 확대** — 구 조건에 걸리던 차는 전부 남고, 판매중이 새로 들어온다.
     *
     * v4 cascade 상 `판매완료` 는 `bl_loading_location`·`bl_document` 가 **둘 다 비어야** 도달한다
     * (반입지가 있으면 선적중/통관중, B/L 이 있으면 거래완료가 먼저 잡힌다). 그래서 새 조건의
     * 두 `whereNull` 은 구 후보를 하나도 못 떨어뜨린다 — 그걸 데이터로 확인한다.
     */
    public function test_widening_never_drops_a_previously_visible_vehicle(): void
    {
        $s = $this->salesman();
        $buyer = Buyer::create(['name' => 'SHIP BUYER', 'is_active' => true]);

        // 구 후보 (판매완료 = 완납)
        $paid = $this->vehicle($s, ['sale_price' => 1_000_000, 'buyer_id' => $buyer->id, 'sale_date' => '2026-01-01']);
        $paid->finalPayments()->create(['amount' => 1_000_000, 'type' => 'balance', 'payment_date' => '2026-01-02', 'confirmed_at' => now()]);
        $paid->refreshProgressCache();

        // 새로 들어와야 할 차 (판매중 = 미완납)
        $unpaid = $this->vehicle($s, ['sale_price' => 2_000_000, 'buyer_id' => $buyer->id, 'sale_date' => '2026-01-01']);

        // 들어오면 안 되는 차들
        $noSale = $this->vehicle($s, ['purchase_price' => 500_000]);                       // 판매 전
        $berthed = $this->vehicle($s, ['sale_price' => 1_000_000, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-01-01', 'bl_loading_location' => 'BUSAN']);               // 반입 = 계획 단계 아님
        $shipped = $this->vehicle($s, ['sale_price' => 1_000_000, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-01-01', 'bl_document' => 'bl/x.pdf']);                    // B/L 발급 = 끝

        $legacy = $this->legacyCandidateIds($s->id);
        $this->assertContains($paid->id, $legacy, '기준선이 잘못 잡혔다 — 구 조건 후보가 비었다');

        $now = $this->shippableIds($s);

        foreach ($legacy as $id) {
            $this->assertContains($id, $now, "구 조건 후보 #{$id} 가 새 조건에서 사라졌다 — 순수 확대가 아니다");
        }
        $this->assertContains($unpaid->id, $now, '미완납 차가 후보에 안 들어왔다 — 확대의 목적 자체');
        $this->assertNotContains($noSale->id, $now);
        $this->assertNotContains($berthed->id, $now, '반입지 있는 차가 후보로 돌아왔다');
        $this->assertNotContains($shipped->id, $now, 'B/L 발급 차가 후보로 돌아왔다');
    }

    /** 남의 차·open 묶음 제외는 종전 그대로. */
    public function test_scope_and_open_bundle_exclusion_survive(): void
    {
        $s = $this->salesman();
        $other = $this->salesman('other@ex.com');

        $mine = $this->vehicle($s, ['sale_price' => 1_000_000]);
        $theirs = $this->vehicle($other, ['sale_price' => 1_000_000]);
        $bundled = $this->vehicle($s, ['sale_price' => 1_000_000]);
        ShippingRequest::create([
            'batch_id' => 'b1', 'vehicle_id' => $bundled->id, 'shipping_method' => 'RORO',
            'requested_by_email' => $s->email, 'status' => ShippingRequest::STATUS_REQUESTED,
        ]);

        $ids = $this->shippableIds($s);

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($bundled->id, $ids);
    }

    /**
     * 📊 미수 필드 — 이름은 `/sales`·`/receivables` 와 같고, **환율 미입력은 null 로 흘린다**.
     * 0 으로 바꾸면 board 가 완납으로 그려 미완납 차를 모르고 묶게 된다(§5-4 의 그 버그).
     */
    public function test_unpaid_fields_ride_along_and_null_stays_null(): void
    {
        $s = $this->salesman();
        $v = $this->vehicle($s, ['sale_price' => 1_000_000]);
        $fx = $this->vehicle($s, ['sale_price' => 5_000, 'currency' => 'USD', 'exchange_rate' => 0]);

        $rows = collect($this->signedGet('/api/internal/board/shippable', ['salesman_email' => $s->email])
            ->assertOk()->json('data'))->keyBy('vehicle_id');

        $this->assertSame(1_000_000, $rows[$v->id]['unpaid_krw']);
        $this->assertEquals(1.0, $rows[$v->id]['unpaid_ratio']);
        $this->assertFalse($rows[$v->id]['fully_paid']);

        $this->assertNull($rows[$fx->id]['unpaid_krw'], '환율 미입력이 0 으로 뭉개졌다 — 가짜 완납');
        $this->assertFalse($rows[$fx->id]['fully_paid']);
    }

    /** 명부는 활성만 · 이름만 — 담당자·연락처가 새면 §3 화이트리스트 위반. */
    public function test_forwarding_roster_is_active_only_and_carries_no_pii(): void
    {
        ForwardingCompany::create(['name' => '가나포워딩', 'is_active' => true,
            'contact_name' => '김담당', 'email' => 'a@f.com', 'phone' => '010-1111-2222']);
        ForwardingCompany::create(['name' => '폐업포워딩', 'is_active' => false]);

        $res = $this->signedGet('/api/internal/board/forwarding-companies')->assertOk();

        $this->assertSame(1, $res->json('count'));
        $this->assertSame('가나포워딩', $res->json('data.0.name'));
        $this->assertSame(['id', 'name'], array_keys($res->json('data.0')), '명부에 이름·id 외 필드가 샜다');
    }

    // ───────────────────────── 포워딩사 · 운임비 (요청 ③④) ─────────────────────────

    /** 포워딩사는 차량 원장에 반영되고, 묶음에도 남아 board 가 다시 그릴 수 있다. */
    public function test_forwarding_lands_on_the_vehicle_and_echoes_back(): void
    {
        $s = $this->salesman();
        $fw = ForwardingCompany::create(['name' => '한진', 'is_active' => true]);
        $v = $this->vehicle($s, ['sale_price' => 1_000_000]);

        $this->signedPost('/api/internal/board/shipping-requests/sync', [
            'salesman_email' => $s->email,
            'bundles' => [[
                'shipping_method' => 'RORO', 'vehicle_ids' => [$v->id],
                'forwarding_company_id' => $fw->id,
            ]],
        ])->assertOk();

        $this->assertSame($fw->id, $v->fresh()->forwarding_company_id);

        $bundle = $this->signedGet('/api/internal/board/bundles', ['salesman_email' => $s->email])
            ->assertOk()->json('data.0');
        $this->assertSame(['id' => $fw->id, 'name' => '한진'], $bundle['forwarding_company']);
    }

    /**
     * 🧭 **관리가 ERP 에서 고친 포워딩사가 재전송으로 되돌아가지 않는다.**
     * sync 는 선언형이라 저장할 때마다 전체가 온다 — 매번 덮으면 관리의 정정이 조용히 사라진다.
     */
    public function test_resync_does_not_revert_a_manual_correction(): void
    {
        $s = $this->salesman();
        $fw = ForwardingCompany::create(['name' => '한진', 'is_active' => true]);
        $corrected = ForwardingCompany::create(['name' => '현대글로비스', 'is_active' => true]);
        $v = $this->vehicle($s, ['sale_price' => 1_000_000]);

        $payload = [
            'salesman_email' => $s->email,
            'bundles' => [[
                'shipping_method' => 'RORO', 'vehicle_ids' => [$v->id],
                'forwarding_company_id' => $fw->id,
            ]],
        ];
        $this->signedPost('/api/internal/board/shipping-requests/sync', $payload)->assertOk();

        // 관리가 ERP 에서 정정
        $v->update(['forwarding_company_id' => $corrected->id]);

        // board 가 같은 내용을 재전송 (칸을 안 건드렸으므로 같은 값)
        $this->signedPost('/api/internal/board/shipping-requests/sync', $payload)->assertOk();
        $this->assertSame($corrected->id, $v->fresh()->forwarding_company_id, '관리 정정이 재전송에 지워졌다');

        // 무관한 변경이 섞여도 판정은 포워딩사 값 기준 — 여전히 안 덮는다.
        $payload['bundles'][0]['bl_type'] = 'original';
        $this->signedPost('/api/internal/board/shipping-requests/sync', $payload)->assertOk();
        $this->assertSame($corrected->id, $v->fresh()->forwarding_company_id, '같은 값 재전송인데 덮었다');

        // 반대로 **영업이 실제로 다른 회사로 바꾸면** 그건 반영된다(이쪽이 안 되면 기능 자체가 죽는다).
        $picked = ForwardingCompany::create(['name' => '팬오션', 'is_active' => true]);
        $payload['bundles'][0]['forwarding_company_id'] = $picked->id;
        $this->signedPost('/api/internal/board/shipping-requests/sync', $payload)->assertOk();
        $this->assertSame($picked->id, $v->fresh()->forwarding_company_id, '영업의 변경이 차량에 안 반영됐다');
    }

    /**
     * 🔍 board 가 고른 포워딩사는 감사에 **사람 이름**으로 남아야 한다.
     * HMAC 경로엔 로그인 세션이 없어 그냥 두면 「시스템」으로 찍히고,
     * "영업이 잘못 골라도 관리가 눈치챌 기회가 사라진다" 는 우려의 대응이 통째로 비게 된다.
     */
    public function test_forwarding_change_is_attributed_to_the_salesman(): void
    {
        $user = User::factory()->create(['email' => 'ship@ex.com', 'email_verified_at' => now()]);
        $s = $this->salesman();
        $s->update(['user_id' => $user->id]);
        $fw = ForwardingCompany::create(['name' => '한진', 'is_active' => true]);
        $v = $this->vehicle($s, ['sale_price' => 1_000_000]);

        $this->signedPost('/api/internal/board/shipping-requests/sync', [
            'salesman_email' => $s->email,
            'bundles' => [['shipping_method' => 'RORO', 'vehicle_ids' => [$v->id], 'forwarding_company_id' => $fw->id]],
        ])->assertOk();

        $log = AuditLog::where('auditable_type', Vehicle::class)
            ->where('auditable_id', $v->id)->where('column_name', 'forwarding_company_id')->first();

        $this->assertNotNull($log, '포워딩사 변경이 감사에 안 남았다');
        $this->assertSame($user->id, $log->user_id, '「시스템」으로 찍혔다 — 누가 골랐는지 알 수 없다');
        $this->assertSame((string) $fw->id, $log->new_value);
    }

    /** 명부에 없거나 비활성인 포워딩사는 422 — 지급 명부 오염 경로를 안 만든다. */
    public function test_unknown_or_inactive_forwarding_is_rejected(): void
    {
        $s = $this->salesman();
        $dead = ForwardingCompany::create(['name' => '폐업', 'is_active' => false]);
        $v = $this->vehicle($s, ['sale_price' => 1_000_000]);

        foreach ([999_999, $dead->id] as $bad) {
            $this->signedPost('/api/internal/board/shipping-requests/sync', [
                'salesman_email' => $s->email,
                'bundles' => [['shipping_method' => 'RORO', 'vehicle_ids' => [$v->id], 'forwarding_company_id' => $bad]],
            ])->assertStatus(422);
        }

        $this->assertSame(0, ShippingRequest::count(), '422 인데 행이 만들어졌다 — 부분 적용');
    }

    /**
     * 💵 1/N — 분모는 **묶음 전체 대수**, 나머지는 **최소 vehicle_id** 한 대.
     * 이미 값이 있는 차는 건너뛰므로 실제 기입 합계가 총액보다 작을 수 있다(의도된 결과).
     */
    public function test_container_freight_splits_by_all_members_and_keeps_manual_values(): void
    {
        $s = $this->salesman();
        $a = $this->vehicle($s, ['sale_price' => 1_000_000]);
        $b = $this->vehicle($s, ['sale_price' => 1_000_000]);
        $c = $this->vehicle($s, ['sale_price' => 1_000_000, 'transport_fee_usd' => 700]);   // 수기값 — 보호 대상

        $this->signedPost('/api/internal/board/shipping-requests/sync', [
            'salesman_email' => $s->email,
            'bundles' => [[
                'shipping_method' => 'CONTAINER', 'vehicle_ids' => [$a->id, $b->id, $c->id],
                'transport_fee_usd_total' => 1000,
            ]],
        ])->assertOk();

        // 1000 / 3 = 333, 나머지 1 은 최소 id 한 대에
        $this->assertSame(334, $a->fresh()->transport_fee_usd);
        $this->assertSame(333, $b->fresh()->transport_fee_usd);
        $this->assertSame(700, $c->fresh()->transport_fee_usd, '수기 입력값이 덮였다');
    }

    /** RORO 면 운임비를 받지 않는다 — board 가 실수로 보내도 서버가 버린다. */
    public function test_roro_bundle_never_records_freight(): void
    {
        $s = $this->salesman();
        $v = $this->vehicle($s, ['sale_price' => 1_000_000]);

        $this->signedPost('/api/internal/board/shipping-requests/sync', [
            'salesman_email' => $s->email,
            'bundles' => [[
                'shipping_method' => 'RORO', 'vehicle_ids' => [$v->id],
                'transport_fee_usd_total' => 900,
            ]],
        ])->assertOk();

        $this->assertNull($v->fresh()->transport_fee_usd);
        $this->assertNull(ShippingRequest::first()->transport_fee_usd_total);
    }

    /** 재전송으로 값이 중복 누적되지 않는다(비어 있을 때만 채우는 규칙의 부수 효과). */
    public function test_freight_is_written_once(): void
    {
        $s = $this->salesman();
        $v = $this->vehicle($s, ['sale_price' => 1_000_000]);
        $payload = [
            'salesman_email' => $s->email,
            'bundles' => [['shipping_method' => 'CONTAINER', 'vehicle_ids' => [$v->id], 'transport_fee_usd_total' => 500]],
        ];

        $this->signedPost('/api/internal/board/shipping-requests/sync', $payload)->assertOk();
        $this->signedPost('/api/internal/board/shipping-requests/sync', $payload)->assertOk();

        $this->assertSame(500, $v->fresh()->transport_fee_usd);
    }

    /** 🧮 분할 규칙 자체 — 단일 출처(`splitFreightUsd`) 단위 검증. */
    public function test_split_rule(): void
    {
        // 나머지 1 은 순서와 무관하게 **최소 id**(5)가 받는다 — 재전송해도 같은 차가 받는다.
        $this->assertEquals([5 => 334, 7 => 333, 9 => 333], ShippingRequest::splitFreightUsd(1000, [9, 5, 7]));
        $this->assertEquals([3 => 500, 4 => 500], ShippingRequest::splitFreightUsd(1000, [3, 4]));
        $this->assertSame([], ShippingRequest::splitFreightUsd(null, [1, 2]));
        $this->assertSame([], ShippingRequest::splitFreightUsd(0, [1, 2]));
        $this->assertSame([], ShippingRequest::splitFreightUsd(100, []));
    }
}
