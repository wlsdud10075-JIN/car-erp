<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 💰 **채권 위험도 = 「받을 힘이 얼마나 남았나」** (jin 2026-09-14 전면 개편).
 *
 * 구 규칙은 **미수 비율만** 봤다(50/70% 경계). 그래서 **시간이 지나도 등급이 안 올라갔다** —
 * 실측 ssancarerp 「주의」에 173일짜리가 있고 「심각」에 10일짜리가 있었다.
 *
 * jin: *「진짜 심각한 건 배가 도착 전인데 돈을 안 받았거나 미수가 많은 거 아닐까?
 *        심각은 선적·통관 과정인데 60%에 맞지 않게 우회를 했다거나…
 *        주의는 판매중인데 기간이 좀 많이 지나서 미수가 있는 것들이고」*
 *
 * 새 판정(위에서부터 먼저 맞는 것):
 *   safe     미수 0
 *   critical B/L 넘어감 · 또는 배가 도착           ← 받을 수단이 사라졌다
 *   danger   떠났는데 게이트 미달 · 또는 안 나갔는데 판매 후 N일
 *   grace    안 나갔고 판매 유예일 이내
 *   caution  나머지
 *
 * 📊 개편 시 이동(배포 전 시뮬레이션): ssancarerp 심각 207 → 86 · 위험 23 → 39.
 */
class ReceivableRiskHybridTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** 미수가 남은 차 한 대. 기본은 「판매 30일 전 · 아직 안 떠남」. */
    private function vehicle(array $attrs = []): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '88아'.str_pad((string) (8000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'buyer_id' => $b->id, 'salesman_id' => $s->id,
            'sale_price' => 10_000_000,
            'sale_date' => now()->subDays(30)->toDateString(),
        ], $attrs))->fresh();
    }

    // ── ① 받을 수단이 사라진 것 = 심각 ──────────────────────────────

    /** 🚨 이번 개편의 핵심 — **배가 도착했는데 미수**. 구 규칙에선 비율이 낮으면 「주의」였다. */
    public function test_an_arrived_ship_with_unpaid_is_critical_even_at_a_small_ratio(): void
    {
        $v = $this->vehicle([
            'shipping_date' => now()->subDays(20)->toDateString(),
            'eta_date' => now()->subDay()->toDateString(),   // 어제 도착
        ]);
        // 미수를 아주 작게 만든다 — 구 규칙이라면 「주의」로 떨어질 비율.
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 9_500_000,
            'payment_date' => now()->subDays(10)->toDateString(), 'confirmed_at' => now()->subDays(10),
        ]);
        $v = $v->fresh();

        $this->assertGreaterThan(0, (int) $v->sale_unpaid_amount, '전제가 안 선다 — 미수가 남아야 한다');
        $this->assertLessThan(10, $v->sale_unpaid_amount / $v->sale_total_amount * 100,
            '전제가 안 선다 — 비율이 낮아야 구 규칙과 갈린다');

        $this->assertSame('critical', $v->receivable_risk_computed,
            '배가 도착했는데 미수인 차가 심각이 아니다 — 이 개편의 핵심 사례다');
    }

    /** B/L 을 넘겼으면 비율과 무관하게 심각. */
    public function test_a_released_bl_is_critical(): void
    {
        $v = $this->vehicle(['bl_document' => 'vehicles/bl.pdf']);

        $this->assertSame('critical', $v->receivable_risk_computed);
    }

    /** ⚓ **운항 중은 아직 심각이 아니다** — 도착해야 지렛대가 사라진다. */
    public function test_a_ship_still_in_transit_is_not_critical(): void
    {
        $v = $this->vehicle([
            'shipping_date' => now()->subDays(5)->toDateString(),
            'eta_date' => now()->addDays(20)->toDateString(),
        ]);

        $this->assertNotSame('critical', $v->receivable_risk_computed,
            '아직 운항 중인데 심각이다 — 도착 전에는 B/L 을 쥐고 있어 받을 힘이 남아 있다');
    }

    /** ⚠️ ETA 가 없으면 「도착 안 함」이다 — 모르는 것을 위험으로 올리면 전 차량이 심각이 된다. */
    public function test_a_missing_eta_is_not_treated_as_arrived(): void
    {
        $v = $this->vehicle(['shipping_date' => now()->subDays(40)->toDateString()]);

        $this->assertNotSame('critical', $v->receivable_risk_computed,
            'ETA 가 비었는데 도착으로 봤다 — ETA 를 안 적는 회사에서 전 차량이 심각이 된다');
    }

    // ── ② 게이트 미달로 나간 차 = 위험 ─────────────────────────────

    /** 떠났는데 입금이 게이트에 못 미치면 위험 — jin 「60%에 맞지 않게 우회를 했다」. */
    public function test_a_departed_car_below_the_shipping_gate_is_danger(): void
    {
        $v = $this->vehicle([
            'shipping_date' => now()->subDays(3)->toDateString(),
            'eta_date' => now()->addDays(30)->toDateString(),
        ]);   // 입금 0 = 게이트 한참 미달

        $this->assertTrue($v->isDeparted(), '전제가 안 선다 — 떠난 차여야 한다');
        $this->assertSame('danger', $v->receivable_risk_computed);
    }

    /** 게이트를 넘겼으면 떠났어도 주의다. */
    public function test_a_departed_car_above_the_gate_is_only_caution(): void
    {
        $v = $this->vehicle([
            'shipping_date' => now()->subDays(3)->toDateString(),
            'eta_date' => now()->addDays(30)->toDateString(),
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 9_000_000,
            'payment_date' => now()->subDays(2)->toDateString(), 'confirmed_at' => now()->subDays(2),
        ]);

        $this->assertSame('caution', $v->fresh()->receivable_risk_computed);
    }

    /** 🔑 임계는 **게이트 설정을 따라간다** — 게이트를 바꾸면 등급도 같이 움직여야 한다. */
    public function test_the_gate_threshold_is_not_hardcoded(): void
    {
        $v = $this->vehicle([
            'shipping_date' => now()->subDays(3)->toDateString(),
            'eta_date' => now()->addDays(30)->toDateString(),
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 7_000_000,
            'payment_date' => now()->subDays(2)->toDateString(), 'confirmed_at' => now()->subDays(2),
        ]);   // 입금 70%

        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "lock_threshold_shipping_entry_{$set}"], ['value' => '60', 'type' => 'string']);
        $this->assertSame('caution', $v->fresh()->receivable_risk_computed, '70% 입금은 60% 게이트를 넘는다');

        Setting::updateOrCreate(['key' => "lock_threshold_shipping_entry_{$set}"], ['value' => '80', 'type' => 'string']);
        $this->assertSame('danger', $v->fresh()->receivable_risk_computed,
            '게이트를 80% 로 올렸는데 70% 입금 차가 그대로다 — 임계가 박혀 있다');
    }

    /**
     * 🔑 **바이어에게 승인된 완화는 「우회」가 아니다** — 그 바이어 임계를 존중한다.
     *
     * 처음엔 전역값을 쓰려 했는데 기존 가드(`BuyerLockThresholdTest::no code bypasses the resolver`)가
     * 잡았고 그게 옳다. jin 이 말한 「60%에 맞지 않게 **우회**」는 승인 없이 나간 차다 —
     * 완화를 받아 나간 차까지 위험으로 올리면 억울하게 빨개진다.
     */
    public function test_a_buyer_level_threshold_is_respected(): void
    {
        $v = $this->vehicle([
            'shipping_date' => now()->subDays(3)->toDateString(),
            'eta_date' => now()->addDays(30)->toDateString(),
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 4_000_000,
            'payment_date' => now()->subDays(2)->toDateString(), 'confirmed_at' => now()->subDays(2),
        ]);   // 입금 40% — 전역 60% 게이트엔 미달

        $this->assertSame('danger', $v->fresh()->receivable_risk_computed, '전제가 안 선다');

        // 이 바이어만 30% 로 완화 — 승인된 조건이므로 「우회」가 아니다.
        $v->buyer->update(['lock_shipping_entry_pct' => 30]);

        $this->assertSame('caution', $v->fresh()->receivable_risk_computed,
            '바이어별 완화를 무시하고 전역값으로 판정했다 — 승인받고 나간 차가 위험이 된다');
    }

    /** ⚠️ 재계산 명령이 buyer 를 eager load 하는지 — 빼면 수천 대 N+1 이 된다. */
    public function test_the_rebuild_command_eager_loads_the_buyer(): void
    {
        $src = file_get_contents(base_path('app/Console/Commands/RebuildVehicleCaches.php'));

        $this->assertStringContainsString("'buyer'", $src, implode("\n", [
            '재계산 명령이 buyer 를 eager load 하지 않는다.',
            '위험도가 바이어별 게이트 임계를 읽으므로 차량마다 바이어를 조회한다(4,700대 = N+1).',
        ]));
    }

    // ── ③ 안 나갔는데 오래된 것 = 위험 ─────────────────────────────

    /** 🕰️ jin 「판매중인데 기간이 좀 많이 지나서 미수가 있는 것들」 — 오래되면 올라간다. */
    public function test_an_undeparted_car_becomes_danger_once_stale(): void
    {
        $days = Setting::receivableStaleDays();

        $fresh = $this->vehicle(['sale_date' => now()->subDays($days - 1)->toDateString()]);
        $stale = $this->vehicle(['sale_date' => now()->subDays($days)->toDateString()]);

        $this->assertSame('caution', $fresh->receivable_risk_computed, '아직 하루 이른 차가 벌써 위험이다');
        $this->assertSame('danger', $stale->receivable_risk_computed,
            "판매 후 {$days}일이 지났는데 아직 주의다 — 시간이 등급을 안 올린다(구 규칙의 문제)");
    }

    /** 일수는 설정을 따라간다 — 화면에서 조정할 값이다(§8 #60). */
    public function test_the_stale_threshold_is_configurable(): void
    {
        $v = $this->vehicle(['sale_date' => now()->subDays(45)->toDateString()]);
        $this->assertSame('caution', $v->receivable_risk_computed, '기본 90일에서 45일차는 주의다');

        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "receivable_stale_days_{$set}"], ['value' => '30', 'type' => 'string']);

        $this->assertSame(30, Setting::receivableStaleDays(), '설정을 안 읽는다');
        $this->assertSame('danger', $v->fresh()->receivable_risk_computed,
            '기준을 30일로 낮췄는데 45일 된 차가 그대로다');
    }

    // ── ④ 유예·완납 ────────────────────────────────────────────────

    /** 판매 직후는 그대로 결제대기다 — 이 개편이 유예를 깨면 안 된다. */
    public function test_a_just_sold_car_is_still_grace(): void
    {
        $v = $this->vehicle(['sale_date' => now()->subDays(2)->toDateString()]);

        $this->assertSame('grace', $v->receivable_risk_computed);
    }

    /** 완납은 안전 — 카드에선 내렸지만 값은 살아 있어야 한다(다른 소비자가 쓴다). */
    public function test_a_fully_paid_car_is_safe(): void
    {
        $v = $this->vehicle();
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10_000_000,
            'payment_date' => now()->subDays(5)->toDateString(), 'confirmed_at' => now()->subDays(5),
        ]);

        $this->assertSame('safe', $v->fresh()->receivable_risk_computed);
    }

    // ── 구조 ───────────────────────────────────────────────────────

    /**
     * 🚫 **비율 경계(50/70)가 되살아나면 실패한다.** 그게 이번에 없앤 규칙이다.
     * 되돌아가도 화면은 정상 렌더되고 등급만 조용히 달라지므로 정적으로 막는다.
     */
    public function test_the_old_ratio_buckets_are_gone(): void
    {
        $src = file_get_contents(base_path('app/Models/Vehicle.php'));
        $start = strpos($src, 'function getReceivableRiskComputedAttribute(');
        $body = substr($src, $start, 2600);

        foreach (['$ratio <= 50', '$ratio <= 70'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, implode("\n", [
                '구 비율 경계(50/70%)가 되살아났다.',
                '2026-09-14 개편으로 등급은 「진행 단계 × 기간 × 게이트」로 바뀌었다.',
                '비율만 보면 173일짜리가 「주의」에 묻힌다(실측).',
            ]));
        }
    }

    /**
     * ⚠️ **`isShippingEntryMet()` 을 여기서 부르면 안 된다** — 그건 `sale_unpaid_amount_krw_cache` 를
     * 읽는데 `Vehicle::saving` 이 그 캐시를 위험도 **다음 줄에서** 갱신한다(옛 값을 본다).
     * 게다가 `$this->buyer` 를 타서 재계산 명령(수천 대)에서 N+1 이 된다.
     */
    public function test_the_risk_accessor_does_not_call_the_gate_helper(): void
    {
        $src = file_get_contents(base_path('app/Models/Vehicle.php'));
        $start = strpos($src, 'function getReceivableRiskComputedAttribute(');
        $body = substr($src, $start, 2600);

        $this->assertStringNotContainsString('isShippingEntryMet(', $body, implode("\n", [
            '위험도 계산이 isShippingEntryMet() 을 부른다.',
            '그건 미수 KRW 캐시를 읽는데 saving 훅이 그 캐시를 이 다음 줄에서 갱신한다 — 옛 값을 본다.',
            '그리고 buyer 를 타서 재계산 명령에서 N+1 이 된다. 비율은 여기서 직접 만들 것.',
        ]));
    }

    /** 🔗 「도착」 판정은 운항 스코프와 같은 출처여야 한다 — 갈리면 pill 과 등급이 다른 답을 한다. */
    public function test_arrival_matches_the_sailing_status(): void
    {
        $arrived = $this->vehicle([
            'shipping_date' => now()->subDays(10)->toDateString(),
            'eta_date' => now()->subDay()->toDateString(),
        ]);
        $transit = $this->vehicle([
            'shipping_date' => now()->subDays(10)->toDateString(),
            'eta_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertTrue($arrived->hasArrived());
        $this->assertFalse($transit->hasArrived());

        $this->assertTrue(Vehicle::query()->sailing('arrived')->whereKey($arrived->id)->exists(),
            'hasArrived() 와 scopeSailing(arrived) 이 갈린다');
        $this->assertTrue(Vehicle::query()->sailing('in_transit')->whereKey($transit->id)->exists());
    }
}
