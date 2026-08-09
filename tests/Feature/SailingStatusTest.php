<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 운항 상태 (jin 2026-08-09) — 진행상태와 **직교하는 표시 전용 축**.
 *
 * 이 테스트가 지키는 것은 세 가지다:
 *   ① accessor 와 scope 가 같은 답을 낸다 (갈리면 pill 카운트와 목록이 어긋난다 — SKILLS §8 #45 형태)
 *   ② 운항중 + 도착 + 미항해 = 전체 (단계 목록 드리프트 감지)
 *   ③ 🚨 **`progress_status_cache` 를 건드리지 않는다** — 이게 깨지면 정산 자동생성과 재고 판정이
 *      동시에 죽는다(§8 #43 raw update 로 훅 미발동 / 출고일 미입력 차의 재고 복귀).
 */
class SailingStatusTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function makeVehicle(array $attrs = []): Vehicle
    {
        $salesman = Salesman::create(['name' => 'S'.++$this->counter, 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'B'.$this->counter, 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '99가'.str_pad((string) $this->counter, 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'salesman_id' => $salesman->id,
            'buyer_id' => $buyer->id,
            'purchase_price' => 1_000_000,
            'sale_price' => 5_000,
            'sale_date' => now()->subMonths(2)->toDateString(),
            'currency' => 'EUR',
            'exchange_rate' => 1500,
        ], $attrs));
    }

    public function test_sailing_status_needs_both_shipping_date_and_eta(): void
    {
        $this->assertNull($this->makeVehicle()->sailing_status, '둘 다 없으면 판정 없음');

        $this->assertNull(
            $this->makeVehicle(['shipping_date' => now()->subDays(10)->toDateString()])->sailing_status,
            'ETA 없이 선적일만으로는 판정하지 않는다'
        );

        $this->assertNull(
            $this->makeVehicle(['eta_date' => now()->addDays(10)->toDateString()])->sailing_status,
            '선적일 없이 ETA만으로는 판정하지 않는다'
        );
    }

    public function test_eta_future_is_in_transit_and_past_is_arrived(): void
    {
        $sailing = $this->makeVehicle([
            'shipping_date' => now()->subDays(10)->toDateString(),
            'eta_date' => now()->addDays(10)->toDateString(),
        ]);
        $arrived = $this->makeVehicle([
            'shipping_date' => now()->subDays(60)->toDateString(),
            'eta_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $sailing->sailing_status);
        $this->assertSame(Vehicle::SAILING_ARRIVED, $arrived->sailing_status);
    }

    /** 오늘이 ETA면 도착으로 본다(경계). */
    public function test_eta_today_counts_as_arrived(): void
    {
        $v = $this->makeVehicle([
            'shipping_date' => now()->subDays(20)->toDateString(),
            'eta_date' => now()->toDateString(),
        ]);

        $this->assertSame(Vehicle::SAILING_ARRIVED, $v->sailing_status);
        $this->assertTrue(Vehicle::query()->sailing('arrived')->whereKey($v->id)->exists());
        $this->assertFalse(Vehicle::query()->sailing('in_transit')->whereKey($v->id)->exists());
    }

    /** ① accessor 와 scope 가 같은 집합을 낸다. */
    public function test_scope_and_accessor_agree(): void
    {
        $this->makeVehicle(['shipping_date' => now()->subDay()->toDateString(), 'eta_date' => now()->addDays(30)->toDateString()]);
        $this->makeVehicle(['shipping_date' => now()->subDays(90)->toDateString(), 'eta_date' => now()->subDays(30)->toDateString()]);
        $this->makeVehicle(['shipping_date' => now()->subDays(3)->toDateString()]);   // ETA 없음
        $this->makeVehicle();                                                          // 둘 다 없음

        foreach (['in_transit' => Vehicle::SAILING_IN_TRANSIT, 'arrived' => Vehicle::SAILING_ARRIVED] as $phase => $label) {
            $byScope = Vehicle::query()->sailing($phase)->pluck('id')->sort()->values()->all();
            $byAccessor = Vehicle::all()
                ->filter(fn (Vehicle $v) => $v->sailing_status === $label)
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($byAccessor, $byScope, "{$phase} — scope 와 accessor 가 갈렸다");
        }
    }

    /** ② 운항중 + 도착 + 미항해 = 전체. */
    public function test_phases_partition_the_whole_set(): void
    {
        $this->makeVehicle(['shipping_date' => now()->subDay()->toDateString(), 'eta_date' => now()->addDays(5)->toDateString()]);
        $this->makeVehicle(['shipping_date' => now()->subDays(50)->toDateString(), 'eta_date' => now()->subDay()->toDateString()]);
        $this->makeVehicle(['shipping_date' => now()->subDays(2)->toDateString()]);
        $this->makeVehicle();

        $inTransit = Vehicle::query()->sailing('in_transit')->count();
        $arrived = Vehicle::query()->sailing('arrived')->count();
        $neither = Vehicle::query()
            ->where(fn ($q) => $q->whereNull('shipping_date')->orWhereNull('eta_date'))
            ->count();

        $this->assertSame(Vehicle::count(), $inTransit + $arrived + $neither);
    }

    /**
     * ③ 🚨 회귀 방지 — 운항 상태는 진행상태 캐시를 **바꾸지 않는다**.
     *
     * 이게 깨지면(= cascade 에 들어가면) 두 가지가 동시에 죽는다:
     *   - 정산 자동생성: '거래완료' 진입을 wasChanged 로 감지하는데, 시간 경과 전이는 raw update 라 훅이 안 뜬다
     *   - 재고 판정: 거래완료가 아니게 되면 출고일 미입력 차가 재고로 복귀한다
     */
    public function test_sailing_does_not_touch_progress_status_cache(): void
    {
        $v = $this->makeVehicle([
            'bl_document' => 'bl/test.pdf',                                 // → 거래완료
            'shipping_date' => now()->subDays(5)->toDateString(),
            'eta_date' => now()->addDays(20)->toDateString(),                // → 운항중
        ]);

        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $v->sailing_status);
        $this->assertSame('거래완료', $v->fresh()->progress_status_cache, '운항중이어도 진행상태는 거래완료 그대로여야 한다');

        // 캐시 재계산을 돌려도 마찬가지.
        $v->refreshCaches();
        $this->assertSame('거래완료', $v->fresh()->progress_status_cache);
    }

    /**
     * ③-2 🚨 정산 자동생성 3경로가 운항 상태에 영향받지 않는다.
     *
     * 트리거는 ①완납(FinalPayment::saved) ②거래완료 진입 ③인코텀즈·운임비 확정 세 곳이고,
     * 셋 다 `createSettlementIfComplete` 로 모인다. 운항 상태는 그중 어느 입력도 쓰지 않는다.
     * 여기서는 ③(운임 게이트)을 운항중 차량으로 태워서 정상 생성되는지 본다 —
     * CFR 은 운임비가 있어야 게이트를 통과한다(`isFreightConfirmedForSettlement`).
     */
    public function test_freight_gate_still_creates_settlement_while_sailing(): void
    {
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($user);

        $v = $this->makeVehicle([
            'sale_price' => 5_000,
            'currency' => 'EUR',
            'exchange_rate' => 1_500,
            'incoterms' => 'CFR',
            'transport_fee' => 0,                                        // 운임 미확정 → 게이트 대기
            'shipping_date' => now()->subDays(3)->toDateString(),
            'eta_date' => now()->addDays(25)->toDateString(),             // 운항중
        ]);
        // 완납시킨다(확정 잔금). 운임비까지 미리 받아둔다 —
        // ⚠️ 운임비는 sale_total_amount(미수 분모)에 들어가므로, 나중에 기입하면 그만큼 미수가 새로 생겨
        //    완납이 깨진다(SKILLS §13). 실무도 운임을 포함해 받고 금액칸을 뒤에 채우는 흐름.
        $expectedFreight = 300;
        $v->finalPayments()->create([
            'amount' => $v->sale_total_amount + $expectedFreight, 'type' => 'balance',
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
            'exchange_rate' => 1_500,
        ]);
        $v->refresh();

        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $v->sailing_status);
        $this->assertFalse($v->isFreightConfirmedForSettlement(), 'CFR + 운임 0 이면 게이트 대기');
        $this->assertSame(0, $v->settlements()->count(), '운임 미확정이면 아직 정산 없음');

        // 운임비를 넣으면 그 저장 시점에 정산이 생긴다 — 운항중이어도 마찬가지.
        $v->update(['transport_fee' => $expectedFreight]);

        $this->assertSame(1, $v->fresh()->settlements()->count(), '운항중이어도 운임 확정 시 정산이 생성돼야 한다');
        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $v->fresh()->sailing_status);
    }

    /** 진행상태와 직교 — 운항중 차량이 여러 진행단계에 걸쳐 있어도 전부 잡힌다. */
    public function test_sailing_spans_multiple_progress_stages(): void
    {
        $shipping = ['shipping_date' => now()->subDays(4)->toDateString(), 'eta_date' => now()->addDays(15)->toDateString()];

        $this->makeVehicle($shipping + ['bl_loading_location' => '평택']);                          // 선적중
        $this->makeVehicle($shipping + ['bl_loading_location' => '인천', 'is_export_cleared' => true]); // 통관중
        $this->makeVehicle($shipping + ['bl_document' => 'bl/x.pdf']);                              // 거래완료

        $stages = Vehicle::query()->sailing('in_transit')->pluck('progress_status_cache')->unique();

        $this->assertCount(3, $stages, '운항중은 진행단계를 가로질러야 한다');
        $this->assertSame(3, Vehicle::query()->sailing('in_transit')->count());
    }

    /** 화면 필터가 scope 와 같은 집합을 낸다 + 카운트 정합. */
    public function test_vehicle_list_filter_matches_scope(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        $this->makeVehicle(['shipping_date' => now()->subDay()->toDateString(), 'eta_date' => now()->addDays(9)->toDateString()]);
        $this->makeVehicle(['shipping_date' => now()->subDays(80)->toDateString(), 'eta_date' => now()->subDays(9)->toDateString()]);
        $this->makeVehicle();

        $sailingPlate = Vehicle::query()->sailing('in_transit')->value('vehicle_number');
        $otherPlate = Vehicle::query()->sailing('arrived')->value('vehicle_number');
        $this->assertSame(1, Vehicle::query()->sailing('in_transit')->count());

        $component = Volt::actingAs($admin)->test('erp.vehicles.index')
            ->call('toggleSailing', 'in_transit')
            ->assertSet('sailingFilter', 'in_transit')
            ->assertSee($sailingPlate)
            ->assertDontSee($otherPlate);

        // pill 카운트는 운항 필터를 뺀 조건 기준이라, 필터가 켜져 있어도 도착 건수가 그대로 보인다.
        $this->assertSame(1, $component->instance()->sailingCounts['arrived']);

        // 같은 걸 다시 누르면 해제된다.
        $component->call('toggleSailing', 'in_transit')
            ->assertSet('sailingFilter', '')
            ->assertSee($otherPlate);
    }
}
