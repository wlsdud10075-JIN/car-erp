<?php

namespace Tests\Feature;

use App\Models\DailyExchangeRate;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 잔금 날짜 → 마감환율 자동 기입 (Phase 2, 2026-07-13). 미확정·외화만, 과거=이력/오늘=실시간.
 */
class FinalPaymentRateAutofillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-13 12:00:00');
        Cache::put('exchange_rates', ['USD' => 1400.0], 3600);   // addFinalPayment 실시간 조회 네트워크 회피
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '55오5555', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'sale_date' => '2026-06-01', 'sale_price' => 5000, 'purchase_date' => '2026-06-01',
        ]);
    }

    public function test_past_date_autofills_closing_rate_for_unconfirmed(): void
    {
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-07-10', 'rate' => 1487.3, 'source' => 'history']);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $v = $this->vehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('addFinalPayment')
            ->set('finalPayments.0.payment_date', '2026-07-10')
            ->assertSet('finalPayments.0.exchange_rate', '1487.3');
    }

    public function test_weekend_date_carries_forward(): void
    {
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-07-10', 'rate' => 1487.3, 'source' => 'history']);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $v = $this->vehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('addFinalPayment')
            ->set('finalPayments.0.payment_date', '2026-07-12')   // 토요일 → 직전 영업일(07-10)
            ->assertSet('finalPayments.0.exchange_rate', '1487.3');
    }

    public function test_missing_date_keeps_manual_rate(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $v = $this->vehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('addFinalPayment')
            ->set('finalPayments.0.exchange_rate', '1350')
            ->set('finalPayments.0.payment_date', '2026-03-01')   // 데이터 이전 → 자동채움 X, 수기값 유지
            ->assertSet('finalPayments.0.exchange_rate', '1350');
    }

    public function test_confirmed_row_not_autofilled(): void
    {
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-07-10', 'rate' => 1487.3, 'source' => 'history']);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $v = $this->vehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('addFinalPayment')
            ->set('finalPayments.0.confirmed_at', '2026-07-01 10:00:00')
            ->set('finalPayments.0.exchange_rate', '1300')
            ->set('finalPayments.0.payment_date', '2026-07-10')   // 확정 → 자동채움 X
            ->assertSet('finalPayments.0.exchange_rate', '1300');
    }
}
