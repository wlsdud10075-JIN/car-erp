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

    /**
     * 발송월 필터는 `202607` 로 친다 (jin 2026-09-01).
     * `<input type="month">` 는 브라우저가 「2026 TAB 08」 식 입력을 강요해서 그렇게 못 친다.
     */
    public function test_month_filter_accepts_a_plain_six_digit_month(): void
    {
        $c = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->set('shipmentMonth', '202607');

        $this->assertSame('2026-07', $c->get('shipmentMonth'));

        foreach (['2026-7' => '2026-07', '2026/08' => '2026-08', '2026.9' => '2026-09'] as $in => $want) {
            $this->assertSame($want, $c->set('shipmentMonth', $in)->get('shipmentMonth'));
        }

        // 못 알아볼 값은 지우지 않는다 — 타이핑 중일 수 있다(지우면 글자를 못 넣는다).
        $this->assertSame('20', $c->set('shipmentMonth', '20')->get('shipmentMonth'));
        $this->assertSame('202613', $c->set('shipmentMonth', '202613')->get('shipmentMonth'), '13월은 월이 아니다');
    }

    /** 필터가 실제로 그 달만 거르는지 — 정규화만 되고 안 걸리면 「조용히 0건」이 된다. */
    public function test_month_filter_actually_filters(): void
    {
        $aug = $this->vehicle();
        $sep = $this->vehicle();
        $aug->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'EDAUG', 'fee' => 100, 'sent_date' => '2026-08-12']);
        $sep->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'EDSEP', 'fee' => 100, 'sent_date' => '2026-09-03']);

        $ids = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->set('shipmentMonth', '202608')
            ->instance()->vehicles()->pluck('id')->all();

        $this->assertContains($aug->id, $ids);
        $this->assertNotContains($sep->id, $ids);
    }

    /** 체크한 차량이 모달을 열 때 바로 대상칸에 들어와야 한다 — 버튼을 또 누르게 하지 않는다. */
    public function test_bulk_modal_picks_up_checked_vehicles_when_it_opens(): void
    {
        $a = $this->vehicle();
        $b = $this->vehicle();

        $c = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $a->id, (string) $b->id])
            ->call('openShipmentBulk');

        $this->assertSame(2, $c->get('shipBulkFromSelection'), '몇 대가 들어왔는지 화면에 적을 값');
        $raw = $c->get('shipBulkRaw');
        $this->assertStringContainsString($a->vehicle_number, $raw);
        $this->assertStringContainsString($b->vehicle_number, $raw);

        // 그대로 미리보기를 누르면 바로 대상이 된다(중간 단계 없음).
        $plan = $c->set('shipBulkTrackingNo', 'ED1KR')->set('shipBulkTotal', '10000')
            ->call('previewShipmentBulk')->get('shipBulkPlan');
        $this->assertCount(2, $plan['targets']);
    }

    /** 상단 요약은 **필터에 걸린 것만** 센다 — 화면 숫자와 목록이 갈리면 아무도 못 믿는다. */
    public function test_header_totals_follow_the_filter(): void
    {
        $inScope = $this->vehicle(['vessel_name' => 'GMT SUM']);
        $outScope = $this->vehicle(['vessel_name' => 'OTHER']);
        $inScope->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'EDIN', 'fee' => 29_560]);
        $inScope->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '4508', 'fee' => 74_028]);
        $outScope->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'EDOUT', 'fee' => 999_999]);

        $totals = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->set('search', 'GMT SUM')
            ->instance()->shipmentTotals();

        $this->assertSame(29_560, $totals['ems'], '필터 밖 차량이 섞이면 안 된다');
        $this->assertSame(74_028, $totals['dhl']);
    }

    /**
     * 🚨 **번역 키가 화면에 그대로 찍히는 것**을 잡는다 (jin 제보 2026-09-01).
     *
     * `vehicle.stat.ems_fee` 를 `stat` 이 아니라 `col` 그룹에 넣었더니 헤더에 키 문자열이
     * 그대로 나왔다 — 예외도 로그도 없고, ko·en 양쪽이 똑같이 틀려서 `LocaleKeyParityTest`
     * (두 파일 대조)도 통과했다. 렌더 결과를 봐야만 잡힌다.
     *
     * 발송 합계 블록·일괄 모달은 **조건부 렌더**라 값을 만들어 둬야 화면에 나온다.
     */
    public function test_no_untranslated_key_leaks_into_the_vehicle_screen(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'EDKEY', 'fee' => 29_560]);
        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '4508', 'fee' => 74_028]);
        $v->update(['container_number' => '6.08_G RORO 12-33_5']);

        $html = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openBulkDate')          // 선적일·ETA·VSL + 컨테이너 접두어 모달
            ->call('openShipmentBulk')      // EMS/DHL 기입 모달
            ->call('openEdit', $v->id)      // 차량 패널(EMS/DHL 탭 포함)
            ->html();

        preg_match_all('/(?<![a-z0-9_.])(?:vehicle|common|domain|settlement)\.[a-z0-9_]+(?:\.[a-z0-9_]+)+/', $html, $m);

        $this->assertSame([], array_values(array_unique($m[0])),
            '번역 키가 화면에 그대로 찍혔습니다 — lang 파일에서 그 키가 어느 그룹에 들어갔는지 확인하세요.');
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
