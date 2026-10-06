<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\CarryoverClearance;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💸 **미청산 이월 → 월배치 자동 조정 줄** (jin 2026-10-06 「너 추천으로 하자. 지금까지 수동으로 넣어서 이전에 이월되었던 게 반영이 안 되었나 봐」).
 *
 * 구: 2차 마감 차액(carryover_out)은 그 담당자에게 **새 정산이 생길 때** 흡수 — 새 차가 없으면 영영 미청산
 *     (실측 ssancarerp 6명 −460,679). 신: 월배치 제출 때 담당자별 잔액이 조정 한 줄로 들어가고 청산 기록으로 0 이 된다.
 *
 * 규칙: + 는 전액(정산 없어도 조정만 있는 행) · − 는 이번 배치 지급액까지만(나머지는 다음 배치) · 지급 0 이면 − 줄 없음 ·
 *       반려하면 되돌아옴 · 새 정산은 더 이상 흡수하지 않음 · 미리보기와 제출이 같은 함수.
 */
class CarryoverAutoBatchLineTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function submitter(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function salesman(string $name = '프리'): Salesman
    {
        return Salesman::create(['name' => $name, 'type' => 'freelance', 'is_active' => true]);
    }

    /** 마감된 정산 한 건 — 이월 잔액만 만든다. */
    private function closedCarry(Salesman $sm, int $carry): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'CO-'.++$this->n, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'purchase_price' => 1_000_000, 'purchase_date' => '2026-06-01',
        ]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => now()->subMonths(2), 'confirmed_at' => now()->subMonths(2),
            'secondary_status' => 'closed', 'secondary_closed_at' => now()->subDay(), 'carryover_out_krw' => $carry,
            'attributed_month' => '2026-07-01',
        ]);
    }

    /** 이번 달(2026-09) 배치에 들어갈 확정 정산 — KRW 완납, 실지급 = 기준액×0.9×50% − 서류비. */
    private function confirmedThisMonth(Salesman $sm, int $salePrice = 10_000_000): Settlement
    {
        $buyer = Buyer::firstOrCreate(['name' => 'CO BUYER'], ['is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'CM-'.++$this->n, 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id, 'purchase_price' => 5_000_000, 'purchase_date' => '2026-08-01',
            'sale_price' => $salePrice, 'sale_date' => '2026-09-01',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => $salePrice, 'payment_date' => '2026-09-05', 'confirmed_at' => now()]);
        $v->refresh()->refreshCaches();

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(), 'attributed_month' => '2026-09-01',
        ]);
    }

    /** 🔑 핵심 — 마감 차액 −45,000 이 제출과 함께 조정 줄이 되고, 청산 기록이 남아 잔액이 0 이 된다. 총액도 그만큼 준다. */
    public function test_submit_turns_unconsumed_carryover_into_an_adjustment_line_and_clears_it(): void
    {
        $sm = $this->salesman();
        $this->closedCarry($sm, -45_000);
        $s = $this->confirmedThisMonth($sm);
        $this->assertSame(-45_000, $sm->fresh()->unconsumed_carryover);
        $payout = (int) $s->actual_payout;

        $batch = SettlementPayoutBatch::submitForMonth($this->submitter(), '2026-09');

        $adj = $batch->adjustments()->where('salesman_id', $sm->id)->first();
        $this->assertNotNull($adj, '자동 조정 줄이 없다');
        $this->assertSame(-45_000, (int) $adj->amount);
        $this->assertStringContainsString('미청산 이월', $adj->reason);
        $this->assertSame($payout - 45_000, (int) $batch->fresh()->total_payout, '배치 총액에 이월이 반영 안 됐다');

        $clear = CarryoverClearance::where('payout_batch_id', $batch->id)->first();
        $this->assertNotNull($clear, '청산 기록이 없다 — 다음 제출에서 또 가져간다');
        $this->assertSame(-45_000, (int) $clear->amount_krw);
        $this->assertSame(0, $sm->fresh()->unconsumed_carryover, '잔액이 0 이 안 됐다');
    }

    /** + 이월(담당자가 받을 돈)은 이번 달 정산이 없어도 전액 — 조정만 있는 행으로 들어간다. */
    public function test_positive_carryover_is_paid_in_full_even_without_a_settlement_this_month(): void
    {
        $owed = $this->salesman('받을사람');
        $this->closedCarry($owed, 38_651);
        $other = $this->salesman('딴사람');
        $this->confirmedThisMonth($other);   // 배치가 비지 않게

        $batch = SettlementPayoutBatch::submitForMonth($this->submitter(), '2026-09');

        $adj = $batch->adjustments()->where('salesman_id', $owed->id)->first();
        $this->assertSame(38_651, (int) $adj?->amount);
        $this->assertSame(0, $owed->fresh()->unconsumed_carryover);
    }

    /** − 이월은 이번 달 지급액까지만 — 넘치는 몫은 미청산으로 남아 다음 배치로. 지급이 0 이면 줄 자체가 없다. */
    public function test_negative_carryover_is_capped_by_this_months_payout_and_the_rest_waits(): void
    {
        $sm = $this->salesman('큰빚');
        $this->closedCarry($sm, -5_000_000);
        $s = $this->confirmedThisMonth($sm, 7_000_000);   // 실지급 = ((7M−5M) + 450,000)×0.9×50% − 50,000 = 1,052,500
        $payout = (int) $s->actual_payout;
        $this->assertGreaterThan(0, $payout);
        $this->assertLessThan(5_000_000, $payout);

        $nothing = $this->salesman('지급없음');
        $this->closedCarry($nothing, -100_000);

        $batch = SettlementPayoutBatch::submitForMonth($this->submitter(), '2026-09');

        $adj = $batch->adjustments()->where('salesman_id', $sm->id)->first();
        $this->assertSame(-$payout, (int) $adj->amount, '지급액까지만 차감해야 한다');
        $this->assertStringContainsString('다음 달', $adj->reason);
        $this->assertSame(-5_000_000 + $payout, $sm->fresh()->unconsumed_carryover, '나머지가 미청산으로 안 남았다');
        $this->assertSame(0, (int) $batch->fresh()->total_payout, '사람 하나의 순액이 0 이면 총액도 0');

        $this->assertNull($batch->adjustments()->where('salesman_id', $nothing->id)->first(), '지급 없는 사람에게서 걷는 줄을 만들었다');
        $this->assertSame(-100_000, $nothing->fresh()->unconsumed_carryover);
    }

    /** 반려하면 청산이 되돌아오고, 다시 제출하면 또 한 줄 — 두 번 빠지지 않는다. */
    public function test_rejecting_the_batch_restores_the_balance_and_resubmit_takes_it_once(): void
    {
        $sm = $this->salesman();
        $this->closedCarry($sm, -45_000);
        $this->confirmedThisMonth($sm);
        $submitter = $this->submitter();

        $batch = SettlementPayoutBatch::submitForMonth($submitter, '2026-09');
        $this->assertSame(0, $sm->fresh()->unconsumed_carryover);

        // [관리](rank 1) 제출 → 다음 계단 = 업무관리자(rank 2)
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);
        $batch->rejectBy($manager, '다시');

        $this->assertSame(0, CarryoverClearance::where('payout_batch_id', $batch->id)->count(), '반려 뒤 청산이 안 지워졌다');
        $this->assertSame(-45_000, $sm->fresh()->unconsumed_carryover, '반려 뒤 잔액이 안 돌아왔다');

        $again = SettlementPayoutBatch::submitForMonth($submitter, '2026-09');
        $this->assertSame(1, $again->adjustments()->where('salesman_id', $sm->id)->count());
        $this->assertSame(-45_000, (int) $again->adjustments()->where('salesman_id', $sm->id)->sum('amount'), '재제출이 두 번 가져갔다');
        $this->assertSame(0, $sm->fresh()->unconsumed_carryover);
    }

    /** 🚫 새 정산은 더 이상 이월을 흡수하지 않는다 — 흡수 + 배치 줄이면 두 번 지급이다. */
    public function test_a_new_settlement_no_longer_absorbs_the_carryover(): void
    {
        $sm = $this->salesman();
        $this->closedCarry($sm, -45_000);

        $s = $this->confirmedThisMonth($sm);

        $this->assertNull($s->fresh()->carryover_in_krw, '새 정산이 이월을 흡수했다(2026-10-06 폐기)');
        $this->assertSame(-45_000, $sm->fresh()->unconsumed_carryover);
    }

    /** 제출 모달 미리보기가 제출과 같은 줄을 보여주고, 합계에도 들어간다. 지급 제외 담당자는 건너뛴다. */
    public function test_the_submit_modal_previews_the_same_lines(): void
    {
        $sm = $this->salesman();
        $this->closedCarry($sm, -45_000);
        $this->confirmedThisMonth($sm);
        $excluded = $this->salesman('자매회사');
        $excluded->update(['payout_excluded' => true]);
        $this->closedCarry($excluded, -999);

        $this->actingAs($this->submitter());
        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-09')->call('openSubmitModal');
        $pv = $c->get('submitPreview');
        $this->assertSame([$sm->id], array_column($pv['carryovers'], 'salesman_id'), '지급 제외 담당자가 섞였거나 줄이 없다');
        $this->assertSame(-45_000, $pv['carryovers'][0]['amount']);
        $this->assertSame(-45_000, $c->get('submitTotals')['carry_sum']);
        $c->assertSee('data-carryover-lines', false)->assertSee(__('settlement.batch.modal_carryover'));

        $c->call('submitPayoutBatch');
        $batch = SettlementPayoutBatch::where('month', '2026-09')->firstOrFail();
        $this->assertSame(-45_000, (int) $batch->adjustments()->where('salesman_id', $sm->id)->sum('amount'));
        $this->assertSame(0, $batch->adjustments()->where('salesman_id', $excluded->id)->count());
    }
}
