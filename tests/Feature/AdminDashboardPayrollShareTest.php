<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkMonthlyClosing;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📊 월정산 v3 8일차 (2026-10-09) — 관리자 대시보드 재무탭: 회사 순이익에서 급여를 빼고, 인원별 기여에 지분율·받아 간 돈·정산관리 앵커.
 *    회사이익 3곳(대시보드 · 월결산 알림톡 · 승인 화면)이 같은 뜻 — 급여·인센티브까지 뺀 값.
 */
class AdminDashboardPayrollShareTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function paidSettlement(Salesman $sm, int $usd, string $paidAt = '2026-10-15'): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'DS'.++$this->n, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-09-01',
            'sale_price' => $usd, 'sale_date' => '2026-09-02',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => $usd, 'exchange_rate' => 1400, 'payment_date' => '2026-09-10', 'confirmed_at' => now()]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => $sm->type === 'freelance' ? 'ratio' : 'per_unit',
            'settlement_ratio' => $sm->type === 'freelance' ? 50 : null, 'per_unit_amount' => $sm->type === 'freelance' ? null : 100_000,
            'settlement_status' => 'paid', 'confirmed_at' => '2026-09-20', 'paid_at' => $paidAt, 'attributed_month' => '2026-09-01',
        ]);
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $e = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $f = Salesman::create(['name' => '최딜러', 'type' => 'freelance', 'is_active' => true]);
        $i = Salesman::create(['name' => '오검차', 'type' => 'inspector', 'is_active' => true]);
        $se = $this->paidSettlement($e, 20_000);   // 총마진 (28,000,000−10,000,000+900,000)×0.9 = 17,010,000 · 지급 100,000
        $sf = $this->paidSettlement($f, 20_000);   // 지급 = 8,505,000 − 50,000 = 8,455,000
        PayrollEntry::replaceFor($e->id, '2026-09', [['label' => '기본급', 'amount' => 2_740_000]]);
        PayrollEntry::replaceFor($i->id, '2026-09', [['label' => '기본급', 'amount' => 2_450_000]]);
        $batch = SettlementPayoutBatch::create([
            'month' => '2026-09', 'submitter_id' => $admin->id, 'submitter_rank' => 2, 'current_level' => 3,
            'status' => 'approved', 'total_payout' => 8_555_000, 'settlement_count' => 2, 'submitted_at' => now(), 'decided_at' => '2026-10-15',
        ]);
        Settlement::whereIn('id', [$se->id, $sf->id])->update(['payout_batch_id' => $batch->id]);
        $batch->adjustments()->create(['salesman_id' => $e->id, 'amount' => 500_000, 'kind' => 'incentive', 'reason' => '우수', 'created_by' => $admin->id]);

        return [$admin, $e, $f, $i, $batch];
    }

    private function cp(User $admin): array
    {
        $this->actingAs($admin);

        return Volt::test('admin.dashboard')->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-31')->instance()->companyProfit();
    }

    public function test_company_net_subtracts_payroll_and_inspectors_are_common_labor(): void
    {
        [$admin, $e, $f, $i] = $this->fixture();
        $cp = $this->cp($admin);

        $margin = 17_010_000 * 2;
        $payout = 100_000 + 8_455_000 + 500_000 + 2_740_000;   // 정산 + 인센티브 + 사내직원 급여
        $this->assertSame($payout, $cp['payout_sum']);
        $this->assertSame(2_450_000, $cp['common_labor']);
        $this->assertSame($margin - $payout - 2_450_000, $cp['company_net'], '회사 순이익 = 총마진 − 실지급(급여·인센티브 포함) − 공통 인건비');

        $rows = collect($cp['ranking'])->keyBy('salesman_id');
        $this->assertFalse($rows->has($i->id), '검차직원은 기여 랭킹에 없다');
        $this->assertSame(17_010_000 - 100_000 - 500_000 - 2_740_000, $rows[$e->id]['contribution']);
        $this->assertSame(17_010_000 - 8_455_000, $rows[$f->id]['contribution']);
        $this->assertSame(2_740_000 + 100_000 + 500_000, $rows[$e->id]['payout'], '받아 간 돈 = 급여 + 정산 + 인센티브');
        $sum = $rows[$e->id]['contribution'] + $rows[$f->id]['contribution'];
        $this->assertSame(round($rows[$e->id]['contribution'] / $sum * 100, 1), $rows[$e->id]['share']);
        $this->assertStringContainsString('focus='.$e->id, $rows[$e->id]['link'], '이름 클릭 → 정산관리 앵커');
        $this->assertStringContainsString('monthFilter=2026-09', $rows[$e->id]['link']);
    }

    public function test_dashboard_renders_share_and_anchor_links(): void
    {
        [$admin, $e] = $this->fixture();
        $this->actingAs($admin);
        $html = Volt::test('admin.dashboard')->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-31')->html();
        $this->assertStringContainsString('data-contrib-row="'.$e->id.'"', $html);
        $this->assertStringContainsString('data-share', $html);
        $this->assertStringContainsString('data-common-labor', $html);
        $this->assertStringContainsString('focus='.$e->id, $html);
        $this->assertStringNotContainsString('+ 사내직원 환차', $html, '옛 공식 문구가 남아 있다');
    }

    public function test_settlement_screen_opens_the_focused_card(): void
    {
        [$admin, $e] = $this->fixture();
        $this->actingAs($admin);
        $this->get(route('erp.settlements.index', ['salesmanFilter' => $e->id, 'monthFilter' => '2026-09', 'focus' => $e->id]))
            ->assertOk()
            ->assertSee('data-summary-card="'.$e->id.'"', false)
            ->assertSee('x-data="{ open: true }"', false);
    }

    public function test_monthly_closing_alimtalk_company_profit_subtracts_payroll_and_adjustments(): void
    {
        [$admin] = $this->fixture();
        $vars = AlimtalkMonthlyClosing::buildVars('2026-09');
        $expected = (17_010_000 - 100_000) + (17_010_000 - 8_455_000) - 500_000 - 2_740_000 - 2_450_000;
        $this->assertSame(number_format($expected).'원', $vars['회사이익'], '월결산 알림톡 회사이익도 급여·인센티브를 뺀 값(문구는 그대로)');
    }

    public function test_approval_page_profit_matches_the_batch_breakdown(): void
    {
        [$admin, , , , $batch] = $this->fixture();
        $batch->update(['status' => 'pending']);
        $stats = $batch->fresh()->profitStats();
        $this->assertSame((17_010_000 - 100_000 - 500_000 - 2_740_000) + (17_010_000 - 8_455_000) - 2_450_000, $stats['company_profit_after_payroll']);
    }
}
