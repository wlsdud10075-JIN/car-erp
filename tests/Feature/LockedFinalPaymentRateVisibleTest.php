<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리에서 만들어진 잔금(잠금 행)도 **입금 환율을 화면에 보여준다** (jin 2026-08-07).
 *
 * 이 행은 차량 패널에서 못 고치므로 종전엔 금액·날짜·비고만 찍었다. 그래서
 * **환율이 잘못 박혀도 눈에 띄지 않았다** — 2026-08-07 heymanerp 실사고(52머6628·63루6484)가
 * 정확히 이 사각지대였다. 외화 잔금 2건에 환율 1 이 굳어 실입금이 원화 1/1642 로 잡혔고,
 * 총마진이 −1,802만이 됐는데도 화면상 단서가 없었다.
 */
class LockedFinalPaymentRateVisibleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 채권관리 회수 → 잔금 미러 = 차량 패널에서 잠금 행으로 렌더된다. */
    private function vehicleWithLockedPayment(float $amount, ?float $rate, string $currency = 'EUR'): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'LCK'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'currency' => $currency,
            'exchange_rate' => $currency === 'KRW' ? 1 : 1642,
            'sale_price' => $amount,
            'sale_date' => '2026-07-30',
            'buyer_id' => Buyer::create(['name' => 'B'.random_int(1000, 9999), 'is_active' => true])->id,
        ]);

        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance',
            'amount' => $amount, 'exchange_rate' => $rate,
            'payment_date' => '2026-07-30', 'confirmed_at' => now(),
        ]);
        // 회수이력이 붙어 있어야 패널이 이 행을 「채권관리에서 편집」 잠금 행으로 그린다.
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'final_payment_id' => $fp->id,
            'method' => 'deposit', 'amount' => $amount, 'collected_at' => '2026-07-30',
            'note' => '판매 잔금 자동 미러링',
        ]);

        return $v;
    }

    public function test_locked_row_shows_the_recorded_rate_and_krw(): void
    {
        $v = $this->vehicleWithLockedPayment(12773, 1642);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('1,642.00')                              // 입금 환율
            ->assertSee('₩'.number_format(12773 * 1642));        // 원화 환산
    }

    /**
     * 🚨 실사고 재현 — 환율 1 이 박힌 잠금 잔금.
     *
     * 고치기 전에는 이 값이 화면 어디에도 안 나와서 발견이 늦었다. 이제는 눈에 보인다.
     */
    public function test_bogus_rate_of_one_is_now_visible(): void
    {
        $v = $this->vehicleWithLockedPayment(12773, 1);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('1.00')
            ->assertSee('₩12,773');   // 2천만원이어야 할 자리에 1만원대 — 보이면 바로 이상하다
    }

    /** 환율이 아예 없으면 빈칸이 아니라 「—」로 — "0원"과 "미입력"을 구분해야 한다. */
    public function test_missing_rate_renders_a_dash(): void
    {
        $v = $this->vehicleWithLockedPayment(12773, null);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->assertSee('—');
    }

    /** 원화 차량엔 환율·환산 칸을 만들지 않는다 — 늘 1 이라 자리만 차지한다. */
    public function test_krw_vehicle_has_no_rate_column(): void
    {
        $v = $this->vehicleWithLockedPayment(20000000, null, 'KRW');
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('20,000,000')
            ->assertDontSee(__('vehicle.panel.rate_at_payment'));   // 환율칸 자체가 안 그려진다
    }
}
