<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 재무처리 탭 — 검색 · 탭별 합계 · 신규 매입잔금 모달 (jin 2026-09-08).
 *
 * 🔒 여기서 지키는 것 셋:
 *   ① 합계는 **보이는 페이지가 아니라 필터 전체**를 더한다 (그리고 검색과 어긋나지 않는다)
 *   ② 신규 매입잔금 「즉시 확정」은 **기본 해제** — 켜져 있으면 확인 없이 지급이 확정된다
 *   ③ 대상 차량 목록에 **오래된 차도 있다** — 종전 `limit(50)` 은 그보다 오래된 차를 조용히 숨겼다
 */
class TransferScreenSearchAndTotalsTest extends TestCase
{
    use RefreshDatabase;

    private const SCREEN = 'erp.transfers.index';

    private int $counter = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function vehicle(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => 'TR'.++$this->counter.'가1111',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_price' => 1_000_000,
            'purchase_date' => '2026-01-01',
        ], $attrs));
    }

    /** ② 즉시 확정 체크박스는 기본이 해제다 — 선언·열기·닫기 세 곳 전부. */
    public function test_immediate_confirm_checkbox_defaults_to_unchecked(): void
    {
        $this->actingAs($this->finance());

        Volt::test(self::SCREEN)
            ->assertSet('newPbpImmediateConfirm', false)   // 선언
            ->call('openNewPbpModal')
            ->assertSet('newPbpImmediateConfirm', false)   // 열기
            ->set('newPbpImmediateConfirm', true)
            ->call('closeNewPbpModal')
            ->call('openNewPbpModal')
            ->assertSet('newPbpImmediateConfirm', false);  // 다시 열어도 해제
    }

    /**
     * ③ 대상 차량 목록에 **오래된 차도 들어간다**.
     * 종전 `limit(50)` 은 최근 50대만 줘서, 그보다 오래된 차는 잔금을 넣을 방법이 아예 없었다.
     */
    public function test_vehicle_options_are_not_capped_at_fifty(): void
    {
        $this->actingAs($this->finance());

        $oldest = $this->vehicle(['vehicle_number' => '00가0001']);
        for ($i = 0; $i < 55; $i++) {
            $this->vehicle();
        }

        $options = Volt::test(self::SCREEN)->get('purchaseEligibleVehicles');
        $ids = collect($options)->pluck('id')->all();

        $this->assertContains($oldest->id, $ids, '오래된 차가 목록에서 빠졌다 — limit 이 되살아났다');
        $this->assertGreaterThan(50, count($ids));
    }

    /** ③-b 콤보박스가 검색할 문자열에 차량번호·매입처·담당자가 다 들어 있어야 한다. */
    public function test_vehicle_option_label_carries_plate_seller_and_salesman(): void
    {
        $this->actingAs($this->finance());
        $sm = Salesman::create(['name' => '김담당', 'email' => 's@t.test', 'is_active' => true]);
        $v = $this->vehicle(['purchase_from' => '동해상사', 'salesman_id' => $sm->id]);

        $label = collect(Volt::test(self::SCREEN)->get('purchaseEligibleVehicles'))
            ->firstWhere('id', $v->id)->name;

        $this->assertStringContainsString($v->vehicle_number, $label);
        $this->assertStringContainsString('동해상사', $label);
        $this->assertStringContainsString('김담당', $label);
    }

    /** ① 매입 잔금 — 검색이 차량번호·매입처·담당자로 걸리고, 합계가 그 결과와 같다. */
    public function test_purchase_tab_search_and_total_agree(): void
    {
        $this->actingAs($this->finance());
        $sm = Salesman::create(['name' => '박영업', 'email' => 'p@t.test', 'is_active' => true]);
        $other = Salesman::create(['name' => '최영업', 'email' => 'c@t.test', 'is_active' => true]);

        $mine = $this->vehicle(['purchase_from' => '서해모터스', 'salesman_id' => $sm->id]);
        $theirs = $this->vehicle(['purchase_from' => '남해오토', 'salesman_id' => $other->id]);

        PurchaseBalancePayment::create(['vehicle_id' => $mine->id, 'amount' => 300_000, 'payment_date' => '2026-02-01']);
        PurchaseBalancePayment::create(['vehicle_id' => $mine->id, 'amount' => 200_000, 'payment_date' => '2026-02-02']);
        PurchaseBalancePayment::create(['vehicle_id' => $theirs->id, 'amount' => 900_000, 'payment_date' => '2026-02-03']);

        $c = Volt::test(self::SCREEN)->set('tabType', 'purchase_payment')->set('statusFilter', 'all');

        // 검색 없음 = 전부
        $this->assertSame([['currency' => 'KRW', 'total' => 1_400_000.0, 'count' => 3]], $c->get('tabTotals'));

        // 담당자로 — 🔎 검색은 버튼(searchNow)으로만 돈다
        $c->set('search', '박영업')->call('searchNow');
        $this->assertSame([['currency' => 'KRW', 'total' => 500_000.0, 'count' => 2]], $c->get('tabTotals'));

        // 매입처로
        $c->set('search', '남해오토')->call('searchNow');
        $this->assertSame([['currency' => 'KRW', 'total' => 900_000.0, 'count' => 1]], $c->get('tabTotals'));

        // 차량번호로
        $c->set('search', $mine->vehicle_number)->call('searchNow');
        $this->assertSame([['currency' => 'KRW', 'total' => 500_000.0, 'count' => 2]], $c->get('tabTotals'));

        // 없는 말
        $c->set('search', '없는거래처')->call('searchNow');
        $this->assertSame([], $c->get('tabTotals'));
    }

    /**
     * ① 합계는 **페이지가 아니라 필터 전체**다.
     * 페이지 컬렉션에서 더하면 「10건만 더한 합계」가 되는데, 그건 화면에서 구분이 안 된다.
     */
    public function test_total_covers_every_filtered_row_not_just_the_page(): void
    {
        $this->actingAs($this->finance());
        $v = $this->vehicle();
        for ($i = 0; $i < 12; $i++) {
            PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'amount' => 100_000, 'payment_date' => '2026-02-01']);
        }

        $c = Volt::test(self::SCREEN)->set('tabType', 'purchase_payment')->set('statusFilter', 'all');

        $this->assertSame(10, $c->get('purchasePayments')->count(), '전제: 한 페이지는 10건이다');
        $this->assertSame(1_200_000.0, $c->get('tabTotals')[0]['total'], '합계가 페이지만 더했다');
        $this->assertSame(12, $c->get('tabTotals')[0]['count']);
    }

    /**
     * 🚨 판매 잔금 합계는 **통화별로 갈린다**. 섞어 더하면 아무 뜻 없는 숫자가 된다.
     */
    public function test_sale_tab_total_splits_by_currency(): void
    {
        $this->actingAs($this->finance());
        $buyer = Buyer::create(['name' => 'CUR BUYER', 'is_active' => true]);

        $usd = $this->vehicle(['currency' => 'USD', 'exchange_rate' => 1300, 'sale_price' => 10_000,
            'sale_date' => '2026-02-01', 'buyer_id' => $buyer->id]);
        $eur = $this->vehicle(['currency' => 'EUR', 'exchange_rate' => 1400, 'sale_price' => 8_000,
            'sale_date' => '2026-02-01', 'buyer_id' => $buyer->id]);

        FinalPayment::create(['vehicle_id' => $usd->id, 'type' => 'balance', 'amount' => 3_000, 'payment_date' => '2026-02-02']);
        FinalPayment::create(['vehicle_id' => $usd->id, 'type' => 'balance', 'amount' => 1_500, 'payment_date' => '2026-02-03']);
        FinalPayment::create(['vehicle_id' => $eur->id, 'type' => 'balance', 'amount' => 2_000, 'payment_date' => '2026-02-04']);

        $totals = Volt::test(self::SCREEN)->set('tabType', 'sale_payment')->set('statusFilter', 'all')->get('tabTotals');

        $this->assertSame([
            ['currency' => 'EUR', 'total' => 2_000.0, 'count' => 1],
            ['currency' => 'USD', 'total' => 4_500.0, 'count' => 2],
        ], $totals, '통화가 섞여 한 줄로 더해졌다');
    }

    /** 검색어를 바꾸면 1페이지로 돌아간다 — 3페이지에서 검색하면 결과가 있는데 빈 화면이 된다. */
    public function test_search_resets_pagination(): void
    {
        $this->actingAs($this->finance());
        $v = $this->vehicle(['purchase_from' => '한빛상사']);
        for ($i = 0; $i < 25; $i++) {
            PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'amount' => 10_000, 'payment_date' => '2026-02-01']);
        }

        Volt::test(self::SCREEN)
            ->set('tabType', 'purchase_payment')->set('statusFilter', 'all')
            ->call('gotoPage', 3)
            ->set('search', '한빛상사')
            ->call('searchNow')
            ->assertSet('paginators.page', 1);
    }
}
