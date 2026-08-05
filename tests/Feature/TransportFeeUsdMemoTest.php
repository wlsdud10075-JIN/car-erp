<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 운임비(USD) 기록칸 (jin 2026-08-05) — 판매탭 총판매가 옆.
 *
 * 이 필드의 계약은 딱 하나다: **적히기만 하고 아무 계산에도 안 들어간다.**
 * 총판매가(sale_total_amount, SKILLS §13 미수율 분모)·미수·정산 전부 무관이어야 한다.
 * 이름이 기존 transport_fee(판매통화 운임비, 미수율 분모에 포함)와 비슷해서
 * 나중에 누가 계산에 끌어 쓸 위험이 큰 필드라 그 경계를 테스트로 못 박는다.
 */
class TransportFeeUsdMemoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function soldVehicle(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'TEST BUYER', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => '12가3456',
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1300,
            'purchase_price' => 5000000,
            'sale_date' => '2026-08-01',
            'buyer_id' => $buyer->id,
            'sale_price' => 10000,
            'transport_fee' => 500,
            'dhl_request' => false,
        ]);
    }

    public function test_saves_and_reloads_through_sale_tab(): void
    {
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('transport_fee_usd_str', '1,250')
            ->call('save');

        $this->assertSame(1250, (int) $v->fresh()->transport_fee_usd);

        // 재진입 시 폼에 콤마 포맷으로 되돌아온다.
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('transport_fee_usd_str', '1,250');
    }

    public function test_does_not_affect_sale_total_or_unpaid(): void
    {
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();

        $totalBefore = (float) $v->sale_total_amount;
        $unpaidBefore = (float) $v->sale_unpaid_amount;

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('transport_fee_usd_str', '9,999,999')
            ->call('save');

        $fresh = $v->fresh();

        $this->assertSame($totalBefore, (float) $fresh->sale_total_amount, '운임비(USD)가 총판매가에 섞였다');
        $this->assertSame($unpaidBefore, (float) $fresh->sale_unpaid_amount, '운임비(USD)가 미수금에 섞였다');
    }

    public function test_blank_stays_null(): void
    {
        $this->actingAs($this->admin());
        $v = $this->soldVehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('save');

        $this->assertNull($v->fresh()->transport_fee_usd);
    }
}
