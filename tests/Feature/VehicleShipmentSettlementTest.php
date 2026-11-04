<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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

    /**
     * 🔒 닫힘 — `EMS 합 + DHL 합 ≡ 발송비 합`. 셋을 따로 갱신하면
     * 「합계는 맞는데 줄을 더하면 안 맞는」 화면이 된다(SKILLS §8 #64).
     */
    public function test_per_carrier_caches_always_add_up_to_the_total(): void
    {
        $v = $this->vehicle();
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED1KR', 'fee' => 14_780]);
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED2KR', 'fee' => 9_000]);
        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '4508', 'fee' => 74_028]);

        $v = $v->fresh();
        $this->assertSame(23_780, $v->ems_fee_total_cache);
        $this->assertSame(74_028, $v->dhl_fee_total_cache);
        $this->assertSame(
            $v->ems_fee_total_cache + $v->dhl_fee_total_cache,
            $v->shipping_fee_total_cache,
            '운송사별 합이 총합과 달라지면 화면이 스스로 모순된다'
        );

        // 하나 지워도 닫혀 있어야 한다.
        $v->shipments()->where('carrier', 'dhl')->first()->delete();
        $v = $v->fresh();
        $this->assertSame(0, $v->dhl_fee_total_cache);
        $this->assertSame($v->ems_fee_total_cache, $v->shipping_fee_total_cache);
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

    /** 🔀 2026-11-03 (jin) — 발송비는 회사:직원 5:5. 프리랜서 실지급에서 **직원 몫(절반)** 만 빠진다(구 08-31~11-02 = 전액). */
    public function test_freelancer_payout_drops_by_half_the_shipping_fee(): void
    {
        $v = $this->vehicle();
        $s = $this->settlement($v, 'ratio');

        $before = $s->actual_payout;
        $margin = $s->total_margin;

        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED1KR', 'fee' => 30_000]);
        $s = $s->fresh()->load('vehicle');

        $this->assertSame($margin, $s->total_margin, '총마진은 안 움직인다 — 비용 10칸이 아니다');
        $this->assertSame(30_000, $s->shipping_fee);
        $this->assertSame($before - 15_000, $s->actual_payout, '직원 몫 절반(15,000)만 — 전액(30,000)이면 구 규칙');
    }

    /**
     * 🔀 2026-11-01 (jin) — 사내직원(건당)은 **건당액을 안 건드린다.** 발송비는 건당 판정 마진(총마진 − 발송비)에서만 보고
     * 실지급에서 빼지 않는다 — 회사가 진다. 구(08-31) 「두 유형 전액 차감」은 손해차량에 발송비만 빠져 마이너스 지급을 만들었다
     * (heymanerp 실측 미지급 17건 전부, −9,579 등 4건 마이너스).
     */
    public function test_employee_payout_is_not_reduced_by_the_shipping_fee(): void
    {
        $v = $this->vehicle();
        $s = $this->settlement($v, 'per_unit');

        $before = $s->actual_payout;
        $this->assertGreaterThan(0, $before);
        $companyBefore = $s->company_net;

        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '74028', 'fee' => 30_000]);
        $s = $s->fresh()->load('vehicle');

        $this->assertSame($before, $s->actual_payout, '건당은 발송비로 안 깎인다');
        $this->assertSame(30_000, $s->shipping_fee, '발송비 자체는 기록된다(회사이익에서 뺀다)');
        $this->assertSame($companyBefore - 30_000, $s->company_net, '회사가 진다 — 회사이익이 그만큼 준다');
        $this->assertSame($s->total_margin - $s->actual_payout - 30_000, $s->company_net);
    }

    /** 손해차량(총마진 − 발송비 < 0): 건당 0 이고 실지급도 0 — 발송비만 빠진 마이너스(−30,000)가 나가면 안 된다. */
    public function test_employee_with_negative_margin_gets_zero_not_minus_shipping(): void
    {
        $v = $this->vehicle(['sale_price' => 9_000_000]);   // 총마진 −90,000
        $s = $this->settlement($v, 'per_unit');
        $this->assertLessThan(0, $s->total_margin);
        $this->assertSame(0, $s->actual_payout);

        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED2KR', 'fee' => 30_000]);
        $s = $s->fresh()->load('vehicle');

        $this->assertSame(0, $s->settlement_amount);
        $this->assertSame(0, $s->actual_payout, '손해차량에 발송비만 빠져 −30,000 이 나가던 것(구 규칙)');
    }

    /** 건당 차등은 「총마진 − 발송비」로 판정한다 — 발송비가 100만 경계를 넘기면 20만 → 10만. 승계 5만은 양수면 그대로. */
    public function test_employee_tier_is_judged_on_margin_minus_shipping(): void
    {
        $tierOn = Salesman::create(['name' => 'TIER', 'is_active' => true, 'type' => 'employee', 'per_unit_tier_enabled' => true]);
        $v = $this->vehicle(['sale_price' => 10_222_222]);   // 총마진 1,009,999 — 경계 바로 위
        $s = $this->settlement($v, 'per_unit', $tierOn);
        $this->assertGreaterThanOrEqual(1_000_000, $s->total_margin);
        $this->assertSame(200_000, $s->settlement_amount);

        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED3KR', 'fee' => 30_000]);
        $s = $s->fresh()->load('vehicle');

        $this->assertSame(100_000, $s->settlement_amount, '1,009,999 − 직원 몫 15,000 < 100만 → 10만');
        $this->assertSame(100_000, $s->actual_payout, '건당 그대로 — 발송비는 안 뺀다');
        $this->assertGreaterThanOrEqual(1_000_000, $s->total_margin, '총마진 자체는 안 움직인다(회사이익·카드가 쓴다)');
    }

    /** 🔀 2026-11-03 — 판정 마진은 「총마진 − 발송비 **직원 몫**」. 전액이면 경계 아래, 절반이면 위로 갈리는 차로 못 박는다. */
    public function test_employee_tier_uses_only_the_staff_half_of_shipping(): void
    {
        $tierOn = Salesman::create(['name' => 'TIER2', 'is_active' => true, 'type' => 'employee', 'per_unit_tier_enabled' => true]);
        $v = $this->vehicle(['sale_price' => 10_222_222]);   // 총마진 1,009,999
        $s = $this->settlement($v, 'per_unit', $tierOn);

        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED4KR', 'fee' => 18_000]);   // 직원 몫 9,000
        $s = $s->fresh()->load('vehicle');

        $this->assertSame(200_000, $s->settlement_amount, '1,009,999 − 9,000 ≥ 100만 → 20만 (전액 18,000 을 빼면 10만으로 떨어진다)');
        $this->assertSame(200_000, $s->actual_payout, '건당은 안 깎는다');
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

        $this->assertSame($before - 15_000, $s->company_net, '회사가 먼저 30,000 을 치르고 직원 몫 15,000 만 되받는다 — 회사 부담 절반(5:5)');
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

    /**
     * 🔀 2026-11-04 (jin «2차 정산이 완료된 건 금액 수정 불가, 텍스트는 수정 가능») — 서류는 3·6개월 뒤에도 나간다.
     * 일괄 기입: 마감 차량 = 번호·날짜만(금액 0) · 금액은 나머지 차량에 n/1(합 = 총액).
     */
    public function test_closed_vehicle_gets_number_only_and_the_fee_goes_to_the_others(): void
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
        $out = $svc->apply('ems', 'ED5KR', [$open->id, $closed->id], 10_000, '2026-11-04', $by, '테스트');

        $this->assertSame([], $plan['skipped']);
        $this->assertSame('text_only', $plan['targets'][1]['state']);
        $this->assertSame(2, $out['applied']);
        $this->assertSame(10_000, $open->fresh()->shipping_fee_total, '금액은 마감 안 된 차량이 전부 받는다');
        $this->assertSame(0, $closed->fresh()->shipping_fee_total);
        $this->assertSame('ED5KR', $closed->fresh()->ems_tracking_no_cache, '번호는 마감 차량에도 남는다');
        $this->assertTrue(AuditLog::where('auditable_id', $closed->id)->where('action', 'shipment_added')->exists(), '줄 추가가 감사로그에');
    }

    public function test_all_closed_with_a_fee_is_refused_before_writing(): void
    {
        $by = $this->admin();
        $this->actingAs($by);
        $closed = $this->vehicle();
        Settlement::create(['vehicle_id' => $closed->id, 'settlement_type' => 'ratio', 'settlement_status' => 'paid', 'secondary_status' => 'closed']);

        $svc = app(BulkVehicleShipmentService::class);
        $this->assertTrue($svc->plan('ems', 'ED6KR', [$closed->id], 5_000, $by)['no_fee_target'], '금액을 나눌 곳이 없다');
        $this->assertFalse($svc->plan('ems', 'ED6KR', [$closed->id], 0, $by)['no_fee_target'], '금액 0 이면 번호만 넣는다');
        $this->expectException(DomainException::class);
        $svc->apply('ems', 'ED6KR', [$closed->id], 5_000, null, $by, '테스트');
    }

    /** 개별 줄 — 금액은 잠기고, 번호·날짜는 고칠 수 있고 감사로그에 칸별로 남는다. 0원 줄은 추가된다. */
    public function test_closed_vehicle_locks_fee_but_not_text(): void
    {
        $by = $this->admin();
        $this->actingAs($by);
        $v = $this->vehicle();
        $row = $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED7KR', 'fee' => 3000, 'sent_date' => '2026-08-01']);
        Settlement::create(['vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_status' => 'paid', 'secondary_status' => 'closed']);

        $row->fresh()->update(['tracking_no' => 'ED8KR', 'sent_date' => '2026-11-04']);   // 텍스트 = 됨
        $this->assertSame('ED8KR', $row->fresh()->tracking_no);
        $cols = AuditLog::where('auditable_id', $v->id)->where('action', 'updated')->pluck('column_name')->all();
        $this->assertContains('shipment_tracking_no', $cols);
        $this->assertContains('shipment_sent_date', $cols);

        $v->shipments()->create(['carrier' => 'dhl', 'tracking_no' => '4508', 'fee' => 0]);   // 0원 줄 추가 = 됨

        try {
            $row->fresh()->update(['fee' => 9000]);   // 금액 = 막힘
            $this->fail('마감 차량 금액이 바뀌면 안 된다');
        } catch (DomainException) {
            $this->assertSame(3000, (int) $row->fresh()->fee);
        }
        $this->expectException(DomainException::class);
        $v->shipments()->create(['carrier' => 'ems', 'tracking_no' => 'ED9KR', 'fee' => 1000]);   // 금액 있는 새 줄 = 막힘
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

        $this->assertSame(15_000, $preview['shippingFee'], '미리보기 「− 발송비」 = 직원 몫(5:5)');
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
