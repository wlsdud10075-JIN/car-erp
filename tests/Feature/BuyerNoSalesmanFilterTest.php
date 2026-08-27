<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 목록 — **담당자 미지정** 필터 (jin 2026-08-27).
 *
 * 퇴사 승계로 바이어를 나눠 넘기고 나면 「아직 주인이 없는 바이어」를 찾아야 한다.
 * 목록이 담당자 없는 행을 맨 뒤로 밀어두기만 하면, 수백 명 중에서는 **영영 안 보인다.**
 */
class BuyerNoSalesmanFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::create([
            'name' => '관리자', 'email' => 'a'.uniqid().'@t.test', 'password' => 'x',
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    public function test_filter_shows_only_buyers_without_a_salesman(): void
    {
        $sm = Salesman::create(['name' => '홍길동', 'is_active' => true, 'type' => 'employee']);
        Buyer::create(['name' => 'ASSIGNED-CO', 'salesman_id' => $sm->id]);
        Buyer::create(['name' => 'ORPHAN-CO', 'salesman_id' => null]);

        $this->actingAs($this->admin());

        Volt::test('erp.buyers.index')
            ->set('salesmanFilter', '__none')
            ->assertSee('ORPHAN-CO')
            ->assertDontSee('ASSIGNED-CO');
    }

    /** 담당자를 고르면 그 사람 것만 — 미지정 토큰이 숫자 id 필터를 망가뜨리면 안 된다. */
    public function test_picking_a_salesman_still_filters_by_that_salesman(): void
    {
        $sm = Salesman::create(['name' => '홍길동', 'is_active' => true, 'type' => 'employee']);
        Buyer::create(['name' => 'ASSIGNED-CO', 'salesman_id' => $sm->id]);
        Buyer::create(['name' => 'ORPHAN-CO', 'salesman_id' => null]);

        $this->actingAs($this->admin());

        Volt::test('erp.buyers.index')
            ->set('salesmanFilter', (string) $sm->id)
            ->assertSee('ASSIGNED-CO')
            ->assertDontSee('ORPHAN-CO');
    }

    /** 비우면 전부 — 필터가 기본 상태를 바꾸지 않는다. */
    public function test_empty_filter_shows_everything(): void
    {
        $sm = Salesman::create(['name' => '홍길동', 'is_active' => true, 'type' => 'employee']);
        Buyer::create(['name' => 'ASSIGNED-CO', 'salesman_id' => $sm->id]);
        Buyer::create(['name' => 'ORPHAN-CO', 'salesman_id' => null]);

        $this->actingAs($this->admin());

        Volt::test('erp.buyers.index')
            ->set('salesmanFilter', '')
            ->assertSee('ASSIGNED-CO')
            ->assertSee('ORPHAN-CO');
    }

    /** 드롭다운에 그 선택지가 실제로 보여야 한다 — 코드에만 있으면 아무도 못 쓴다(SKILLS §8 #60). */
    public function test_the_option_is_visible_in_the_dropdown(): void
    {
        $this->actingAs($this->admin());
        Volt::test('erp.buyers.index')->assertSee(__('buyer.no_salesman_filter'));
    }
}
