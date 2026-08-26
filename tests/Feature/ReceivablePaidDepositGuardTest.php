<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\ReceivableHistory;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 — 잠긴 차량에 '입금(deposit)' 회수이력 추가 시 500 방지 (2026-06-11).
 *
 * 버그: deposit 방식은 ReceivableHistory::saved → syncFinalPayment 가 신규 FinalPayment 를 만드는데,
 *   차량이 잠겨 있으면 FinalPayment::creating 가드가 DomainException → RH 는 이미 insert 된 뒤라
 *   "저장은 되고 500" + 미납엔 무반영(고아 RH). 실측: 144더7415/223나5353 (운영, super·김혜진 입력).
 * 수정: 사전 차단(친절 메시지) + 트랜잭션 래핑(예외 시 RH 롤백). cash/offset/other 는 정상.
 *
 * 🔀 정산 락 개편 통일 (jin 2026-08-26) — 잠금 트리거가 'paid' → 2차 정산 마감(closed).
 *   FinalPayment::creating 과 같은 트리거를 봐야 한다. 갈리면 화면은 «넣을 수 있다» 하고
 *   모델이 던져서 다시 500 + 고아 RH 가 된다(이 파일이 원래 막으려던 그 사고).
 */
class ReceivablePaidDepositGuardTest extends TestCase
{
    use RefreshDatabase;

    private function paidVehicle(?string $secondaryStatus = null): Vehicle
    {
        $buyer = Buyer::create(['name' => 'RCV BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'RCV-1', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
            'buyer_id' => $buyer->id, 'sale_date' => '2026-05-01',
            'sale_price' => 10000, 'transport_fee' => 0,   // 미납 10000 (입금 없음)
        ]);
        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'confirmed_at' => now(), 'paid_at' => now(),
            'secondary_status' => $secondaryStatus,
        ]);

        return $v;
    }

    public function test_deposit_on_closed_vehicle_is_blocked_without_500_or_orphan_rh(): void
    {
        $v = $this->paidVehicle('closed');  // auth 미존재 시점 → paid 전환 가드 우회
        $user = User::factory()->create(['role' => '재무']);   // canViewReceivables
        $this->actingAs($user);

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->set('hCollectedAt', '2026-05-11')
            ->set('hCollectorId', (string) $user->id)
            ->set('hMethod', 'deposit')
            ->set('hAmount', '10000')
            ->call('saveHistory')
            ->assertHasErrors('hMethod');   // 500 대신 친절한 검증 에러

        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->count(), '고아 RH 가 생성됨');
    }

    public function test_deposit_on_paid_but_not_closed_vehicle_succeeds(): void
    {
        // 정산 락 개편 통일 (jin 2026-08-26) — paid·2차 대기(pending) 차량은 이제 통과한다.
        //   운임비처럼 paid 후 확정되는 매출의 입금을 기록할 정상 경로다(248가4049).
        $v = $this->paidVehicle('pending');
        $user = User::factory()->create(['role' => '재무']);
        $this->actingAs($user);

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->set('hCollectedAt', '2026-05-11')
            ->set('hCollectorId', (string) $user->id)
            ->set('hMethod', 'deposit')
            ->set('hAmount', '10000')
            ->call('saveHistory')
            ->assertHasNoErrors();

        $rh = ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'deposit')->first();
        $this->assertNotNull($rh, 'deposit 회수이력이 저장되지 않음');
        $this->assertNotNull($rh->final_payment_id, '미러 잔금(FinalPayment)이 생성되지 않음 — creating 가드에 막힘');

        // ⚠️ 미러 잔금은 confirmed_at 없이 생성된다(기존 설계) → 미수는 재무확정 후에 줄어든다.
        //   이 파일이 검증하는 건 "가드에 막히지 않는다"까지. 완납→2차 마감 흐름은
        //   FreightAfterPaidSettlementDeadlockTest 가 확정 잔금으로 검증한다.
        $this->assertSame(10000, (int) $v->fresh()->sale_unpaid_amount, '미확정 미러 잔금이 미수를 줄이면 안 됨');
    }

    public function test_cash_on_paid_vehicle_succeeds_and_reduces_unpaid(): void
    {
        $v = $this->paidVehicle();          // auth 미존재 시점 → paid 전환 가드 우회
        $user = User::factory()->create(['role' => '재무']);   // canViewReceivables
        $this->actingAs($user);

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->set('hCollectedAt', '2026-05-11')
            ->set('hCollectorId', (string) $user->id)
            ->set('hMethod', 'cash')
            ->set('hAmount', '10000')
            ->call('saveHistory')
            ->assertHasNoErrors();

        $this->assertSame(1, ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'cash')->count());
        $v->refresh();
        $this->assertSame(0, (int) $v->sale_unpaid_amount, '현금 회수가 미납에서 차감 안 됨');
    }
}
