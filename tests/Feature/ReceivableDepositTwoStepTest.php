<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 「입금」은 **2단계**다 — 화면이 그걸 말해야 한다 (jin 2026-09-09 제보).
 *
 * 🚨 `입금`만 성격이 다르다. 그것만 **판매잔금(FinalPayment)을 미확정으로 만들고**, 미수는 재무가
 *    확정해야 준다. 나머지(현금·상계·기타·손실)는 회수이력 행이 곧바로 미수를 깎는다.
 *    그런데 목록이 둘을 똑같이 그려서 「입금은 왜 안 먹지?」가 됐다(jin 이 과입금 테스트 중 발견).
 *
 * 🧭 회계 흐름은 그대로 둔다(jin 결정 — 표시만 고친다). 재무 확정을 건너뛰면 「기록」과 「확정」을
 *    나눈 이중 확인이 사라진다. 운영 실측(heymanerp): 미러 잔금 229건 **전부 확정**·미확정 0건이라
 *    실무에서는 재무가 곧바로 확정하고 있었다 — 그래서 이 구간이 여태 안 드러났다.
 */
class ReceivableDepositTwoStepTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function vehicle(): Vehicle
    {
        $sm = Salesman::create(['name' => 'S1', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'TWO STEP', 'is_active' => true, 'salesman_id' => $sm->id]);

        return Vehicle::create([
            'vehicle_number' => '55마5555', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1500, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'sale_date' => '2026-09-01', 'sale_price' => 10000,
        ]);
    }

    /** 전제 — 「입금」은 저장해도 미수가 안 움직인다(설계). 그래서 과입금도 안 잡힌다. */
    public function test_deposit_does_not_move_the_receivable_until_finance_confirms(): void
    {
        $v = $this->vehicle();
        $this->actingAs($this->finance());

        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'deposit', 'amount' => 12000,
            'collected_at' => '2026-09-02',
        ]);

        $this->assertSame(10000.0, (float) $v->fresh()->sale_unpaid_amount,
            '입금은 재무 확정 전까지 미수를 안 줄인다');

        // 확정하면 그때 움직인다 — 과입금(음수)도 이때 잡힌다.
        $fp = FinalPayment::where('vehicle_id', $v->id)->sole();
        app(PaymentConfirmationService::class)->confirmPayment($fp, auth()->user(), null, null);

        $this->assertSame(-2000.0, (float) $v->fresh()->sale_unpaid_amount, '확정 후 과입금이 잡혀야 한다');
    }

    /** 대조군 — 현금은 즉시 미수를 깎는다. 두 방식의 **타이밍이 다르다**는 게 이 화면의 함정이다. */
    public function test_cash_moves_the_receivable_immediately(): void
    {
        $v = $this->vehicle();
        $this->actingAs($this->finance());

        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'cash', 'amount' => 12000,
            'collected_at' => '2026-09-02',
        ]);

        $this->assertSame(-2000.0, (float) $v->fresh()->sale_unpaid_amount);
    }

    /** 🚨 목록이 그 차이를 **보여줘야** 한다 — 표시가 없으면 현금 행과 구분이 안 된다(SKILLS §8 #60). */
    public function test_pending_deposit_is_marked_in_the_history_list(): void
    {
        $v = $this->vehicle();
        $this->actingAs($this->finance());
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'deposit', 'amount' => 5000,
            'collected_at' => '2026-09-02',
        ]);

        $html = Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->html();

        $this->assertStringContainsString(__('receivable.pending_confirm'), $html,
            '미확정 입금이 확정된 것과 똑같이 보인다');
    }

    /** 확정되면 표시가 사라진다 — 안 사라지면 이번엔 「왜 계속 대기지?」가 된다. */
    public function test_the_mark_disappears_once_finance_confirms(): void
    {
        $v = $this->vehicle();
        $this->actingAs($this->finance());
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'deposit', 'amount' => 5000,
            'collected_at' => '2026-09-02',
        ]);
        app(PaymentConfirmationService::class)->confirmPayment(
            FinalPayment::where('vehicle_id', $v->id)->sole(), auth()->user(), null, null);

        $html = Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->html();

        $this->assertStringNotContainsString(__('receivable.pending_confirm'), $html);
    }

    /** 고르는 순간 알려준다 — 저장 뒤에 알면 이미 헷갈린 뒤다. */
    public function test_choosing_deposit_explains_the_two_step_before_saving(): void
    {
        $v = $this->vehicle();

        $html = Volt::actingAs($this->finance())->test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->set('hMethod', 'deposit')
            ->html();

        $this->assertStringContainsString(__('receivable.deposit_two_step'), $html);
    }

    /**
     * 🚨 **부분 select 함정** — `finalPayment` 를 부분 로드하면서 `confirmed_at` 을 빼면 판정이 늘
     *    null 이 되어 **확정된 입금까지 「대기중」으로 보인다**. 예외도 로그도 없다(SKILLS §8 #83).
     */
    public function test_the_eager_load_carries_the_confirmation_column(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/receivables/index.blade.php'));

        $this->assertStringContainsString("'receivableHistories.finalPayment:id,confirmed_at'", $src,
            'confirmed_at 을 빼면 모든 입금이 대기중으로 보인다');
    }
}
