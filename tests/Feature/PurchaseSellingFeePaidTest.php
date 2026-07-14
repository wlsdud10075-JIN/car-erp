<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * jin 2026-07-14 — 매입 요약(karaba 항목별) 매도비 paid 가 지급해도 0 으로 안 빠지던 버그.
 * 원인: panelSellingFeePaid 가 22-C-E 에서 DROP 된 selling_fee_payment 컬럼을 읽어 항상 0.
 * 매도비 지급은 이제 PBP type='selling_fee' confirmed 행으로 저장되므로 그걸 합산해야 함.
 */
class PurchaseSellingFeePaidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_panel_selling_fee_paid_reflects_confirmed_selling_fee_pbp(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        $v = Vehicle::create([
            'vehicle_number' => '22가2222', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'purchase_date' => '2026-06-01',
            'purchase_price' => 5_000_000, 'selling_fee' => 300_000,
        ]);

        // 구입금액 지급(계약금) 1,000,000 + 매도비 지급 300,000 — 둘 다 confirmed·기일도래 PBP.
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'type' => 'down', 'payment_date' => '2026-06-05', 'confirmed_at' => now(),
        ]);
        $v->purchaseBalancePayments()->create([
            'amount' => 300_000, 'type' => 'selling_fee', 'payment_date' => '2026-06-05', 'confirmed_at' => now(),
        ]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('panelSellingFeeTotal', 300_000)
            ->assertSet('panelSellingFeePaid', 300_000)          // 버그 시 0 이었음
            ->assertSet('panelPurchasePriceTotal', 5_000_000)
            ->assertSet('panelPurchasePricePaid', 1_000_000)     // 총지급 1.3M - 매도비 0.3M
            ->assertSet('panelPurchaseUnpaid', 4_000_000);       // 5.3M - 1.3M
    }

    // 미확정/미도래 매도비 PBP 는 지급으로 안 잡힘 (미지급 필터와 대칭).
    public function test_unconfirmed_selling_fee_pbp_not_counted(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        $v = Vehicle::create([
            'vehicle_number' => '33가3333', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'purchase_date' => '2026-06-01',
            'purchase_price' => 5_000_000, 'selling_fee' => 300_000,
        ]);
        // 미확정(confirmed_at 없음) 매도비 → 지급 반영 X
        $v->purchaseBalancePayments()->create([
            'amount' => 300_000, 'type' => 'selling_fee', 'payment_date' => '2026-06-05', 'confirmed_at' => null,
        ]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('panelSellingFeePaid', 0)
            ->assertSet('panelPurchaseUnpaid', 5_300_000);
    }
}
