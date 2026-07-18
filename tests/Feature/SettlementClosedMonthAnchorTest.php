<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * jin 2026-07-18 — "마감된 달은 동결. 늦게 완성된 건은 완성된 달(현재 열린 달)에 포함" 규칙.
 * 완납월이 이미 마감(승인된 배치 존재)이면 귀속월을 현재 열린 달로 이월.
 */
class SettlementClosedMonthAnchorTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-07-18 10:00:00'));
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function soldVehicle(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.++$this->c, 'is_active' => true]);
        $salesman = Salesman::create(['name' => 'S'.$this->c, 'is_active' => true, 'type' => 'employee']);

        return Vehicle::create([
            'vehicle_number' => 'CM'.$this->c, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 10_000_000,
            'sale_date' => '2026-06-01', 'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ]);
    }

    private function closeMonth(string $ym): void
    {
        $u = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        SettlementPayoutBatch::create([
            'month' => $ym, 'submitter_id' => $u->id, 'submitter_rank' => 1,
            'current_level' => 2, 'status' => SettlementPayoutBatch::STATUS_APPROVED,
            'total_payout' => 100_000, 'settlement_count' => 1,
            'submitted_at' => now(), 'decided_at' => now(),
        ]);
    }

    public function test_open_month_anchors_to_full_payment_month(): void
    {
        // 6월 미마감 → 완납월(6월) 그대로.
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();
        $v->finalPayments()->create([
            'amount' => 10_000_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);

        $this->assertSame('2026-06-01', Settlement::first()->attributed_month->format('Y-m-d'));
    }

    public function test_closed_month_rolls_forward_to_current_open_month(): void
    {
        // 6월 마감(승인 배치) 후 6월자 잔금이 뒤늦게 완납 → 귀속월은 현재 열린 달(7월)로 이월.
        $this->closeMonth('2026-06');
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();
        $v->finalPayments()->create([
            'amount' => 10_000_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);

        $s = Settlement::first();
        $this->assertNotNull($s);
        $this->assertSame('2026-07-01', $s->attributed_month->format('Y-m-d'), '6월 마감 → 7월로 이월');
    }

    public function test_rolls_past_multiple_closed_months(): void
    {
        // 6월·7월 둘 다 마감 → 완납월(6월) 이월 시 7월도 건너뛰고 8월로.
        $this->closeMonth('2026-06');
        $this->closeMonth('2026-07');
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();
        $v->finalPayments()->create([
            'amount' => 10_000_000, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);

        $this->assertSame('2026-08-01', Settlement::first()->attributed_month->format('Y-m-d'));
    }

    public function test_reanchor_command_moves_unpaid_out_of_closed_month(): void
    {
        $this->closeMonth('2026-06');
        $salesman = Salesman::create(['name' => 'RM', 'is_active' => true, 'type' => 'employee']);
        $v = Vehicle::create(['vehicle_number' => 'RC1', 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);
        // 마감된 6월로 귀속된 미지급(pending·미배치) 정산 — 이월 대상.
        $late = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $salesman->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'pending', 'attributed_month' => '2026-06-01',
        ]);

        $this->artisan('settlements:reanchor-closed-month --apply')->assertSuccessful();

        $this->assertSame('2026-07-01', $late->fresh()->attributed_month->format('Y-m-d'));
    }

    public function test_reanchor_command_leaves_paid_and_batched_untouched(): void
    {
        $this->closeMonth('2026-06');
        $batch = SettlementPayoutBatch::where('month', '2026-06')->first();
        $salesman = Salesman::create(['name' => 'RM2', 'is_active' => true, 'type' => 'employee']);
        $v = Vehicle::create(['vehicle_number' => 'RC2', 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);
        // paid + 배치 소속 6월 정산 — 마감 동결, 절대 이동 금지.
        $paid = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $salesman->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'paid', 'paid_at' => now(), 'secondary_status' => 'pending',
            'payout_batch_id' => $batch->id, 'attributed_month' => '2026-06-01',
        ]);

        $this->artisan('settlements:reanchor-closed-month --apply')->assertSuccessful();

        $this->assertSame('2026-06-01', $paid->fresh()->attributed_month->format('Y-m-d'), 'paid·배치 정산은 동결');
    }
}
