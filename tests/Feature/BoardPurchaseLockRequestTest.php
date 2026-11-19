<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BoardPurchaseLockAllowance as Allowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔐 board 발 매입 락 해제 요청 → ERP 승인큐 (jin 2026-11-19). 권위 = docs/integration/board-portal-api.md §17.
 *
 * 지키는 것:
 *   ① 요청은 락 걸린 «본인» 바이어로만 · 대기 건이 있으면 새 요청 거부 · 금액은 저장되지 않는다
 *   ② 승인 = 대수 부여(줄여서 승인) → 차가 ERP 에 등록될 때 1대씩 차감 → 다 쓰면 다시 0 (마지막 걸음까지, §8 #105)
 *   ③ 대수가 없어도 purchase-sync 는 받는다(돈이 나간 뒤다) · 재전송은 차감하지 않는다
 *   ④ 7일 지나면 남은 대수 소멸 · 추가 승인은 더해진다
 *   ⑤ 요청을 누른 사람(대리 관리 포함)은 그 건을 승인할 수 없다
 *   ⑥ ERP 계정 없는 영업의 요청도 받는다(requester_id 비움)
 */
class BoardPurchaseLockRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/internal/board/purchase-lock-requests';

    private string $secret = 'test-board-read-secret';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        config()->set('services.purchase_sync.hmac_secret', 'sync-secret');
        config()->set('services.nice.provide_url', '');
        config()->set('services.nice.provide_token', '');
    }

    private function signedPost(string $path, array $payload)
    {
        $body = json_encode($payload);
        $ts = now()->timestamp;

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', "POST\n".$path."?\n".$ts."\n".$body, $this->secret),
            'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function signedGet(string $path, array $query)
    {
        ksort($query);
        $ts = now()->timestamp;
        $qs = http_build_query($query);

        return $this->get($path.'?'.$qs, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', "GET\n".$path.'?'.$qs."\n".$ts."\n", $this->secret),
            'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    /** board 가 구매확정 뒤 보내는 연동 B — 새 차량. */
    private function sync(string $plate, Salesman $s, ?int $buyerId, ?int $listingId = null, array $extra = [])
    {
        $body = json_encode(array_merge([
            'contract_version' => 5, 'vehicle_number' => $plate, 'source' => 'auction', 'final_price' => 10_000_000,
            'salesman_email' => $s->email, 'buyer_id' => $buyerId, 'purchase_listing_id' => $listingId,
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/api/internal/purchase-sync', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BOARD_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'sync-secret'),
        ], $body);
    }

    private function salesman(string $email, bool $withUser = true): Salesman
    {
        $user = $withUser ? User::factory()->create(['permission' => 'user', 'role' => '영업', 'email' => $email]) : null;

        return Salesman::create(['name' => 'S'.++$this->n, 'email' => $email, 'is_active' => true, 'user_id' => $user?->id]);
    }

    /** 미수율 100% — 판매가만 있고 입금 0. 락 기준(기본 50%)을 넘는다. */
    private function lockedBuyer(Salesman $s): Buyer
    {
        $b = Buyer::create(['name' => 'LOCKED '.++$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
        Vehicle::create([
            'vehicle_number' => 'LK'.$this->n.'가1234', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $s->id, 'buyer_id' => $b->id, 'purchase_date' => '2026-11-01', 'purchase_price' => 20_000_000,
            'sale_price' => 30_000_000, 'sale_date' => '2026-11-02',
        ])->refreshCaches();

        return $b;
    }

    private function manager(string $email = 'mgr@car-erp.test'): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email' => $email, 'email_verified_at' => now()]);
    }

    private function body(Salesman $s, Buyer $b, array $extra = []): array
    {
        return array_merge([
            'salesman_email' => $s->email, 'buyer_id' => $b->id, 'requested_count' => 5,
            'reason' => '다음 주 잔금 입금 예정입니다', 'requested_by_name' => $s->name, 'requested_by_email' => $s->email,
            'purchase_listing_id' => 9001, 'vehicle_number' => '00마0005', 'vehicle_label' => '팰리세이드',
        ], $extra);
    }

    private function approve(User $by, int $requestId, int $count): void
    {
        $this->actingAs($by);
        Volt::test('erp.approvals.index')
            ->call('openApproveModal', $requestId)
            ->set('decisionCount', (string) $count)
            ->call('decide');
    }

    public function test_request_is_stored_for_a_locked_own_buyer_without_amounts(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);

        $res = $this->signedPost(self::PATH, $this->body($s, $b, ['sale_price' => 9999, 'purchase_price_krw' => 8888]));
        $res->assertStatus(201)->assertJson(['status' => 'pending', 'requested_count' => 5]);

        $req = ApprovalRequest::sole();
        $this->assertSame(ApprovalRequest::TYPE_BOARD_PURCHASE_LOCK, $req->action_type);
        $this->assertSame(Buyer::class, $req->target_type);
        $this->assertSame($b->id, (int) $req->target_id);
        $this->assertSame($s->user_id, $req->requester_id);
        $this->assertSame(5, $req->payload['requested_count']);
        $this->assertArrayNotHasKey('sale_price', $req->payload, '금액은 승인 대상이 아니다 — 보내도 저장하지 않는다');
        $this->assertArrayNotHasKey('purchase_price_krw', $req->payload);
        $this->assertSame(1, AuditLog::where('action', 'board_purchase_lock_requested')->count());

        // 대기 건이 있으면 새 요청을 받지 않는다 — 기존 건을 돌려준다.
        $this->signedPost(self::PATH, $this->body($s, $b))->assertStatus(409)
            ->assertJson(['error' => 'already_pending', 'request' => ['id' => $req->id, 'status' => 'pending']]);
        $this->assertSame(1, ApprovalRequest::count());
    }

    public function test_request_is_refused_for_someone_elses_buyer_and_for_an_unlocked_buyer(): void
    {
        $s = $this->salesman('a@ex.com');
        $other = $this->salesman('b@ex.com');
        $theirs = $this->lockedBuyer($other);
        $free = Buyer::create(['name' => 'FREE', 'is_active' => true, 'salesman_id' => $s->id]);

        $this->signedPost(self::PATH, $this->body($s, $theirs))->assertStatus(404);
        $this->signedPost(self::PATH, $this->body($s, $free))->assertStatus(409)->assertJson(['error' => 'not_locked']);
        $this->signedPost(self::PATH, $this->body($s, $this->lockedBuyer($s), ['requested_by_email' => null]))->assertStatus(422);
        $this->signedGet(self::PATH, ['salesman_email' => $s->email, 'buyer_id' => $theirs->id])->assertStatus(404);
        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_approved_count_is_used_up_car_by_car_and_the_next_car_is_still_accepted(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);
        $id = $this->signedPost(self::PATH, $this->body($s, $b))->json('id');

        // 5대 요청 → 3대만 승인
        $this->approve($this->manager(), $id, 3);
        $req = ApprovalRequest::findOrFail($id);
        $this->assertSame('approved', $req->status);
        $this->assertSame(3, $req->payload['approved_count']);

        $get = fn () => $this->signedGet(self::PATH, ['salesman_email' => $s->email, 'buyer_id' => $b->id])->assertOk()->json();
        $this->assertSame(3, $get()['allowance']['remaining']);
        $this->assertSame('approved', $get()['latest']['status']);

        // /buyers — 락 원판정은 그대로(true), 남은 대수가 같이 내려간다
        $row = collect($this->signedGet('/api/internal/board/buyers', ['salesman_email' => $s->email])->assertOk()->json('data'))->firstWhere('id', $b->id);
        $this->assertTrue($row['purchase_locked']);
        $this->assertSame(3, $row['purchase_lock_allowance']['remaining']);

        foreach ([1, 2, 3] as $i) {
            $r = $this->sync("7{$i}가700{$i}", $s, $b->id, 9000 + $i)->assertStatus(201);
            $this->assertTrue($r->json('purchase_lock.consumed'));
            $this->assertSame(3 - $i, $r->json('purchase_lock.remaining'));
        }
        $this->assertNull($get()['allowance'], '다 쓰면 남은 대수가 없다');
        $this->assertSame('exhausted', $get()['latest']['status']);
        $this->assertNotNull($req->fresh()->used_at);
        $this->assertSame([9001, 9002, 9003], array_column($req->fresh()->payload['used'], 'listing_id'));
        $this->assertSame(3, AuditLog::where('action', 'board_purchase_lock_used')->where('approval_request_id', $id)->count());

        // 재전송(같은 차량번호)은 차감하지 않는다
        $this->sync('71가7001', $s, $b->id, 9001)->assertStatus(200);
        $this->assertCount(3, $req->fresh()->payload['used']);

        // 대수가 없어도 등록은 막지 않는다 — 초과로 감사에만 남는다
        $over = $this->sync('74가7004', $s, $b->id, 9004)->assertStatus(201);
        $this->assertFalse($over->json('purchase_lock.consumed'));
        $this->assertSame(1, AuditLog::where('action', 'board_purchase_lock_overshoot')->count());
        $this->assertNull($this->signedGet('/api/internal/board/buyers', ['salesman_email' => $s->email])->json('data.0.purchase_lock_allowance'));
    }

    public function test_cars_without_a_buyer_and_ordinary_registrations_do_not_touch_the_count(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);
        $plain = Buyer::create(['name' => 'PLAIN', 'is_active' => true, 'salesman_id' => $s->id]);
        $id = $this->signedPost(self::PATH, $this->body($s, $b, ['requested_count' => 2]))->json('id');
        $this->approve($this->manager(), $id, 2);

        $this->sync('81가8001', $s, null, 1, ['buyer_undecided' => true])->assertStatus(201)->assertJsonPath('purchase_lock.consumed', false);
        $this->sync('82가8002', $s, $plain->id, 2)->assertStatus(201)->assertJsonPath('purchase_lock.consumed', false);

        $this->assertSame(2, Allowance::summary($b->id)['remaining']);
        $this->assertSame(0, AuditLog::where('action', 'board_purchase_lock_overshoot')->count(), '승인과 무관한 평소 등록은 초과로 남기지 않는다');
    }

    public function test_unused_cars_expire_after_seven_days_and_a_new_approval_adds_up(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);
        $mgr = $this->manager();

        $first = $this->signedPost(self::PATH, $this->body($s, $b, ['requested_count' => 3]))->json('id');
        $this->approve($mgr, $first, 3);
        $this->sync('91가9001', $s, $b->id, 1)->assertStatus(201);
        $this->assertSame(2, Allowance::summary($b->id)['remaining']);

        // 남아 있어도 추가 요청 가능 — 승인되면 더해진다
        $this->travel(3)->days();
        $second = $this->signedPost(self::PATH, $this->body($s, $b, ['requested_count' => 3]))->assertStatus(201)->json('id');
        $this->approve($mgr, $second, 3);
        $sum = Allowance::summary($b->id);
        $this->assertSame(5, $sum['remaining']);
        $this->assertSame(ApprovalRequest::find($first)->decided_at->addDays(7)->toIso8601String(), $sum['expires_at'], '먼저 만료되는 시각을 알려 준다');

        // 차감은 먼저 만료되는 승인부터
        $this->sync('92가9002', $s, $b->id, 2)->assertStatus(201);
        $this->assertCount(2, ApprovalRequest::find($first)->payload['used']);
        $this->assertCount(0, ApprovalRequest::find($second)->payload['used']);

        // 첫 승인 후 7일이 지나면 그 승인의 남은 1대는 사라지고 둘째 승인 3대만 남는다
        $this->travel(5)->days();
        $this->assertSame(3, Allowance::summary($b->id)['remaining']);
        $this->assertSame('expired', Allowance::stateOf(ApprovalRequest::find($first)));

        $this->travel(7)->days();
        $this->assertNull(Allowance::summary($b->id));
        $this->assertSame('expired', $this->signedGet(self::PATH, ['salesman_email' => $s->email, 'buyer_id' => $b->id])->json('latest.status'));
    }

    public function test_the_person_who_pressed_the_request_cannot_decide_it(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);
        $proxy = $this->manager('proxy@car-erp.test');
        // 관리가 영업 대신 눌렀다 — 명의는 영업, 누른 사람은 관리
        $id = $this->signedPost(self::PATH, $this->body($s, $b, ['requested_by_name' => '대리관리', 'requested_by_email' => 'PROXY@car-erp.test']))->json('id');

        $this->approve($proxy, $id, 5);
        $this->assertSame('pending', ApprovalRequest::find($id)->status, '누른 사람은 자기 건을 승인할 수 없다');

        $this->approve($this->manager('other@car-erp.test'), $id, 5);
        $this->assertSame('approved', ApprovalRequest::find($id)->status);
    }

    public function test_approving_more_than_requested_is_refused(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);
        $id = $this->signedPost(self::PATH, $this->body($s, $b, ['requested_count' => 2]))->json('id');

        $this->approve($this->manager(), $id, 3);
        $this->assertSame('pending', ApprovalRequest::find($id)->status);
        $this->assertNull(Allowance::summary($b->id));
    }

    public function test_a_salesman_without_an_erp_account_can_still_request_and_rejection_is_reported(): void
    {
        $s = $this->salesman('nouser@ex.com', withUser: false);
        $b = $this->lockedBuyer($s);
        $id = $this->signedPost(self::PATH, $this->body($s, $b))->assertStatus(201)->json('id');
        $this->assertNull(ApprovalRequest::find($id)->requester_id);

        $mgr = $this->manager();
        $this->actingAs($mgr);
        Volt::test('erp.approvals.index')
            ->assertSee($s->name)   // 요청자 칸 — 계정이 없으면 영업 이름으로
            ->assertSeeHtml('data-board-purchase-lock')
            ->call('openRejectModal', $id)
            ->set('decisionNote', '미수가 너무 큽니다')
            ->call('decide');

        $list = $this->signedGet(self::PATH, ['salesman_email' => $s->email])->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('rejected', $list[0]['status']);
        $this->assertSame('미수가 너무 큽니다', $list[0]['decision_note']);
        $this->assertSame($b->id, $list[0]['buyer_id']);
        $this->assertSame($mgr->name, $list[0]['decided_by_name']);

        // 거절 뒤 재요청 가능
        $this->signedPost(self::PATH, $this->body($s, $b))->assertStatus(201);
        // 남의 결과는 안 보인다
        $this->assertSame([], $this->signedGet(self::PATH, ['salesman_email' => $this->salesman('z@ex.com')->email])->json('data'));
    }

    /** board 는 super 가 누를 때 POST 에 scope=all 을 안 붙인다 — ERP 가 그 이메일이 super 인지 직접 본다. */
    public function test_erp_super_can_request_for_any_buyer_but_a_plain_user_cannot(): void
    {
        $b = $this->lockedBuyer($this->salesman('a@ex.com'));
        $super = User::factory()->create(['permission' => 'super', 'role' => '관리', 'email' => 'super@car-erp.test']);
        $this->manager('plain@car-erp.test');

        $this->signedPost(self::PATH, $this->body($this->salesman('x@ex.com'), $b, ['salesman_email' => 'plain@car-erp.test']))->assertStatus(403);
        $this->signedPost(self::PATH, ['salesman_email' => $super->email, 'buyer_id' => $b->id, 'requested_count' => 1,
            'reason' => 'e2e 시험 요청입니다', 'requested_by_name' => $super->name, 'requested_by_email' => $super->email])->assertStatus(201);
        $this->assertNull(ApprovalRequest::sole()->requester_id);
        $this->assertSame(1, $this->signedGet(self::PATH, ['salesman_email' => $super->email, 'buyer_id' => $b->id])->assertOk()->json('latest.requested_count'));
    }

    /** 3대를 30대로 잘못 올렸다 — board 에서 거두고 다시 올린다 (jin 2026-11-19). */
    public function test_a_pending_request_can_be_withdrawn_from_board_and_requested_again(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);
        $id = $this->signedPost(self::PATH, $this->body($s, $b, ['requested_count' => 30]))->json('id');
        $cancel = fn (int $rid, Salesman $who) => $this->signedPost(self::PATH.'/'.$rid.'/cancel', ['salesman_email' => $who->email, 'cancelled_by_name' => $who->name]);

        // 남의 바이어 건은 못 거둔다
        $cancel($id, $this->salesman('other@ex.com'))->assertStatus(404);
        $this->assertSame('pending', ApprovalRequest::find($id)->status);

        $cancel($id, $s)->assertOk()->assertJson(['id' => $id, 'status' => 'cancelled']);
        $this->assertSame('cancelled', ApprovalRequest::find($id)->status);
        $this->assertSame('cancelled', $this->signedGet(self::PATH, ['salesman_email' => $s->email, 'buyer_id' => $b->id])->json('latest.status'));
        $this->assertSame(1, AuditLog::where('action', 'board_purchase_lock_cancelled')->count());
        $this->assertSame(0, ApprovalRequest::actionable()->where('status', 'pending')->count(), '거둔 건은 승인큐·벨에서 사라진다');

        // 거둔 뒤 바로 다시 요청할 수 있다
        $again = $this->signedPost(self::PATH, $this->body($s, $b, ['requested_count' => 3]))->assertStatus(201)->json('id');

        // 결정된 건은 못 거둔다
        $this->approve($this->manager(), $again, 3);
        $cancel($again, $s)->assertStatus(409)->assertJson(['error' => 'already_decided']);
        $this->assertSame(3, Allowance::summary($b->id)['remaining']);
        // 거둔 건은 결정 목록(종·웹푸시)에 안 섞인다
        $this->assertSame([$again], array_column($this->signedGet(self::PATH, ['salesman_email' => $s->email])->json('data'), 'id'));
    }

    public function test_decision_modal_shows_the_buyers_current_figures_not_amounts(): void
    {
        $s = $this->salesman('a@ex.com');
        $b = $this->lockedBuyer($s);
        $id = $this->signedPost(self::PATH, $this->body($s, $b))->json('id');

        $this->actingAs($this->manager());
        Volt::test('erp.approvals.index')
            ->call('openApproveModal', $id)
            ->assertSet('decisionCount', '5')
            ->assertSeeHtml('data-lock-now')
            ->assertSeeHtml('data-lock-count')
            ->assertSee('30,000,000')      // 이 바이어의 지금 미수
            ->assertSee('100%');           // 미수율
    }
}
