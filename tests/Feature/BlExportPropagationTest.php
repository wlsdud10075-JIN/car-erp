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
 * item 8(a) (jin 2026-07-07) — 선적(bl) 당사자 지정 시 수출통관(export) 당사자 자동 전파.
 *
 * 50% 진입 우회가 선적·수출통관 통합(clearance∪shipping)돼 선적탭에서 통관 당사자 채움이 도메인상 정합.
 * ⚠️ SKILLS #24: 판매탭(sale→export) 자동전파는 여전히 금지 — sale→bl 서버전파가 bl→export 로
 *    연쇄되지 않아야 함(updated 훅은 클라 편집에서만).
 */
class BlExportPropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_bl_party_propagates_to_export_when_empty(): void
    {
        $buyer = Buyer::create(['name' => 'BL BUYER', 'is_active' => true]);
        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'BL CONS', 'is_active' => true]);
        $this->actingAs($this->manager());

        $c = Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('bl_buyer_id_str', (string) $buyer->id)        // updatedBlBuyerIdStr → bl 컨사이니 클리어
            ->set('bl_consignee_id_str', (string) $consignee->id); // updatedBlConsigneeIdStr → propagateBlToExport

        $c->assertSet('export_buyer_id_str', (string) $buyer->id);
        $c->assertSet('export_consignee_id_str', (string) $consignee->id);
    }

    public function test_existing_export_party_not_overwritten(): void
    {
        $buyer = Buyer::create(['name' => 'BL BUYER2', 'is_active' => true]);
        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'BL CONS2', 'is_active' => true]);
        $expBuyer = Buyer::create(['name' => 'EXP BUYER', 'is_active' => true]);
        $expCons = Consignee::create(['buyer_id' => $expBuyer->id, 'name' => 'EXP CONS', 'is_active' => true]);
        $this->actingAs($this->manager());

        $c = Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('export_buyer_id_str', (string) $expBuyer->id)
            ->set('export_consignee_id_str', (string) $expCons->id)
            ->set('bl_buyer_id_str', (string) $buyer->id)
            ->set('bl_consignee_id_str', (string) $consignee->id);

        // 명시 통관 당사자 보존 (전파가 덮어쓰지 않음)
        $c->assertSet('export_buyer_id_str', (string) $expBuyer->id);
        $c->assertSet('export_consignee_id_str', (string) $expCons->id);
    }

    public function test_save_persists_export_party_from_bl(): void
    {
        // 게이트 통과 조건(말소완료 + 판매 0 → C5 skip)만 갖춘 export 차량을 raw 생성 후 편집.
        $buyer = Buyer::create(['name' => 'SAVE BUYER', 'is_active' => true]);
        $cons = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'SAVE CONS', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '99가1234', 'sales_channel' => 'export',
            'is_deregistered' => true, 'deregistration_document' => 'derg.pdf',
            'purchase_price' => 1000, 'purchase_date' => '2026-01-01',
        ]);
        // admin — 전 차량 스코프(salesman 없는 차량도 편집). manager role='관리'는 팀 스코프라 제외.
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]));

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_reg_owner_rrn', '900101-1234567')   // H10 말소 RRN 필수
            ->set('bl_buyer_id_str', (string) $buyer->id)
            ->set('bl_consignee_id_str', (string) $cons->id)
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame($buyer->id, $v->export_buyer_id, '저장 후 통관 바이어 = 선적 바이어');
        $this->assertSame($cons->id, $v->export_consignee_id, '저장 후 통관 컨사이니 = 선적 컨사이니');
    }
}
