<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회사이익 대시보드 — type별 환차귀속 검증.
 *
 * 회사몫 = total_margin − actual_payout
 *
 * 🔀 2026-08-06 (jin) 개편 — `+ exchange_difference_krw` 항 제거.
 *   그 항은 구 모델에서 actual_payout 에 1:1 로 더해지던 환차를 상쇄하려고 있던 것이다.
 *   이제 환차는 **총마진의 환율(실효 입금환율)** 로 들어오고 payout 엔 안 더해지므로,
 *   그대로 두면 회사이익이 환차만큼 부풀려진다.
 *
 * 환차 귀속이 바뀌었다 — 구: 프리랜서 환차는 회사 무영향(전액 담당자).
 *   신: 환차가 마진공식(×0.9×비율)을 거치므로 **회사도 잔여분을 가져간다.**
 *   사내직원은 정산액이 건당 고정이라 종전대로 환차가 통째로 회사에 남는다.
 *
 * 테스트는 KRW 차량에 환차를 가짜로 박는 대신 **실제 외화 입금환율 차이**를 만든다.
 * 신 모델에서는 환차가 총마진을 통해 흐르므로, 가짜 환차로는 아무것도 검증되지 않는다.
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

    /**
     * USD 차량 — 판매환율 1,000 / 입금환율은 인자로.
     *
     * 입금 @1,000 → 판매금원화 10,000,000 → 총마진 3,915,000
     * 입금 @1,100 → 판매금원화 11,000,000 → 총마진 4,815,000  (실현 환차 1,000,000)
     */
    private function usdVehicle(float $receiptRate): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'CP-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1000,
            'dhl_request' => false,
            'sale_price' => 10_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000, 'selling_fee' => 1_000_000,
            'cost_deregistration' => 100_000,
        ]);

        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance',
            'amount' => 10_000, 'exchange_rate' => $receiptRate,
            'payment_date' => '2026-05-10', 'confirmed_at' => now(),
        ]);

        return $v->refresh();
    }

    private function companyProfit(User $admin): array
    {
        $this->actingAs($admin);

        return Volt::test('admin.dashboard')
            ->set('dateFrom', '2026-05-01')
            ->set('dateTo', '2026-05-31')
            ->instance()->companyProfit;
    }

    private function settle(Vehicle $v, Salesman $sm, string $type): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => $type,
            'settlement_ratio' => $type === 'ratio' ? 50 : null,
            'per_unit_amount' => $type === 'per_unit' ? 100_000 : null,
            'settlement_status' => 'paid', 'paid_at' => '2026-05-20',
            'secondary_status' => 'closed', 'exchange_difference_krw' => 1_000_000,
            'confirmed_at' => '2026-05-10',
        ]);
    }

    /** 프리랜서 — 환차가 마진공식을 거쳐 절반만 담당자에게. 나머지는 회사 몫. */
    public function test_freelancer_shares_fx_with_the_company(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => 'CP프리', 'type' => 'freelance', 'is_active' => true]);

        $s = $this->settle($this->usdVehicle(1100), $sm, 'ratio');

        // 총마진 4,815,000 / 정산액 2,407,500 − 서류비 50,000 = 실지급 2,357,500
        $this->assertSame(4_815_000, $s->total_margin);
        $this->assertSame(2_357_500, $s->actual_payout, '환차가 payout 에 재가산되면 안 된다');

        $cp = $this->companyProfit($admin);

        // 회사몫 = 4,815,000 − 2,357,500. 저장된 환차 1,000,000 을 더하면 안 된다.
        $this->assertSame(2_457_500, $cp['company_net']);
        $this->assertSame(0, $cp['fx_absorbed'], '프리랜서는 사내직원 흡수분에 안 잡힌다');
    }

    /**
     * 같은 차를 판매환율(=환차 0)로 정산했을 때와 비교 — 실현 환차 1,000,000 의 행방.
     *
     *   총마진에 들어오는 몫 = 1,000,000 × 0.9 = 900,000   (× 0.9 = 부가세 10% 차감)
     *     → 담당자 450,000 (×50%) / 회사 450,000
     *   총마진 밖에 남는 몫 = 100,000                       (부가세 차감분)
     *
     * ⚠️ 회사가 실제로 쥔 현금은 550,000 이지만 **company_net 에는 450,000 만 잡힌다** —
     *    company_net 은 총마진 기준 지표라 부가세 차감분은 애초에 그 바깥이다.
     *    운임비가 있는 차라면 운임비 환차도 같은 이유로 총마진 밖에 남는다.
     */
    public function test_fx_split_between_salesman_and_company(): void
    {
        $sm = Salesman::create(['name' => 'CP프리2', 'type' => 'freelance', 'is_active' => true]);

        $noFx = $this->settle($this->usdVehicle(1000), $sm, 'ratio');
        $withFx = $this->settle($this->usdVehicle(1100), $sm, 'ratio');

        $this->assertSame(1_907_500, $noFx->actual_payout);
        $this->assertSame(2_357_500, $withFx->actual_payout);

        $marginGain = $withFx->total_margin - $noFx->total_margin;
        $toSalesman = $withFx->actual_payout - $noFx->actual_payout;
        $toCompany = ($withFx->total_margin - $withFx->actual_payout)
            - ($noFx->total_margin - $noFx->actual_payout);

        $this->assertSame(900_000, $marginGain, '실현 환차 1,000,000 × 0.9 만 총마진에 들어온다');
        $this->assertSame(450_000, $toSalesman, '환차 × 0.9 × 50%');
        $this->assertSame(450_000, $toCompany);
        $this->assertSame($marginGain, $toSalesman + $toCompany, '총마진 증가분이 둘로 정확히 갈려야 한다');
    }

    /** 사내직원 — 정산액이 건당 고정이라 환차가 통째로 회사에 남는다. */
    public function test_employee_fx_absorbed_by_company(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => 'CP사내', 'type' => 'employee', 'is_active' => true]);

        $s = $this->settle($this->usdVehicle(1100), $sm, 'per_unit');

        $this->assertSame(100_000, $s->actual_payout);

        $cp = $this->companyProfit($admin);

        // 회사몫 = 4,815,000 − 100,000 (환차는 총마진에 이미 포함 — 따로 더하지 않는다)
        $this->assertSame(4_715_000, $cp['company_net']);
        $this->assertSame(1_000_000, $cp['fx_absorbed'], '사내직원 실현 환차는 전액 회사 귀속(정보 표시)');
    }
}
