<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * board↔erp 요청·확인 신호 D단계 — 재무 처리 화면.
 *
 * 화면 선택 근거(jin 2026-08-07): 재무가 통장 보며 잔금을 실제로 기입하는 자리가 여기다.
 * 기입과 회신이 한 화면에서 끝나야 카톡으로 안 돌아간다.
 *
 * ⚠️ [입금요청]은 **PBP 행이 아직 없는 상태**로 온다(board 는 재무가 기입하기 *전*에 요청한다).
 *    그래서 잔금 목록이 아니라 BoardRequest 기준 별도 섹션으로 띄운다 — 이 전제가 깨지면
 *    "요청은 왔는데 화면에 아무것도 없다"가 된다.
 */
class BoardRequestFinanceScreenTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function vehicle(?int $buyerId = null): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'BFS-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'buyer_id' => $buyerId,
            'purchase_date' => '2026-08-01', 'purchase_price' => 1_000_000,
        ]);
    }

    /** PBP 행이 하나도 없어도 요청이 보여야 한다 — 이게 정상 상태다. */
    public function test_purchase_request_shows_without_any_pbp_row(): void
    {
        $v = $this->vehicle();
        BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        $this->assertSame(0, $v->purchaseBalancePayments()->count(), '전제: 잔금 행이 없다');

        Volt::actingAs($this->finance())->test('erp.transfers.index')
            ->set('tabType', 'purchase_payment')
            ->assertSee($v->vehicle_number)
            ->assertSee('sales@ex.com');
    }

    /** 지급 입력 버튼 → 그 차량으로 prefill. 최근 50대 밖이어도 선택칸이 비지 않아야 한다. */
    public function test_pay_button_prefills_vehicle_even_outside_recent_list(): void
    {
        $target = $this->vehicle();
        // 이후 등록된 차량 60대에 밀려 '최근 50대' 목록 밖으로 나간다.
        foreach (range(1, 60) as $i) {
            $this->vehicle();
        }

        $component = Volt::actingAs($this->finance())->test('erp.transfers.index')
            ->set('tabType', 'purchase_payment')
            ->call('openNewPbpModal', (string) $target->id);

        $component->assertSet('newPbpVehicleId', (string) $target->id)
            ->assertSet('showNewPbpModal', true)
            ->assertSee($target->vehicle_number);
    }

    public function test_sale_confirm_batch_shows_partial_progress(): void
    {
        $buyer = Buyer::create(['name' => 'ABC TRADING', 'is_active' => true]);
        $v1 = $this->vehicle($buyer->id);
        $v2 = $this->vehicle($buyer->id);
        $batch = 'batch-screen-1';
        $l1 = BoardRequest::open($v1->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, $batch);
        BoardRequest::open($v2->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, $batch);
        $l1->markDone();

        Volt::actingAs($this->finance())->test('erp.transfers.index')
            ->set('tabType', 'sale_payment')
            ->assertSee('ABC TRADING')
            ->assertSee('1/2')                 // 부분확인 — 2대 중 1대
            ->assertSee($v2->vehicle_number);
    }

    public function test_finance_can_confirm_single_vehicle(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $line = BoardRequest::open(
            $this->vehicle($buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-1'
        );

        $user = $this->finance();
        Volt::actingAs($user)->test('erp.transfers.index')
            ->set('tabType', 'sale_payment')
            ->call('confirmBoardSaleLine', $line->id);

        $line->refresh();
        $this->assertSame(BoardRequest::STATUS_DONE, $line->status);
        $this->assertSame($user->id, $line->confirmed_by_id, '누가 확인했는지 남아야 한다(감사)');
    }

    /**
     * 영업은 확인할 수 없다 — "재무가 통장을 봤다"가 이 기능의 존재 이유다(SoD).
     *
     * 1차 방어는 화면 진입(`mount` 403)이라 영업은 컴포넌트를 마운트조차 못 한다.
     * `confirmBoardSaleLine` 안의 `canConfirmFinance` 가드는 그 뒤의 방어 심층이다
     * (라우트·미들웨어가 느슨해져도 액션 자체가 안 먹게 — SKILLS §8 #26 의 "mutating 은 매번 재인가").
     */
    public function test_sales_role_cannot_even_open_the_screen(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        $this->actingAs($sales)->get('/erp/transfers')->assertStatus(403);
    }

    /** 방어 심층 — 권한 없는 사용자가 액션에 직접 닿아도 상태가 안 바뀐다. */
    public function test_confirm_action_rejects_user_without_finance_permission(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $line = BoardRequest::open(
            $this->vehicle($buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-1'
        );
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        // 화면은 재무로 열고, 액션 시점의 인증만 영업으로 바꾼다(세션 탈취·권한 강등 시나리오).
        $component = Volt::actingAs($this->finance())->test('erp.transfers.index')
            ->set('tabType', 'sale_payment');

        $this->actingAs($sales);
        $component->call('confirmBoardSaleLine', $line->id);

        $this->assertSame(BoardRequest::STATUS_OPEN, $line->fresh()->status, '영업이 판매대금을 확인해버렸다');
    }

    /** 전부 확인된 묶음은 목록에서 사라진다 — 남은 일만 보여야 화면이 안 쌓인다. */
    public function test_fully_confirmed_batch_disappears(): void
    {
        $buyer = Buyer::create(['name' => 'DONE CO', 'is_active' => true]);
        $line = BoardRequest::open(
            $this->vehicle($buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-done'
        );
        $line->markDone();

        Volt::actingAs($this->finance())->test('erp.transfers.index')
            ->set('tabType', 'sale_payment')
            ->assertDontSee('DONE CO');
    }

    /** 매입 요청은 매입 탭에만, 판매 확인은 판매 탭에만 — 탭이 섞이면 재무가 혼란스럽다. */
    public function test_requests_do_not_leak_across_tabs(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $pv = $this->vehicle();
        $sv = $this->vehicle($buyer->id);
        BoardRequest::open($pv->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');
        BoardRequest::open($sv->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-x');

        Volt::actingAs($this->finance())->test('erp.transfers.index')
            ->set('tabType', 'purchase_payment')
            ->assertSee($pv->vehicle_number)
            ->assertDontSee($sv->vehicle_number);
    }
}
