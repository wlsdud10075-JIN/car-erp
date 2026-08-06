<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 월배치 제출 확인 모달 (jin 2026-08-06) — 조정을 **제출 전에** 확정한다.
 *
 * 구: 제출 → 카톡 발송 → 그제서야 월배치 화면에서 조정.
 *     조정은 pending 동안만 가능한데 카톡은 이미 나간 뒤라 **승인자가 본 총액 ≠ 실제 지급액**.
 *     승인자가 바로 승인하면 조정 기회 자체가 사라지고, 매입취소 손실은 사람이 기억해야 했다.
 * 신: 정산관리 제출 모달에서 차감을 정하고 넘긴다 → 카톡 총액이 정확하다.
 *     최종 승인 시 차감한 손실의 cancel_loss_settled_at 자동 기록(반려되면 안 찍힘).
 */
class SettlementBatchSubmitModalTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function gwanri(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function salesman(string $name = '가태웅'): Salesman
    {
        return Salesman::create(['name' => $name, 'type' => 'freelance', 'is_active' => true]);
    }

    /** 지급 대상 정산 1건 (per_unit 10만) — 완납 KRW 차량이라 지급 게이트 통과. */
    private function payableSettlement(Salesman $sm): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'SBM'.++$this->c, 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'currency' => 'KRW', 'exchange_rate' => 1,
        ]);

        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-05-15',
        ]);
    }

    private function cancelledVehicle(Salesman $sm, int $shortfall): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'CAN'.++$this->c, 'sales_channel' => 'export',
            'salesman_id' => $sm->id,
            'cancel_status' => Vehicle::CANCEL_CLOSED,
            'cancel_shortfall_krw' => $shortfall,
            'cancelled_at' => now(),
        ]);
    }

    public function test_preview_lists_losses_and_totals_before_submitting(): void
    {
        $sm = $this->salesman();
        $this->payableSettlement($sm);
        $this->cancelledVehicle($sm, 60_000);   // 담당자 몫 30,000
        $this->actingAs($this->gwanri());

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-05')->call('openSubmitModal');

        $this->assertTrue($c->get('showSubmitModal'));
        $preview = $c->get('submitPreview');
        $this->assertSame(1, $preview['count']);
        $this->assertSame(100_000, $preview['payout_sum']);
        $this->assertSame(30_000, $preview['losses'][0]['sum']);
        $this->assertTrue($preview['losses'][0]['payable']);

        // 기본 = 전부 체크 → 최종 70,000
        $this->assertSame(70_000, $c->get('submitTotals')['final']);
    }

    public function test_submit_creates_batch_with_loss_adjustment_applied(): void
    {
        $sm = $this->salesman();
        $this->payableSettlement($sm);
        $veh = $this->cancelledVehicle($sm, 60_000);
        $this->actingAs($this->gwanri());

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-05')
            ->call('openSubmitModal')
            ->call('submitPayoutBatch');

        $batch = SettlementPayoutBatch::firstOrFail();
        $this->assertSame(1, $batch->adjustments()->count());

        $adj = $batch->adjustments()->first();
        $this->assertSame(-30_000, (int) $adj->amount);
        $this->assertSame([$veh->id], $adj->cancel_vehicle_ids, '어느 차량을 덮는지 박혀 있어야 승인 시 도장이 찍힌다');

        // 🚨 카톡이 이 총액으로 나간다 — 조정이 반영돼 있어야 승인자가 본 숫자와 실제가 같다.
        $this->assertSame(70_000, (int) $batch->fresh()->total_payout);
    }

    /** 체크를 풀면 차감하지 않는다 — "이번 달은 빼지 말자"는 재량. */
    public function test_unchecking_a_loss_skips_the_deduction(): void
    {
        $sm = $this->salesman();
        $this->payableSettlement($sm);
        $this->cancelledVehicle($sm, 60_000);
        $this->actingAs($this->gwanri());

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-05')
            ->call('openSubmitModal')
            ->set('submitLossChecked', [])
            ->call('submitPayoutBatch');

        $batch = SettlementPayoutBatch::firstOrFail();
        $this->assertSame(0, $batch->adjustments()->count());
        $this->assertSame(100_000, (int) $batch->total_payout);
    }

    /** 그 달 지급이 없는 담당자의 손실은 차감 대상이 아니다 — 뺄 곳이 없다. */
    public function test_loss_without_payout_this_month_is_not_deductible(): void
    {
        $withPay = $this->salesman('지급있음');
        $noPay = $this->salesman('지급없음');
        $this->payableSettlement($withPay);
        $this->cancelledVehicle($noPay, 60_000);
        $this->actingAs($this->gwanri());

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-05')->call('openSubmitModal');

        $loss = collect($c->get('submitPreview')['losses'])->firstWhere('salesman_id', $noPay->id);
        $this->assertFalse($loss['payable']);
        $this->assertSame([], $c->get('submitLossChecked'), '차감 불가건이 기본 체크되면 안 된다');

        $c->call('submitPayoutBatch');
        $this->assertSame(0, SettlementPayoutBatch::firstOrFail()->adjustments()->count());
    }

    public function test_manual_adjustment_can_be_added_in_the_modal(): void
    {
        $sm = $this->salesman();
        $this->payableSettlement($sm);
        $this->actingAs($this->gwanri());

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-05')
            ->call('openSubmitModal')
            ->set('newAdjSalesmanId', (string) $sm->id)
            ->set('newAdjAmount', '-20,000')       // 콤마·음수 파싱
            ->set('newAdjReason', '6월 과지급 환수')
            ->call('addSubmitAdjustment')
            ->call('submitPayoutBatch');

        $batch = SettlementPayoutBatch::firstOrFail();
        $this->assertSame(-20_000, (int) $batch->adjustments()->first()->amount);
        $this->assertSame(80_000, (int) $batch->total_payout);
    }

    /** 🚨 최종 승인 시에만 도장이 찍힌다. 반려면 안 찍혀야 다음 배치에서 다시 청구된다. */
    public function test_cancel_loss_is_stamped_on_final_approval_only(): void
    {
        $sm = $this->salesman();
        $this->payableSettlement($sm);
        $veh = $this->cancelledVehicle($sm, 60_000);
        $gwanri = $this->gwanri();
        $this->actingAs($gwanri);

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-05')
            ->call('openSubmitModal')
            ->call('submitPayoutBatch');

        $batch = SettlementPayoutBatch::firstOrFail();
        $this->assertNull($veh->fresh()->cancel_loss_settled_at, '제출만으로 찍히면 안 된다');

        // 중간 계단(업무관리자) 승인 — 아직 최종 아님
        $batch->approveBy(User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]));
        $this->assertNull($veh->fresh()->cancel_loss_settled_at, '중간 승인에 찍히면 안 된다');

        // 대표 최종 승인
        $batch->fresh()->approveBy(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $this->assertNotNull($veh->fresh()->cancel_loss_settled_at, '최종 승인 시 반영 도장이 찍혀야 한다');
    }

    public function test_rejected_batch_leaves_the_loss_unsettled(): void
    {
        $sm = $this->salesman();
        $this->payableSettlement($sm);
        $veh = $this->cancelledVehicle($sm, 60_000);
        $this->actingAs($this->gwanri());

        Volt::test('erp.settlements.index')
            ->set('monthFilter', '2026-05')
            ->call('openSubmitModal')
            ->call('submitPayoutBatch');

        SettlementPayoutBatch::firstOrFail()
            ->rejectBy(User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]), '금액 확인 필요');

        $this->assertNull(
            $veh->fresh()->cancel_loss_settled_at,
            '반려됐는데 반영으로 찍히면 손실이 영영 청구되지 않는다'
        );
        // 다시 청구 대상으로 남아 있어야 한다
        $this->assertArrayHasKey($sm->id, Vehicle::unsettledCancelLossBySalesman());
    }
}
