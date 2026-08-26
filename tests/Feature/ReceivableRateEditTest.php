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

    /**
     * 🕳️ 2026-08-26 — 금액·수금일 소급 변경도 막혀야 한다.
     *
     * 여긴 원래 «가려져» 있었다. deposit 신규 차단이 트리거 'paid' 로 넓게 걸려 있었고
     * (closed 는 실무상 항상 paid) 그게 이 편집 경로를 선점했다. 판매 잔금 락을 closed 로
     * 좁히면서 그 선점이 사라졌는데, 아래엔 **환율 가드밖에 없었다**.
     *
     * 흘러가면 ReceivableHistory::syncFinalPayment 가
     *   FinalPayment::where('id', ...)->update($payload)
     * 로 확정 잔금을 고친다 — **query-builder update 라 모델 updating 락·잠금해제 토큰·
     * AuditLog 가 전부 안 뜬다.** 조용히 통과하므로 예외도 로그도 없다.
     */
    public function test_amount_and_date_edit_blocked_when_secondary_settlement_closed(): void
    {
        $u = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($u);
        $v = $this->usdVehicle();
        $rh = $this->deposit($v, $u);
        $origAmount = (float) FinalPayment::find($rh->final_payment_id)->amount;

        DB::table('settlements')->insert([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
            'other_deduction' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Volt::test('erp.receivables.index')
            ->call('openPanel', $v->id)
            ->call('editHistory', $rh->id)
            ->set('hAmount', '9999')
            ->call('saveHistory')
            ->assertHasErrors('hAmount');

        $this->assertEqualsWithDelta(
            $origAmount,
            (float) FinalPayment::find($rh->refresh()->final_payment_id)->amount,
            0.001,
            '마감 차량의 확정 잔금 금액이 회수이력 편집으로 조용히 바뀜'
        );
    }
}
