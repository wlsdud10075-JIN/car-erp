<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💳 바이어 목록 「적립금」 열 (jin 2026-11-09 «바이어명/담당자/국가/이메일 헤더에 적립금 헤더를 만들어서 내용을 보게»).
 *
 * 목록 표에 통화별 적립금 잔액이 바로 보인다(패널을 열지 않아도). 숫자는 적립금 탭·포털과 같은
 * SavingsLedger::balancesFor — 따로 세는 코드가 생기면 두 숫자가 갈린다(§8 #44). 0 인 통화는 안 적고, 없으면 '-'.
 */
class BuyerSavingsHeaderTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function buyer(string $name): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => $name, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    private function txn(Buyer $buyer, string $type, float $amount, string $currency): void
    {
        $latest = SavingsStatus::where('buyer_id', $buyer->id)->where('currency', $currency)->orderByDesc('id')->first();
        $delta = $type === 'USED' ? -$amount : $amount;
        SavingsStatus::create([
            'buyer_id' => $buyer->id, 'currency' => $currency, 'exchange_rate' => 1400,
            'transaction_type' => $type, 'savings' => $type === 'USED' ? -$amount : $amount,
            'balance' => (float) ($latest?->balance ?? 0) + $delta, 'note' => '테스트',
        ]);
    }

    /** 표 행 안의 적립금 칸만 잘라낸다 — 다른 칸·패널에 같은 숫자가 있어도 헛통과하지 않게(§8 #107). */
    private function savingsCellFor(string $html, string $buyerName): string
    {
        $this->assertSame(1, preg_match('/<tr[^>]*>\s*<td[^>]*>'.preg_quote($buyerName, '/').'[\s\S]*?<td[^>]*data-savings-cell[^>]*>([\s\S]*?)<\/td>/u', $html, $m), "{$buyerName} 행의 적립금 칸이 없다");

        return $m[1];
    }

    public function test_the_list_has_a_savings_column_with_the_latest_balance_per_currency(): void
    {
        $rich = $this->buyer('SAVRICH');
        $this->txn($rich, 'EARNED', 300, 'EUR');
        $this->txn($rich, 'USED', 100, 'EUR');       // 최신 잔액 200 — 합이 아니라 마지막 balance
        $this->txn($rich, 'EARNED', 120.5, 'USD');
        $this->txn($rich, 'EARNED', 50, 'JPY');
        $this->txn($rich, 'USED', 50, 'JPY');        // 0 → 안 적는다
        $none = $this->buyer('SAVNONE');

        $this->actingAs($this->finance());
        $html = Volt::test('erp.buyers.index')->html();

        $this->assertStringContainsString('data-savings-col', $html, '표 헤더에 적립금 열이 없다');
        $this->assertStringContainsString('>적립금<', $html);

        $cell = $this->savingsCellFor($html, 'SAVRICH');
        $this->assertStringContainsString('EUR', $cell);
        $this->assertStringContainsString('200.00', $cell);
        $this->assertStringNotContainsString('300.00', $cell, '합계가 아니라 최신 잔액이어야 한다');
        $this->assertStringContainsString('USD', $cell);
        $this->assertStringContainsString('120.50', $cell);
        $this->assertStringNotContainsString('JPY', $cell, '잔액 0 인 통화는 안 적는다');

        $this->assertSame('-', trim(strip_tags($this->savingsCellFor($html, 'SAVNONE'))), '적립금 없는 바이어는 - 로 보인다');
    }

    public function test_a_negative_balance_is_shown_in_red(): void
    {
        $b = $this->buyer('SAVNEG');
        $this->txn($b, 'USED', 40, 'EUR');   // -40

        $this->actingAs($this->finance());
        $cell = $this->savingsCellFor(Volt::test('erp.buyers.index')->html(), 'SAVNEG');

        $this->assertStringContainsString('-40.00', $cell);
        $this->assertStringContainsString('text-red-600', $cell);
    }
}
