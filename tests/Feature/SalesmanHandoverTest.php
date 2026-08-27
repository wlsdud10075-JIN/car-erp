<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SalesmanHandoverService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 퇴사 승계 (jin 2026-08-27) — A 가 하던 일을 B 가 통째로 받는다.
 *
 * 지키는 것 셋:
 *  ① **경계는 「정산이 생겼나」 하나다** — 정산 없는 차만 B 로. 있는 차는 A 그대로.
 *  ② **A 의 정산이 소급으로 안 깎인다** — 승계 표시가 바이어에 붙어 있어 과거 건까지 읽히던 것.
 *  ③ **흔적이 남는다** — `buyers.salesman_id` 는 평소 감사에 안 남는다.
 */
class SalesmanHandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Settlement::flushParamMemo();
    }

    private function salesman(string $name, string $type = 'employee'): Salesman
    {
        return Salesman::create(['name' => $name, 'is_active' => true, 'type' => $type]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => '관리자', 'email' => 'admin'.uniqid().'@t.test',
            'password' => 'x', 'permission' => 'admin', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function vehicle(Salesman $sm, ?int $buyerId = null, array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '77가'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'salesman_id' => $sm->id,
            'buyer_id' => $buyerId,
        ], $attrs));
    }

    // ── ① 경계 = 정산 유무 ───────────────────────────────────────────────

    public function test_only_vehicles_without_a_settlement_move(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);

        $inProgress = $this->vehicle($a, $buyer->id);
        $settled = $this->vehicle($a, $buyer->id);
        Settlement::create([
            'vehicle_id' => $settled->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        (new SalesmanHandoverService)->apply($a, $b, $this->admin());

        $this->assertSame($b->id, $inProgress->fresh()->salesman_id, '진행중 차는 B 로 간다');
        $this->assertSame($a->id, $settled->fresh()->salesman_id, '정산 빠진 차는 A 그대로');
        $this->assertSame($b->id, $buyer->fresh()->salesman_id, '바이어는 전부 B 로');
    }

    /** 정산 자체는 옮기지 않는다 — A 계정 정산은 A 가 받는다. */
    public function test_existing_settlements_stay_with_the_departing_salesman(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);
        $v = $this->vehicle($a, $buyer->id);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        (new SalesmanHandoverService)->apply($a, $b, $this->admin());

        $this->assertSame($a->id, $s->fresh()->salesman_id, '정산 담당자는 안 바뀐다');
    }

    /** 미리보기와 실행이 같은 판정을 쓴다 — 갈리면 「옮긴다고 떴는데 안 옮겨진」 행이 남는다. */
    public function test_preview_matches_what_apply_does(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);
        $move = $this->vehicle($a, $buyer->id);
        $keep = $this->vehicle($a, $buyer->id);
        Settlement::create([
            'vehicle_id' => $keep->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        $svc = new SalesmanHandoverService;
        $plan = $svc->preview($a, $b);

        $this->assertSame([$move->id], array_column($plan['vehicles'], 'id'));
        $this->assertSame([$keep->id], array_column($plan['skipped'], 'id'));
        $this->assertSame('has_settlement', $plan['skipped'][0]['reason'], '사유가 같이 나와야 한다');

        $result = $svc->apply($a, $b, $this->admin());
        $this->assertSame(1, $result['vehicles']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['buyers']);
    }

    // ── ② 소급 차단 ──────────────────────────────────────────────────────

    /**
     * 🚨 이 테스트가 이 기능의 존재 이유다.
     *
     * 승계 표시는 **바이어**에 붙어 있어 그 바이어의 과거 건까지 같이 읽힌다.
     * 막지 않으면 퇴사자 A 의 아직 안 굳은 정산이 건당 5만으로 다시 잘린다 —
     * A 가 신규 개척한 실적인데 승계 단가로 깎이는 것이고, 예외도 경고도 없이 조용히 바뀐다.
     */
    public function test_handover_does_not_recut_the_departing_salesmans_settlement(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);

        // A 시절 건 — 총마진이 100만 이상이라 tier 로 20만이어야 한다.
        $v = $this->vehicle($a, $buyer->id, [
            'purchase_price' => 10_000_000, 'sale_price' => 30_000_000,
            'sale_date' => now()->subMonth()->toDateString(), 'exchange_rate' => 1, 'currency' => 'KRW',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);
        $before = $s->fresh()->effective_per_unit_amount;
        $this->assertGreaterThan(50_000, $before, '전제: 승계가 아니면 5만보다 크다');

        (new SalesmanHandoverService)->apply($a, $b, $this->admin());
        Settlement::flushParamMemo();

        $this->assertTrue((bool) $buyer->fresh()->is_inherited, '전제: 승계 표시가 켜졌다');
        $this->assertSame(
            $before,
            $s->fresh()->effective_per_unit_amount,
            'A 의 정산이 승계 단가로 깎였다 — 신규 개척 실적을 승계로 읽은 것이다'
        );
    }

    /** 승계 이후 B 가 받는 건은 건당 5만이 맞다 — 게이트가 과하게 막으면 기능이 죽는다. */
    public function test_new_deals_for_the_receiving_salesman_are_inherited(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);
        (new SalesmanHandoverService)->apply($a, $b, $this->admin());
        Settlement::flushParamMemo();

        $v = $this->vehicle($b, $buyer->id, [
            'purchase_price' => 10_000_000, 'sale_price' => 30_000_000,
            'sale_date' => now()->toDateString(), 'exchange_rate' => 1, 'currency' => 'KRW',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $b->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        $this->assertSame(50_000, $s->fresh()->effective_per_unit_amount);
    }

    /**
     * ⚠️ 한 요청 안에서 같은 바이어의 A 행과 B 행이 **각각** 판정돼야 한다.
     *    판정 결과를 바이어별로 캐시하면 먼저 읽힌 쪽 답이 둘 다에 붙는다 —
     *    정산 목록 화면이 정확히 그 상황이다.
     */
    public function test_two_settlements_of_the_same_buyer_are_judged_separately(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);

        $money = [
            'purchase_price' => 10_000_000, 'sale_price' => 30_000_000,
            'sale_date' => now()->toDateString(), 'exchange_rate' => 1, 'currency' => 'KRW',
        ];
        $va = $this->vehicle($a, $buyer->id, $money);
        $sa = Settlement::create([
            'vehicle_id' => $va->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        (new SalesmanHandoverService)->apply($a, $b, $this->admin());
        Settlement::flushParamMemo();

        $vb = $this->vehicle($b, $buyer->id, $money);
        $sb = Settlement::create([
            'vehicle_id' => $vb->id, 'salesman_id' => $b->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        // 같은 요청 안에서 A 행 먼저 읽고 B 행을 읽는다.
        $aAmount = $sa->fresh()->effective_per_unit_amount;
        $bAmount = $sb->fresh()->effective_per_unit_amount;

        $this->assertGreaterThan(50_000, $aAmount, 'A 행이 승계로 읽혔다 — 메모가 새고 있다');
        $this->assertSame(50_000, $bAmount, 'B 행이 승계로 안 읽혔다 — 메모가 새고 있다');
    }

    /**
     * 하위호환 — 2026-08-04~08-27 사이에 **수기로** 켠 바이어는 승계 전 담당자가 비어 있다.
     * 그 경우는 종전대로 전부 적용해야 한다(기존 운영 데이터의 정산액이 안 바뀌게).
     */
    public function test_manually_marked_buyer_without_a_predecessor_keeps_old_behaviour(): void
    {
        $sm = $this->salesman('SM');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $sm->id, 'is_inherited' => true]);
        $v = $this->vehicle($sm, $buyer->id, [
            'purchase_price' => 10_000_000, 'sale_price' => 30_000_000,
            'sale_date' => now()->toDateString(), 'exchange_rate' => 1, 'currency' => 'KRW',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        $this->assertSame(50_000, $s->fresh()->effective_per_unit_amount);
    }

    // ── 승계 표시는 받는 사람의 타입이 정한다 ───────────────────────────

    public function test_freelancer_receives_without_the_inherited_mark(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B', 'freelance');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);

        $svc = new SalesmanHandoverService;
        $this->assertFalse($svc->marksInherited($b));

        $svc->apply($a, $b, $this->admin());

        $this->assertSame($b->id, $buyer->fresh()->salesman_id);
        $this->assertFalse((bool) $buyer->fresh()->is_inherited, '프리랜서는 그대로 넘어간다');
    }

    // ── ③ 흔적 · 인가 ───────────────────────────────────────────────────

    public function test_buyer_reassignment_is_audited(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);

        (new SalesmanHandoverService)->apply($a, $b, $this->admin(), '2026-08 퇴사');

        $this->assertTrue(
            AuditLog::where('auditable_type', Buyer::class)->where('auditable_id', $buyer->id)
                ->where('column_name', 'salesman_id')->exists(),
            '바이어 담당자 이동이 감사에 안 남았다 — Buyer 엔 감사 훅이 없어 여기서 남겨야 한다'
        );

        $bulk = AuditLog::where('action', 'salesman_handover')->first();
        $this->assertNotNull($bulk, '일괄 출처가 안 남았다');
        $this->assertStringContainsString('2026-08 퇴사', (string) $bulk->new_value);
        // 감사로그 드롭박스가 무한히 늘지 않게 — column_name 은 실제 컬럼이어야 한다.
        $this->assertSame('salesman_id', $bulk->column_name);
    }

    public function test_sales_user_cannot_run_the_handover(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $sales = User::create([
            'name' => '영업', 'email' => 'sales'.uniqid().'@t.test', 'password' => 'x',
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);
        (new SalesmanHandoverService)->apply($a, $b, $sales);
    }

    public function test_inactive_receiver_is_refused(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $b->update(['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        (new SalesmanHandoverService)->apply($a, $b, $this->admin());
    }

    /** 넘기는 쪽은 이미 비활성일 수 있다 — 「먼저 내리고 나중에 넘기는」 순서를 막지 않는다. */
    public function test_inactive_sender_is_allowed(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $a->update(['is_active' => false]);
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);

        (new SalesmanHandoverService)->apply($a, $b, $this->admin());

        $this->assertSame($b->id, $buyer->fresh()->salesman_id);
    }

    /** 감사로그 화면이 영문 식별자를 그대로 찍지 않게 — 새 action 은 한글 라벨이 있어야 한다. */
    public function test_the_new_action_has_a_korean_label(): void
    {
        $label = config('column_labels.actions.salesman_handover');
        $this->assertNotNull($label, '한글 라벨이 없다 — 감사 화면에 영문이 그대로 나간다');
        $this->assertMatchesRegularExpression('/[가-힣]/u', $label);
    }

    // ── 화면 ─────────────────────────────────────────────────────────────

    /** 버튼이 [관리] 이상에게만 보인다 — 정산 금액을 바꾸는 작업이다. */
    public function test_button_is_visible_only_to_approvers(): void
    {
        $this->salesman('A');

        $this->actingAs($this->admin());
        Volt::test('erp.salesmen.index')->assertSee(__('salesman.handover.button'));

        $clearance = User::create([
            'name' => '통관', 'email' => 'cl'.uniqid().'@t.test', 'password' => 'x',
            'permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now(),
        ]);
        $this->actingAs($clearance);
        Volt::test('erp.salesmen.index')->assertDontSee(__('salesman.handover.button'));
    }

    /**
     * 받을 사람을 고르면 **미리보기가 나온다.** 그리고 그 미리보기가 실행과 같은 판정이어야 한다 —
     * 여기서는 「건너뛸 것」이 화면에 실제로 렌더되는지를 본다(카운터로 뭉개지 않았나).
     */
    public function test_modal_shows_what_moves_and_what_stays(): void
    {
        $a = $this->salesman('떠나는사람');
        $b = $this->salesman('받는사람');
        Buyer::create(['name' => 'BUYER-X', 'salesman_id' => $a->id]);
        $keep = $this->vehicle($a);
        Settlement::create([
            'vehicle_id' => $keep->id, 'salesman_id' => $a->id,
            'settlement_type' => 'per_unit', 'settlement_status' => 'pending',
        ]);

        $this->actingAs($this->admin());
        Volt::test('erp.salesmen.index')
            ->call('openHandover', $a->id)
            ->set('handoverToId', (string) $b->id)
            ->assertSee('BUYER-X')
            ->assertSee($keep->vehicle_number)        // 건너뛰는 차량번호가 보여야 한다
            ->assertSee(__('salesman.handover.skipping'))
            ->assertSee('건당 5만원');                 // 승계 표시 판정이 문장으로 보여야 한다(§8 #60)
    }

    /** 화면에서 실행하면 실제로 이관된다. */
    public function test_running_from_the_screen_applies(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $buyer = Buyer::create(['name' => 'BUYER', 'salesman_id' => $a->id]);

        $this->actingAs($this->admin());
        Volt::test('erp.salesmen.index')
            ->call('openHandover', $a->id)
            ->set('handoverToId', (string) $b->id)
            ->call('runHandover');

        $this->assertSame($b->id, $buyer->fresh()->salesman_id);
    }

    // ── 나눠 넘기기 (jin 2026-08-27) ───────────────────────

    /** 고른 바이어만 넘어간다. 나머지는 A 에 남아 **다음 사람에게 넘길 수 있어야** 한다. */
    public function test_only_the_picked_buyers_move(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $c = $this->salesman('C');
        $x = Buyer::create(['name' => 'X', 'salesman_id' => $a->id]);
        $y = Buyer::create(['name' => 'Y', 'salesman_id' => $a->id]);

        $svc = new SalesmanHandoverService;
        $svc->apply($a, $b, $this->admin(), null, [$x->id]);

        $this->assertSame($b->id, $x->fresh()->salesman_id);
        $this->assertSame($a->id, $y->fresh()->salesman_id, '안 고른 바이어는 A 에 남는다');

        // 두 번째 승계 — 남은 것을 C 에게
        $svc->apply($a, $c, $this->admin(), null, [$y->id]);
        $this->assertSame($c->id, $y->fresh()->salesman_id);
    }

    /** 두 번째로 열면 이미 넘어간 바이어는 목록에서 빠진다 — 남은 것만 보여야 나눌 수 있다. */
    public function test_second_open_shows_only_what_is_left(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $x = Buyer::create(['name' => 'X', 'salesman_id' => $a->id]);
        $y = Buyer::create(['name' => 'Y', 'salesman_id' => $a->id]);

        $svc = new SalesmanHandoverService;
        $svc->apply($a, $b, $this->admin(), null, [$x->id]);

        $plan = $svc->preview($a, $b);
        $this->assertSame([$y->id], array_column($plan['candidates'], 'id'));
    }

    /** 차량은 **그 바이어를 따라간다** — 안 고른 바이어의 차는 같이 안 간다. */
    public function test_vehicles_follow_their_buyer(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $x = Buyer::create(['name' => 'X', 'salesman_id' => $a->id]);
        $y = Buyer::create(['name' => 'Y', 'salesman_id' => $a->id]);
        $vx = $this->vehicle($a, $x->id);
        $vy = $this->vehicle($a, $y->id);

        (new SalesmanHandoverService)->apply($a, $b, $this->admin(), null, [$x->id]);

        $this->assertSame($b->id, $vx->fresh()->salesman_id);
        $this->assertSame($a->id, $vy->fresh()->salesman_id, '안 고른 바이어의 차까지 따라갔다');
    }

    /**
     * 담당 바이어가 없는 차는 **그대로 A 에 남는다** (jin 2026-08-27 — 체크박스를 만들었다가 뺐다).
     * 그 차들은 대부분 **바이어에 담당자가 안 붙어서** 생긴다 — 승계 모달에서 고를 일이 아니라
     * 바이어 탭에서 담당자를 지정하면 저절로 풀린다. 그래서 「그대로 두는 것」에 사유로 적어 보여준다.
     */
    public function test_vehicles_without_a_buyer_stay_behind(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $x = Buyer::create(['name' => 'X', 'salesman_id' => $a->id]);
        $orphan = $this->vehicle($a, null);

        $svc = new SalesmanHandoverService;
        $plan = $svc->preview($a, $b, [$x->id]);

        $this->assertNotContains($orphan->id, array_column($plan['vehicles'], 'id'));
        $this->assertContains('no_buyer', array_column($plan['skipped'], 'reason'), '사유가 보여야 어디서 고칠지 안다');

        $svc->apply($a, $b, $this->admin(), null, [$x->id]);
        $this->assertSame($a->id, $orphan->fresh()->salesman_id);
    }

    /** 고른 게 없고 넘길 차도 없으면 아무 일도 안 한다 — 빈 감사로그만 남기지 않는다. */
    public function test_empty_selection_is_refused(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        Buyer::create(['name' => 'X', 'salesman_id' => $a->id]);

        $this->expectException(\InvalidArgumentException::class);
        (new SalesmanHandoverService)->apply($a, $b, $this->admin(), null, []);
    }

    /** 클라이언트가 남의 바이어 id 를 넣어도 무시된다(§8 #26). */
    public function test_injected_foreign_buyer_ids_are_ignored(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $c = $this->salesman('C');
        $mine = Buyer::create(['name' => 'MINE', 'salesman_id' => $a->id]);
        $others = Buyer::create(['name' => 'OTHERS', 'salesman_id' => $c->id]);

        (new SalesmanHandoverService)->apply($a, $b, $this->admin(), null, [$mine->id, $others->id]);

        $this->assertSame($c->id, $others->fresh()->salesman_id, '남의 바이어가 넘어갔다');
    }

    /** 화면에서 체크를 풀면 그만큼만 넘어간다 — 미리보기와 실행이 같은 인자를 쓴다. */
    public function test_screen_partial_handover(): void
    {
        $a = $this->salesman('A');
        $b = $this->salesman('B');
        $x = Buyer::create(['name' => 'X', 'salesman_id' => $a->id]);
        $y = Buyer::create(['name' => 'Y', 'salesman_id' => $a->id]);

        $this->actingAs($this->admin());
        Volt::test('erp.salesmen.index')
            ->call('openHandover', $a->id)
            ->set('handoverToId', (string) $b->id)
            ->set('handoverBuyerIds', [(string) $x->id])
            ->call('runHandover');

        $this->assertSame($b->id, $x->fresh()->salesman_id);
        $this->assertSame($a->id, $y->fresh()->salesman_id);
    }
}
