<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\User;
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
}
