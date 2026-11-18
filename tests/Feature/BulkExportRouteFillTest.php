<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\ForwardingCompany;
use App\Models\Port;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleShippingDateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🚢 선적일·ETA·VSL 일괄 모달의 「수출 정보 묶음」 (jin 2026-11-18).
 *
 * 실측 ssancarerp 06-01~ RORO+DURRESS 1,287대 = 반입지·포워딩사·선적항·선적방법·인코텀즈·도착항 조합 하나가 98.9%.
 * 지키는 것: ①한 바이어일 때만 ②빈칸만 채움(FOB 같은 예외 보존 — jin (가)) ③이미 컨사이니가 있는 차는 그대로
 *            ④대기 허용 항로라 입금 0 이어도 저장(C5 건너뜀) — 말소 체크 안 된 차는 사유와 함께 제외.
 */
class BulkExportRouteFillTest extends TestCase
{
    use RefreshDatabase;

    private Port $durres;

    private ForwardingCompany $fw;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->durres = Port::create(['type' => 'discharge', 'name' => 'DURRESS, ALBANIA', 'code' => 'ALDRZ', 'is_active' => true, 'allow_shipping_wait' => true]);
        Port::create(['type' => 'unloading', 'name' => '인천항 3부두', 'code' => 'ICN3', 'is_active' => true]);
        Port::create(['type' => 'loading', 'name' => 'INCHEON, KOREA', 'code' => 'KRINC', 'is_active' => true]);
        $this->fw = ForwardingCompany::create(['name' => 'FW SEVEN', 'is_active' => true]);
        $this->buyer = Buyer::create(['name' => 'KOREA AUTO IMPORTS', 'is_active' => true]);
    }

    private function vehicle(string $no, array $attrs = [], ?Buyer $buyer = null): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);
        $b = $buyer ?? $this->buyer;

        return Vehicle::create(array_merge([
            'vehicle_number' => $no, 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1480,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000, 'purchase_date' => '2026-11-10',
            'buyer_id' => $b->id, 'sale_price' => 5000, 'sale_date' => '2026-11-10',   // 입금 0 = 미수 100%
            'is_deregistered' => true,
        ], $attrs));
    }

    private function route(?int $consigneeId): array
    {
        return [
            'bl_loading_location' => '인천항 3부두', 'forwarding_company_id' => (string) $this->fw->id,
            'port_of_loading' => 'INCHEON, KOREA', 'shipping_method' => 'RORO', 'incoterms' => 'CFR',
            'discharge_port_id' => (string) $this->durres->id, 'bl_consignee_id' => $consigneeId ? (string) $consigneeId : '',
        ];
    }

    private function user(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    public function test_fills_empty_fields_and_passes_the_gate_on_the_wait_route_even_unpaid(): void
    {
        $cons = Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'CONS A']);
        $v = $this->vehicle('11가1111');

        $r = app(BulkVehicleShippingDateService::class)->apply(Vehicle::query(), [], $this->user(), '알바니아', null, $this->route($cons->id));

        $this->assertSame(1, $r['applied'], json_encode($r['skipped'], JSON_UNESCAPED_UNICODE));
        $v->refresh();
        $this->assertSame('인천항 3부두', $v->bl_loading_location);
        $this->assertSame($this->fw->id, (int) $v->forwarding_company_id);
        $this->assertSame('RORO', $v->shipping_method);
        $this->assertSame('CFR', $v->incoterms);
        $this->assertSame($this->durres->id, (int) $v->discharge_port_id);
        $this->assertSame($cons->id, (int) $v->bl_consignee_id);
        $this->assertSame($cons->id, (int) $v->export_consignee_id, '통관 컨사이니도 이어받는다');
        $this->assertSame($this->buyer->id, (int) $v->bl_buyer_id);
    }

    /** 대조군 — 대기 허용이 아닌 도착항이면 같은 미수 차가 입금률 게이트에 걸린다(위 테스트가 헛통과가 아니라는 증거). */
    public function test_non_wait_route_unpaid_vehicle_is_gated(): void
    {
        $cons = Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'CONS A']);
        $antwerp = Port::create(['type' => 'discharge', 'name' => 'ANTWERP, BELGIUM', 'code' => 'BEANR', 'is_active' => true, 'allow_shipping_wait' => false]);
        $v = $this->vehicle('12가1212');

        $r = app(BulkVehicleShippingDateService::class)->apply(Vehicle::query(), [], $this->user(), 'x', null,
            array_merge($this->route($cons->id), ['discharge_port_id' => (string) $antwerp->id]));

        $this->assertSame(0, $r['applied']);
        $this->assertStringContainsString('입금률', $r['skipped'][0]['reason']);
        $this->assertNull($v->fresh()->bl_loading_location);
    }

    public function test_existing_values_and_consignee_are_kept(): void
    {
        $a = Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'CONS A']);
        $b = Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'CONS B']);
        $v = $this->vehicle('22나2222', ['incoterms' => 'FOB', 'bl_buyer_id' => $this->buyer->id, 'bl_consignee_id' => $b->id]);

        app(BulkVehicleShippingDateService::class)->apply(Vehicle::query(), [], $this->user(), '알바니아', null, $this->route($a->id));

        $v->refresh();
        $this->assertSame('FOB', $v->incoterms, '예외 값(FOB)을 덮었다');
        $this->assertSame($b->id, (int) $v->bl_consignee_id, '이미 지정된 컨사이니를 덮었다');
        $this->assertSame('RORO', $v->shipping_method, '빈 칸은 채운다');
    }

    public function test_mixed_buyers_are_refused(): void
    {
        $other = Buyer::create(['name' => 'OTHER', 'is_active' => true]);
        $this->vehicle('33다3333');
        $this->vehicle('44라4444', [], $other);

        $this->expectException(InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(Vehicle::query(), [], $this->user(), 'x', null, $this->route(null));
    }

    public function test_consignee_of_another_buyer_is_refused(): void
    {
        $other = Buyer::create(['name' => 'OTHER', 'is_active' => true]);
        $foreign = Consignee::create(['buyer_id' => $other->id, 'name' => 'FOREIGN']);
        $this->vehicle('55마5555');

        $this->expectException(InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(Vehicle::query(), [], $this->user(), 'x', null, $this->route($foreign->id));
    }

    public function test_not_deregistered_vehicle_is_skipped_with_reason(): void
    {
        $cons = Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'CONS A']);
        $ok = $this->vehicle('66바6666');
        $blocked = $this->vehicle('77사7777', ['is_deregistered' => false]);

        $r = app(BulkVehicleShippingDateService::class)->apply(Vehicle::query(), [], $this->user(), 'x', null, $this->route($cons->id));

        $this->assertSame(1, $r['applied']);
        $this->assertSame('인천항 3부두', $ok->fresh()->bl_loading_location);
        $this->assertNull($blocked->fresh()->bl_loading_location, '게이트에 걸린 차는 아무것도 안 바뀐다');
        $this->assertStringStartsWith('gate:', $r['skipped'][0]['reason']);
    }

    public function test_modal_prefills_buyer_defaults_and_single_consignee(): void
    {
        $cons = Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'CONS ONLY']);
        // 이 바이어의 지난 차 = 조합의 출처
        $this->vehicle('88아8888', [
            'bl_loading_location' => '인천항 3부두', 'forwarding_company_id' => $this->fw->id, 'port_of_loading' => 'INCHEON, KOREA',
            'shipping_method' => 'RORO', 'incoterms' => 'CFR', 'discharge_port_id' => $this->durres->id,
            'bl_buyer_id' => $this->buyer->id, 'bl_consignee_id' => $cons->id,
        ]);
        $target = $this->vehicle('99자9999');
        $this->actingAs($this->user());

        Volt::test('erp.vehicles.index')
            ->set('search', '99자9999')
            ->call('openBulkDate')
            ->set('bulkRouteOn', true)
            ->assertSet('bulkRoute.incoterms', 'CFR')
            ->assertSet('bulkRoute.discharge_port_id', (string) $this->durres->id)
            ->assertSet('bulkRoute.bl_consignee_id', (string) $cons->id)
            ->assertSee('KOREA AUTO IMPORTS')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', false);

        $this->assertSame('RORO', $target->fresh()->shipping_method);
        $this->assertSame($cons->id, (int) $target->fresh()->bl_consignee_id);
    }

    public function test_modal_blocks_mixed_buyers_and_two_consignees_are_not_preselected(): void
    {
        Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'C1']);
        Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'C2']);
        $other = Buyer::create(['name' => 'OTHER', 'is_active' => true]);
        $this->vehicle('10가1010');
        $this->actingAs($this->user());

        Volt::test('erp.vehicles.index')
            ->set('search', '10가1010')
            ->call('openBulkDate')
            ->set('bulkRouteOn', true)
            ->assertSet('bulkRoute.bl_consignee_id', '');

        $this->vehicle('20나2020', [], $other);
        Volt::test('erp.vehicles.index')
            ->call('openBulkDate')
            ->set('bulkRouteOn', true)
            ->assertSeeHtml('data-bulk-route-blocked');
    }
}
