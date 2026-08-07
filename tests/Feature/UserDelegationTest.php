<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\UserDelegation;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 휴가 대리 위임 (jin 2026-08-07).
 *
 * 목적 하나 — [관리]가 휴가 갈 때 **담당 영업 N명을 한 번에** 대타에게 넘긴다.
 * 종전엔 영업을 한 명씩 열어 「담당 [관리]」 체크박스에 대타를 추가해야 했다(5명이면 5번).
 *
 * ⚠️ 넘어가는 건 **스코프뿐**이다. 승인 계단·권한 등급은 안 넘긴다 —
 *    월배치 승인은 며칠이면 다녀와서 처리하면 된다는 게 jin 판단.
 */
class UserDelegationTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function user(string $perm, ?string $role = null): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role ?? '관리', 'email_verified_at' => now()]);
    }

    /** 영업 user + Salesman + 담당 [관리] 배정까지 한 번에. */
    private function salesmanUnder(User $manager): Salesman
    {
        $u = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $manager->managedSalesmanUsers()->attach($u->id);

        return Salesman::create(['name' => '영업'.++$this->c, 'is_active' => true, 'user_id' => $u->id]);
    }

    private function delegate(User $from, User $to, array $attrs = []): UserDelegation
    {
        return UserDelegation::create(array_merge([
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'is_active' => true,
            'ends_at' => now()->addDays(7)->toDateString(),
        ], $attrs));
    }

    /**
     * 핵심 — 위임 하나로 담당 영업 **전원**이 대타에게 보인다.
     *
     * `getSubordinateSalesmanIds()` 가 스코프 단일 출처라 차량·바이어·재고·알람·export 가 전부 따라온다.
     */
    public function test_one_switch_hands_over_the_whole_team(): void
    {
        $absent = $this->user('user', '관리');
        $standIn = $this->user('user', '관리');

        $team = collect(range(1, 5))->map(fn () => $this->salesmanUnder($absent));
        $vehicles = $team->map(fn ($s) => Vehicle::create([
            'vehicle_number' => 'DLG'.++$this->c, 'sales_channel' => 'export', 'salesman_id' => $s->id,
        ]));

        foreach ($vehicles as $v) {
            $this->assertFalse($standIn->canScopeVehicle($v), '위임 전에는 남의 팀 차량을 못 본다');
        }

        $this->delegate($absent, $standIn);
        $standIn->forgetDelegationMemo();

        foreach ($team as $s) {
            $this->assertContains($s->id, $standIn->getSubordinateSalesmanIds());
        }
        foreach ($vehicles as $v) {
            $this->assertTrue($standIn->canScopeVehicle($v), '위임 후에는 팀 차량 전부가 보인다');
        }
        $this->assertCount(5, $standIn->getManagedSalesmanUserIds(), '사용자관리 스코프도 함께 넘어간다');
    }

    /** 위임자 본인은 그대로 다 본다 — 넘긴 게 아니라 **함께** 보는 것. */
    public function test_delegator_keeps_their_own_scope(): void
    {
        $absent = $this->user('user', '관리');
        $standIn = $this->user('user', '관리');
        $s = $this->salesmanUnder($absent);

        $this->delegate($absent, $standIn);

        $this->assertContains($s->id, $absent->getSubordinateSalesmanIds());
    }

    /** 복귀일이 지나면 켜져 있어도 무효 — cron 이 안 돌아도 스코프가 안 샌다(조회 시점 판정). */
    public function test_expired_delegation_grants_nothing(): void
    {
        $absent = $this->user('user', '관리');
        $standIn = $this->user('user', '관리');
        $s = $this->salesmanUnder($absent);
        $this->delegate($absent, $standIn, ['ends_at' => now()->subDay()->toDateString()]);

        $this->assertNotContains($s->id, $standIn->getSubordinateSalesmanIds());
        $this->assertTrue(UserDelegation::first()->isExpired());
    }

    /** 복귀일 당일까지는 유효하다 — "오늘 복귀"인데 오전에 끊기면 안 된다. */
    public function test_delegation_is_valid_through_the_return_date_itself(): void
    {
        $absent = $this->user('user', '관리');
        $standIn = $this->user('user', '관리');
        $s = $this->salesmanUnder($absent);
        $this->delegate($absent, $standIn, ['ends_at' => now()->toDateString()]);

        $this->assertContains($s->id, $standIn->getSubordinateSalesmanIds());
    }

    /** 종료하면 즉시 회수된다. */
    public function test_stopping_revokes_immediately(): void
    {
        $absent = $this->user('user', '관리');
        $standIn = $this->user('user', '관리');
        $s = $this->salesmanUnder($absent);
        $d = $this->delegate($absent, $standIn);

        $this->assertContains($s->id, $standIn->getSubordinateSalesmanIds());

        $d->update(['is_active' => false]);
        $standIn->forgetDelegationMemo();

        $this->assertNotContains($s->id, $standIn->getSubordinateSalesmanIds());
    }

    /**
     * 🚨 승인 계단·권한 등급은 **안 넘어간다** (jin 2026-08-07).
     *
     * 위임은 "보이게 해주는" 기능이지 결재를 넘기는 기능이 아니다.
     */
    public function test_delegation_does_not_move_approval_rank(): void
    {
        $admin = $this->user('admin');            // rank 3
        $standIn = $this->user('user', '관리');   // rank 1
        $this->salesmanUnder($admin);
        $this->delegate($admin, $standIn);
        $standIn->forgetDelegationMemo();

        $this->assertSame(1, $standIn->approvalRank(), '위임을 받아도 본인 계단 그대로');

        $v = Vehicle::create(['vehicle_number' => 'RANK1', 'sales_channel' => 'export']);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-05-15', 'attributed_month' => '2026-05-01',
        ]);
        $batch = SettlementPayoutBatch::submitForMonth($this->user('manager'), '2026-05');   // current_level = 3

        $this->assertSame(3, $batch->current_level);
        $this->assertFalse($batch->canDecide($standIn), '대표 위임을 받아도 대표 결재는 못 한다');
        $this->assertSame('confirmed', $s->fresh()->settlement_status);
    }

    /**
     * ⚠️ 위임은 연쇄되지 않는다 — A→B, B→C 여도 C 가 A 의 팀을 얻으면 안 된다.
     * (순환 A→B→A 로 무한루프가 나는 것도 같은 구조로 막힌다.)
     */
    public function test_delegation_does_not_chain(): void
    {
        $a = $this->user('user', '관리');
        $b = $this->user('user', '관리');
        $c = $this->user('user', '관리');
        $sa = $this->salesmanUnder($a);
        $sb = $this->salesmanUnder($b);

        $this->delegate($a, $b);
        $this->delegate($b, $c);
        $c->forgetDelegationMemo();

        $ids = $c->getSubordinateSalesmanIds();
        $this->assertContains($sb->id, $ids, 'B 의 팀은 받는다');
        $this->assertNotContains($sa->id, $ids, 'B 를 거쳐 A 의 팀까지 타고 오면 안 된다');
    }

    public function test_circular_delegation_terminates(): void
    {
        $x = $this->user('user', '관리');
        $y = $this->user('user', '관리');
        $this->delegate($x, $y);
        $this->delegate($y, $x);

        $this->assertIsArray($x->getSubordinateSalesmanIds());
        $this->assertIsArray($y->getSubordinateSalesmanIds());
    }

    /* ─── 화면(사용자관리) ───────────────────────────────────────────── */

    /** 화면에서 대타 지정 → 저장 → 감사로그. 복귀일 검증도 함께. */
    public function test_screen_starts_and_stops_delegation(): void
    {
        $absent = $this->user('user', '관리');
        $standIn = $this->user('user', '관리');
        $s = $this->salesmanUnder($absent);

        $this->actingAs($absent);
        $c = Volt::test('admin.users.index');

        // 복귀일 없이 → 거부
        $c->set('delegateTo', (string) $standIn->id)->set('delegateUntil', '')->call('startDelegation');
        $this->assertSame(0, UserDelegation::count(), '복귀일이 비면 안 켜진다');

        // 지난 날짜 → 거부
        $c->set('delegateUntil', now()->subDay()->toDateString())->call('startDelegation');
        $this->assertSame(0, UserDelegation::count(), '지난 날짜도 안 켜진다');

        // 정상
        $c->set('delegateUntil', now()->addDays(5)->toDateString())->call('startDelegation');
        $d = UserDelegation::first();
        $this->assertNotNull($d);
        $this->assertTrue($d->is_active);
        $this->assertSame(1, AuditLog::where('action', 'delegation_activated')->count());

        $standIn->forgetDelegationMemo();
        $this->assertContains($s->id, $standIn->getSubordinateSalesmanIds());

        // 종료
        $c->call('stopDelegation', $d->id);
        $this->assertFalse($d->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'delegation_deactivated')->count());
    }

    /** 🚨 남의 위임을 대신 끌 수 없다 — id 는 클라이언트가 주입한다(SKILLS §8 #26). */
    public function test_cannot_stop_someone_elses_delegation(): void
    {
        $absent = $this->user('user', '관리');
        $standIn = $this->user('user', '관리');
        $this->salesmanUnder($standIn);   // standIn 도 팀이 있어야 화면 카드가 뜬다
        $d = $this->delegate($absent, $standIn);

        $this->actingAs($standIn);
        Volt::test('admin.users.index')->call('stopDelegation', $d->id)->assertStatus(403);

        $this->assertTrue($d->fresh()->is_active);
    }

    /** 🚨 후보 목록 밖의 사람(영업 등)에게는 못 넘긴다 — 스코프를 넓히는 기능이라 대상 검증이 핵심. */
    public function test_cannot_delegate_to_someone_outside_the_candidate_list(): void
    {
        $absent = $this->user('user', '관리');
        $sales = $this->user('user', '영업');   // rank 0 — 후보 아님
        $this->salesmanUnder($absent);

        $this->actingAs($absent);
        Volt::test('admin.users.index')
            ->set('delegateTo', (string) $sales->id)
            ->set('delegateUntil', now()->addDays(3)->toDateString())
            ->call('startDelegation')
            ->assertStatus(403);

        $this->assertSame(0, UserDelegation::count());
    }

    /** 담당 영업이 없으면 넘길 게 없다 — 카드도 안 뜬다(업무관리자·대표는 원래 전체 스코프). */
    public function test_card_hidden_when_no_team(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager);

        $this->assertSame([], Volt::test('admin.users.index')->get('myTeamNames'));
    }
}
