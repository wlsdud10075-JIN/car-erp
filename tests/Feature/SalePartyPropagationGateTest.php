<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2026-06-01 회귀 — 판매 당사자 자동전파 × C5 게이트 충돌 (커밋 7cbde2d 회귀).
 *
 * 증상: [관리]가 판매가+바이어+컨사이니를 입력하면 propagateSaleParty 가 통관(export) 당사자까지
 *       자동으로 채우고, export_buyer_id 는 guardStageOrderForExport 의 $hasExportInput(통관 진입 신호)
 *       이라서 <50% 입금 차량의 판매 저장이 "입금률 < 50% … clearance 단계 진입 불가" 로 통째 차단됐다.
 *
 * Fix(Option B): 자동전파에서 통관(export) 당사자 제거. B/L 당사자는 게이트 트리거가 아니라 전파 유지.
 */
class SalePartyPropagationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_manager_can_save_sale_with_consignee_before_50_percent_paid(): void
    {
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $sub = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id, 'email_verified_at' => now()]);
        $salesman = Salesman::create(['name' => '팀원영업', 'user_id' => $sub->id, 'is_active' => true, 'type' => 'freelance']);
        $buyer = Buyer::create(['name' => 'PROP BUYER', 'is_active' => true]);
        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'PROP CONS', 'is_active' => true]);

        $this->actingAs($manager);

        // 판매가 + 바이어 입력, 입금 0% → 당사자 이어받기(export_buyer 세팅)에도 C5 차단 없어야 함(방향1).
        //   당사자 축소(jin 2026-07-09): 컨사이니는 선적(bl_consignee)에서 입력.
        $c = Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'PROP-1')
            ->set('currency', 'KRW')
            ->set('exchange_rate_str', '1')
            ->set('salesman_id_str', (string) $salesman->id)
            ->set('purchase_price_str', '5,000,000')
            ->set('sale_date', '2026-05-01')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('bl_consignee_id_str', (string) $consignee->id)
            ->set('sale_price_str', '5,000,000')
            ->call('save')
            ->assertHasNoErrors();   // ← 핵심: 0% 입금 + export 이어받기여도 저장 차단 없음

        // 당사자 이어받기 — 바이어=판매 → 선적·통관, 컨사이니=선적 → 통관.
        $c->assertSet('export_buyer_id_str', (string) $buyer->id);
        $c->assertSet('bl_buyer_id_str', (string) $buyer->id);
        $c->assertSet('export_consignee_id_str', (string) $consignee->id);

        $v = Vehicle::where('vehicle_number', 'PROP-1')->firstOrFail();
        $this->assertSame('판매중', $v->progress_status, '0% 입금이라도 판매 입력은 저장돼 판매중');
        $this->assertSame((int) $buyer->id, (int) $v->export_buyer_id, '통관 바이어 = 판매 바이어(이어받기)');
        $this->assertSame((int) $buyer->id, (int) $v->bl_buyer_id, 'B/L 바이어 = 판매 바이어(이어받기)');
    }
}
