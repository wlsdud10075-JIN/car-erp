<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 사내직원 차등 정산 tier (2026-06-22 jin 확정) + Setting override 검증.
 *   매입금액 ≥ 1억 → 총마진×25% / 총마진<0 → 0 / 총마진<100만 → 10만 / 그 외 → 20만
 */
class SettlementEmployeeTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Settlement::flushParamMemo();
    }

    public function test_tier_branches_with_defaults(): void
    {
        $purchase = 50_000_000; // 1억 미만

        // 총마진 < 0 → 0
        $this->assertSame(0, Settlement::employeePerUnitTier(-500_000, $purchase));
        // 총마진 < 100만 → 10만
        $this->assertSame(100_000, Settlement::employeePerUnitTier(0, $purchase));
        $this->assertSame(100_000, Settlement::employeePerUnitTier(999_999, $purchase));
        // 총마진 = 100만 (경계, <아님) → 20만
        $this->assertSame(200_000, Settlement::employeePerUnitTier(1_000_000, $purchase));
        // 총마진 ≥ 100만 → 20만 (상한 없음)
        $this->assertSame(200_000, Settlement::employeePerUnitTier(50_000_000, $purchase));
    }

    public function test_high_purchase_uses_rate_even_for_negative_margin(): void
    {
        // 매입금액 ≥ 1억 → 총마진 × 25% (트리거 최우선)
        $this->assertSame(2_500_000, Settlement::employeePerUnitTier(10_000_000, 100_000_000));
        $this->assertSame(2_500_000, Settlement::employeePerUnitTier(10_000_000, 250_000_000));
        // 1억 이상 + 음수 마진 → 음수 (엑셀 공식 그대로)
        $this->assertSame(-250_000, Settlement::employeePerUnitTier(-1_000_000, 120_000_000));
    }

    public function test_setting_override_reflected(): void
    {
        // 고율 25 → 30, 상한 20만 → 30만, 1억 트리거 → 2억 으로 변경
        Setting::create(['key' => 'settlement_employee_high_rate', 'value' => '30', 'type' => 'integer']);
        Setting::create(['key' => 'settlement_employee_amount_high', 'value' => '300000', 'type' => 'integer']);
        Setting::create(['key' => 'settlement_employee_high_threshold', 'value' => '200000000', 'type' => 'integer']);
        Settlement::flushParamMemo();

        // 매입 1억 < 2억 트리거 → 더이상 고율 아님 → 총마진 5백만 ≥ 100만 → 상한(30만)
        $this->assertSame(300_000, Settlement::employeePerUnitTier(5_000_000, 100_000_000));
        // 매입 2억 ≥ 2억 → 고율 30%
        $this->assertSame(3_000_000, Settlement::employeePerUnitTier(10_000_000, 200_000_000));
    }

    public function test_freelance_ratio_default_from_setting(): void
    {
        $this->assertSame(50, Settlement::param('settlement_freelance_ratio'));
        Setting::create(['key' => 'settlement_freelance_ratio', 'value' => '40', 'type' => 'integer']);
        Settlement::flushParamMemo();
        $this->assertSame(40, Settlement::param('settlement_freelance_ratio'));
    }

    public function test_super_saves_params_via_settings_page(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);

        $params = [
            'settlement_freelance_ratio' => 45,
            'settlement_employee_amount_high' => 250_000,
            'settlement_employee_high_rate' => 30,
        ] + Settlement::PARAM_DEFAULTS;

        Volt::actingAs($super)->test('admin.settings')
            ->set('settlementParams', $params)
            ->call('saveSettlementParams');

        $this->assertDatabaseHas('settings', ['key' => 'settlement_freelance_ratio', 'value' => '45']);
        $this->assertDatabaseHas('settings', ['key' => 'settlement_employee_amount_high', 'value' => '250000']);

        Settlement::flushParamMemo();
        $this->assertSame(45, Settlement::param('settlement_freelance_ratio'));
        $this->assertSame(250_000, Settlement::employeePerUnitTier(5_000_000, 10_000_000)); // 총마진≥100만 → 상한 25만
    }

    /**
     * paid 전환 시 사내직원 per_unit(tier) 동결 → 2차 비용보정으로 total_margin 변해도
     * 정산액 불변 → closed actual_payout = paid snapshot → carryover_out = 0 (SKILLS §5-5 불변식).
     * (advisor 지적: tier 가 margin 의존이라 미동결 시 1억+ 직원 carry_out 비0 발생)
     */
    public function test_paid_freezes_employee_per_unit_against_margin_change(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'TIER-FREEZE-1',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'purchase_price' => 120_000_000, 'selling_fee' => 0,   // 매입 1억 이상 → 비율제(×25%)
            'sale_price' => 200_000_000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01', 'dhl_request' => false,
        ]);

        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => null,
            'settlement_status' => 'confirmed',
        ]);

        // total_margin = ((200M-120M) + 120M×0.09) × 0.9 = (80M+10.8M)×0.9 = 81,720,000
        // tier(1억+) = 81,720,000 × 25% = 20,430,000
        $expected = 20_430_000;
        $this->assertSame(81_720_000, $s->total_margin);
        $this->assertSame($expected, $s->settlement_amount);
        $this->assertNull($s->per_unit_amount);

        // paid 전환 → 동결
        $s->update(['settlement_status' => 'paid']);
        $s->refresh();
        $this->assertSame($expected, (int) $s->per_unit_amount, 'paid 시 tier 값이 per_unit_amount 로 동결');
        $this->assertSame($expected, (int) ($s->confirmed_snapshot['actual_payout'] ?? -1));

        // 2차 비용보정: 말소비 5천만 추가 → total_margin 급감 (미동결이면 tier 9,180,000 으로 재계산됨)
        $v->update(['cost_deregistration' => 50_000_000]);
        $s->refresh();
        $this->assertSame(36_720_000, $s->total_margin, '총마진은 비용보정 반영해 변함');
        $this->assertSame($expected, $s->settlement_amount, '동결돼 정산액은 불변(재계산 안 됨)');
        $this->assertSame(
            (int) ($s->confirmed_snapshot['actual_payout'] ?? 0),
            $s->actual_payout,
            'closed actual_payout = paid snapshot → carryover_out 0'
        );
    }
}
