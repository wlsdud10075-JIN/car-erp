<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\SavingsStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SavingsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 적립금 선입선출 + 적립 시점 환율 원화 병기 (jin 2026-07-29).
 *
 * 두 요구가 한 몸인 이유: 환율은 **적립 시점에 고정**되므로, 어느 적립분이 나갔는지(FIFO)가
 * 곧 남은 크레딧의 원화 가치를 정한다.
 */
class SavingsFifoLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): SavingsLedger
    {
        return app(SavingsLedger::class);
    }

    /** 원장에 직접 행을 쌓는다(러닝 잔액은 이 테스트의 관심사가 아니라 0 으로 둔다). */
    private function row(Buyer $b, string $type, float $savings, ?float $rate, string $cur = 'EUR'): SavingsStatus
    {
        return SavingsStatus::create([
            'buyer_id' => $b->id, 'currency' => $cur, 'exchange_rate' => $rate,
            'transaction_type' => $type, 'savings' => $savings, 'balance' => 0,
        ]);
    }

    public function test_oldest_lot_is_consumed_first(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 500, 1400);   // 먼저 적립
        $this->row($b, 'EARNED', 500, 1600);   // 나중 적립
        $this->row($b, 'USED', -300, 1500);    // 300 사용

        $lots = $this->ledger()->lots($b->id, 'EUR');

        $this->assertEquals(200, $lots[0]['remaining'], '오래된 lot 이 먼저 깎인다');
        $this->assertEquals(500, $lots[1]['remaining'], '나중 lot 은 그대로');
    }

    public function test_krw_uses_rate_at_earning_not_at_use(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 500, 1400);
        $this->row($b, 'EARNED', 500, 1600);
        $this->row($b, 'USED', -300, 9999);    // 사용 시점 환율은 환산에 안 쓰인다

        // 잔여 = 200(@1400) + 500(@1600) = 280,000 + 800,000
        $this->assertSame(1_080_000, $this->ledger()->balanceKrw($b->id, 'EUR')['krw']);
    }

    public function test_consuming_a_whole_lot_moves_to_the_next(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 500, 1400);
        $this->row($b, 'EARNED', 500, 1600);
        $this->row($b, 'USED', -700, 1500);

        $lots = $this->ledger()->lots($b->id, 'EUR');

        $this->assertEquals(0, $lots[0]['remaining']);
        $this->assertTrue($lots[0]['consumed']);
        $this->assertEquals(300, $lots[1]['remaining']);
        $this->assertSame(480_000, $this->ledger()->balanceKrw($b->id, 'EUR')['krw'], '300 × 1600');
    }

    public function test_refund_unwinds_into_the_original_lot_at_its_own_rate(): void
    {
        // 🚨 되돌아온 크레딧은 **나갔던 lot 으로, 그 lot 의 환율 그대로** 돌아가야 한다.
        //    REFUND 를 새 lot 으로 만들면 되돌아온 돈이 REFUND 행의 환율을 갖게 돼 원화 가치가 바뀐다.
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 500, 1400);
        $this->row($b, 'EARNED', 500, 1600);
        $this->row($b, 'USED', -700, 1500);
        $this->row($b, 'REFUND', 200, 1500);   // 되돌림 → 순유출 500

        $lots = $this->ledger()->lots($b->id, 'EUR');

        $this->assertCount(2, $lots, 'REFUND 는 새 lot 이 아니라 되감기');
        $this->assertEquals(0, $lots[0]['remaining'], '오래된 lot 은 여전히 소진');
        $this->assertEquals(500, $lots[1]['remaining'], '두 번째 lot 이 원래 환율로 되차오름');
        $this->assertSame(800_000, $this->ledger()->balanceKrw($b->id, 'EUR')['krw'], '500 × 1600 (REFUND 환율 1500 아님)');
    }

    public function test_refund_beyond_outflow_becomes_a_real_lot(): void
    {
        // 되돌릴 유출이 없는 초과분은 실제 새 크레딧 — 안 남기면 Σ잔여 < 잔액 이 된다.
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 100, 1400);
        $this->row($b, 'USED', -100, 1400);
        $this->row($b, 'REFUND', 300, 1500);   // 100 은 되감기, 200 은 새 크레딧

        $lots = $this->ledger()->lots($b->id, 'EUR');

        $this->assertEquals(100, $lots[0]['remaining'], '되감긴 원래 lot');
        $this->assertEquals(200, $lots[1]['remaining'], '초과분은 새 lot');
        $this->assertSame(140_000 + 300_000, $this->ledger()->balanceKrw($b->id, 'EUR')['krw']);
    }

    public function test_remaining_sum_always_equals_running_balance(): void
    {
        // 불변식 — 화면 잔액(러닝 스냅샷)과 lot 잔여 합이 어긋나면 둘 중 하나가 거짓말이다.
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $seq = [['EARNED', 500], ['EARNED', 300], ['USED', -200], ['REFUND', 100], ['USED', -450], ['ADJUSTMENT', 250]];
        $running = 0.0;
        foreach ($seq as [$type, $amt]) {
            $running += $amt;
            $r = $this->row($b, $type, $amt, 1400);
            $r->update(['balance' => $running]);
        }

        $sum = $this->ledger()->lots($b->id, 'EUR')->sum('remaining');
        $this->assertEqualsWithDelta($running, $sum, 0.01, 'Σ lot 잔여 = 러닝 잔액');
    }

    public function test_rate_missing_is_reported_not_silently_zero(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 500, 1400);
        $this->row($b, 'EARNED', 300, null);   // 환율 미입력

        $res = $this->ledger()->balanceKrw($b->id, 'EUR');

        $this->assertSame(700_000, $res['krw'], '환율 있는 분만 환산');
        $this->assertEquals(300, $res['unrated'], '환산 못한 잔액은 별도 보고 — 0 으로 삼키지 않는다');
    }

    public function test_plan_shows_which_lots_would_go_out(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 500, 1400);
        $this->row($b, 'EARNED', 500, 1600);

        $plan = $this->ledger()->plan($b->id, 'EUR', 700);

        $this->assertCount(2, $plan['lots']);
        $this->assertEquals(500, $plan['lots'][0]['take']);
        $this->assertEquals(200, $plan['lots'][1]['take']);
        $this->assertSame(700_000 + 320_000, $plan['krw'], '500×1400 + 200×1600');
        $this->assertEquals(0, $plan['shortfall']);
    }

    public function test_plan_reports_shortfall(): void
    {
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 100, 1400);

        $this->assertEquals(400, $this->ledger()->plan($b->id, 'EUR', 500)['shortfall']);
    }

    public function test_vehicle_earning_records_sale_exchange_rate(): void
    {
        // 판매탭에서 적립하면 그 시점 판매환율이 박제돼야 원화 병기가 된다.
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '11가1001', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1716, 'sale_price' => 5000,
            'sale_date' => '2026-07-01', 'buyer_id' => $buyer->id, 'dhl_request' => false,
        ]);

        $v->syncSavingsDeposit(500);

        $lot = SavingsStatus::where('buyer_id', $buyer->id)->where('transaction_type', 'EARNED')->first();
        $this->assertEquals(1716, (float) $lot->exchange_rate);
        $this->assertSame(858_000, $this->ledger()->balanceKrw($buyer->id, 'EUR')['krw'], '500 × 1716');
    }

    public function test_buyer_screen_records_manual_rate(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);

        Volt::test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('txn_currency', 'EUR')
            ->set('txn_type', 'EARNED')
            ->set('txn_amount', '500')
            ->set('txn_rate', '1500')
            ->call('addSavingsTransaction')
            ->assertHasNoErrors();

        $this->assertEquals(1500, (float) SavingsStatus::where('buyer_id', $buyer->id)->first()->exchange_rate);
    }

    public function test_screen_shows_krw_and_next_out_without_a_lot_list(): void
    {
        // jin 2026-07-29 — lot 을 줄줄이 나열했더니 "보기 되게 복잡"하고 아래 거래내역 표와 겹쳤다.
        //   → 잔액 줄에 **원화 + 다음 차감 한 줄**만. 날짜별 내역은 거래내역 표가 담당.
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $b = Buyer::create(['name' => 'B', 'is_active' => true]);
        $this->row($b, 'EARNED', 1500, 1487, 'USD');
        $this->row($b, 'EARNED', 1500, 1487, 'USD');

        $page = Volt::test('erp.buyers.index')->call('openEdit', $b->id);

        $page->assertSeeText('4,461,000');                       // 3,000 × 1,487 원화 병기
        $page->assertSeeText('다음 차감');                        // 선입선출 안내 한 줄
        $page->assertDontSeeText('선입선출 잔여 적립분');           // 별도 목록 블록은 없어야 한다
    }

    public function test_krw_currency_defaults_rate_to_one(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);

        Volt::test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('txn_currency', 'KRW')
            ->set('txn_type', 'EARNED')
            ->set('txn_amount', '300000')
            ->call('addSavingsTransaction')
            ->assertHasNoErrors();

        $this->assertEquals(1, (float) SavingsStatus::where('buyer_id', $buyer->id)->first()->exchange_rate);
        $this->assertSame(300_000, $this->ledger()->balanceKrw($buyer->id, 'KRW')['krw']);
    }
}
