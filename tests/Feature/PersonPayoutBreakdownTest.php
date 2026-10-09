<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payout\BatchPayoutBreakdown;
use App\Services\Payout\PersonPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🧮 월정산 v3 계산 단일 출처 — 3일차 (2026-10-09). jin 2026-10-07 예시의 구조를 숫자가 닫히는 값으로 재현한다.
 *
 * 사내직원 E: USD 100,000 × 판매환율 1,400 · 입금환율 1,410(완납) · 매입 1억 · 비용 0 · 건당 10만 · 급여 2,740,000 · 인센티브 +500,000
 *   총마진(실효) = ((141,000,000 − 100,000,000) + 9,000,000) × 0.9 = 45,000,000
 *   환산(실효)   = 45,000,000 × 50% − 서류비 50,000 = 22,450,000 · 환산(판매환율 1,400) = 22,000,000 → ① 1차 환차 450,000
 *   실지급 = 2,740,000 + 100,000 + 500,000 = 3,340,000 · 급여공제후 마진 = 19,110,000 · 초과 배율 +5.7 · 회사 기여 41,660,000
 * 프리랜서 F: USD 50,000 × 1,400(입금 동일) · 매입 5천만 · 이월 32,175 · 수기 조정 +100,000
 *   총마진 22,050,000 · 환산 10,975,000 · 환산 합계 = 10,975,000 + 32,175 + 100,000 = 11,107,175 = Σactual_payout + Σ조정(닫힘)
 * 검차 I: 급여 2,450,000 만 → 공통 인건비.
 */
class PersonPayoutBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function vehicle(Salesman $sm, int $usd, int $purchase, int $payRate, string $month = '2026-10'): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'BD'.++$this->n, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => $purchase, 'purchase_date' => $month.'-01',
            'sale_price' => $usd, 'sale_date' => $month.'-02',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => $usd, 'exchange_rate' => $payRate, 'payment_date' => $month.'-10', 'confirmed_at' => now()]);

        return $v->fresh();
    }

    private function settlement(Vehicle $v, Salesman $sm, array $attrs = []): Settlement
    {
        return Settlement::create(array_merge([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => $sm->type === 'freelance' ? 'ratio' : 'per_unit',
            'settlement_ratio' => $sm->type === 'freelance' ? 50 : null,
            'per_unit_amount' => $sm->type === 'freelance' ? null : 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-15', 'attributed_month' => '2026-10-01',
        ], $attrs));
    }

    private function fixture(): array
    {
        $e = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $f = Salesman::create(['name' => '최딜러', 'type' => 'freelance', 'is_active' => true]);
        $i = Salesman::create(['name' => '오검차', 'type' => 'inspector', 'is_active' => true]);
        PayrollEntry::replaceFor($e->id, '2026-10', [['label' => '기본급', 'amount' => 2_340_000], ['label' => '식대', 'amount' => 200_000], ['label' => '자가운전', 'amount' => 200_000]]);
        PayrollEntry::replaceFor($i->id, '2026-10', [['label' => '기본급', 'amount' => 2_200_000], ['label' => '식대', 'amount' => 250_000]]);

        $se = $this->settlement($this->vehicle($e, 100_000, 100_000_000, 1410), $e);
        $sf = $this->settlement($this->vehicle($f, 50_000, 50_000_000, 1400), $f, ['carryover_in_krw' => 32_175]);

        $submitter = User::factory()->create(['permission' => 'manager', 'role' => '관리', 'email_verified_at' => now()]);
        $batch = SettlementPayoutBatch::create([
            'month' => '2026-10', 'submitter_id' => $submitter->id, 'submitter_rank' => 2, 'current_level' => 3,
            'status' => 'pending', 'total_payout' => 0, 'settlement_count' => 2, 'submitted_at' => now(),
        ]);
        Settlement::whereIn('id', [$se->id, $sf->id])->update(['payout_batch_id' => $batch->id]);
        $batch->addAdjustment($submitter, $e->id, 500_000, '10월 실적 우수', null, SettlementPayoutAdjustment::KIND_INCENTIVE);
        $batch->addAdjustment($submitter, $f->id, 100_000, '반타작 몫');

        return [$batch->fresh(), $e, $f, $i, $se, $sf];
    }

    public function test_employee_card_closes_on_jins_structure(): void
    {
        [$batch, $e] = $this->fixture();
        $r = collect(BatchPayoutBreakdown::forBatch($batch)['people'])->firstWhere('salesman_id', $e->id);

        $this->assertSame('employee', $r['type']);
        $this->assertSame(45_000_000, $r['total_margin']);
        $this->assertSame(22_000_000, $r['equiv_sale_rate'], '당월 프리랜서 공식 정산(판매환율)');
        $this->assertSame(450_000, $r['fx_primary'], '① 1차에 녹은 환차');
        $this->assertSame(0, $r['carry']);
        $this->assertSame(22_450_000, $r['equiv_total'], '환산 합계 = 판매환율 + ① + 이월 + 수기');
        $this->assertSame(2_740_000, $r['payroll']);
        $this->assertSame(100_000, $r['settlement_pay'], '정산금 = 건당');
        $this->assertSame(500_000, $r['incentive']);
        $this->assertSame(3_340_000, $r['payout'], '실지급 = 급여 + 정산금 + 인센티브');
        $this->assertSame(19_110_000, $r['margin_after_pay'], '급여공제후 마진 = 환산 합계 − 실지급');
        $this->assertSame(5.7, $r['excess_ratio'], '초과 배율 = (환산 − 실지급) ÷ 실지급');
        $this->assertSame(41_660_000, $r['company_contribution'], '회사 기여 = 총마진 − 실지급 − 발송비');
    }

    public function test_freelancer_equiv_total_equals_actual_payout_plus_adjustments(): void
    {
        [$batch, , $f, , , $sf] = $this->fixture();
        $r = collect(BatchPayoutBreakdown::forBatch($batch)['people'])->firstWhere('salesman_id', $f->id);

        $this->assertSame(10_975_000, $r['equiv_sale_rate']);
        $this->assertSame(0, $r['fx_primary'], '입금환율 = 판매환율이면 ① 은 0');
        $this->assertSame(32_175, $r['carry'], '프리랜서 이월 = 실제 carryover_in');
        $this->assertSame(100_000, $r['adj_manual']);
        $this->assertSame(11_107_175, $r['equiv_total']);
        $this->assertSame((int) $sf->fresh()->actual_payout + 100_000, $r['equiv_total'], '🔒 닫힘 항등식 — 환산 합계 = Σactual_payout + Σ조정(§8 #64)');
        $this->assertSame($r['equiv_total'], $r['payout'], '프리랜서는 환산이 곧 지급(인센티브 0)');
        $this->assertNull($r['margin_after_pay']);
        $this->assertNull($r['excess_ratio']);
        $this->assertSame(22_050_000 - 11_107_175, $r['company_contribution']);
    }

    public function test_inspector_is_payroll_only_and_totals_split_common_labor(): void
    {
        [$batch, $e, $f, $i] = $this->fixture();
        $b = BatchPayoutBreakdown::forBatch($batch);
        $r = collect($b['people'])->firstWhere('salesman_id', $i->id);

        $this->assertSame('inspector', $r['type']);
        $this->assertSame(2_450_000, $r['payout']);
        $this->assertNull($r['company_contribution']);
        $this->assertNull($r['share']);

        $t = $b['totals'];
        $this->assertSame(3_340_000 + 11_107_175 + 2_450_000, $t['transfer_total'], '송금 총액 = 급여·검차 포함');
        $this->assertSame(41_660_000 + 10_942_825, $t['contribution_sum']);
        $this->assertSame(2_450_000, $t['common_labor']);
        $this->assertSame(41_660_000 + 10_942_825 - 2_450_000, $t['company_net'], '회사 순이익 = 기여 합 − 공통 인건비');
        $this->assertSame(2, $t['vehicles']);

        $people = collect($b['people']);
        $this->assertSame(79.2, $people->firstWhere('salesman_id', $e->id)['share']);
        $this->assertSame(20.8, $people->firstWhere('salesman_id', $f->id)['share']);
        $this->assertSame([$e->id, $f->id, $i->id], $people->pluck('salesman_id')->all(), '회사 기여 큰 순, 검차는 맨 뒤');
    }

    public function test_negative_contribution_gets_a_negative_share(): void
    {
        [$batch, $e] = $this->fixture();
        // 사내직원에게 급여를 총마진보다 크게 — 회사 기여가 음수가 된다
        PayrollEntry::replaceFor($e->id, '2026-10', [['label' => '기본급', 'amount' => 50_000_000]]);   // E 기여 −5.6M, 합 +5.34M
        $b = BatchPayoutBreakdown::forBatch($batch->fresh());
        $r = collect($b['people'])->firstWhere('salesman_id', $e->id);

        $this->assertLessThan(0, $r['company_contribution']);
        $this->assertLessThan(0, $r['share'], '마이너스 지분율은 마이너스로 보인다(jin)');
        $this->assertGreaterThan(100, collect($b['people'])->whereNotNull('share')->max('share'), '음수가 있으면 나머지 합이 100% 를 넘는다');
        $this->assertLessThan(0, $r['margin_after_pay']);
        $this->assertLessThan(0, $r['excess_ratio']);
    }

    public function test_employee_hypothetical_carry_from_a_secondary_close_in_the_month(): void
    {
        $e = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $v = $this->vehicle($e, 100_000, 100_000_000, 1400, '2026-09');
        // 9월에 지급·10월에 2차 마감 — 스냅샷보다 총마진이 1,000,000 늘었다(비용이 줄었다)
        $s = $this->settlement($v, $e, [
            'settlement_status' => 'paid', 'paid_at' => '2026-09-10', 'attributed_month' => '2026-09-01',
            'secondary_status' => 'closed', 'secondary_closed_at' => '2026-10-05 10:00:00',
        ]);
        $s->forceFill(['confirmed_snapshot' => [
            'total_margin' => (int) $s->total_margin - 1_000_000,
            'sales_amount_krw' => (int) $s->sales_amount_krw,
            'cost_total' => 1_000_000 / 0.9,
        ]])->saveQuietly();

        $r = PersonPayoutBreakdown::build($e, '2026-10', collect(), collect(), 2_000_000);
        $this->assertSame(500_000, $r['carry'], '프리랜서였다면 2차 마감에서 받았을 차액 = Δ총마진 × 50%');
        $this->assertSame(0, $r['fx_secondary'], '환차분은 0(비용 변동)');
        $this->assertSame(500_000, $r['equiv_total'], '이 달 정산이 없어도 이월(가상)은 환산에 들어간다');
        $this->assertSame(2_000_000, $r['payout']);
    }

    public function test_month_preview_uses_draft_adjustments_without_a_batch(): void
    {
        $e = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $s = $this->settlement($this->vehicle($e, 100_000, 100_000_000, 1400), $e);

        $b = BatchPayoutBreakdown::forMonthPreview('2026-10', collect([$s->fresh(['vehicle', 'salesman'])]), [
            ['salesman_id' => $e->id, 'amount' => 300_000, 'kind' => 'incentive', 'reason' => '초안'],
        ]);
        $r = $b['people'][0];
        $this->assertSame(300_000, $r['incentive']);
        $this->assertNull($r['payroll'], '급여 미입력은 null 로 남는다(0 이 아니다)');
        $this->assertSame(100_000 + 300_000, $r['payout']);
    }
}
