<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 월정산 v3 5일차 — 사람 카드가 세 화면(정산관리 담당자별 합계 미리보기 · 월정산 · 승인 링크)에 **같은 컴포넌트**로 뜬다.
 * 숫자 검증은 PersonPayoutBreakdownTest. 여기는 「그 화면에 그 카드가 그 값으로 그려지나」만 본다.
 */
class PayoutCardScreensTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $e = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true, 'per_unit_tier_enabled' => false]);
        $v = Vehicle::create([
            'vehicle_number' => 'CARD1', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $e->id, 'purchase_price' => 100_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => 100_000, 'sale_date' => '2026-10-02',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 100_000, 'exchange_rate' => 1400, 'payment_date' => '2026-10-10', 'confirmed_at' => now()]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $e->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-15', 'attributed_month' => '2026-10-01',
        ]);
        PayrollEntry::replaceFor($e->id, '2026-10', [['label' => '기본급', 'amount' => 2_740_000]]);

        return [$admin, $e, $s];
    }

    public function test_settlement_summary_card_shows_the_v3_preview(): void
    {
        [$admin, $e] = $this->fixture();
        $this->actingAs($admin);

        $html = Volt::test('erp.settlements.index')->set('monthFilter', '2026-10')->call('toggleSummaries')->html();

        $this->assertStringContainsString('data-v3-preview', $html, '담당자별 합계 카드에 v3 미리보기가 없다');
        $this->assertMatchesRegularExpression('/data-person-card="'.$e->id.'" data-person-type="employee"/', $html);
        $this->assertStringContainsString(__('payout_card.incentive_preview'), $html, '미리보기는 인센티브를 「제출 때 입력」으로 표시해야 한다');
        // 환산(판매환율) 22,000,000 · 실지급 = 급여 2,740,000 + 정산금 100,000
        $this->assertStringContainsString('data-equiv-total>22,000,000', $html);
        $this->assertStringContainsString('data-payout>₩2,840,000', $html);
        $this->assertStringContainsString(__('payout_card.excess_ratio'), $html);
    }

    public function test_card_component_renders_each_type_from_a_breakdown_array(): void
    {
        $base = ['salesman_id' => 9, 'name' => '표본', 'month' => '2026-10', 'count' => 0, 'vehicles' => [], 'payroll' => null,
            'incentive' => 0, 'adj_manual' => 0, 'adj_carry' => 0, 'deposit' => null, 'tier' => false, 'margin_rate' => null, 'unsupported' => false,
            'equiv_sale_rate' => null, 'fx_primary' => null, 'carry' => null, 'fx_secondary' => null, 'equiv_total' => null,
            'settlement_pay' => 0, 'payout' => 0, 'total_margin' => 0, 'shipping' => 0, 'company_contribution' => null,
            'margin_after_pay' => null, 'excess_ratio' => null, 'share' => null];

        $inspector = view('components.payout.person-card', ['person' => ['type' => 'inspector', 'payout' => 2_450_000] + $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        $this->assertStringContainsString(__('payout_card.inspector_in_net_v'), $inspector, '검차직원 카드는 공통 인건비 안내');
        $this->assertStringContainsString('data-payroll-missing', $inspector);

        $employee = view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 5_840_000, 'equiv_total' => 21_501_356, 'equiv_sale_rate' => 21_560_612,
            'fx_primary' => -91_431, 'carry' => 32_175, 'margin_after_pay' => 15_661_356, 'excess_ratio' => 2.7, 'company_contribution' => 40_000_000, 'share' => 58.4,
            'payroll' => 2_740_000, 'settlement_pay' => 3_100_000, 'total_margin' => 46_000_000, 'count' => 31] + $base, 'mode' => 'batch', 'open' => true, 'changed' => ['incentive']])->render();
        $this->assertStringContainsString('+2.7'.__('payout_card.times'), $employee, '초과 배율은 부호를 항상 붙인다');
        $this->assertStringContainsString('58.4%', $employee);
        $this->assertStringContainsString('data-changed', $employee, '바뀐 칸이 있으면 「변경됨」 뱃지');
        $this->assertStringContainsString('−91,431', $employee);

        $negative = view('components.payout.person-card', ['person' => ['type' => 'employee', 'payout' => 2_800_000, 'equiv_total' => 560_000, 'equiv_sale_rate' => 560_000,
            'fx_primary' => 0, 'carry' => 0, 'margin_after_pay' => -2_240_000, 'excess_ratio' => -0.8, 'company_contribution' => -1_620_000, 'share' => -2.3,
            'payroll' => 2_600_000, 'settlement_pay' => 200_000, 'total_margin' => 1_200_000, 'count' => 2] + $base, 'mode' => 'batch', 'open' => true, 'changed' => []])->render();
        $this->assertStringContainsString('−0.8'.__('payout_card.times'), $negative, '마이너스 배율은 「−」 로 보인다(색만으로 가르지 않는다)');
        $this->assertStringContainsString('−2.3%', $negative, '마이너스 지분율도 「−」');
    }
}
