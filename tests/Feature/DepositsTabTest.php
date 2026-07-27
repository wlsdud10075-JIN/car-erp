<?php

namespace Tests\Feature;

use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 예치·가수금 (jin 2026-07-27, 안건4 1단계) — 가수금 + 경매보증금 2탭.
 * 회수/반제 = 행 삭제(softDelete) → 목록 합계 = 현재 잔액.
 */
class DepositsTabTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $permission = 'user'): User
    {
        return User::factory()->create([
            'permission' => $permission,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    public function test_finance_role_can_add_advance_receipt(): void
    {
        $this->actingAs($this->user('재무'));

        Volt::test('erp.deposits.index')
            ->set('date', '2026-07-10')
            ->set('party', 'OO상사')
            ->set('person', '김담당')
            ->set('amount', '5,000,000')
            ->call('add')
            ->assertHasNoErrors();

        $row = AdvanceReceipt::sole();
        $this->assertSame('OO상사', $row->company_name);
        $this->assertSame('김담당', $row->person_name);
        $this->assertSame(5000000, (int) $row->amount);
    }

    public function test_auction_tab_saves_to_auction_deposits(): void
    {
        $this->actingAs($this->user('재무'));

        Volt::test('erp.deposits.index')
            ->call('setTab', 'auction')
            ->set('date', '2026-07-10')
            ->set('party', '현대글로비스')
            ->set('amount', '3000000')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame('현대글로비스', AuctionDeposit::sole()->auction_house);
        $this->assertSame(0, AdvanceReceipt::count(), '경매 탭 입력이 가수금으로 새면 안 된다');
    }

    /** 회수하면 행을 지운다 → 목록 합계가 곧 현재 잔액. 단 softDelete 라 DB 에는 남는다. */
    public function test_remove_soft_deletes_and_drops_from_total(): void
    {
        $this->actingAs($this->user('재무'));
        $keep = AuctionDeposit::create(['deposited_date' => '2026-07-01', 'auction_house' => 'A', 'amount' => 5000000]);
        $gone = AuctionDeposit::create(['deposited_date' => '2026-07-02', 'auction_house' => 'B', 'amount' => 3000000]);

        $c = Volt::test('erp.deposits.index')->call('setTab', 'auction');
        $this->assertSame(8000000, $c->get('total'));

        $c->call('remove', $gone->id);
        $this->assertSame(5000000, $c->get('total'));

        $this->assertSame(1, AuctionDeposit::count());
        $this->assertSame(2, AuctionDeposit::withTrashed()->count(), 'softDelete 라 이력은 남아야 한다');
        $this->assertNotNull($keep->fresh());
    }

    public function test_amount_must_be_positive(): void
    {
        $this->actingAs($this->user('재무'));

        Volt::test('erp.deposits.index')
            ->set('date', '2026-07-10')
            ->set('party', 'OO상사')
            ->set('amount', '0')
            ->call('add')
            ->assertHasErrors('amount');

        $this->assertSame(0, AdvanceReceipt::count());
    }

    public function test_sales_role_cannot_open(): void
    {
        $this->actingAs($this->user('영업'));

        $this->get('/erp/deposits')->assertStatus(403);
    }

    /** 관리·업무관리자·대표도 접근 가능(canEnterCashBalance = 통장잔액 입력과 같은 축). */
    public function test_management_and_admin_can_open(): void
    {
        foreach ([['관리', 'user'], ['관리', 'admin']] as [$role, $permission]) {
            $this->actingAs($this->user($role, $permission));
            $this->get('/erp/deposits')->assertOk();
        }
    }

    /*
     * ⚠️ add()·remove() 에도 abort_unless(canEnterCashBalance) 가 들어 있다(SKILLS §8 #26 —
     *    mount 1회 인가에만 의존하면 IDOR). 다만 그 재인가를 테스트로 재현하려면 컴포넌트를 연 뒤
     *    계정을 바꿔야 하는데, Livewire 테스트는 세션 중간 계정 전환 시 스냅샷이 깨져 검증이 불가능하다.
     *    (영업으로는 mount 에서 막혀 컴포넌트 자체가 안 열린다 = test_sales_role_cannot_open 이 커버.)
     *    → 가드는 코드에 유지하되 이 케이스는 테스트하지 않는다. 가드를 지우지 말 것.
     */
}
