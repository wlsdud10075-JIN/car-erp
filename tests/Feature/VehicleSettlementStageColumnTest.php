<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량목록 「정산」 컬럼 (jin 2026-09-07) — 정산으로 빠졌는지를 행에서 바로 본다.
 *
 * 🚨 판정 기준이 「게이트 통과」가 아니라 **정산 행의 실재**다. 둘은 실제로 어긋나고
 *    (그 불일치를 정리하는 `ReconcileFreightGate` 명령이 따로 있다), 사람이 알고 싶은 것은
 *    「정산이 진짜 생겼나」이기 때문. 게이트는 「왜 아직 안 생겼나」를 설명하는 보조 상태다.
 *
 * ⚠️ jin 의 최초 설명(「판매완료 + FOB/CFR(운임비 있음)이면 빠진다」)과 코드는 다르다 —
 *    KRW 는 인코텀즈·운임비와 무관하게 통과하고, FOB 는 운임비가 0이어도 통과한다.
 *    운임비 조건이 붙는 것은 **CFR 뿐**이다. 그 셋을 각각 테스트로 박아 둔다.
 */
class VehicleSettlementStageColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    /**
     * 완납·담당자 있는 판매 차량 하나.
     *
     * ⚠️ `sale_unpaid_amount_krw_cache` 를 직접 넣어도 `Vehicle::saving` 훅이 다시 계산해 덮어쓴다 —
     *    완납 상태는 **확정 잔금을 실제로 넣어** 만든다. $paid=false 면 미완납으로 둔다.
     */
    private function soldVehicle(array $extra = [], bool $paid = true): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(array_merge([
            'vehicle_number' => '11가'.random_int(1000, 9999),
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
            'salesman_id' => $sm->id,
            'sale_price' => 10_000, 'sale_date' => now()->toDateString(),
        ], $extra));

        if ($paid && $v->sale_price > 0) {
            $v->finalPayments()->create([
                'amount' => $v->sale_total_amount, 'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
            ]);
            $v->refreshProgressCache();
            $v = $v->fresh();
        }

        return $v;
    }

    public function test_settled_when_settlement_is_paid(): void
    {
        $v = $this->soldVehicle(['incoterms' => 'FOB']);
        Settlement::create(['vehicle_id' => $v->id, 'settlement_type' => 'per_unit', 'settlement_status' => 'paid']);

        $this->assertSame(Vehicle::SETTLEMENT_STAGE_DONE, $v->fresh()->settlementStage());
    }

    public function test_waiting_while_settlement_is_not_paid_yet(): void
    {
        // 「정산됨」과 「정산대기」를 나눈 이유 — 행이 생긴 것과 지급까지 끝난 것은 다르다(jin).
        foreach (['pending', 'calculating', 'confirmed'] as $status) {
            $v = $this->soldVehicle(['incoterms' => 'FOB']);
            Settlement::create(['vehicle_id' => $v->id, 'settlement_type' => 'per_unit', 'settlement_status' => $status]);

            $this->assertSame(Vehicle::SETTLEMENT_STAGE_WAITING, $v->fresh()->settlementStage(), $status);
        }
    }

    public function test_freight_waiting_only_for_cfr_without_transport_fee(): void
    {
        // CFR + 운임비 0 → 게이트에 막힘. 인코텀즈 미입력(외화)도 같다.
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_FREIGHT,
            $this->soldVehicle(['incoterms' => 'CFR', 'transport_fee' => 0])->settlementStage());
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_FREIGHT,
            $this->soldVehicle(['incoterms' => null])->settlementStage());
    }

    public function test_gate_passes_for_krw_and_fob_and_cfr_with_fee(): void
    {
        // 🚨 jin 설명에 없던 두 갈래 — KRW 는 인코텀즈 무관, FOB 는 운임비 0이어도 통과.
        //    통과했는데 정산이 아직 없으면 「운임대기」가 아니라 '-' 다(막힌 게 아니므로).
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_NONE,
            $this->soldVehicle(['currency' => 'KRW', 'incoterms' => null])->settlementStage());
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_NONE,
            $this->soldVehicle(['incoterms' => 'FOB', 'transport_fee' => 0])->settlementStage());
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_NONE,
            $this->soldVehicle(['incoterms' => 'CFR', 'transport_fee' => 500])->settlementStage());
    }

    public function test_none_when_not_sold_or_unpaid_or_cancelled(): void
    {
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_NONE,
            $this->soldVehicle(['sale_price' => 0], paid: false)->settlementStage(), '미판매');
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_NONE,
            $this->soldVehicle(['incoterms' => null], paid: false)->settlementStage(), '미완납');
        // 환율 미입력이면 완납 판정 자체가 불가(cache NULL) — scopeAwaitingFreightConfirm 과 같은 취급.
        $this->assertSame(Vehicle::SETTLEMENT_STAGE_NONE,
            $this->soldVehicle(['incoterms' => null, 'exchange_rate' => 0], paid: false)->settlementStage(), '환율 미입력');
    }

    public function test_matches_the_dashboard_freight_queue(): void
    {
        // 대시보드 「인코텀즈 확정 필요」 카드와 같은 집합이어야 한다 — 조건을 옮겨 적으면 갈린다(§8 #44).
        $blocked = $this->soldVehicle(['incoterms' => 'CFR', 'transport_fee' => 0]);
        $this->soldVehicle(['incoterms' => 'FOB']);

        $queue = Vehicle::query()->action('freight_confirm_pending')->pluck('id')->all();
        $this->assertSame([$blocked->id], $queue);

        $flagged = Vehicle::all()->filter(fn (Vehicle $v) => $v->settlementStage() === Vehicle::SETTLEMENT_STAGE_FREIGHT)
            ->pluck('id')->values()->all();
        $this->assertSame($queue, $flagged, '컬럼의 「운임대기」와 대시보드 큐가 같은 집합이어야 한다');
    }

    public function test_list_loads_stage_without_per_row_queries(): void
    {
        // 서브쿼리 별칭이 빠지면 행마다 관계를 읽어 20행=20쿼리가 된다.
        $this->admin();
        for ($i = 0; $i < 3; $i++) {
            $v = $this->soldVehicle(['incoterms' => 'FOB', 'purchase_price' => 1_000_000, 'purchase_date' => now()->toDateString()]);
            Settlement::create(['vehicle_id' => $v->id, 'settlement_type' => 'per_unit', 'settlement_status' => 'paid']);
        }

        $rows = Volt::test('erp.vehicles.index')->instance()->vehicles;   // #[Computed] — viewData 로는 안 잡힌다
        $this->assertGreaterThan(0, count($rows));
        foreach ($rows as $v) {
            $this->assertArrayHasKey('settlement_status_peek', $v->getAttributes(),
                '목록 쿼리가 정산 상태를 서브쿼리로 함께 실어야 한다');
        }
    }

    public function test_column_renders_korean_labels_not_keys(): void
    {
        // 번역 키를 엉뚱한 그룹에 넣으면 화면에 키 문자열이 그대로 찍힌다(§8 #73).
        $this->admin();
        $v = $this->soldVehicle(['incoterms' => 'CFR', 'transport_fee' => 0,
            'purchase_price' => 1_000_000, 'purchase_date' => now()->toDateString()]);

        $html = Volt::test('erp.vehicles.index')->html();
        $this->assertStringNotContainsString('vehicle.col.settlement_stage', $html);
        $this->assertStringNotContainsString('vehicle.settlement_badge.', $html);
        $this->assertStringContainsString('운임대기', $html);
        $this->assertNotNull($v);
    }
}
