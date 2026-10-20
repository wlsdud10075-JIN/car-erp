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
 * ☑️ 재무처리 판매·매입 잔금 탭 — 진행상태 칸 · 줄 체크박스 · 선택 합계 · 진행상태 필터 (jin 2026-10-20).
 *
 * 선택은 잔금 **한 줄** 단위(한 차에 잔금이 여러 줄일 수 있다). 합계는 통화별 + 차량 대수. 위의 탭 합계(필터 전체)와 따로.
 * 자금이체 탭은 손대지 않는다(jin «더 헷갈릴 것 같다»).
 */
class TransfersSelectedTotalsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function vehicle(string $currency = 'KRW', array $over = []): Vehicle
    {
        $salesman = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true, 'type' => 'employee']);
        $buyer = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '88가'.(1000 + $this->n), 'sales_channel' => 'export',
            'currency' => $currency, 'exchange_rate' => $currency === 'KRW' ? 1 : 1400, 'dhl_request' => false,
            'sale_price' => 10_000, 'sale_date' => '2026-09-01', 'purchase_price' => 5_000_000, 'purchase_date' => '2026-08-01',
            'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ], $over));
    }

    public function test_sale_tab_selected_rows_total_per_currency_with_vehicle_count(): void
    {
        $this->actingAs($this->finance());
        $usd = $this->vehicle('USD');
        $eur = $this->vehicle('EUR');
        $a = FinalPayment::create(['vehicle_id' => $usd->id, 'amount' => 1000, 'type' => 'balance', 'payment_date' => '2026-10-01']);
        $b = FinalPayment::create(['vehicle_id' => $usd->id, 'amount' => 500, 'type' => 'balance', 'payment_date' => '2026-10-02']);
        $c = FinalPayment::create(['vehicle_id' => $eur->id, 'amount' => 700, 'type' => 'balance', 'payment_date' => '2026-10-03']);
        FinalPayment::create(['vehicle_id' => $eur->id, 'amount' => 9999, 'type' => 'balance', 'payment_date' => '2026-10-04']);   // 안 고른 줄

        $c1 = Volt::test('erp.transfers.index')->set('tabType', 'sale_payment')->set('statusFilter', 'all')
            ->set('selectedPaymentIds', [(string) $a->id, (string) $b->id, (string) $c->id]);
        $sel = $c1->get('selectedTotals');
        $this->assertSame(3, $sel['count']);
        $this->assertSame(2, $sel['vehicles'], '한 차의 잔금 두 줄 = 차량 1대');
        $this->assertSame([['currency' => 'EUR', 'total' => 700.0], ['currency' => 'USD', 'total' => 1500.0]], $sel['rows'], '통화를 섞어 더하지 않는다');

        $html = $c1->html();
        $this->assertStringContainsString('data-selected-totals', $html);
        $this->assertStringContainsString('1,500.00', $html);
        $this->assertStringContainsString(__('transfer.selected.label', ['n' => 3, 'v' => 2]), $html);

        // 탭을 바꾸면 비운다
        $c1->set('tabType', 'purchase_payment')->assertSet('selectedPaymentIds', []);
    }

    public function test_purchase_tab_shows_progress_badge_and_page_toggle_selects_then_clears(): void
    {
        $this->actingAs($this->finance());
        $v1 = $this->vehicle();
        $v2 = $this->vehicle();
        $p1 = PurchaseBalancePayment::create(['vehicle_id' => $v1->id, 'amount' => 2_000_000, 'type' => 'balance', 'payment_date' => '2026-10-01']);
        $p2 = PurchaseBalancePayment::create(['vehicle_id' => $v2->id, 'amount' => 3_000_000, 'type' => 'balance', 'payment_date' => '2026-10-01']);

        $c = Volt::test('erp.transfers.index')->set('tabType', 'purchase_payment')->set('statusFilter', 'all');
        $html = $c->html();
        $this->assertStringContainsString(__('transfer.col.progress'), $html);
        $this->assertMatchesRegularExpression('/badge badge-purple" title="[^"]*">\s*'.preg_quote(__('domain.progress.판매중'), '/').'/u', $html, '진행상태 뱃지 + 뜻 호버');

        $ids = [$p1->id, $p2->id];
        $c->call('togglePageSelection', $ids);
        $this->assertSame(5_000_000.0, $c->get('selectedTotals')['rows'][0]['total']);
        $c->call('togglePageSelection', $ids)->assertSet('selectedPaymentIds', []);
        $c->set('selectedPaymentIds', [(string) $p1->id])->call('clearSelection')->assertSet('selectedPaymentIds', []);
    }

    public function test_stage_filter_narrows_the_list_and_the_tab_total(): void
    {
        $this->actingAs($this->finance());
        $selling = $this->vehicle();                                                   // 판매중
        $loaded = $this->vehicle('KRW', ['bl_loading_location' => '평택']);            // 선적중
        PurchaseBalancePayment::create(['vehicle_id' => $selling->id, 'amount' => 1_000_000, 'type' => 'balance', 'payment_date' => '2026-10-01']);
        PurchaseBalancePayment::create(['vehicle_id' => $loaded->id, 'amount' => 4_000_000, 'type' => 'balance', 'payment_date' => '2026-10-01']);

        $c = Volt::test('erp.transfers.index')->set('tabType', 'purchase_payment')->set('statusFilter', 'all')->set('stageFilter', '선적중');
        $this->assertSame([$loaded->id], $c->get('purchasePayments')->pluck('vehicle_id')->all());
        $this->assertSame(4_000_000.0, $c->get('tabTotals')[0]['total'], '필터 전체 합계도 같은 범위');
    }
}
