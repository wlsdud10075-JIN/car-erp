<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 무담보 한도 (jin 2026-08-10) — 국내에 차가 없어 보증금을 못 쓰는 단골이
 * **새 차 계약금을 걸 때** 쓰는 한도.
 *
 * 규칙 (jin 확정):
 *   - 묶이는 것은 **계약금뿐**이다. 매입 잔금·매도비에는 쓰지 않는다.
 *   - **보증금과는 독립**이다. 보증금이 남아 있어도 계약금은 무담보에서 빠진다
 *     (보증금 초과분 기준으로 하면 차값 전체가 초과분에 들어가 500만짜리 한도가 한 번에 날아간다).
 *   - **선적 진입 조건(판매금 N% 입금)을 넘는 순간 그 차 몫이 풀린다** — 계속 묶여 있으면 안 되니까.
 *   - 무담보까지 0이면 신규 차량 등록이 막힌다. 미설정 바이어는 종전 미수율 판정 그대로.
 *
 * 배경(운영 실측 heymanerp): 기존 매입 락은 선적 전 국내 차량만 담보로 봐서, 차가 다 선적되면
 * 게이지가 null 이라 락이 통째로 사라졌다. AUTO DIOR 는 선적전 0대인데 선적 후 미수 3.19억.
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

    /**
     * @param  int  $down  확정 계약금 (무담보가 묶이는 유일한 대상)
     * @param  int  $balance  확정 매입 잔금 (무담보와 무관해야 한다)
     */
    private function vehicle(
        Buyer $b, int $salePrice, int $paidKrw, int $down = 0, int $balance = 0,
        bool $shippedOut = false, bool $unsecuredDown = true
    ): Vehicle {
        $v = Vehicle::create([
            'vehicle_number' => 'UL'.++$this->counter.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $b->salesman_id, 'buyer_id' => $b->id,
            'purchase_date' => '2026-08-01', 'purchase_price' => 20_000_000,
            'sale_price' => $salePrice, 'sale_date' => '2026-08-02',
            'warehouse_out_date' => $shippedOut ? '2026-08-05' : null,
            'is_unsecured_down' => $unsecuredDown,
        ]);
        if ($paidKrw > 0) {
            $v->finalPayments()->create([
                'amount' => $paidKrw, 'type' => 'balance', 'exchange_rate' => 1,
                'payment_date' => '2026-08-03', 'confirmed_at' => now(),
            ]);
        }
        foreach ([['down', $down], ['balance', $balance]] as [$type, $amt]) {
            if ($amt > 0) {
                $v->purchaseBalancePayments()->create([
                    'amount' => $amt, 'type' => $type,
                    'payment_date' => '2026-08-03', 'confirmed_at' => now(),
                ]);
            }
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    /** 미설정 바이어 — 국내 차 0대면 게이지가 null (기존 동작 유지, 운영 충격 0). */
    public function test_buyer_without_limit_keeps_existing_behaviour(): void
    {
        $b = $this->buyer();
        $this->vehicle($b, 5_000_000, 5_000_000, 0, 0, true);

        $this->assertFalse($b->hasUnsecuredLimit());
        $this->assertNull($b->receivableGauge(), '보증금도 한도도 없으면 게이지 없음 = 기존 동작');
    }

    /** 설정 바이어 — 국내 차 0대여도 게이지가 생기고 한도 전액이 여력이다. */
    public function test_limit_creates_gauge_even_with_no_domestic_vehicles(): void
    {
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 5_000_000, 5_000_000, 0, 0, true);

        $g = $b->receivableGauge();

        $this->assertNotNull($g, '한도가 있으면 국내 차 0대여도 게이지를 만들어야 한다');
        $this->assertSame(0, $g['base_limit_krw'], '국내 차가 없으니 보증금은 0');
        $this->assertSame(5_000_000, $g['unsecured_available_krw']);
        $this->assertSame(0.0, $g['ratio'], '0 나누기 방어');
    }

    /** 🚨 계약금만 묶인다 — 매입 잔금은 무담보를 건드리지 않는다. */
    public function test_only_the_down_payment_locks_the_credit(): void
    {
        $b = $this->buyer(5_000_000);
        // 판매 없음(=보증금 0). 계약금 200만 + 매입 잔금 900만.
        $this->vehicle($b, 0, 0, down: 2_000_000, balance: 9_000_000);

        $g = $b->receivableGauge();

        $this->assertSame(2_000_000, $g['locked_down_payment_krw'], '계약금만 잡혀야 한다');
        $this->assertSame(2_000_000, $g['unsecured_used_krw']);
        $this->assertSame(3_000_000, $g['unsecured_available_krw'],
            '매입 잔금 900만은 무담보와 무관하다');
    }

    /** 🚨 선적 진입 조건을 넘으면 그 차의 계약금이 풀린다 — 계속 묶여 있으면 안 된다. */
    public function test_deposit_is_released_once_shipping_entry_is_met(): void
    {
        $b = $this->buyer(5_000_000);
        $need = Setting::lockRequiredPaidPct('shipping_entry');   // 기본 50, 운영 60

        // 판매 1,000만 / 계약금 300만. 입금이 필요 비율에 **미달**이면 묶여 있어야 한다.
        $short = (int) (10_000_000 * ($need - 10) / 100);
        $v = $this->vehicle($b, 10_000_000, $short, down: 3_000_000);

        $this->assertSame(3_000_000, $b->receivableGauge()['locked_down_payment_krw'],
            '선적 진입 전이면 계약금이 묶여 있어야 한다');

        // 필요 비율을 넘겨 입금하면 그 차 몫이 풀린다.
        $v->finalPayments()->create([
            'amount' => 10_000_000 - $short, 'type' => 'balance', 'exchange_rate' => 1,
            'payment_date' => '2026-08-04', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        $g = $b->fresh()->receivableGauge();
        $this->assertSame(0, $g['locked_down_payment_krw'], '선적 진입 조건을 넘으면 풀려야 한다');
        $this->assertSame(5_000_000, $g['unsecured_available_krw'], '무담보가 전액 복구돼야 한다');
    }

    /**
     * 🚨 무담보는 **보증금과 독립**이다 — 보증금이 남아 있어도 계약금은 무담보에서 빠진다.
     *
     * 보증금 초과분을 기준으로 삼으면 **차값 전체가 초과분에 들어가** 500만짜리 한도가
     * 한 번에 날아간다. 계약금은 운영 실측상 86%가 100만원 이하(78건 중 67건)라
     * 규모가 근본적으로 다르다 — 섞으면 이 기능이 의미를 잃는다(jin 2026-08-10).
     */
    public function test_credit_is_independent_of_the_deposit(): void
    {
        $b = $this->buyer(5_000_000);
        $need = Setting::lockRequiredPaidPct('shipping_entry');
        $short = (int) (10_000_000 * ($need - 10) / 100);   // 선적 진입 미달 → 계약금 묶임

        // 보증금이 계약금보다 훨씬 크지만, 계약금은 그대로 무담보에서 빠진다.
        $this->vehicle($b, 10_000_000, $short, down: 1_000_000);

        $g = $b->receivableGauge();

        $this->assertGreaterThan(1_000_000, $g['base_limit_krw'], '보증금이 계약금보다 큰 전제');
        $this->assertSame(1_000_000, $g['unsecured_used_krw'], '보증금과 무관하게 계약금만큼 빠진다');
        $this->assertSame(4_000_000, $g['unsecured_available_krw']);
    }

    /** 매입 잔금은 아무리 커도 무담보를 건드리지 않는다. */
    public function test_balance_payments_never_touch_the_credit(): void
    {
        $b = $this->buyer(5_000_000);
        // 계약금 50만 + 매입 잔금 1,500만(차값). 무담보는 계약금만 본다.
        $this->vehicle($b, 0, 0, down: 500_000, balance: 15_000_000);

        $g = $b->receivableGauge();

        $this->assertSame(500_000, $g['unsecured_used_krw'], '잔금 1,500만은 무담보와 무관');
        $this->assertSame(4_500_000, $g['unsecured_available_krw']);
    }

    /** 계약금이 한도를 넘어도 사용량은 한도까지만(음수 여력 없음). */
    public function test_usage_is_capped_at_the_limit(): void
    {
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 0, 0, down: 8_000_000);

        $g = $b->receivableGauge();

        $this->assertSame(5_000_000, $g['unsecured_used_krw']);
        $this->assertSame(0, $g['unsecured_available_krw']);
    }

    /**
     * 🚨 체크하지 않은 계약금은 무담보를 건드리지 않는다.
     *
     * 계약금 행만으로는 그 돈이 바이어가 보낸 것인지 회사가 대신 낸 것인지 알 수 없다.
     * 무담보는 회사가 대신 내준 몫을 담는 주머니라 사람이 명시한 것만 센다
     * (jin: "이거 실제로 50만원이 누구 돈일 줄 알고?").
     */
    public function test_unchecked_down_payment_does_not_touch_the_credit(): void
    {
        $b = $this->buyer(5_000_000);
        // 바이어가 직접 보낸 돈으로 낸 계약금 → 체크 안 함.
        $this->vehicle($b, 0, 0, down: 500_000, unsecuredDown: false);

        $g = $b->receivableGauge();

        $this->assertSame(0, $g['locked_down_payment_krw'], '체크 안 한 계약금은 안 잡힌다');
        $this->assertSame(5_000_000, $g['unsecured_available_krw'], '무담보는 그대로여야 한다');
    }

    /**
     * 🚨 회사 돈이 나가 있는 동안에는 체크를 **풀 수 없다** (jin 2026-08-10 제보).
     *
     * 계약금 기록은 그대로인데 체크만 끄면 한도가 돌아와 락을 그냥 우회할 수 있다.
     * 푸는 방법은 하나 — 판매대금을 받아 선적 진입 조건을 넘기는 것(그때 자동으로 풀린다).
     */
    public function test_flag_cannot_be_unticked_while_money_is_out(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);
        $v = $this->vehicle($b, 10_000_000, 0, down: 500_000);

        Volt::actingAs($admin)->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_unsecured_down', false)
            ->call('save');

        $this->assertTrue((bool) $v->fresh()->is_unsecured_down, '해제가 거부돼야 한다');
        $this->assertSame(4_500_000, $b->fresh()->receivableGauge()['unsecured_available_krw'],
            '한도가 돌아오면 안 된다');
    }

    /** 선적 진입 조건을 넘긴 뒤에는 체크를 풀 수 있다(이미 회수된 돈이므로). */
    public function test_flag_can_be_unticked_after_shipping_entry(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);
        $need = Setting::lockRequiredPaidPct('shipping_entry');
        $v = $this->vehicle($b, 10_000_000, (int) (10_000_000 * ($need + 10) / 100), down: 500_000);

        $this->assertTrue($v->isShippingEntryMet(), '선적 진입을 넘긴 전제');

        Volt::actingAs($admin)->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_unsecured_down', false)
            ->call('save');

        $this->assertFalse((bool) $v->fresh()->is_unsecured_down, '회수된 뒤에는 해제된다');
    }

    /** 게이지의 해제 판정과 차량 헬퍼가 같은 답을 낸다 — 갈리면 "화면은 풀렸는데 저장은 막힌다". */
    public function test_release_check_is_single_source(): void
    {
        $b = $this->buyer(5_000_000);
        $need = Setting::lockRequiredPaidPct('shipping_entry');

        $met = $this->vehicle($b, 10_000_000, (int) (10_000_000 * ($need + 10) / 100), down: 300_000);
        $notMet = $this->vehicle($b, 10_000_000, (int) (10_000_000 * ($need - 20) / 100), down: 400_000);

        $this->assertTrue($met->isShippingEntryMet());
        $this->assertFalse($notMet->isShippingEntryMet());
        // 게이지는 헬퍼를 그대로 쓰므로, 묶인 계약금은 미충족 차량 것만이어야 한다.
        $this->assertSame(400_000, $b->fresh()->receivableGauge()['locked_down_payment_krw']);
    }

    /** 선적 후 미수는 한도 계산에 안 들어간다 — 표시만 된다. */
    public function test_shipped_unpaid_is_reported_but_not_deducted(): void
    {
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 30_000_000, 0, down: 2_000_000, shippedOut: true);

        $g = $b->receivableGauge();

        $this->assertSame(30_000_000, $g['shipped_unpaid_krw']);
        $this->assertSame(5_000_000, $g['unsecured_available_krw'],
            '출고된 차는 계약금도 무담보를 안 묶는다(이미 나간 차)');
    }

    /** 게이트 — 미설정은 통과, 설정+소진은 차단. */
    public function test_gate_blocks_only_when_credit_is_exhausted(): void
    {
        $admin = $this->admin();

        $free = $this->buyer();                                  // 미설정 + 다 선적 → 통과
        $this->vehicle($free, 5_000_000, 5_000_000, 0, 0, true);

        $capped = $this->buyer(5_000_000);                       // 계약금으로 한도 소진
        $this->vehicle($capped, 0, 0, down: 5_000_000);

        foreach ([[$free, false], [$capped, true]] as [$buyer, $shouldBlock]) {
            $c = Volt::actingAs($admin)->test('erp.vehicles.index')
                ->call('openCreate')
                ->set('vehicle_number', '99하'.random_int(1000, 9999))
                ->set('buyer_id_str', (string) $buyer->id)
                ->set('salesman_id_str', (string) $buyer->salesman_id)
                ->set('purchase_price_str', '1,000,000')
                ->call('save');

            $this->assertSame($shouldBlock, $c->get('showPurchaseGate'),
                $shouldBlock ? '무담보 소진 바이어는 막혀야 한다' : '미설정 바이어는 종전대로 통과');
        }
    }

    /** 게이트 모달이 한도 모드 숫자를 담는다 — 미수율만 보이면 "미수 0인데 왜?"가 된다. */
    public function test_gate_modal_shows_limit_numbers(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 0, 0, down: 5_000_000);

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

    /** 바이어 화면 숫자 = 게이트가 쓰는 숫자. 갈리면 "화면엔 여력이 있는데 막힌다"가 된다. */
    public function test_buyer_screen_and_gate_use_the_same_numbers(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);
        $this->vehicle($b, 10_000_000, 4_000_000, down: 3_000_000);

        $screen = Volt::actingAs($admin)->test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->get('buyerReceivable');
        $gate = $b->fresh()->receivableGauge();

        foreach (['base_limit_krw', 'unsecured_limit_krw', 'locked_down_payment_krw',
            'unsecured_used_krw', 'unsecured_available_krw'] as $k) {
            $this->assertSame($gate[$k], $screen[$k], "{$k} — 화면과 게이트가 갈렸다");
        }
    }

    /** 차량이 0대인데 한도만 있는 바이어도 목록 게이지에 나온다. */
    public function test_list_gauge_includes_buyer_with_limit_but_no_vehicles(): void
    {
        $admin = $this->admin();
        $b = $this->buyer(5_000_000);

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

    /**
     * 한도 변경은 감사로그에 남는다 — 누가 언제 얼마로 올렸는지가 감사 핵심.
     *
     * ⚠️ 2026-08-21 부터 **super 전용**이다(구: [관리] 이상). 한도를 넣으면 그 바이어의 매입 판정이
     *    미수율 → 금액으로 통째로 바뀌므로 락 % 와 같은 무게로 다룬다. 실무자가 자기가 막히면
     *    자기가 푸는 걸 막는 게 목적이라, 여기서 admin 을 쓰면 저장이 무시된다(권한 자체는
     *    `BuyerLockAdminOnlyTest` 가 별도로 검증).
     */
    public function test_limit_change_is_audited(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $b = $this->buyer();

        Volt::actingAs($super)->test('erp.buyers.index')
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
