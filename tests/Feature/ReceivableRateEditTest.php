<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 입금 환율 편집 (Phase 3, 2026-07-13) — amount_krw 재계산 + 2차 마감 소급 차단.
 */
class ReceivableRateEditTest extends TestCase
{
    use RefreshDatabase;

    private function usdVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '77하7777', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_date' => '2026-06-01', 'sale_price' => 5000, 'purchase_date' => '2026-06-01',
        ]);
    }

    private function deposit(Vehicle $v, User $u): ReceivableHistory
    {
        return ReceivableHistory::create([
            'vehicle_id' => $v->id, 'collected_at' => '2026-07-10', 'collector_id' => $u->id,
            'method' => 'deposit', 'amount' => 1000, 'exchange_rate' => 1300,
        ]);
    }

    public function test_editing_deposit_rate_recomputes_final_payment_amount_krw(): void
    {
        $u = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($u);
        $v = $this->usdVehicle();
        $rh = $this->deposit($v, $u);

        $fpId = $rh->refresh()->final_payment_id;
        $this->assertNotNull($fpId);
        $this->assertEqualsWithDelta(1300000.0, (float) FinalPayment::find($fpId)->amount_krw, 0.01);   // 1000×1300

        Volt::test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->call('editHistory', $rh->id)
            ->set('hExchangeRate', '1400')
            ->call('saveHistory')
            ->assertHasNoErrors();

        $fp = FinalPayment::find($fpId);
        $this->assertEqualsWithDelta(1400.0, (float) $fp->exchange_rate, 0.001);
        $this->assertEqualsWithDelta(1400000.0, (float) $fp->amount_krw, 0.01);   // 1000×1400 재계산
    }

    public function test_rate_edit_blocked_when_secondary_settlement_closed(): void
    {
        $u = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($u);
        $v = $this->usdVehicle();
        $rh = $this->deposit($v, $u);

        // 2차 마감 정산 존재 → 환율 수정 차단. closed-guard 격리 검증 위해 settlement_status='confirmed'
        //   (paid 면 기존 paid-가드가 먼저 hMethod 로 막음 — 실제 closed 는 항상 paid 라 그 경로가 선점).
        DB::table('settlements')->insert([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio',
            'settlement_status' => 'confirmed', 'secondary_status' => 'closed',
            'other_deduction' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Volt::test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->call('editHistory', $rh->id)
            ->set('hExchangeRate', '1400')
            ->call('saveHistory')
            ->assertHasErrors('hExchangeRate');

        $this->assertEqualsWithDelta(1300.0, (float) FinalPayment::find($rh->refresh()->final_payment_id)->exchange_rate, 0.001);
    }
}
