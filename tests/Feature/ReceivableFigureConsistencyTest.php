<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkDailySummary;
use App\Console\Commands\AlimtalkWeeklySummary;
use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권 수치 정합 — 채권관리 · 관리자 대시보드 · 대표 알림톡이 **같은 숫자**를 말한다 (jin 2026-08-20).
 *
 * 사고: 세 곳의 기본 기간이 없음 / 2개월 / 3개월 로 제각각이었고, 자르는 컬럼마저 **매입일** 이었다.
 *   ⇒ 대표가 아침에 「선적전 23건 2.50억」 알림톡을 받고 본인 대시보드를 열면 「9건 1.74억」 이 보였다(실측 heymanerp).
 *   ⇒ jin: "아무리 조회해도 그 수치가 안 나왔거든."
 * 게다가 오래 못 받은 돈일수록 매입일이 옛날이라 **화면에서 먼저 사라졌다** — 정확히 반대로 동작했다.
 *
 * ⚠️ 이 부류는 기능 테스트로 못 잡힌다 — 세 화면 다 정상 렌더되고 숫자만 다르다. 그래서 값을 직접 맞대본다.
 */
class ReceivableFigureConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 매입일이 오래된 미수 차량 1대. 기간 필터가 살아 있으면 화면에서 사라진다. */
    private function oldPurchaseUnpaid(string $number, int $salePrice, int $paid, ?string $outDate = null): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B-'.$number, 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $number,
            'sales_channel' => 'export',
            'sale_price' => $salePrice,
            'sale_date' => now()->subMonths(10)->toDateString(),
            'purchase_date' => now()->subMonths(10)->toDateString(),   // ← 기본 기간(2~3개월) 밖
            'buyer_id' => $buyer->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'warehouse_out_date' => $outDate,
        ]);
        if ($paid > 0) {
            $v->finalPayments()->create([
                'amount' => $paid, 'type' => 'balance',
                'payment_date' => now()->subMonths(9)->toDateString(), 'confirmed_at' => now(),
            ]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    /**
     * 🔒 핵심 가드 — 10개월 전 매입한 미수 차량이 세 곳 모두에서 보인다.
     * 어느 한 곳이라도 기간 기본값을 되살리면 여기서 깨진다.
     */
    public function test_old_purchase_unpaid_vehicle_appears_in_all_three_places(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('11가1111', 10_000_000, 0);                                  // 선적전
        $this->oldPurchaseUnpaid('22가2222', 20_000_000, 0, now()->subMonths(8)->toDateString()); // 선적후

        // ① 채권관리 — 목록·탭 카운트
        $c = Volt::test('erp.receivables.index');
        $counts = $c->instance()->classificationCounts;
        $this->assertSame(2, $counts['all'], '채권관리가 옛 매입 차량을 기간으로 잘라냈다');
        $this->assertSame(1, $counts['before_shipping']);
        $this->assertSame(1, $counts['after_shipping']);

        // ② 관리자 대시보드
        $cls = Volt::test('admin.dashboard')->instance()->receivableKpis()['classification'];
        $this->assertSame(1, $cls['before_shipping']['count'], '대시보드가 옛 매입 차량을 기간으로 잘라냈다');
        $this->assertSame(10_000_000, $cls['before_shipping']['unpaid']);
        $this->assertSame(1, $cls['after_shipping']['count']);
        $this->assertSame(20_000_000, $cls['after_shipping']['unpaid']);

        // ③ 대표 알림톡 — 세 값이 글자 그대로 같아야 한다
        $vars = AlimtalkDailySummary::buildVars();
        $this->assertSame('1', $vars['선적전건수']);
        $this->assertSame('1', $vars['선적후건수']);
        $this->assertStringStartsWith('10,000,000원', $vars['선적전금액']);
        $this->assertStringStartsWith('20,000,000원', $vars['선적후금액']);
    }

    /**
     * 🐛 탭 숫자와 목록이 어긋나던 버그 — 탭 카운트만 필터를 안 탔다.
     * (탭엔 「선적전 23」, 눌러 들어가면 20건. 실측 heymanerp)
     */
    public function test_tab_count_matches_the_list_it_opens(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('33가3333', 10_000_000, 0);
        $this->oldPurchaseUnpaid('44가4444', 10_000_000, 0);

        // 사용자가 기간을 직접 좁히면 탭 숫자도 같이 줄어야 한다.
        $c = Volt::test('erp.receivables.index')
            ->set('dateFrom', now()->subMonths(1)->format('Y-m-d'))
            ->set('dateTo', now()->format('Y-m-d'));

        $counts = $c->instance()->classificationCounts;
        $listed = $c->instance()->vehicles()->total();

        $this->assertSame(0, $counts['all'], '기간을 좁혔는데 탭 숫자가 안 따라갔다');
        $this->assertSame($listed, $counts['all'], '탭 숫자와 목록 건수가 다르다');
    }

    /**
     * 🚨 완납을 목록에서 뺐다고 **미수율 분모에서까지 빼면 안 된다**.
     * 빼면 17.1% 가 49.5% 로 튄다 — 대표 알림톡이 이상했던 바로 그 계산이 화면에도 생긴다.
     */
    public function test_paid_up_vehicles_stay_in_the_ratio_denominator(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('55가5555', 10_000_000, 0);            // 미수 1,000만
        $this->oldPurchaseUnpaid('66가6666', 90_000_000, 90_000_000);   // 완납 9,000만

        $s = Volt::test('erp.receivables.index')->instance()->summary();

        $this->assertSame(100_000_000, $s['total_sale_krw'], '완납 차가 분모에서 빠졌다');
        $this->assertSame(10_000_000, $s['total_unpaid_krw']);
        $this->assertSame(10.0, $s['unpaid_ratio_pct'], '완납을 빼면 100% 가 되어 버린다');
        $this->assertSame(2, $s['sold_count']);
        $this->assertSame(1, $s['unpaid_count']);
    }

    /** 탭을 바꿔도 KPI 는 안 흔들린다 — "어느 화면에서 보든 같은 숫자" 의 근거. */
    public function test_kpi_does_not_follow_the_selected_tab(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('77가7777', 10_000_000, 0);
        $this->oldPurchaseUnpaid('88가8888', 90_000_000, 90_000_000);

        $all = Volt::test('erp.receivables.index')->instance()->summary();
        $tab = Volt::test('erp.receivables.index')->set('classification', 'before_shipping')->instance()->summary();

        $this->assertSame($all['total_sale_krw'], $tab['total_sale_krw']);
        $this->assertSame($all['unpaid_ratio_pct'], $tab['unpaid_ratio_pct']);
    }

    /** 「채권 전체」 = 결제대기 + 선적전 + 선적후. 화면에 적어 둔 등식이 실제로 성립해야 한다. */
    public function test_all_tab_equals_grace_plus_before_plus_after(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('91가1111', 10_000_000, 0);                                   // 선적전
        $this->oldPurchaseUnpaid('92가2222', 10_000_000, 0, now()->subDays(3)->toDateString()); // 선적후
        $this->oldPurchaseUnpaid('93가3333', 30_000_000, 30_000_000);                           // 완납(제외)

        // 결제대기 = 출고 전 + 판매일이 유예일 이내
        $g = $this->oldPurchaseUnpaid('94가4444', 10_000_000, 0);
        $g->update(['sale_date' => now()->toDateString()]);
        $g->refreshCaches();

        $c = Volt::test('erp.receivables.index')->instance()->classificationCounts;

        $this->assertSame(1, $c['grace'], '결제대기 탭이 유예 차량을 못 잡았다');
        $this->assertSame(
            $c['all'],
            $c['grace'] + $c['before_shipping'] + $c['after_shipping'],
            '채권 전체가 세 탭의 합과 다르다 — 화면의 등식이 거짓말이 된다'
        );
        $this->assertSame(1, $c['paid_up'], '완납이 별도 탭으로 안 빠졌다');
    }

    /** 초과입금(미수 음수)은 완납에 묻히지 말고 드러나야 한다 — 돌려줘야 할 돈. */
    public function test_overpaid_vehicles_are_surfaced(): void
    {
        $this->actingAs($this->admin());
        $v = $this->oldPurchaseUnpaid('95가5555', 10_000_000, 12_000_000);   // 200만 초과입금

        $c = Volt::test('erp.receivables.index');
        $s = $c->instance()->summary();

        $this->assertSame(1, $s['overpaid_count']);
        $this->assertSame(2_000_000, $s['overpaid_krw']);
        $c->assertSee('초과입금');

        // 총미수는 초과분에 상계되지 않는다 — 상계하면 탭 합계와 어긋난다.
        $this->assertSame(0, $s['total_unpaid_krw']);

        // 초과입금 차는 완납 탭에서 열람할 수 있어야 한다(회수이력·환불 처리 진입점).
        $this->assertLessThan(0, (int) $v->fresh()->sale_unpaid_amount_krw_cache);
        $this->assertSame(1, $c->instance()->classificationCounts['paid_up']);
    }

    /**
     * 알림톡 % = **미수 총액 대비 구성비**이고, 그 두 % 가 화면에도 있어야 한다 (jin 2026-08-20).
     * jin: "미수금 얼마 중 선적전이 어느 비중이고, 선적후가 어느 비중이다. 이렇게 가야 이해할 수 있을 것 같은데"
     */
    public function test_alimtalk_share_is_a_split_of_the_unpaid_total_and_appears_on_screen(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('81가1111', 10_000_000, 0);                                   // 선적전 1,000만
        $this->oldPurchaseUnpaid('82가2222', 30_000_000, 0, now()->subMonths(8)->toDateString()); // 선적후 3,000만
        $this->oldPurchaseUnpaid('83가3333', 60_000_000, 60_000_000);                          // 완납(비중에 영향 없어야)

        // 미수 4,000만 중 선적전 25% / 선적후 75%
        $vars = AlimtalkDailySummary::buildVars();
        $this->assertStringContainsString('(25%)', $vars['선적전금액']);
        $this->assertStringContainsString('(75%)', $vars['선적후금액']);

        // 합계엔 % 를 붙이지 않는다 — 구성비의 합은 늘 100% 라 정보가 0 이고, 다른 분모를 쓰면 또 섞인다.
        $this->assertSame('40,000,000원', $vars['미수합계']);

        // 같은 두 % 가 채권관리 화면에 있어야 대조가 된다.
        $c = Volt::test('erp.receivables.index');
        $s = $c->instance()->summary();
        $this->assertSame(25.0, $s['before_share_pct']);
        $this->assertSame(75.0, $s['after_share_pct']);
        $c->assertSee('선적전 25% · 선적후 75%');

        // 관리자 대시보드 카드에도 같은 값.
        $cls = Volt::test('admin.dashboard')->instance()->receivableKpis()['classification'];
        $this->assertSame(25.0, $cls['before_shipping']['share_pct']);
        $this->assertSame(75.0, $cls['after_shipping']['share_pct']);
    }

    /** 주간요약이 일일요약과 같은 규칙을 써야 한다 — 한쪽만 고치면 금·월 숫자가 어긋난다. */
    public function test_weekly_summary_uses_the_same_share_rule(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('84가4444', 10_000_000, 0);
        $this->oldPurchaseUnpaid('85가5555', 30_000_000, 0, now()->subMonths(8)->toDateString());

        $daily = AlimtalkDailySummary::buildVars();
        $weekly = AlimtalkWeeklySummary::buildVars();

        $this->assertSame($daily['선적전금액'], $weekly['선적전금액']);
        $this->assertSame($daily['선적후금액'], $weekly['선적후금액']);
    }

    /** 미납률(미수 차량끼리) 은 미수율(완납 포함) 과 다른 값이며 둘 다 화면에 있어야 한다. */
    public function test_default_rate_and_unpaid_rate_are_both_shown(): void
    {
        $this->actingAs($this->admin());
        $this->oldPurchaseUnpaid('86가6666', 10_000_000, 4_000_000);   // 미수 600만 / 그 차 판매 1,000만
        $this->oldPurchaseUnpaid('87가7777', 90_000_000, 90_000_000);  // 완납

        $c = Volt::test('erp.receivables.index');
        $s = $c->instance()->summary();

        $this->assertSame(6.0, $s['unpaid_ratio_pct'], '미수율 = 600만 / 1억(완납 포함)');
        $this->assertSame(60.0, $s['default_ratio_pct'], '미납률 = 600만 / 1,000만(미수 차량끼리)');
        $c->assertSee('미납률 60');
    }
}
