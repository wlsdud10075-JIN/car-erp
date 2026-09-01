<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleShipmentService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 서류 발송비(우체국 EMS · DHL) → 정산 반영 (jin 2026-08-31).
 *
 * 확정 사항 두 가지가 이 테스트의 전부다:
 *   ① 실지급액에서 **전액** 차감 — 프리랜서·사내직원 동일 (총마진 차감이 아니다)
 *   ② 재발송은 **행이 늘어난다** — 덮어쓰면 앞 발송 금액이 조용히 사라진다(실측 연 132만원)
 */
class VehicleShipmentSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Settlement::flushParamMemo();
    }

    private function vehicle(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '12가'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 10_000_000,
            'selling_fee' => 0,
            'sale_price' => 20_000_000,
            'sale_date' => '2026-08-01',
        ], $attrs));
    }

    private function settlement(Vehicle $v, string $type, ?Salesman $s = null): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $s?->id,
            'settlement_type' => $type,
            'settlement_status' => 'pending',
        ]);
    }

    // ── ① 캐시 ───────────────────────────────────────────────────

    public function test_adding_a_shipment_refreshes_the_vehicle_cache(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED105401414KR', 'fee' => 29_560, 'sent_date' => '2026-01-20']);

        $v = $v->fresh();
        $this->assertSame('ED105401414KR', $v->ems_tracking_no_cache);
        $this->assertNull($v->dhl_tracking_no_cache);
        $this->assertSame(29_560, $v->shipping_fee_total_cache);
        $this->assertSame(29_560, $v->shipping_fee_total);
        $this->assertSame('2026-01-20', $v->shipping_sent_date_cache->format('Y-m-d'));
    }

    /** 🚨 이 테스트가 이 기능의 존재 이유다 — 재발송 금액이 합산돼야 한다(덮어쓰기 금지). */
    public function test_re_shipment_adds_up_instead_of_overwriting(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '9421602326', 'fee' => 17_368, 'sent_date' => '2026-03-01']);
        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '7851688983', 'fee' => 52_111, 'sent_date' => '2026-06-24']);

        $v = $v->fresh();
        $this->assertSame(69_479, $v->shipping_fee_total, '앞 발송이 사라지면 연 132만원이 아무에게도 청구되지 않는다');
        $this->assertSame('7851688983', $v->dhl_tracking_no_cache, '조회용 번호는 최신 발송분');
    }

    public function test_latest_tracking_number_wins_per_carrier(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'EE111111111KR', 'fee' => 1000, 'sent_date' => '2026-02-01']);
        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '5555555555', 'fee' => 2000, 'sent_date' => '2026-05-01']);

        $v = $v->fresh();
        $this->assertSame('EE111111111KR', $v->ems_tracking_no_cache);
        $this->assertSame('5555555555', $v->dhl_tracking_no_cache);
        $this->assertSame(3000, $v->shipping_fee_total);
    }

    public function test_tracking_number_is_normalized(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'EMS', 'tracking_no' => ' ed-1054 0141 4kr ', 'fee' => 100]);

        $this->assertSame('ED105401414KR', $v->fresh()->ems_tracking_no_cache);
    }

    public function test_unknown_carrier_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->vehicle()->shipments()->create(['carrier' => 'fedex', 'tracking_no' => 'X1', 'fee' => 100]);
    }

    // ── ② 정산 반영 ──────────────────────────────────────────────

    public function test_freelancer_payout_drops_by_the_full_shipping_fee(): void
    {
        $v = $this->vehicle();
        $s = $this->settlement($v, 'ratio');

        $before = $s->actual_payout;
        $margin = $s->total_margin;

        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED1KR', 'fee' => 30_000]);
        $s = $s->fresh()->load('vehicle');

        $this->assertSame($margin, $s->total_margin, '총마진은 안 움직인다 — 비용 10칸이 아니다');
        $this->assertSame(30_000, $s->shipping_fee);
        $this->assertSame($before - 30_000, $s->actual_payout, '반값(15,000)이 아니라 전액이어야 한다');
    }

    /**
     * 사내직원은 정산액이 총마진과 무관해 「총마진 차감」으로는 **아무 일도 안 일어난다**.
     * ssancarerp 실측 1,662 건이 그 상태가 될 뻔했다 — 그래서 정산액 옆에서 뺀다.
     */
    public function test_employee_payout_drops_by_the_full_shipping_fee_too(): void
    {
        $v = $this->vehicle();
        $s = $this->settlement($v, 'per_unit');

        $before = $s->actual_payout;
        $this->assertGreaterThan(0, $before);

        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '74028', 'fee' => 30_000]);
        $s = $s->fresh()->load('vehicle');

        $this->assertSame($before - 30_000, $s->actual_payout);
    }

    public function test_company_borne_shipment_keeps_the_number_but_costs_nobody(): void
    {
        $v = $this->vehicle();
        $s = $this->settlement($v, 'ratio');
        $before = $s->actual_payout;

        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED9KR', 'fee' => 0]);
        $v = $v->fresh();
        $s = $s->fresh()->load('vehicle');

        $this->assertSame('ED9KR', $v->ems_tracking_no_cache, '바이어 조회용 번호는 남아야 한다');
        $this->assertSame($before, $s->actual_payout);
    }

    /**
     * 회사이익 = 총마진 − 실지급액 − 발송비.
     * 마지막 항이 없으면 «지급에서 뺀 만큼» 회사이익이 부풀어 대표 화면이 틀린다.
     */
    public function test_company_net_is_not_inflated_by_the_deduction(): void
    {
        $v = $this->vehicle();
        $s = $this->settlement($v, 'ratio');
        $before = $s->company_net;

        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED8KR', 'fee' => 30_000]);
        $s = $s->fresh()->load('vehicle');

        $this->assertSame($before, $s->company_net, '회사는 먼저 치르고 되받았을 뿐 순증이 없다');
        $this->assertSame($s->total_margin - $s->actual_payout - 30_000, $s->company_net);
    }

    // ── ③ 일괄 기입 ──────────────────────────────────────────────

    private function admin(): User
    {
        return User::create([
            'name' => 'admin', 'email' => 'a'.random_int(1, 99999).'@t.test',
            'password' => bcrypt('x'), 'permission' => 'admin', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    public function test_bulk_splits_the_total_and_the_remainder_goes_to_the_first(): void
    {
        $by = $this->admin();
        $this->actingAs($by);
        $vs = collect(range(1, 3))->map(fn () => $this->vehicle());

        $out = app(BulkVehicleShipmentService::class)
            ->apply('ems', 'ED777KR', $vs->pluck('id')->all(), 10_000, '2026-08-01', $by, '테스트');

        $this->assertSame(3, $out['applied']);
        $fees = $vs->map(fn ($v) => $v->fresh()->shipping_fee_total)->all();
        $this->assertSame([3334, 3333, 3333], $fees, '나머지 1원은 첫 차량 — 합이 총액과 같아야 한다');
        $this->assertSame(10_000, array_sum($fees));
    }

    public function test_reapplying_the_same_number_replaces_instead_of_stacking(): void
    {
        $by = $this->admin();
        $this->actingAs($by);
        $vs = collect(range(1, 2))->map(fn () => $this->vehicle());
        $svc = app(BulkVehicleShipmentService::class);

        $svc->apply('dhl', '4508354922', $vs->pluck('id')->all(), 100_000, '2026-08-01', $by, '1차');
        $svc->apply('dhl', '4508354922', $vs->pluck('id')->all(), 100_000, '2026-08-01', $by, '재기입');

        $this->assertSame(50_000, $vs[0]->fresh()->shipping_fee_total, '같은 명세를 두 번 넣어도 이중청구되지 않는다');
        $this->assertSame(1, $vs[0]->shipments()->count());
    }

    public function test_dropping_a_vehicle_from_the_shipment_is_reported_not_silent(): void
    {
        $by = $this->admin();
        $this->actingAs($by);
        $vs = collect(range(1, 2))->map(fn () => $this->vehicle());
        $svc = app(BulkVehicleShipmentService::class);

        $svc->apply('dhl', '999', $vs->pluck('id')->all(), 100_000, null, $by, '1차');

        $plan = $svc->plan('dhl', '999', [$vs[0]->id], 100_000, $by);
        $this->assertSame([$vs[1]->id], array_column($plan['removed'], 'id'), '빠지는 차량이 미리보기에 뜬다');

        $out = $svc->apply('dhl', '999', [$vs[0]->id], 100_000, null, $by, '축소');
        $this->assertSame(1, $out['removed']);
        $this->assertSame(100_000, $vs[0]->fresh()->shipping_fee_total);
        $this->assertSame(0, $vs[1]->fresh()->shipping_fee_total);
    }

    public function test_closed_secondary_settlement_is_skipped_with_a_reason(): void
    {
        $by = $this->admin();
        $this->actingAs($by);
        $open = $this->vehicle();
        $closed = $this->vehicle();
        Settlement::create([
            'vehicle_id' => $closed->id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
        ]);

        $svc = app(BulkVehicleShipmentService::class);
        $plan = $svc->plan('ems', 'ED5KR', [$open->id, $closed->id], 10_000, $by);
        $out = $svc->apply('ems', 'ED5KR', [$open->id, $closed->id], 10_000, null, $by, '테스트');

        $this->assertSame([['id' => $closed->id, 'number' => $closed->vehicle_number, 'reason' => 'settlement_closed']], $plan['skipped']);
        $this->assertSame($plan['skipped'], $out['skipped'], '미리보기와 실행이 갈리면 안 된다');
        $this->assertSame(10_000, $open->fresh()->shipping_fee_total, '건너뛴 차량 몫까지 남은 차량이 받는다');
        $this->assertSame(0, $closed->fresh()->shipping_fee_total);
    }

    public function test_closed_vehicle_blocks_individual_row_too(): void
    {
        $by = $this->admin();
        $this->actingAs($by);
        $v = $this->vehicle();
        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
        ]);

        $this->expectException(DomainException::class);
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED4KR', 'fee' => 1000]);
    }

    public function test_eight_digit_date_is_normalized_on_the_server(): void
    {
        $this->assertSame('2026-08-01', BulkVehicleShipmentService::normalizeDate('20260801'));
        $this->assertSame('2026-08-01', BulkVehicleShipmentService::normalizeDate('2026-08-01'));
        $this->assertNull(BulkVehicleShipmentService::normalizeDate(''));
        $this->assertNull(BulkVehicleShipmentService::normalizeDate('아무거나'));
    }

    public function test_bulk_requires_approval_permission(): void
    {
        $user = User::create([
            'name' => '영업', 'email' => 's'.random_int(1, 99999).'@t.test',
            'password' => bcrypt('x'), 'permission' => 'user', 'role' => '영업',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);
        app(BulkVehicleShipmentService::class)->apply('ems', 'ED3KR', [$this->vehicle()->id], 1000, null, $user, 'x');
    }

    /**
     * 🚨 정산 편집 패널은 실지급액을 **자체 계산**한다(미리보기). 발송비를 거기 안 빼면
     *    「화면엔 X 인데 실제 지급은 X−발송비」가 된다 — 공식 복제의 그 형태(SKILLS §8 #45).
     */
    public function test_settlement_screen_preview_matches_the_model(): void
    {
        $v = $this->vehicle();
        $s = $this->settlement($v, 'ratio');
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED7KR', 'fee' => 30_000]);

        $admin = $this->admin();
        $component = Volt::actingAs($admin)->test('erp.settlements.index')
            ->call('openEdit', $s->id);

        $preview = $component->instance()->marginData();
        $model = $s->fresh()->load('vehicle');

        $this->assertSame(30_000, $preview['shippingFee']);
        $this->assertSame($model->actual_payout, $preview['actualPayout'],
            '미리보기와 실제 실지급액이 갈리면 재무가 다른 숫자를 보고 지급한다');
    }

    public function test_deleting_a_row_gives_the_money_back(): void
    {
        $v = $this->vehicle();
        $row = $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED2KR', 'fee' => 5000]);
        $this->assertSame(5000, $v->fresh()->shipping_fee_total);

        $row->delete();
        $this->assertSame(0, $v->fresh()->shipping_fee_total);
        $this->assertNull($v->fresh()->ems_tracking_no_cache);
    }
}
