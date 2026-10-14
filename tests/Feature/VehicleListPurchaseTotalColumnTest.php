<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량관리 「매입총액」 컬럼 (jin 2026-10-14 «매입가+매도비=매입가총액 으로 컬럼 하나»).
 *
 * 단일 출처 = `Vehicle::purchase_total_amount`(= 매입가 + 매도비, SKILLS §13 총매입액).
 * 매입 미지급 분모도 같은 accessor 를 쓴다 — 둘이 갈리면 「미지급은 0 인데 총액이 다른」 행이 생긴다.
 */
class VehicleListPurchaseTotalColumnTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_purchase_total_is_price_plus_selling_fee(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '12가3456', 'sales_channel' => 'export',
            'purchase_price' => 10_000_000, 'selling_fee' => 330_000, 'purchase_date' => '2026-10-01',
        ]);

        $this->assertSame(10_330_000, $v->purchase_total_amount);
        $this->assertSame($v->purchase_total_amount - $v->purchase_paid_amount, $v->purchase_unpaid_amount);
    }

    public function test_list_renders_the_column_when_toggled_on(): void
    {
        Vehicle::create([
            'vehicle_number' => '12가3456', 'sales_channel' => 'export',
            'purchase_price' => 10_000_000, 'selling_fee' => 330_000, 'purchase_date' => now()->toDateString(),
        ]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('syncViewport', false)
            ->call('syncVisibleColumns', ['purchase_total'])
            ->assertSeeHtml("x-show=\"visible['purchase_total']\"")
            ->assertSee('₩10,330,000');
    }

    public function test_column_is_offered_in_the_toggle_dropdown(): void
    {
        $this->actingAs($this->admin());
        $keys = array_column(Volt::test('erp.vehicles.index')->instance()->columnToggleOptions(), 'key');

        $this->assertContains('purchase_total', $keys);
    }
}
