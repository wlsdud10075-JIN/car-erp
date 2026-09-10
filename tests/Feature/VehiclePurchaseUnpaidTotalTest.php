<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💸 차량관리 — 진행상태 옆 「매입중」 병기 + 「미지급 총액」 지표 (jin 2026-09-10).
 *
 * 제보: 「판매중이라 나와도 매입 잔금이 남아 있으면 매입중이 같이 떴으면 좋겠다」 +
 *      「미지급 총액이 판매총액 합 옆에 보였으면 좋겠다」.
 *
 * 🧭 **요청 그대로 만들면 안 되는 곳이 한 군데 있었다.** jin 은 「매입중 진행상태를 눌렀을 때」
 *    총액이 보이길 원했지만, 미지급 차의 대부분은 진행상태가 **판매중·선적완료·거래완료**라
 *    그 pill 에 안 잡힌다(실측 heymanerp 30대 중 매입중 4대뿐 = 13%). 그래서 총액 자체를
 *    클릭 필터로 만들었다. 이 테스트의 중심은 **「합계 = 클릭 결과」** 불변식이다(SKILLS §9).
 */
class VehiclePurchaseUnpaidTotalTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    /** @param  array<string,mixed>  $attrs */
    private function vehicle(Buyer $buyer, array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '11가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'currency' => 'EUR',
            'exchange_rate' => 1400,
            'dhl_request' => false,
            'salesman_id' => $buyer->salesman_id,
            'buyer_id' => $buyer->id,
            'purchase_date' => '2026-09-01',
            'purchase_price' => 10_000_000,
        ], $attrs));
    }

    /** 확정 매입 잔금 — 미지급을 줄이는 유일한 경로(payment_date 도래 + confirmed_at, SKILLS §13). */
    private function payPurchase(Vehicle $v, int $amount): void
    {
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => $amount,
            'payment_date' => '2026-09-02', 'confirmed_at' => now(),
        ]);
        $v->refresh();
    }

    /**
     * ⚠️ 차량관리 화면은 렌더 1회가 무겁다(blade 706KB) — **`set()` 을 붙이지 말 것.**
     *    `set()` 마다 전체 리렌더라 호출 하나에 몇 초씩 붙는다(실측: set 2개 = 테스트 하나 19.7초).
     *    날짜 필터는 `dateType` 기본이 'all' 이라 안 걸리므로 비울 필요도 없다.
     */
    private function screen()
    {
        return Volt::actingAs($this->admin())->test('erp.vehicles.index');
    }

    // ── ① 진행상태 옆 「매입중」 병기 ─────────────────────────────

    /** 판매중인데 매입 잔금이 남았으면 「매입중」이 같이 뜬다 — 이 요청의 본체. */
    public function test_sold_vehicle_with_unpaid_purchase_also_shows_maeipjung(): void
    {
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer, [
            'sale_price' => 20_000_000, 'sale_date' => '2026-09-05',
        ]);
        $v->refresh();
        $this->assertSame('판매중', $v->progress_status_cache, '전제: 진행상태가 판매중이어야 한다');
        $this->assertGreaterThan(0, $v->purchase_unpaid_amount, '전제: 매입 미지급이 남아야 한다');

        $html = $this->screen()->html();

        // ⚠️ 「매입중」 글자만 세면 안 된다 — 진행상태 **필터 pill 스트립**에도 10단계 이름이 전부
        //    렌더되므로 항상 걸린다. 병기 뱃지는 호버 문구(title)로만 식별된다.
        $badge = __('vehicle.purchase_unpaid_badge_title', ['amount' => number_format($v->purchase_unpaid_amount)]);
        $this->assertStringContainsString(__('domain.progress.판매중'), $html);
        $this->assertStringContainsString($badge, $html,
            '판매중인데 매입 잔금이 남았는데 「매입중」 병기가 안 뜬다');
    }

    /** 매입을 전액 지급하면 그 병기가 사라진다 — 안 사라지면 거짓 신호가 남는다. */
    public function test_badge_disappears_once_the_purchase_is_fully_paid(): void
    {
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer, ['sale_price' => 20_000_000, 'sale_date' => '2026-09-05']);
        $this->payPurchase($v, 10_000_000);
        $this->assertSame(0, $v->purchase_unpaid_amount, '전제: 완납이어야 한다');

        $html = $this->screen()->html();

        $this->assertStringNotContainsString(__('vehicle.purchase_unpaid_badge_title', ['amount' => '10,000,000']), $html,
            '완납인데 「매입중」 병기가 아직 붙어 있다');
        // 진행상태 필터 pill 은 그대로 있어야 한다 — 뱃지만 사라지는 것이 맞다.
        $this->assertStringContainsString(__('domain.progress.매입중'), $html);
    }

    /**
     * 진행상태가 이미 「매입중」이면 병기하지 않는다 — 같은 말이 두 번 되면 뜻이 흐려진다.
     * (판매 전 차량은 v4 cascade 상 매입중이다.)
     */
    public function test_no_duplicate_badge_when_the_status_is_already_maeipjung(): void
    {
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer);   // 판매 없음 → 매입중
        $v->refresh();
        $this->assertSame('매입중', $v->progress_status_cache);

        $html = $this->screen()->html();

        // 진행상태가 이미 매입중이므로 **병기 뱃지는 0개**여야 한다(그 title 이 안 나와야 한다).
        $this->assertStringNotContainsString(
            __('vehicle.purchase_unpaid_badge_title', ['amount' => number_format($v->purchase_unpaid_amount)]),
            $html,
            '진행상태가 이미 「매입중」인데 병기까지 붙어 같은 말이 두 번 됐다'
        );
    }

    // ── ② 미지급 총액 지표 ───────────────────────────────────────

    /**
     * 🔑 **합계 = 클릭 결과.** 숫자를 눌러 나온 차들의 미지급 합이 그 숫자와 같아야 한다.
     *    갈리면 「보이는 숫자를 눌렀는데 다른 차가 나오는」 화면이 된다(SKILLS §9 불변식).
     */
    public function test_the_total_equals_the_sum_of_what_the_click_filter_shows(): void
    {
        $buyer = $this->buyer();
        $unpaidA = $this->vehicle($buyer, ['sale_price' => 20_000_000, 'sale_date' => '2026-09-05']);
        $unpaidB = $this->vehicle($buyer, ['purchase_price' => 7_000_000]);
        $paid = $this->vehicle($buyer, ['purchase_price' => 5_000_000]);
        $this->payPurchase($paid, 5_000_000);

        $c = $this->screen();
        $total = $c->instance()->purchaseUnpaidTotals();

        $this->assertSame(17_000_000, $total['total'], '미지급 합이 틀렸다');
        $this->assertSame(2, $total['cnt'], '대수가 틀렸다 — 완납 차가 섞였다');

        // 눌러서 나온 집합이 정확히 그 두 대여야 한다.
        $c->call('toggleUnpaidFilter');
        $ids = collect($c->instance()->vehicles->items())->pluck('id')->sort()->values()->all();
        $this->assertSame([$unpaidA->id, $unpaidB->id], $ids,
            '합계를 만든 차량과 클릭 결과가 다르다');

        // 한 번 더 누르면 해제된다 — 끌 방법이 없으면 필터에 갇힌다.
        $c->call('toggleUnpaidFilter');
        $this->assertSame('', $c->instance()->action);
        $this->assertCount(3, $c->instance()->vehicles->items(), '해제됐는데 전체가 안 돌아온다');
    }

    /**
     * 거래완료 차량의 미지급도 센다 — 기존 `purchase_unpaid`(할일 큐)는 이걸 뺀다.
     * 실측 heymanerp 거래완료 2대·1,480만원: 큐 기준으로 합치면 그만큼 빈다.
     */
    public function test_completed_vehicles_are_counted_in_the_total(): void
    {
        $buyer = $this->buyer();
        $done = $this->vehicle($buyer, [
            'sale_price' => 20_000_000, 'sale_date' => '2026-09-05',
            'bl_document' => 'docs/bl.pdf',   // v4 cascade → 거래완료
        ]);
        $done->refresh();
        $this->assertSame('거래완료', $done->progress_status_cache, '전제: 거래완료여야 한다');

        $this->assertSame(10_000_000, $this->screen()->instance()->purchaseUnpaidTotals()['total'],
            '거래완료 차의 미지급이 총액에서 빠졌다');

        // 대조 — 할일 큐(purchase_unpaid)는 의도적으로 제외한다. 그 동작은 건드리지 않았다.
        $this->assertSame(0, Vehicle::query()->action('purchase_unpaid')->count(),
            '할일 큐가 거래완료를 포함하게 바뀌었다 — 기존 동작이 변했다');
    }

    /** 매입취소 차량은 합산하되 그 사실을 꼬리로 밝힌다 (jin 2026-09-10 «화면 그대로 + 꼬리표시»). */
    public function test_cancelled_purchases_are_included_and_disclosed(): void
    {
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['purchase_price' => 3_000_000]);
        $this->vehicle($buyer, [
            'purchase_price' => 4_000_000,
            'cancel_status' => Vehicle::CANCEL_ACTIVE,
        ]);

        $c = $this->screen();
        $total = $c->instance()->purchaseUnpaidTotals();

        $this->assertSame(7_000_000, $total['total'], '매입취소분이 합계에서 빠졌다');
        $this->assertSame(1, $total['cancelled_cnt']);
        $this->assertSame(4_000_000, $total['cancelled_total']);

        $this->assertStringContainsString(
            __('vehicle.stat.purchase_unpaid_cancelled', ['count' => 1, 'amount' => number_format(4_000_000)]),
            $c->html(),
            '매입취소가 포함됐는데 화면이 그 사실을 밝히지 않는다 — 자금현황과 숫자가 달라 보인다'
        );
    }

    /** 미지급이 0 이면 지표가 통째로 안 보인다 — 발송비합과 같은 규칙(셀 게 없다는 뜻). */
    public function test_the_indicator_hides_itself_when_nothing_is_owed(): void
    {
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer, ['purchase_price' => 5_000_000]);
        $this->payPurchase($v, 5_000_000);

        $this->assertStringNotContainsString(__('vehicle.stat.purchase_unpaid'), $this->screen()->html(),
            '미지급 0 인데 빈 지표가 헤더를 어지럽힌다');
    }

    /**
     * 필터가 켜져 있으면 합이 0 이 되어도 지표가 남는다 — 사라지면 **끌 버튼이 없어져** 필터에 갇힌다.
     * (필터를 켠 뒤 그 차를 완납 처리하면 실제로 이 상태가 된다.)
     */
    public function test_the_indicator_stays_visible_while_the_filter_is_on(): void
    {
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer, ['purchase_price' => 5_000_000]);

        $c = $this->screen();
        $c->call('toggleUnpaidFilter');
        $this->payPurchase($v, 5_000_000);

        $html = $c->html();
        $this->assertStringContainsString(__('vehicle.stat.purchase_unpaid'), $html,
            '필터가 켜진 채 합이 0 이 되자 지표가 사라졌다 — 해제할 방법이 없어진다');
        $this->assertStringContainsString(__('vehicle.stat.purchase_unpaid_off'), $html,
            '켜져 있다는 표시(누르면 해제)가 없다');
    }

    // ── 정적·번역 가드 ───────────────────────────────────────────

    /**
     * 🈳 새 문구가 ko·en 양쪽에 있고 **올바른 그룹**에 들어갔는지 — 엉뚱한 그룹에 넣으면
     *    키 문자열이 화면에 그대로 찍히는데, ko·en 대조 테스트는 둘 다 틀려서 통과한다(SKILLS §8 #73).
     */
    public function test_no_untranslated_key_leaks_into_the_totals_strip(): void
    {
        $buyer = $this->buyer();
        $this->vehicle($buyer, [
            'purchase_price' => 3_000_000,
            'cancel_status' => Vehicle::CANCEL_ACTIVE,
        ]);
        $this->vehicle($buyer, ['sale_price' => 9_000_000, 'sale_date' => '2026-09-05']);

        $html = $this->screen()->call('toggleUnpaidFilter')->html();

        preg_match_all('/\b(?:vehicle|domain|common)\.[a-z_]+\.[a-z_]+/i', $html, $m);
        $this->assertSame([], array_unique($m[0]),
            '번역 안 된 키가 화면에 새어 나왔다 — lang 그룹을 확인할 것');
    }

    /**
     * 🔒 합계와 필터가 **같은 액션 하나**를 본다 — 조건을 옮겨 적으면 갈린다(SKILLS §8 #44).
     *    되돌아가도 화면은 정상 렌더되므로 정적으로 막는다.
     */
    public function test_total_and_filter_share_one_source(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/erp/vehicles/index.blade.php'));

        // 합계도 토글도 같은 액션 이름을 통과해야 한다.
        $this->assertStringContainsString("->action('purchase_unpaid_all')", $src,
            '합계가 scopeAction 을 안 거친다 — 클릭 결과와 갈릴 수 있다');
        $this->assertStringContainsString("? '' : 'purchase_unpaid_all'", $src,
            '토글이 같은 액션 이름을 쓰지 않는다 — 켜기·끄기가 한 값으로 오가야 한다');
        // 🚫 조건 복제 금지 — 화면이 미지급 판정을 직접 적으면 액션과 갈린다(§8 #44·#45).
        $this->assertDoesNotMatchRegularExpression('/purchaseUnpaidRawExpr\(\)[^;]{0,40}> 0/', $src,
            '미지급 조건을 화면에 옮겨 적었다 — Vehicle::scopeAction 단일 출처를 쓸 것');
    }
}
