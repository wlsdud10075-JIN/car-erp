<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * board 요청·확인 신호의 **처리 자리 = 차량관리**(jin 2026-08-09 재설계).
 *
 * 처음엔 재무 처리 화면에 섹션을 두고 [지급 입력]까지 붙였다가 걷어냈다. 이유:
 *   - 재무 처리 화면엔 **차값·계약금·매도비가 안 보인다**. 실무는 차량관리 매입 탭에서 금액을 보며 넣는다.
 *   - 신호 하나 때문에 다른 화면으로 건너가 다시 입력하는 건 카톡보다 나을 게 없다.
 *   - jin 이 원한 범위는 **신호뿐** — 기입·반영은 추후다.
 *
 * 그래서 지금은 보증금 매입 뱃지와 **같은 자리·같은 모양**으로 차량관리에 붙고, 드로어에서 회신한다.
 */
class BoardRequestVehicleBadgeTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function vehicle(?int $buyerId = null): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'BFS'.++$this->counter.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'buyer_id' => $buyerId,
            'purchase_date' => '2026-08-01', 'purchase_price' => 1_000_000,
        ]);
    }

    /** 🚫 재무 처리 화면은 board 신호를 더 이상 다루지 않는다 — 되돌아오면 이 테스트가 잡는다. */
    public function test_finance_screen_no_longer_carries_board_requests(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/erp/transfers/index.blade.php'));

        $this->assertStringNotContainsString('BoardRequest', $src,
            '재무 처리 화면에 board 신호가 다시 들어왔다 — 처리 자리는 차량관리다(jin 2026-08-09)');
        $this->assertStringNotContainsString('boardPurchaseRequests', $src);
        $this->assertStringNotContainsString('confirmBoardSaleLine', $src);
    }

    /** 사이드바 「재무 처리」 뱃지에도 섞지 않는다 — 눌러도 갈 곳이 없다. */
    public function test_sidebar_finance_badge_excludes_board_requests(): void
    {
        $src = file_get_contents(base_path('resources/views/components/layouts/app/sidebar.blade.php'));

        $this->assertStringNotContainsString('BoardRequest::openCount', $src,
            '사이드바 재무 처리 뱃지에 board 신호가 합산됐다 — 그 화면엔 board 섹션이 없다');
    }

    public function test_list_shows_badge_for_open_request(): void
    {
        $v = $this->vehicle();
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->assertSee(__('vehicle.board_badge_purchase'));
    }

    public function test_list_badge_disappears_when_closed(): void
    {
        $v = $this->vehicle();
        $req = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');
        $req->markDone();

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->assertDontSee(__('vehicle.board_badge_purchase'));
    }

    /** 두 신호가 같은 차에 동시에 걸릴 수 있다 — 둘 다 보여야 한다. */
    public function test_both_badges_can_show_together(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v = $this->vehicle($buyer->id);
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');
        BoardRequest::raise($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-1');

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->assertSee(__('vehicle.board_badge_purchase'))
            ->assertSee(__('vehicle.board_badge_sale'));
    }

    public function test_drawer_shows_request_and_confirms_sale(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v = $this->vehicle($buyer->id);
        $line = BoardRequest::raise($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-1');

        $user = $this->admin();
        $component = Volt::actingAs($user)->test('erp.vehicles.index')
            ->call('openEdit', $v->id);

        $component->assertSee('sales@ex.com')          // 누가 요청했는지
            ->assertSee(__('vehicle.board_confirm_btn'));

        $component->call('confirmBoardRequest', $line->id);

        $line->refresh();
        $this->assertSame(BoardRequest::STATUS_DONE, $line->status);
        $this->assertSame($user->id, $line->confirmed_by_id, '누가 확인했는지 남아야 한다(감사)');
    }

    /** 입금요청엔 확인 버튼이 없다 — 매입 탭에 지급을 기입하면 자동으로 사라진다. */
    public function test_purchase_request_has_no_confirm_button(): void
    {
        $v = $this->vehicle();
        $line = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        $component = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id);

        $component->assertDontSee(__('vehicle.board_confirm_btn'));

        // 액션을 직접 호출해도 입금요청은 안 닫힌다(type 가드).
        $component->call('confirmBoardRequest', $line->id);
        $this->assertSame(BoardRequest::STATUS_OPEN, $line->fresh()->status);
    }

    /**
     * 계약금·매입잔금 (2026-08-11) — 목록 뱃지·드로어 금액·회신이 모두 붙어야 한다.
     *
     * 이 부류는 **에러 없이 조용히 빠진다** — type 을 늘렸는데 화면이 두 개 리터럴로 분기하고
     * 있으면 뱃지가 안 뜨고, 확인 액션이 옛 type 으로 좁혀져 있으면 버튼을 눌러도 아무 일이
     * 안 일어난다(둘 다 실제로 그 상태였다). 그래서 렌더와 회신을 함께 잡는다.
     */
    public function test_split_purchase_requests_render_amount_and_confirm(): void
    {
        $v = $this->vehicle();
        $deposit = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT, 'sales@ex.com', amountKrw: 3_000_000);
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com', amountKrw: 7_500_000);

        $user = $this->admin();

        // ① 목록 — 두 뱃지가 함께 뜬다(한 차에 계약금·잔금이 동시에 성립한다).
        Volt::actingAs($user)->test('erp.vehicles.index')
            ->assertSee(__('vehicle.board_badge_deposit'))
            ->assertSee(__('vehicle.board_badge_balance'));

        // ② 드로어 — 금액이 보여야 한다. 이게 없으면 돈 보낼 사람이 얼마인지 알 수 없어 기능이 무의미하다.
        $component = Volt::actingAs($user)->test('erp.vehicles.index')->call('openEdit', $v->id);
        $component->assertSee(number_format(3_000_000))
            ->assertSee(number_format(7_500_000))
            ->assertSee(__('vehicle.board_confirm_btn'));

        // ③ 회신 — 계약금은 사람이 눌러야만 닫힌다.
        $component->call('confirmBoardRequest', $deposit->id);
        $deposit->refresh();
        $this->assertSame(BoardRequest::STATUS_DONE, $deposit->status, '계약금 확인 버튼이 아무 일도 하지 않았다');
        $this->assertSame($user->id, $deposit->confirmed_by_id);
    }

    /** 다른 차량의 신호 id 를 주입해도 안 닫힌다 — 패널에 연 차량으로 한정(IDOR). */
    public function test_cannot_confirm_request_of_another_vehicle(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $opened = $this->vehicle($buyer->id);
        $other = $this->vehicle($buyer->id);
        $otherLine = BoardRequest::raise($other->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-2');

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $opened->id)
            ->call('confirmBoardRequest', $otherLine->id);

        $this->assertSame(BoardRequest::STATUS_OPEN, $otherLine->fresh()->status);
    }

    /**
     * 확인 권한 = canConfirmFinance (super·admin·업무관리자·role∈{재무,관리}).
     * 영업은 erp 를 쓰지 않지만 계정은 존재하므로(board 매칭용) 가드는 유지한다.
     *
     * @param  array<string, mixed>  $attrs
     */
    #[DataProvider('confirmerProvider')]
    public function test_permission_matrix(array $attrs, bool $allowed): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);

        // ⚠️ 차량 스코프(canScopeVehicle)를 먼저 통과해야 드로어가 열린다 —
        //    영업은 본인 차량만, 관리는 본인 팀 차량만. 담당자 없는 차로 테스트하면
        //    권한이 아니라 스코프에서 막혀 무엇을 검증했는지 알 수 없게 된다.
        $salesUser = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => 'TEAM', 'email' => 'team'.$this->counter.'@ex.com', 'is_active' => true, 'user_id' => $salesUser->id]);

        $v = $this->vehicle($buyer->id);
        $v->update(['salesman_id' => $sm->id]);
        $line = BoardRequest::raise($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-3');

        $isSalesCase = ($attrs['permission'] ?? '') === 'user' && ($attrs['role'] ?? '') === '영업';
        $user = $isSalesCase
            ? $salesUser                                              // 본인 담당 차량이라 드로어는 열린다
            : User::factory()->create(array_merge(['email_verified_at' => now()], $attrs));

        if (($attrs['permission'] ?? '') === 'user' && ($attrs['role'] ?? '') === '관리') {
            $user->managedSalesmanUsers()->attach($salesUser->id);    // 본인 팀으로 편입
            $user = $user->fresh();
        }

        Volt::actingAs($user)->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('confirmBoardRequest', $line->id);

        $this->assertSame(
            $allowed ? BoardRequest::STATUS_DONE : BoardRequest::STATUS_OPEN,
            $line->fresh()->status,
            '권한 판정이 기대와 다르다: '.json_encode($attrs, JSON_UNESCAPED_UNICODE)
        );
    }

    public static function confirmerProvider(): array
    {
        return [
            '재무 role' => [['permission' => 'user', 'role' => '재무'], true],
            '관리 role' => [['permission' => 'user', 'role' => '관리'], true],
            '업무관리자' => [['permission' => 'manager', 'role' => '영업'], true],
            '최고관리자' => [['permission' => 'admin', 'role' => '관리'], true],
            '영업' => [['permission' => 'user', 'role' => '영업'], false],
        ];
    }
}
