<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회사이익 대시보드 (2026-07-06 재피벗 ④) — type별 환차귀속 검증.
 *
 * 회사몫 = total_margin − actual_payout + (exchange_difference_krw ?? 0)
 *   - 프리랜서(ratio): 환차가 actual_payout 에 포함 → 상쇄 → 환차에 무관 (회사 무영향).
 *   - 사내직원(per_unit): actual_payout=건당(환차 미포함) → 회사가 환차 흡수.
 */
class AdminDashboardCompanyProfitTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    /** KRW → total_margin 결정적 2,520,000. (13M 판매 / 10M 매입 / 100k 비용) */
    private function krwVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'CP-'.++$this->counter,
            'sales_channel' => 'heyman', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 13_000_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 10_000_000, 'selling_fee' => 1_000_000,
            'cost_deregistration' => 100_000,
        ]);
    }

    private function companyProfit(User $admin): array
    {
        $this->actingAs($admin);

        return Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->instance()->companyProfit;
    }

    public function test_freelancer_fx_is_company_neutral(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => 'CP프리', 'type' => 'freelance', 'is_active' => true]);

        $v = $this->krwVehicle();
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => '2026-05-20',
            'secondary_status' => 'closed', 'exchange_difference_krw' => 30_000,
            'confirmed_at' => '2026-05-10',
        ]);

        $cp = $this->companyProfit($admin);

        // total_margin 2,520,000 / 정산금 1,260,000 − 서류비 50,000 = base 1,210,000 / +환차 30,000 = payout 1,240,000
        // 회사몫 = 2,520,000 − 1,240,000 + 30,000 = 1,310,000 = total_margin − base (환차 상쇄, 회사 무영향)
        $this->assertSame(1_310_000, $cp['company_net']);
        $this->assertSame(0, $cp['fx_absorbed'], '프리랜서 환차는 회사흡수 아님');
        $this->assertSame(1_310_000, $cp['ranking'][0]['contribution']);
    }

    public function test_employee_fx_absorbed_by_company(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => 'CP사내', 'type' => 'employee', 'is_active' => true]);

        $v = $this->krwVehicle();
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'paid', 'paid_at' => '2026-05-20',
            'secondary_status' => 'closed', 'exchange_difference_krw' => 30_000,
            'confirmed_at' => '2026-05-10',
        ]);

        $cp = $this->companyProfit($admin);

        // payout = 건당 100,000 (환차 미포함). 회사몫 = 2,520,000 − 100,000 + 30,000 = 2,450,000
        $this->assertSame(2_450_000, $cp['company_net']);
        $this->assertSame(30_000, $cp['fx_absorbed'], '사내직원 환차는 회사가 흡수');
    }
}
