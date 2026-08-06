<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 사내직원 정산 — 담당자별 tier on/off + 퇴사자 승계 바이어 5만원 (jin 2026-08-04).
 *
 * 우선순위: 총마진<0 → 0  >  승계 바이어 → 5만  >  tier OFF → 10만  >  tier ON → 기존 tier
 */
class SettlementTierPerSalesmanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Settlement::flushParamMemo();
    }

    private function salesman(bool $tier): Salesman
    {
        return Salesman::create([
            'name' => $tier ? 'tier켬' : 'tier끔', 'type' => 'employee',
            'per_unit_tier_enabled' => $tier, 'is_active' => true,
        ]);
    }

    /** 총마진이 목표치가 되도록 판매가를 잡은 차량. 총마진 = ((판매−매입) + 매입×0.09) × 0.9 */
    private function vehicle(string $no, int $purchase, int $sale, ?int $buyerId = null): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $no,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_price' => $purchase, 'selling_fee' => 0,
            'sale_price' => $sale, 'sale_date' => '2026-05-01', 'purchase_date' => '2026-04-01',
            'buyer_id' => $buyerId,
        ]);
    }

    private function settlement(Vehicle $v, Salesman $sm): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => null,
            'settlement_status' => 'pending',
        ]);
    }

    public function test_tier_off_salesman_always_gets_base_amount(): void
    {
        $off = $this->salesman(false);

        // 총마진 ≥ 100만 인데도 tier OFF 라 10만 (종전엔 20만이었다)
        $s = $this->settlement($this->vehicle('TIER-OFF-1', 20_000_000, 22_000_000), $off);
        $this->assertGreaterThanOrEqual(1_000_000, $s->total_margin);
        $this->assertSame(100_000, $s->effective_per_unit_amount);

        // 매입합계 1억 이상이어도 tier OFF 면 25% 안 탄다
        $s2 = $this->settlement($this->vehicle('TIER-OFF-2', 120_000_000, 200_000_000), $off);
        $this->assertSame(100_000, $s2->effective_per_unit_amount);
    }

    public function test_tier_on_salesman_keeps_existing_behaviour(): void
    {
        $on = $this->salesman(true);

        $s = $this->settlement($this->vehicle('TIER-ON-1', 20_000_000, 22_000_000), $on);
        $this->assertSame(200_000, $s->effective_per_unit_amount);

        // 매입 1억↑ → 총마진 × 25%
        $s2 = $this->settlement($this->vehicle('TIER-ON-2', 120_000_000, 200_000_000), $on);
        $this->assertSame(81_720_000, $s2->total_margin);
        $this->assertSame(20_430_000, $s2->effective_per_unit_amount);
    }

    public function test_loss_vehicle_is_zero_regardless_of_tier_or_inheritance(): void
    {
        $inherited = Buyer::create(['name' => '승계바이어-손해', 'is_inherited' => true]);

        // 판매가 < 매입가 → 총마진 음수
        foreach ([true, false] as $tier) {
            $sm = $this->salesman($tier);
            $v = $this->vehicle('LOSS-'.($tier ? 'ON' : 'OFF'), 30_000_000, 20_000_000, $inherited->id);
            $s = $this->settlement($v, $sm);
            $this->assertLessThan(0, $s->total_margin);
            $this->assertSame(0, $s->effective_per_unit_amount, '손해차량은 승계·tier 무관하게 0원');
        }
    }

    public function test_inherited_buyer_overrides_tier_and_high_threshold(): void
    {
        $on = $this->salesman(true);
        $inherited = Buyer::create(['name' => '승계바이어', 'is_inherited' => true]);
        $normal = Buyer::create(['name' => '일반바이어', 'is_inherited' => false]);

        // 승계 바이어 + 매입 1억↑ → 25%(2천만원대) 가 아니라 5만원 (jin: 승계 우선)
        $s = $this->settlement($this->vehicle('INH-1', 120_000_000, 200_000_000, $inherited->id), $on);
        $this->assertSame(50_000, $s->effective_per_unit_amount);

        // 같은 조건의 일반 바이어는 종전대로 25%
        $s2 = $this->settlement($this->vehicle('INH-2', 120_000_000, 200_000_000, $normal->id), $on);
        $this->assertSame(20_430_000, $s2->effective_per_unit_amount);
    }

    public function test_inherited_applies_to_tier_off_salesman_too(): void
    {
        $off = $this->salesman(false);
        $inherited = Buyer::create(['name' => '승계바이어2', 'is_inherited' => true]);

        $s = $this->settlement($this->vehicle('INH-3', 20_000_000, 22_000_000, $inherited->id), $off);
        $this->assertSame(50_000, $s->effective_per_unit_amount, 'tier OFF 여도 승계는 10만이 아니라 5만');
    }

    /**
     * 🚨 Buyer 는 SoftDeletes — 바이어가 삭제돼도 승계 5만원이 유지돼야 한다.
     * 일반 관계로 읽으면 null 이 되어 조용히 10만/tier 로 떨어진다(에러 없이 금액만 틀림).
     */
    public function test_inherited_survives_soft_deleted_buyer(): void
    {
        $on = $this->salesman(true);
        $inherited = Buyer::create(['name' => '삭제될승계바이어', 'is_inherited' => true]);
        $s = $this->settlement($this->vehicle('INH-DEL', 120_000_000, 200_000_000, $inherited->id), $on);
        $this->assertSame(50_000, $s->effective_per_unit_amount);

        $inherited->delete();
        $s->refresh();
        Settlement::flushParamMemo();   // 메모 히트로 우연히 통과하는 걸 막고 실제 재조회를 강제
        $this->assertSame(50_000, $s->effective_per_unit_amount, '바이어 soft delete 후에도 승계 5만원 유지');
    }

    /** 재무가 per_unit_amount 를 직접 넣으면 승계·tier 무엇보다 우선한다(최우선 override). */
    public function test_manual_per_unit_amount_wins_over_everything(): void
    {
        $on = $this->salesman(true);
        $inherited = Buyer::create(['name' => '승계바이어3', 'is_inherited' => true]);
        $v = $this->vehicle('MANUAL-1', 20_000_000, 22_000_000, $inherited->id);

        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $on->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 333_000,
            'settlement_status' => 'pending',
        ]);
        $this->assertSame(333_000, $s->effective_per_unit_amount);
    }

    /** 담당자 없는 정산(데이터 결손)은 보수적으로 tier OFF — 과다지급 방지. */
    public function test_settlement_without_salesman_falls_back_to_base_amount(): void
    {
        $v = $this->vehicle('NO-SM-1', 120_000_000, 200_000_000);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => null,
            'settlement_status' => 'pending',
        ]);
        $this->assertSame(100_000, $s->effective_per_unit_amount);
    }

    /** 승계 금액도 Setting 으로 조정 가능해야 한다(전 파라미터 동일 규약). */
    public function test_inherited_amount_is_setting_overridable(): void
    {
        Setting::create([
            'key' => 'settlement_employee_inherited_amount', 'value' => '70000', 'type' => 'integer',
        ]);
        Settlement::flushParamMemo();

        $on = $this->salesman(true);
        $inherited = Buyer::create(['name' => '승계바이어4', 'is_inherited' => true]);
        $s = $this->settlement($this->vehicle('INH-SET', 20_000_000, 22_000_000, $inherited->id), $on);
        $this->assertSame(70_000, $s->effective_per_unit_amount);
    }

    /**
     * 🚨 salesman 관계를 컬럼 제한해서 eager load 하면 tier 가 조용히 꺼진다 (jin 2026-08-06 실측).
     *
     * `with('salesman:id,name')` 은 per_unit_tier_enabled 를 안 실어서 `(bool) null` = false 가 되고,
     * 차등정산 담당자의 정산액이 **10만 고정**으로 계산된다. 실측 20,430,000 → 100,000 (20배 오차).
     * 예외도 경고도 없다 — 화면 합계만 조용히 틀린다.
     *
     * 정산액을 계산하는 쿼리에서는 salesman 컬럼을 제한하지 말 것.
     */
    public function test_constrained_salesman_eager_load_would_break_tier(): void
    {
        $on = $this->salesman(true);
        $v = $this->vehicle('EAGER-1', 120_000_000, 150_000_000);   // 매입합계 1억↑ → 고율 tier
        $s = $this->settlement($v, $on);

        $expected = Settlement::with(['vehicle', 'salesman'])->find($s->id)->settlement_amount;
        $this->assertGreaterThan(100_000, $expected, 'tier 가 실제로 적용되는 표본이어야 의미가 있다');

        // 정산관리 담당자 카드가 쓰는 경로 — 여기서 값이 갈리면 화면 합계가 틀린다.
        $viaSummary = Settlement::with(['vehicle', 'salesman'])->get()
            ->groupBy('salesman_id')->map(fn ($g) => (int) $g->sum('settlement_amount'))->first();

        $this->assertSame($expected, $viaSummary);
    }
}
