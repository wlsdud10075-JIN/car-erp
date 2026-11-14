<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💴 **재무도 비용 10개를 고친다** (jin 2026-11-14 «a로 해주고, 재무 이상 다 되어야»).
 *
 * 2차 정산 안내(차량 창 띠 · 정산 드로어)는 재무에게 「비용 10개를 실측으로 고친다」고 말하는데,
 * 비용 칸이 회계 필드 묶음(C7-a silent restore)에 같이 들어 있어 재무 저장이 조용히 원값으로 되돌아갔다
 * (가이드 씬 찍다 발견 — 탁송비 150,000 저장 → 0, 정산은 「비용 대기」 그대로).
 * 매입가·판매가·환율은 종전대로 영업·관리 이상만. 2차 대기 잠금 해제는 재무 이상(업무관리자 포함, 영업 제외).
 */
class FinanceCostEditTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $permission = 'user'): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'email_verified_at' => now()]);
    }

    private function stockVehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '12가3456', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'purchase_price' => 5_000_000, 'purchase_date' => '2026-11-01',
        ]);
    }

    /** 지급(paid)된 정산이 있는 완납 차 = 2차 대기(비용 대기). */
    private function paidVehicle(): array
    {
        $sm = Salesman::firstOrCreate(['name' => '프리'], ['type' => 'freelance', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500,
            'salesman_id' => $sm->id, 'purchase_price' => 8_000_000, 'purchase_date' => '2026-10-01',
            'sale_price' => 10_000, 'sale_date' => '2026-10-10',
            'buyer_id' => Buyer::create(['name' => 'TEST BUYER', 'is_active' => true])->id,
        ]);
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => 10_000, 'exchange_rate' => 1500, 'payment_date' => '2026-10-20', 'confirmed_at' => now(),
        ]);
        $v->refresh()->refreshCaches();

        Settlement::$allowBatchPayout = true;
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(), 'attributed_month' => '2026-10-01',
        ]);
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        Settlement::$allowBatchPayout = false;

        return [$v->fresh(), $s->fresh()];
    }

    public function test_finance_saves_a_cost_but_purchase_price_is_still_restored(): void
    {
        $v = $this->stockVehicle();
        $this->actingAs($this->user('재무'));

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('cost_towing_str', '150,000')
            ->set('purchase_price_str', '6,000,000')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertEquals(150_000, (float) $v->cost_towing, '재무의 탁송비가 원값으로 되돌아갔다');
        $this->assertEquals(5_000_000, (float) $v->purchase_price, '재무가 매입가를 바꿨다 — 비용 10개만 열어야 한다');
    }

    public function test_clearance_cost_edit_is_still_restored(): void
    {
        $v = $this->stockVehicle();
        $this->actingAs($this->user('수출통관'));

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('cost_towing_str', '150,000')
            ->call('save');

        $this->assertEquals(0, (float) $v->fresh()->cost_towing);
    }

    public function test_finance_enters_a_secondary_cost_and_the_settlement_becomes_ready(): void
    {
        [$v, $s] = $this->paidVehicle();
        $this->assertNull($s->secondary_ready_at);
        $this->actingAs($this->user('재무'));

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('cost_towing_str', '150,000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(150_000, (float) $v->fresh()->cost_towing);
        $this->assertNotNull($s->fresh()->secondary_ready_at, '2차 대기 차의 재무 비용 입력이 「2차 가능」을 못 켰다');
    }

    public function test_work_manager_can_correct_costs_during_secondary_pending(): void
    {
        [$v] = $this->paidVehicle();
        // role 이 관리가 아닌 업무관리자 — 구 판정(admin·재무·관리)으로는 2차 대기 잠금에 막혔다
        $this->actingAs($this->user('영업', 'manager'));

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('cost_towing_str', '150,000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(150_000, (float) $v->fresh()->cost_towing);
    }

    /** 명세서 기입(일괄)도 재무 이상 — 2차 정산 비용을 넣는 실무의 주 경로(jin 11-14). EMS/DHL 일괄은 관리 이상 그대로. */
    public function test_finance_can_use_the_statement_import_but_not_the_shipment_bulk(): void
    {
        [$v, $s] = $this->paidVehicle();
        $finance = $this->user('재무');
        $this->actingAs($finance);

        Volt::test('erp.vehicles.index')->call('openCostImport')->assertSet('showCostImport', true);
        Volt::test('erp.vehicles.index')->call('openShipmentBulk')->assertStatus(403);

        $res = app(BulkVehicleCostService::class)
            ->apply('cost_towing', [$v->id => 150_000], $finance, '재무 명세서 기입 테스트', true);
        $this->assertSame(1, $res['applied']);
        $this->assertNotNull($s->fresh()->secondary_ready_at, '명세서 기입이 「2차 가능」을 못 켰다');
    }

    public function test_clearance_cannot_open_the_statement_import(): void
    {
        $this->actingAs($this->user('수출통관'));
        Volt::test('erp.vehicles.index')->call('openCostImport')->assertStatus(403);
    }

    public function test_secondary_correction_predicate_is_finance_and_above_without_sales(): void
    {
        $this->assertTrue($this->user('재무')->canCorrectSecondaryCosts());
        $this->assertTrue($this->user('관리')->canCorrectSecondaryCosts());
        $this->assertTrue($this->user('관리', 'manager')->canCorrectSecondaryCosts());
        $this->assertTrue($this->user('관리', 'admin')->canCorrectSecondaryCosts());
        $this->assertTrue($this->user('관리', 'super')->canCorrectSecondaryCosts());
        $this->assertFalse($this->user('영업')->canCorrectSecondaryCosts());
        $this->assertFalse($this->user('수출통관')->canCorrectSecondaryCosts());

        $this->assertTrue($this->user('재무')->canEditVehicleCostFields());
        $this->assertFalse($this->user('재무')->canEditVehicleFinancialFields());
        $this->assertFalse($this->user('수출통관')->canEditVehicleCostFields());
    }
}
