<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 회의확장씬 #6+7 보강 (2026-05-23) — 환차 자동 정산금 반영 검증.
 *
 * 사용자 결정:
 *   - 대상: 프리랜서(ratio)만. 사내직원(per_unit)은 미반영 (회사 부담).
 *   - 계산: 1:1 직접 가산 (actual_payout + exchange_difference_krw).
 *   - 표시: 기존 actual_payout 컬럼에 반영.
 */
class SettlementExchangeDiffPayoutTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeManager(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function makeSettlement(string $type, array $extra = []): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'EXD-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 10000, 'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
            'purchase_price' => 5_000_000,
            'selling_fee' => 100_000,
        ]);

        return Settlement::create(array_merge([
            'vehicle_id' => $v->id,
            'settlement_type' => $type,
            'settlement_ratio' => $type === 'ratio' ? 50 : null,
            'per_unit_amount' => $type === 'per_unit' ? 100_000 : null,
            'settlement_status' => 'paid',
            'confirmed_at' => now(), 'paid_at' => now(),
        ], $extra));
    }

    public function test_ratio_closed_with_positive_diff_adds_to_payout(): void
    {
        $s = $this->makeSettlement('ratio', [
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 12000,
        ]);
        $basePayout = $s->settlement_amount - $s->document_fee;

        $this->assertSame($basePayout + 12000, $s->actual_payout);
    }

    public function test_ratio_closed_with_negative_diff_subtracts_from_payout(): void
    {
        $s = $this->makeSettlement('ratio', [
            'secondary_status' => 'closed',
            'exchange_difference_krw' => -8000,
        ]);
        $basePayout = $s->settlement_amount - $s->document_fee;

        $this->assertSame($basePayout - 8000, $s->actual_payout);
    }

    public function test_per_unit_closed_with_diff_does_not_change_payout(): void
    {
        $s = $this->makeSettlement('per_unit', [
            'secondary_status' => 'closed',
            'exchange_difference_krw' => 12000,
        ]);
        // per_unit: actual_payout = per_unit_amount - 0(document_fee) - 0(other_deduction)
        $basePayout = $s->settlement_amount;

        $this->assertSame($basePayout, $s->actual_payout);   // 환차 미반영
    }

    public function test_ratio_pending_without_stored_diff_does_not_change_payout(): void
    {
        $s = $this->makeSettlement('ratio', [
            'secondary_status' => 'pending',
            'exchange_difference_krw' => null,
        ]);
        $basePayout = $s->settlement_amount - $s->document_fee;

        $this->assertSame($basePayout, $s->actual_payout);
    }
}
