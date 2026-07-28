<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 적립금 사용을 채권관리 회수방법으로 (jin 2026-07-28).
 *
 * 판매탭·채권관리 **둘 다에서** 쓸 수 있게 하되, 어디서 쓰든
 *   ① 바이어 적립금 잔액(SavingsStatus)이 정확히 차감되고
 *   ② 미수는 딱 한 번만 줄고(이중 차감 없음)
 *   ③ 회수이력에 기록이 남는다(이력 합 = savings_used)
 * 는 세 가지가 항상 성립해야 한다.
 */
class SavingsInReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function vehicle(int $salePrice = 1_000_000): Vehicle
    {
        $buyer = Buyer::create(['name' => 'RB'.++$this->c, 'is_active' => true]);
        $salesman = Salesman::create(['name' => 'RS'.$this->c, 'is_active' => true, 'type' => 'employee']);

        return Vehicle::create([
            'vehicle_number' => 'RV'.$this->c, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => $salePrice,
            'sale_date' => '2026-06-01', 'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ]);
    }

    private function seedSavings(Vehicle $v, float $balance): void
    {
        SavingsStatus::create([
            'buyer_id' => $v->buyer_id, 'currency' => $v->currency,
            'transaction_type' => 'EARNED', 'savings' => $balance, 'balance' => $balance,
        ]);
    }

    private function financeUser(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function latestBalance(Vehicle $v): float
    {
        return (float) (SavingsStatus::where('buyer_id', $v->buyer_id)
            ->where('currency', $v->currency)->orderByDesc('id')->first()?->balance ?? 0);
    }

    // ── 채권관리 → 적립금 사용 ────────────────────────────────────────

    public function test_receivable_savings_row_reduces_unpaid_once_and_draws_balance(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $user = $this->financeUser();

        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-06-10',
            'collector_id' => $user->id, 'method' => 'savings', 'amount' => 300_000,
        ]);

        $v->refresh();
        $this->assertEqualsWithDelta(300_000, (float) $v->savings_used, 0.01, '회수이력이 savings_used 를 갱신');
        $this->assertEqualsWithDelta(200_000, $this->latestBalance($v), 0.01, '바이어 적립금 잔액 차감');
        // 미수는 100만 - 30만 = 70만. 60만(이중 차감)이면 회수이력 합에서도 빠진 것.
        $this->assertEqualsWithDelta(700_000, $v->sale_unpaid_amount, 0.01, '미수는 한 번만 차감돼야 한다');
    }

    public function test_editing_savings_row_applies_only_the_difference(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $user = $this->financeUser();

        $h = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-06-10',
            'collector_id' => $user->id, 'method' => 'savings', 'amount' => 300_000,
        ]);
        $h->update(['amount' => 400_000]);

        $v->refresh();
        $this->assertEqualsWithDelta(400_000, (float) $v->savings_used, 0.01, '차액만 반영(700,000 되면 중복 가산)');
        $this->assertEqualsWithDelta(100_000, $this->latestBalance($v), 0.01);
        $this->assertEqualsWithDelta(600_000, $v->sale_unpaid_amount, 0.01);
    }

    public function test_deleting_savings_row_restores_balance_and_unpaid(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $user = $this->financeUser();

        $h = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-06-10',
            'collector_id' => $user->id, 'method' => 'savings', 'amount' => 300_000,
        ]);
        $h->delete();

        $v->refresh();
        $this->assertEqualsWithDelta(0, (float) $v->savings_used, 0.01);
        $this->assertEqualsWithDelta(500_000, $this->latestBalance($v), 0.01, '적립금 환원(REFUND)');
        $this->assertEqualsWithDelta(1_000_000, $v->sale_unpaid_amount, 0.01);
        $this->assertSame('REFUND', SavingsStatus::where('buyer_id', $v->buyer_id)->orderByDesc('id')->first()->transaction_type);
    }

    /** 방법을 적립금 → 현금으로 바꾸면 적립금 사용분은 되돌아가야 한다. */
    public function test_changing_method_away_from_savings_reverts_usage(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $user = $this->financeUser();

        $h = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-06-10',
            'collector_id' => $user->id, 'method' => 'savings', 'amount' => 300_000,
        ]);
        $h->update(['method' => 'cash']);

        $v->refresh();
        $this->assertEqualsWithDelta(0, (float) $v->savings_used, 0.01);
        $this->assertEqualsWithDelta(500_000, $this->latestBalance($v), 0.01);
        // 이제 현금 회수라 회수이력 합으로 미수 차감 — 총 차감액은 여전히 30만(경로만 바뀜)
        $this->assertEqualsWithDelta(700_000, $v->sale_unpaid_amount, 0.01);
    }

    // ── 판매탭 → 회수이력 자동 기록 ───────────────────────────────────

    public function test_sales_tab_usage_is_mirrored_into_receivable_history(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $this->actingAs($this->financeUser());

        $v->update(['savings_used' => 300_000]);

        $rows = ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'savings')->get();
        $this->assertCount(1, $rows, '판매탭 사용분도 이력에 남아야 추적이 된다');
        $this->assertEqualsWithDelta(300_000, (float) $rows->sum('amount'), 0.01);
        // 미러가 savings_used 를 또 건드리지 않았는지 (이중 반영 방지 플래그 검증)
        $this->assertEqualsWithDelta(300_000, (float) $v->fresh()->savings_used, 0.01);
        $this->assertEqualsWithDelta(700_000, $v->fresh()->sale_unpaid_amount, 0.01);
    }

    /** 이력 합 = savings_used 불변식 — 판매탭에서 증액·감액을 반복해도 유지. */
    public function test_history_sum_always_matches_savings_used(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $this->actingAs($this->financeUser());

        $v->update(['savings_used' => 300_000]);
        $v->fresh()->update(['savings_used' => 450_000]);
        $v->fresh()->update(['savings_used' => 100_000]);

        $sum = (float) ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'savings')->sum('amount');
        $this->assertEqualsWithDelta(100_000, $sum, 0.01, '증액·감액 delta 가 누적돼 최종 사용액과 일치');
        $this->assertEqualsWithDelta(100_000, (float) $v->fresh()->savings_used, 0.01);
        $this->assertEqualsWithDelta(400_000, $this->latestBalance($v), 0.01);
    }

    // ── 화면(채권관리 드로어) ─────────────────────────────────────────

    public function test_screen_blocks_usage_over_balance(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 100_000);
        $this->actingAs($this->financeUser());

        Volt::test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->set('hCollectedAt', '2026-06-10')
            ->set('hCollectorId', (string) auth()->id())
            ->set('hMethod', 'savings')
            ->set('hAmount', '300000')
            ->call('saveHistory')
            ->assertHasErrors('hAmount');

        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->count());
        $this->assertEqualsWithDelta(0, (float) $v->fresh()->savings_used, 0.01);
    }

    public function test_screen_saves_savings_collection(): void
    {
        $v = $this->vehicle();
        $this->seedSavings($v, 500_000);
        $this->actingAs($this->financeUser());

        Volt::test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->set('hCollectedAt', '2026-06-10')
            ->set('hCollectorId', (string) auth()->id())
            ->set('hMethod', 'savings')
            ->set('hAmount', '200000')
            ->call('saveHistory')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(200_000, (float) $v->fresh()->savings_used, 0.01);
        $this->assertEqualsWithDelta(300_000, $this->latestBalance($v), 0.01);
    }
}
