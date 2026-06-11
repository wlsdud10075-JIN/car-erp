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
 * 채권관리 — paid 정산 차량에 '입금(deposit)' 회수이력 추가 시 500 방지 (2026-06-11).
 *
 * 버그: deposit 방식은 ReceivableHistory::saved → syncFinalPayment 가 신규 FinalPayment 를 만드는데,
 *   정산이 paid 면 FinalPayment::creating(paid) 가드가 DomainException → RH 는 이미 insert 된 뒤라
 *   "저장은 되고 500" + 미납엔 무반영(고아 RH). 실측: 144더7415/223나5353 (운영, super·김혜진 입력).
 * 수정: paid+deposit 사전 차단(친절 메시지) + 트랜잭션 래핑(예외 시 RH 롤백). cash/offset/other 는 정상.
 */
class ReceivablePaidDepositGuardTest extends TestCase
{
    use RefreshDatabase;

    private function paidVehicle(): Vehicle
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
        ]);

        return $v;
    }

    public function test_deposit_on_paid_vehicle_is_blocked_without_500_or_orphan_rh(): void
    {
        $v = $this->paidVehicle();          // auth 미존재 시점 → paid 전환 가드 우회
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
