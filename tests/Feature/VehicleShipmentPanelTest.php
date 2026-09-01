<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량 편집 「EMS / DHL」 탭 + 일괄 기입 모달 (jin 2026-08-31).
 *
 * 화면 쪽에서 조용히 틀어질 수 있는 것만 본다:
 *   ① 발송을 **안 건드린 저장**이 마감 차량에서 막히지 않는다 (SKILLS §8 #65)
 *   ② 빈 행이 유령 발송으로 저장되지 않는다
 *   ③ 붙여넣기에서 **못 찾은 값이 화면에 남는다** (조용히 빠지면 대수가 안 맞는다)
 *   ④ 미리보기와 실제 기입이 같은 판정을 쓴다
 */
class VehicleShipmentPanelTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Settlement::flushParamMemo();
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /**
     * ⚠️ 차량번호는 **실제 번호판 꼴**이어야 한다 — 일괄 기입의 붙여넣기 파서가
     *    `\d{2,3}[가-힣]\d{4}` 로 번호판과 차대번호를 가른다(안 맞으면 VIN 으로 찾다 실패).
     * ⚠️ 판매가를 넣으면 바이어가 필수다(save 검증) — 안 넣으면 저장이 통째로 막혀
     *    「발송이 저장 안 된다」로 보인다.
     */
    private function vehicle(array $attrs = []): Vehicle
    {
        $n = ++$this->counter;
        $buyer = Buyer::create(['name' => 'BUYER-'.$n, 'country_id' => null]);

        return Vehicle::create(array_merge([
            'vehicle_number' => sprintf('%02d가%04d', 10 + $n, 1000 + $n),
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'purchase_price' => 10_000_000, 'sale_price' => 20_000_000, 'sale_date' => '2026-08-01',
            'buyer_id' => $buyer->id,
        ], $attrs));
    }

    public function test_panel_saves_ems_and_dhl_rows(): void
    {
        $v = $this->vehicle();

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('shipments', [
                ['id' => null, 'carrier' => 'ems', 'tracking_no' => 'ED105401414KR', 'fee' => '29,560', 'sent_date' => '20260120'],
                ['id' => null, 'carrier' => 'dhl', 'tracking_no' => '4508354922', 'fee' => '74028', 'sent_date' => '2026-02-03'],
            ])
            ->call('save');

        $v->refresh();
        $this->assertSame(2, $v->shipments()->count());
        $this->assertSame('ED105401414KR', $v->ems_tracking_no_cache);
        $this->assertSame('4508354922', $v->dhl_tracking_no_cache);
        $this->assertSame(103_588, $v->shipping_fee_total, '금액칸의 콤마가 잘려 29,560 이 29 가 되면 안 된다');

        // 8자리 날짜는 서버가 정규화한다 — 그대로 새면 date 캐스트가 1970 으로 읽는다(SKILLS §14).
        $ems = $v->shipments()->where('carrier', 'ems')->first();
        $this->assertSame('2026-01-20', $ems->sent_date->format('Y-m-d'));
    }

    public function test_empty_row_is_not_saved_as_a_ghost_shipment(): void
    {
        $v = $this->vehicle();

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('addShipment', 'ems')          // [＋발송 추가]만 누르고 아무것도 안 씀
            ->call('save');

        $this->assertSame(0, $v->fresh()->shipments()->count());
    }

    public function test_removing_a_row_deletes_it_and_returns_the_money(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED1KR', 'fee' => 5000]);

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('removeShipment', 0)
            ->call('save');

        $v->refresh();
        $this->assertSame(0, $v->shipments()->count());
        $this->assertSame(0, $v->shipping_fee_total);
    }

    /**
     * 🚨 마감 차량이라도 **발송을 안 건드린 저장은 통과해야 한다.**
     *
     * 폼에는 기존 발송 행이 그대로 실려 오므로, 값 비교 없이 save() 를 부르면 마감 가드가 떠서
     * 「메모만 고쳤는데 저장이 막히는」 상태가 된다 — 2026-08-26 무담보 가드가 밟은 그 함정이다.
     */
    public function test_untouched_shipments_do_not_block_saving_a_closed_vehicle(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '999', 'fee' => 12_345, 'sent_date' => '2026-05-01']);
        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
        ]);

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('tabMemos.bl', '마감 뒤에 남기는 한 줄')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame('마감 뒤에 남기는 한 줄', $v->memo_bl);
        $this->assertSame(12_345, $v->shipping_fee_total, '발송 금액은 그대로여야 한다');
    }

    // ── 일괄 기입 모달 ───────────────────────────────────────────

    public function test_bulk_modal_matches_by_vin_and_shows_what_it_could_not_find(): void
    {
        $a = $this->vehicle(['nice_reg_vin' => 'WAUZZZ8R2DA096294']);
        $b = $this->vehicle(['nice_reg_vin' => 'WDC0G0FB0GF036731']);

        $c = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openShipmentBulk')
            ->set('shipBulkCarrier', 'ems')
            ->set('shipBulkTrackingNo', 'ED105401414KR')
            ->set('shipBulkTotal', '29,560')
            // 관리표의 차대번호 칸을 그대로 붙여넣는 흐름 — 공백 구분에 없는 차가 섞여 있다.
            ->set('shipBulkRaw', 'WAUZZZ8R2DA096294 WDC0G0FB0GF036731 NOSUCHVIN12345678')
            ->call('previewShipmentBulk');

        $plan = $c->get('shipBulkPlan');
        $this->assertCount(2, $plan['targets']);
        $this->assertSame(14_780, $plan['per_unit']);
        $this->assertSame(['NOSUCHVIN12345678'], $c->get('shipBulkUnmatched'),
            '못 찾은 값이 조용히 빠지면 「몇 대가 왜 없지」가 된다');

        $c->call('applyShipmentBulk');

        $this->assertSame(14_780, $a->fresh()->shipping_fee_total);
        $this->assertSame(14_780, $b->fresh()->shipping_fee_total);
        $this->assertSame(29_560, $a->fresh()->shipping_fee_total + $b->fresh()->shipping_fee_total);
    }

    /** 미리보기가 보여준 대상·건너뜀이 실제 기입과 같아야 한다(SKILLS §8 #67). */
    public function test_preview_and_apply_agree_on_skipped_vehicles(): void
    {
        $open = $this->vehicle();
        $closed = $this->vehicle();
        Settlement::create([
            'vehicle_id' => $closed->id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
        ]);

        $c = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openShipmentBulk')
            ->set('shipBulkCarrier', 'dhl')
            ->set('shipBulkTrackingNo', '4508354922')
            ->set('shipBulkTotal', '10000')
            ->set('shipBulkRaw', $open->vehicle_number.' '.$closed->vehicle_number)
            ->call('previewShipmentBulk');

        $plan = $c->get('shipBulkPlan');
        $this->assertSame([$closed->id], array_column($plan['skipped'], 'id'));
        $this->assertSame('settlement_closed', $plan['skipped'][0]['reason']);

        $c->call('applyShipmentBulk');
        $this->assertSame(10_000, $open->fresh()->shipping_fee_total);
        $this->assertSame(0, $closed->fresh()->shipping_fee_total);
    }

    public function test_bulk_modal_is_closed_to_users_without_approval_permission(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        Salesman::create(['name' => '영업', 'type' => 'freelance', 'is_active' => true]);

        Volt::actingAs($sales)->test('erp.vehicles.index')
            ->call('openShipmentBulk')
            ->assertStatus(403);
    }
}
