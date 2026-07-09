<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 당사자 축소 (jin 2026-07-09) — 통관(export) 당사자는 이어받기만.
 *   바이어: 판매(buyer_id) → 선적·통관. 컨사이니: 선적(bl_consignee_id) → 통관.
 *   방향1(2026-07-08)로 export_buyer_id 는 C5 게이트 트리거가 아니라 자유 세팅 안전.
 */
class BlExportPropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_export_parties_inherit_from_sale_buyer_and_bl_consignee(): void
    {
        $buyer = Buyer::create(['name' => 'BUYER', 'is_active' => true]);
        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'CONS', 'is_active' => true]);
        $this->actingAs($this->admin());

        $c = Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('buyer_id_str', (string) $buyer->id)             // 판매 바이어 → 선적·통관 이어받기
            ->set('bl_consignee_id_str', (string) $consignee->id); // 선적 컨사이니 → 통관 이어받기

        $c->assertSet('bl_buyer_id_str', (string) $buyer->id);
        $c->assertSet('export_buyer_id_str', (string) $buyer->id);
        $c->assertSet('export_consignee_id_str', (string) $consignee->id);
    }

    public function test_changing_sale_buyer_updates_inherited_export_buyer(): void
    {
        $b1 = Buyer::create(['name' => 'B1', 'is_active' => true]);
        $b2 = Buyer::create(['name' => 'B2', 'is_active' => true]);
        $this->actingAs($this->admin());

        $c = Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('buyer_id_str', (string) $b1->id);
        $c->assertSet('export_buyer_id_str', (string) $b1->id);

        // 판매 바이어 변경 → 통관 바이어도 따라감 (authoritative)
        $c->set('buyer_id_str', (string) $b2->id);
        $c->assertSet('export_buyer_id_str', (string) $b2->id);
        $c->assertSet('bl_buyer_id_str', (string) $b2->id);
    }

    public function test_save_persists_inherited_export_parties(): void
    {
        $buyer = Buyer::create(['name' => 'SAVE BUYER', 'is_active' => true]);
        $cons = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'SAVE CONS', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '99가1234', 'sales_channel' => 'export',
            'is_deregistered' => true, 'deregistration_document' => 'derg.pdf',
            'purchase_price' => 1000, 'purchase_date' => '2026-01-01',
        ]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_reg_owner_rrn', '900101-1234567')   // H10 말소 RRN 필수
            ->set('buyer_id_str', (string) $buyer->id)        // 판매 바이어
            ->set('bl_consignee_id_str', (string) $cons->id)  // 선적 컨사이니
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame($buyer->id, $v->export_buyer_id, '저장 후 통관 바이어 = 판매 바이어');
        $this->assertSame($buyer->id, $v->bl_buyer_id, '저장 후 선적 바이어 = 판매 바이어');
        $this->assertSame($cons->id, $v->export_consignee_id, '저장 후 통관 컨사이니 = 선적 컨사이니');
    }
}
