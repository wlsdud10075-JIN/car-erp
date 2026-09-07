<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashAllocation;
use App\Models\FinalPayment;
use App\Models\SavingsStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 현금 원장 토글을 켠 뒤에도, **켜기 전에 확정된** 잔금의 과입금을 「적립금으로 전환」할 수
 * 있어야 한다 (jin 2026-09-07 제보로 발견한 버그의 회귀 가드).
 *
 * 토글 전 확정분은 `buyer_cash_allocations` 행이 없다. 그런데 전환은 그 잔금을 **감액**하고,
 * `FinalPayment::updating` 이 금액 변경을 보고 `assertAvailable` 을 부른다.
 * 거기서 필요액을 «새 금액 − 이미 배분된 금액» 으로 잡는데 배분이 0 이라 **감액인데도 전액**을
 * 요구하게 되고, 그 바이어의 현금은 0 이라 차단된다.
 */
class OverpayConvertWithCashLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** 토글을 켜기 «전에» 과입금 확정된 외화 차량 — 현금 원장에 대응 입금이 없다. */
    private function legacyOverpaidVehicle(): array
    {
        $buyer = Buyer::create(['name' => 'LEGACY BUYER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => 'LGC-1', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1400, 'dhl_request' => false,
            'buyer_id' => $buyer->id, 'sale_date' => '2026-05-01',
            'sale_price' => 10000, 'transport_fee' => 0,
        ]);
        FinalPayment::create([          // 13,000 확정 → 미수 −3,000
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 13000,
            'payment_date' => '2026-05-02', 'confirmed_at' => now(),
        ]);

        return [$v->fresh(), $buyer];
    }

    private function enableCashLedger(): void
    {
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    public function test_convert_works_while_cash_ledger_is_off(): void
    {
        // 대조군 — 토글이 꺼져 있으면 종전대로 된다(게이트 자체가 안 탄다).
        [$v, $buyer] = $this->legacyOverpaidVehicle();
        $this->actingAs(User::factory()->create(['role' => '재무']));

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToSavings');

        $this->assertSame(0, (int) $v->fresh()->sale_unpaid_amount);
        $this->assertSame(3000.0, (float) SavingsStatus::where('buyer_id', $buyer->id)->sum('savings'));
    }

    public function test_convert_still_works_after_turning_the_cash_ledger_on(): void
    {
        // 잔금이 «줄어드는» 방향이라 현금을 한 푼도 더 쓰지 않는다 — 토글과 무관하게 통과해야 한다.
        //   고치기 전엔 두 군데서 막혔다: ①assertAvailable 이 전액을 요구(배분 0) ②allocate 가
        //   전액을 배분하려다 race 가드. 둘 다 「원장 밖에서 확정된 잔금」이라는 같은 뿌리다.
        [$v, $buyer] = $this->legacyOverpaidVehicle();
        $this->assertSame(0, BuyerCashAllocation::count(), '토글 전 확정분은 배분 행이 없다');
        $this->enableCashLedger();
        $this->actingAs(User::factory()->create(['role' => '재무']));

        Volt::test('erp.receivables.index')
            ->set('selectedVehicleId', $v->id)
            ->call('convertOverpayToSavings');

        $this->assertSame(0, (int) $v->fresh()->sale_unpaid_amount, '과입금이 0 이 되어야 한다');
        $this->assertSame(3000.0, (float) SavingsStatus::where('buyer_id', $buyer->id)->sum('savings'),
            '초과분이 적립금으로 옮겨져야 한다');
    }
}
