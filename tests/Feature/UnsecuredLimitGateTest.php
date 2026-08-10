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
 * 무담보 한도 (jin 2026-08-10) — 담보(선적 전 국내 차량)가 없어도 이 금액까지 매입을 허용한다.
 *
 * 배경(운영 실측 heymanerp 2026-08-10): 기존 매입 게이트는 **선적 전 국내 차량**만 담보로 본다.
 * 그래서 차가 다 선적돼 국내에 0대면 게이지가 `null` 이라 락이 통째로 사라진다 —
 * AUTO DIOR 는 선적 전 0대인데 선적 후 미수가 3.19억이었다.
 *
 * 이 테스트가 지키는 것:
 *   ① 미설정 바이어는 **기존 동작 그대로**(운영 충격 0 — jin 확정)
 *   ② 설정 바이어는 국내 차 0대여도 한도만큼 매입되고, 소진되면 막힌다
 *   ③ 판매대금이 들어오면 기본 한도가 살아나 **자동 회복**된다
 *   ④ 화면 숫자(바이어 패널)와 게이트 판정이 **같은 값**을 쓴다
 */
class UnsecuredLimitGateTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function buyer(?int $limit = null): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->counter, 'is_active' => true]);

        return Buyer::create([
            'name' => 'B'.$this->counter, 'is_active' => true,
            'salesman_id' => $s->id, 'unsecured_limit_krw' => $limit,
        ]);
    }

    /** 매입 지급이 확정된 차 1대. $shippedOut 이면 출고까지 끝나 담보에서 빠진다. */
    private function vehicle(Buyer $b, int $salePrice, int $paidKrw, int $purchasePaid, bool $shippedOut = false): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'UL'.++$this->counter.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $b->salesman_id, 'buyer_id' => $b->id,
            'purchase_date' => '2026-08-01', 'purchase_price' => 10_000_000,
            'sale_price' => $salePrice, 'sale_date' => '2026-08-02',
            'warehouse_out_date' => $shippedOut ? '2026-08-05' : null,
        ]);
        if ($paidKrw > 0) {
            $v->finalPayments()->create([
                'amount' => $paidKrw, 'type' => 'balance', 'exchange_rate' => 1,
                'payment_date' => '2026-08-03', 'confirmed_at' => now(),
            ]);
        }
        if ($purchasePaid > 0) {
            $v->purchaseBalancePayments()->create([
                'amount' => $purchasePaid, 'payment_date' => '2026-08-03', 'confirmed_at' => now(),
            ]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    /** ① 미설정 바이어 — 국내 차 0대면 게이지가 null (기존 동작 유지). */
    public function test_buyer_without_limit_keeps_existing_behaviour(): void
    {
        $b = $this->buyer();                                  // 한도 미설정
        $this->vehicle($b, 5_000_000, 5_000_000, 0, true);    // 다 선적됨

        $this->assertFalse($b->hasUnsecuredLimit());
        $this->assertNull($b->receivableGauge(), '담보도 한도도 없으면 게이지 없음 = 기존 동작');
    }

    /** ② 설정 바이어 — 국내 차 0대여도 게이지가 생기고 한도만큼 여력이 있다. */
    public function test_limit_creates_gauge_even_with_no_domestic_vehicles(): void
    {
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 5_000_000, 5_000_000, 0, true);    // 다 선적됨 → 담보 0

        $g = $b->receivableGauge();

        $this->assertNotNull($g, '한도가 있으면 국내 차 0대여도 게이지를 만들어야 한다');
        $this->assertSame(0, $g['base_limit_krw'], '담보가 없으니 기본 한도는 0');
        $this->assertSame(5_000_000, $g['unsecured_limit_krw']);
        $this->assertSame(5_000_000, $g['limit_krw']);
        $this->assertSame(5_000_000, $g['unsecured_available_krw'], '한도 전액이 여력');
        $this->assertSame(0.0, $g['ratio'], '0 나누기 방어 — 담보 0이면 미수율은 0');
    }

    /** ② 담보가 없으면 매입 지급이 무담보분을 그대로 깎는다. */
    public function test_limit_is_consumed_by_purchase_payments(): void
    {
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 0, 0, 5_000_000);                  // 매입만 하고 아직 안 팔린 차 = 담보 0

        $g = $b->receivableGauge();

        $this->assertSame(5_000_000, $g['unsecured_used_krw']);
        $this->assertSame(0, $g['unsecured_available_krw'], '무담보분을 다 쓰면 잔액 0');
    }

    /**
     * 🚨 담보 한도 안에서 쓰는 동안은 무담보분이 **줄지 않는다**.
     *    "정말 마지막에 쓴다"의 핵심. 초기 구현은 총한도에서 총사용액을 빼는 바람에
     *    담보를 이미 넘긴 바이어가 「남은 여력 0」으로 보였다(실측 OSAKA MOTORS).
     */
    public function test_unsecured_is_untouched_while_collateral_covers_it(): void
    {
        $b = $this->buyer(5_000_000);
        // 판매 1,000만 전액 입금 → 담보 한도 500만. 매입 지급 300만은 그 안에서 해결된다.
        $this->vehicle($b, 10_000_000, 10_000_000, 3_000_000);

        $g = $b->receivableGauge();

        $this->assertSame(5_000_000, $g['base_limit_krw'], '입금액 1,000만 × 50%');
        $this->assertSame(0, $g['unsecured_used_krw'], '담보로 커버되는 동안 무담보분은 손대지 않는다');
        $this->assertSame(5_000_000, $g['unsecured_available_krw'], '설정한 금액을 그대로 쓸 수 있어야 한다');
    }

    /** ③ 보증금을 넘어선 만큼만 무담보에서 빠진다 — 미수율과 무관하게 **금액**으로만 판정. */
    public function test_only_the_excess_over_deposit_draws_down(): void
    {
        $b = $this->buyer(5_000_000);
        // 판매 1,000만 중 400만 입금 → 보증금 = 400만 × 50% = 200만.
        // 매입 지급 500만 → 보증금 200만을 쓰고 초과 300만이 무담보에서 빠진다.
        $this->vehicle($b, 10_000_000, 4_000_000, 5_000_000);

        $g = $b->receivableGauge();

        $this->assertSame(2_000_000, $g['base_limit_krw']);
        $this->assertSame(3_000_000, $g['unsecured_used_krw']);
        $this->assertSame(2_000_000, $g['unsecured_available_krw']);
    }

    /** 같은 금액이면 미수율이 달라도 결과가 같아야 한다 — 예측 가능성(jin: "헷갈린다"의 해소). */
    public function test_drawdown_does_not_depend_on_unpaid_ratio(): void
    {
        // 두 바이어 모두 보증금 200만(입금 400만) · 매입 지급 500만. 미수만 다르다.
        $high = $this->buyer(5_000_000);
        $this->vehicle($high, 10_000_000, 4_000_000, 5_000_000);   // 미수율 60%
        $low = $this->buyer(5_000_000);
        $this->vehicle($low, 4_400_000, 4_000_000, 5_000_000);     // 미수율 9%

        $gh = $high->receivableGauge();
        $gl = $low->receivableGauge();

        $this->assertGreaterThan($gl['ratio'], $gh['ratio'], '미수율은 서로 달라야 한다');
        $this->assertSame($gh['unsecured_used_krw'], $gl['unsecured_used_krw'],
            '미수율이 달라도 같은 금액이면 무담보 차감은 같아야 한다');
    }

    /** 선적 후 미수는 한도 계산에 들어가지 않는다(이번 범위 밖) — 표시만 된다. */
    public function test_shipped_unpaid_is_reported_but_not_deducted(): void
    {
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 30_000_000, 0, 0, true);           // 선적 후 미수 3,000만

        $g = $b->receivableGauge();

        $this->assertSame(30_000_000, $g['shipped_unpaid_krw']);
        $this->assertSame(5_000_000, $g['unsecured_available_krw'], '선적 후 미수가 한도를 깎으면 안 된다');
    }

    /**
     * 🚨 무담보를 설정하면 그 바이어는 **미수율이 아니라 금액**으로 관리된다.
     *    그래서 보증금을 이미 초과해 쓴 바이어는 설정 즉시 막힐 수 있다 — 의도된 동작이다
     *    (실측 OSAKA MOTORS: 보증금 5,473만인데 매입 지급 7,786만. 원래 막혔어야 할 상태였고
     *     미수율 게이트가 관대해서 통과하고 있었다). jin 2026-08-10 확정.
     */
    public function test_setting_a_limit_switches_the_buyer_to_amount_based_control(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);
        // 미수율은 10% 로 낮지만(기존 판정이면 통과), 매입 지급 1,000만이
        // 보증금 450만 + 무담보 500만 = 950만을 넘는다.
        $this->vehicle($b, 10_000_000, 9_000_000, 10_000_000);

        $g = $b->fresh()->receivableGauge();
        $this->assertLessThan(0.4, $g['ratio'], '미수율만 보면 통과하는 상태');
        $this->assertSame(0, $g['unsecured_available_krw'], '보증금·무담보를 모두 소진');

        $c = Volt::actingAs($admin)->test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', '99저1111')
            ->set('buyer_id_str', (string) $b->id)
            ->set('salesman_id_str', (string) $b->salesman_id)
            ->set('purchase_price_str', '1,000,000')
            ->call('save');

        $this->assertTrue($c->get('showPurchaseGate'),
            '무담보까지 소진되면 미수율이 낮아도 막혀야 한다 — 그게 마지막 방어선의 뜻이다');
    }

    /** ①·② 게이트 — 미설정은 통과, 설정+소진은 차단. */
    public function test_gate_blocks_only_when_limit_is_exhausted(): void
    {
        $admin = $this->admin();

        // 한도 미설정 + 다 선적됨 → 종전대로 통과
        $free = $this->buyer();
        $this->vehicle($free, 5_000_000, 5_000_000, 0, true);

        // 한도 설정 + 소진 → 차단
        $capped = $this->buyer(5_000_000);
        $this->vehicle($capped, 0, 0, 5_000_000);

        foreach ([[$free, false], [$capped, true]] as [$buyer, $shouldBlock]) {
            $c = Volt::actingAs($admin)->test('erp.vehicles.index')
                ->call('openCreate')
                ->set('vehicle_number', '99하'.random_int(1000, 9999))
                ->set('buyer_id_str', (string) $buyer->id)
                ->set('salesman_id_str', (string) $buyer->salesman_id)
                ->set('purchase_price_str', '1,000,000')
                ->call('save');

            $this->assertSame($shouldBlock, $c->get('showPurchaseGate'),
                $shouldBlock ? '한도 소진 바이어는 막혀야 한다' : '한도 미설정 바이어는 종전대로 통과해야 한다');
        }
    }

    /** 게이트 모달이 한도 모드로 뜨고 숫자를 담는다 — 미수율만 보이면 "미수 0인데 왜?"가 된다. */
    public function test_gate_modal_shows_limit_numbers_in_unsecured_mode(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 0, 0, 5_000_000);

        $info = Volt::actingAs($admin)->test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', '99하4321')
            ->set('buyer_id_str', (string) $b->id)
            ->set('salesman_id_str', (string) $b->salesman_id)
            ->set('purchase_price_str', '1,000,000')
            ->call('save')
            ->get('purchaseGateInfo');

        $this->assertTrue($info['unsecured_mode']);
        $this->assertSame(5_000_000, $info['unsecured_limit']);
        $this->assertSame(5_000_000, $info['unsecured_used']);
    }

    /** ④ 바이어 화면 숫자 = 게이트가 쓰는 숫자. 갈리면 "화면엔 여력이 있는데 막힌다"가 된다. */
    public function test_buyer_screen_and_gate_use_the_same_numbers(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 10_000_000, 4_000_000, 3_000_000);

        $screen = Volt::actingAs($admin)->test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->get('buyerReceivable');

        $gate = $b->fresh()->receivableGauge();

        foreach (['base_limit_krw', 'unsecured_limit_krw', 'unsecured_used_krw', 'unsecured_available_krw', 'used_krw'] as $k) {
            $this->assertSame($gate[$k], $screen[$k], "{$k} — 화면과 게이트가 갈렸다");
        }
    }

    /** 차량이 0대인데 한도만 있는 바이어도 목록 게이지에 나온다("패널엔 있는데 목록엔 없다" 방지). */
    public function test_list_gauge_includes_buyer_with_limit_but_no_vehicles(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);                          // 차량 0대

        $gauges = Volt::actingAs($admin)->test('erp.buyers.index')->get('receivableGauges');

        $this->assertArrayHasKey($b->id, $gauges);
        $this->assertSame(5_000_000, $gauges[$b->id]['unsecured_available_krw']);
    }

    /**
     * 🚨 컬럼을 제한해 조회한 Buyer 로 락을 판정하면 **조용히 0** 이 되어 락이 사라진다.
     *    같은 형태로 정산액이 20배 틀린 적이 있다(예외·경고 0). 그래서 큰 소리로 죽인다.
     */
    public function test_limit_check_throws_when_column_is_not_loaded(): void
    {
        $b = $this->buyer(5_000_000);
        $partial = Buyer::select('id', 'name')->find($b->id);

        $this->expectException(\LogicException::class);
        $partial->hasUnsecuredLimit();
    }

    /** 한도 변경은 감사로그에 남는다 — 누가 언제 얼마로 올렸는지가 감사 핵심. */
    public function test_limit_change_is_audited(): void
    {
        $admin = $this->admin();
        $b = $this->buyer();

        Volt::actingAs($admin)->test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->set('unsecured_limit_krw_str', '5,000,000')
            ->call('save');

        $this->assertSame(5_000_000, (int) $b->fresh()->unsecured_limit_krw);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $b->id,
            'column_name' => 'unsecured_limit_krw',
        ]);
    }
}
