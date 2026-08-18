<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 연동 B 멱등 재전송 — **비어 있는 칸만 채우기** (2026-08-18, board 인계 ①).
 *
 * 배경: 급한 차는 매입가만 넣고 판매가 없이 ERP 로 보낸다. 나중에 판매가를 채워 재전송해도
 * 멱등 분기가 첨부만 받고 **금액을 통째로 무시**했다.
 *
 * 이 테스트가 지키는 불변식 3개:
 *  1. 빈 칸만 채운다 — **이미 있는 값은 절대 안 덮는다**(관리가 ERP 에서 고친 값 보호)
 *  2. 판매 3종 + sale_date 는 **세트** — 부분 기입은 `chk_sale_required` 위반으로 죽는다
 *  3. **왜 안 채웠는지 응답에 담는다** — board 가 200 만 보고 성공으로 기록하는 걸 막는다
 */
class PurchaseSyncFillIfEmptyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-hmac-secret';

    private const URI = '/api/internal/purchase-sync';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.purchase_sync.hmac_secret', self::SECRET);
        config()->set('services.nice.provide_url', '');
        config()->set('services.nice.provide_token', '');
        config()->set('filesystems.purchase_sync_inbound_disk', config('filesystems.vehicle_docs_disk'));
    }

    private function postSigned(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BOARD_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'contract_version' => 3,
            'vehicle_number' => '12가3456',
            'owner_name' => '홍길동',
            'source' => 'auction',
            'final_price' => 12000000,
            'salesman_email' => 'sales@car-erp.test',
        ], $overrides);
    }

    private function salesman(): Salesman
    {
        return Salesman::create([
            'name' => '김영업', 'email' => 'sales@car-erp.test', 'type' => 'freelance', 'is_active' => true,
        ]);
    }

    /** 매입가만 보내서 차량을 만든다 (판매 필드 전부 빈 상태). */
    private function createPurchaseOnly(): Vehicle
    {
        $this->postSigned($this->payload())->assertStatus(201);
        $v = Vehicle::where('vehicle_number', '12가3456')->firstOrFail();
        $this->assertSame(0.0, (float) $v->sale_price, '전제: 판매가가 비어 있어야 한다');

        return $v;
    }

    // ── 핵심 흐름 ────────────────────────────────────────────────

    public function test_late_sale_price_is_applied_on_resend(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();

        $res = $this->postSigned($this->payload([
            'sale_price' => 8000,
            'sale_currency' => 'EUR',
            'sale_exchange_rate' => 1450,
        ]));

        $res->assertStatus(200);
        $this->assertContains('sale_price', $res->json('fields_filled'));

        $v->refresh();
        $this->assertSame(8000.0, (float) $v->sale_price);
        $this->assertSame('EUR', $v->currency);
        $this->assertSame(1450.0, (float) $v->exchange_rate);
        $this->assertNotNull($v->sale_date, 'chk_sale_required — sale_price>0 이면 sale_date 필수');
    }

    /** sale_date 는 **수신일**로 채운다 — 신규 생성 경로와 같은 판단(board 엔 판매일 개념이 없다). */
    public function test_sale_date_is_the_receive_date(): void
    {
        $this->salesman();
        $this->createPurchaseOnly();

        $this->postSigned($this->payload([
            'sale_price' => 8000, 'sale_currency' => 'USD', 'sale_exchange_rate' => 1300,
        ]))->assertStatus(200);

        $v = Vehicle::where('vehicle_number', '12가3456')->firstOrFail();
        $this->assertSame(now()->toDateString(), $v->sale_date->toDateString());
    }

    /** 판매가가 들어가면 진행상태도 따라 움직인다(캐시 훅이 도는 경로인지 확인). */
    public function test_progress_status_follows(): void
    {
        $this->salesman();
        $this->createPurchaseOnly();

        $this->postSigned($this->payload([
            'sale_price' => 8000, 'sale_currency' => 'USD', 'sale_exchange_rate' => 1300,
        ]))->assertStatus(200);

        $v = Vehicle::where('vehicle_number', '12가3456')->firstOrFail();
        $this->assertSame('판매중', $v->progress_status_cache);
    }

    // ── 안 덮는다 ────────────────────────────────────────────────

    public function test_existing_sale_price_is_never_overwritten(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();

        // 관리가 ERP 에서 직접 넣은 값
        $v->update([
            'sale_price' => 9999, 'currency' => 'USD', 'exchange_rate' => 1300, 'sale_date' => '2026-07-01',
        ]);

        $res = $this->postSigned($this->payload([
            'sale_price' => 1, 'sale_currency' => 'JPY', 'sale_exchange_rate' => 9,
        ]));

        $res->assertStatus(200);
        $this->assertSame('already_set', $res->json('fields_skipped.sale_price'));

        $v->refresh();
        $this->assertSame(9999.0, (float) $v->sale_price, '관리가 고친 값이 board 재전송으로 되돌아가면 안 된다');
        $this->assertSame('USD', $v->currency);
        $this->assertSame('2026-07-01', $v->sale_date->toDateString());
    }

    public function test_existing_transport_fee_is_never_overwritten(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();
        $v->update(['transport_fee' => 700]);

        $res = $this->postSigned($this->payload(['transport_fee' => 100]));

        $this->assertSame('already_set', $res->json('fields_skipped.transport_fee'));
        $this->assertSame(700.0, (float) $v->refresh()->transport_fee);
    }

    // ── 부분 데이터는 통째로 보류 ─────────────────────────────────

    public function test_sale_price_without_exchange_rate_is_held_with_a_reason(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();

        $res = $this->postSigned($this->payload([
            'sale_price' => 8000, 'sale_currency' => 'EUR',   // 환율 없음
        ]));

        $res->assertStatus(200);
        $this->assertSame('missing_exchange_rate', $res->json('fields_skipped.sale_price'));
        $this->assertNotContains('sale_price', $res->json('fields_filled'));

        $v->refresh();
        $this->assertSame(0.0, (float) $v->sale_price, 'CHECK 위반을 만들지 않는다');
        $this->assertNull($v->sale_date);
    }

    // ── 바이어 / 컨사이니 ────────────────────────────────────────

    public function test_buyer_and_consignee_fill_when_empty(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);
        $c = Consignee::create(['buyer_id' => $b->id, 'name' => 'ATLAS CNEE', 'is_active' => true]);

        $res = $this->postSigned($this->payload(['buyer_id' => $b->id, 'consignee_id' => $c->id]));

        $this->assertContains('buyer_id', $res->json('fields_filled'));
        $this->assertContains('consignee_id', $res->json('fields_filled'));

        $v->refresh();
        $this->assertSame($b->id, $v->buyer_id);
        $this->assertSame($c->id, $v->consignee_id);
    }

    /** 차량의 바이어와 다른 바이어의 컨사이니는 붙이지 않는다 — 붙으면 서류가 조용히 틀린다. */
    public function test_consignee_of_another_buyer_is_refused(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();
        $mine = Buyer::create(['name' => 'MINE', 'is_active' => true]);
        $other = Buyer::create(['name' => 'OTHER', 'is_active' => true]);
        $otherCnee = Consignee::create(['buyer_id' => $other->id, 'name' => 'OTHER CNEE', 'is_active' => true]);

        $v->update(['buyer_id' => $mine->id]);

        $res = $this->postSigned($this->payload([
            'buyer_id' => $other->id, 'consignee_id' => $otherCnee->id,
        ]));

        $this->assertSame('already_set', $res->json('fields_skipped.buyer_id'));
        $this->assertSame('buyer_mismatch', $res->json('fields_skipped.consignee_id'));
        $this->assertNull($v->refresh()->consignee_id);
    }

    public function test_invalid_buyer_is_reported_not_silently_dropped(): void
    {
        $this->salesman();
        $this->createPurchaseOnly();

        $res = $this->postSigned($this->payload(['buyer_id' => 999999]));

        $this->assertSame('invalid_or_inactive', $res->json('fields_skipped.buyer_id'));
    }

    // ── 응답 계약 ────────────────────────────────────────────────

    /** 아무것도 안 보내면 조용히 아무 일도 없다(기존 멱등 동작 보존). */
    public function test_plain_resend_changes_nothing(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();
        $before = $v->updated_at;

        $res = $this->postSigned($this->payload());

        $res->assertStatus(200);
        $this->assertSame([], $res->json('fields_filled'));
        $this->assertEquals($before, $v->refresh()->updated_at);
    }

    /** 첨부 실패 통로(`attachments_failed`)가 그대로 살아 있어야 한다 — 같은 응답에 얹었다. */
    public function test_attachment_channel_still_present(): void
    {
        $this->salesman();
        $this->createPurchaseOnly();

        $res = $this->postSigned($this->payload(['sale_price' => 8000, 'sale_exchange_rate' => 1300]));

        $res->assertJsonStructure(['vehicle_id', 'attachments_added', 'attachments_failed', 'fields_filled', 'fields_skipped']);
    }

    // ── 감사 귀속 ────────────────────────────────────────────────

    /**
     * `buyer_id` 는 감사 대상 컬럼인데 HMAC 경로엔 세션이 없어 「시스템」으로 찍힌다(SKILLS §8 #56).
     * salesman_email 로 User 를 찾아 귀속시킨다 — cron 과 구분되게.
     */
    public function test_audit_is_attributed_to_the_salesman_user(): void
    {
        $this->salesman();
        $user = User::create([
            'name' => '김영업', 'email' => 'sales@car-erp.test', 'password' => bcrypt('x'),
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);
        $v = $this->createPurchaseOnly();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);

        $this->postSigned($this->payload(['buyer_id' => $b->id]))->assertStatus(200);

        $log = AuditLog::where('auditable_id', $v->id)->where('column_name', 'buyer_id')->latest('id')->first();
        $this->assertNotNull($log, 'buyer_id 변경이 감사에 남아야 한다');
        $this->assertSame($user->id, $log->user_id, '「시스템」이 아니라 사람으로 남아야 한다');
    }

    /** 연결된 User 가 없으면 종전대로 null — 기능이 죽지는 않는다. */
    public function test_missing_user_does_not_break_the_fill(): void
    {
        $this->salesman();
        $v = $this->createPurchaseOnly();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);

        $this->postSigned($this->payload(['buyer_id' => $b->id]))->assertStatus(200);

        $this->assertSame($b->id, $v->refresh()->buyer_id);
    }
}
