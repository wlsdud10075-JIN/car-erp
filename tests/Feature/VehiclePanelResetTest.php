<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * jin 2026-07-14 — 차량 편집 후 신규등록 진입 시 "저장 후 표시" 요약 스냅샷(panel*)이
 * 직전 차량 값으로 잔존하던 버그. resetForm() 에 panel* 초기화 누락이 원인.
 */
class VehiclePanelResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_opening_create_after_edit_clears_panel_summary_snapshots(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        // 매입·판매 값이 있는 차량 A (chk_sale_required: sale_price>0 → sale_date·buyer·환율 동반).
        $buyer = Buyer::create(['name' => '바이어A', 'is_active' => true]);
        $a = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'purchase_date' => '2026-06-01', 'purchase_price' => 5_000_000, 'selling_fee' => 300_000,
            'sale_date' => '2026-06-10', 'buyer_id' => $buyer->id, 'sale_price' => 8_000_000,
            'deregistration_notice_phone' => '010-1234-5678',
        ]);

        $component = Volt::test('erp.vehicles.index')
            ->call('openEdit', $a->id);

        // 편집 화면엔 A 스냅샷이 채워진다 (버그 아님 — 정상).
        $component->assertSet('panelPurchaseTotal', 5_300_000)
            ->assertSet('panelSaleTotal', 8_000_000.0)
            ->assertSet('deregistrationBuyerPhone', '010-1234-5678');

        // 패널 닫고 → 신규 등록 진입 → 요약 스냅샷·딜러번호 전부 초기화돼야 한다.
        $component->call('close')
            ->call('openCreate')
            ->assertSet('editingId', null)
            ->assertSet('panelPurchaseTotal', null)
            ->assertSet('panelPurchasePaid', null)
            ->assertSet('panelPurchaseUnpaid', null)
            ->assertSet('panelSaleTotal', null)
            ->assertSet('panelSaleUnpaid', null)
            ->assertSet('panelUnpaidRatio', null)
            ->assertSet('deregistrationBuyerPhone', '')
            ->assertSet('purchase_price_str', '')
            ->assertSet('sale_price_str', '');
    }
}
