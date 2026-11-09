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
 * 💳 바이어 편집 패널 헤더의 적립금 띠 (jin 2026-11-09 «바이어 탭에서 적립금 바로 볼 수 있는 헤더»).
 *
 * 적립금 탭을 누르지 않아도 통화별 잔액(+원화 병기)이 헤더에 보인다. 숫자는 적립금 탭과 같은
 * SavingsLedger 잔액 — 따로 세는 코드가 생기면 두 숫자가 갈린다(§8 #44). 신규 등록 패널엔 안 그린다.
 */
class BuyerSavingsHeaderTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    private function earn(Buyer $buyer, float $amount, string $currency, float $rate): void
    {
        $latest = SavingsStatus::where('buyer_id', $buyer->id)->where('currency', $currency)->orderByDesc('id')->first();
        SavingsStatus::create([
            'buyer_id' => $buyer->id, 'currency' => $currency, 'exchange_rate' => $rate,
            'transaction_type' => 'EARNED', 'savings' => $amount, 'balance' => (float) ($latest?->balance ?? 0) + $amount,
            'note' => '테스트 적립',
        ]);
    }

    public function test_the_panel_header_shows_every_currency_balance_with_krw(): void
    {
        $b = $this->buyer();
        $this->earn($b, 300, 'EUR', 1400);
        $this->earn($b, 120.5, 'USD', 1300);

        $this->actingAs($this->finance());
        $html = Volt::test('erp.buyers.index')->call('openEdit', $b->id)->html();

        $this->assertSame(1, preg_match('/<button[^>]*data-savings-header[\s\S]*?<\/button>/u', $html, $m), '헤더 적립금 띠가 없다');
        $strip = $m[0];
        $this->assertStringContainsString('EUR', $strip);
        $this->assertStringContainsString('300.00', $strip);
        $this->assertStringContainsString('≈ 420,000원', $strip, '원화 병기(300×1400)가 없다');
        $this->assertStringContainsString('USD', $strip);
        $this->assertStringContainsString('120.50', $strip);
        $this->assertStringContainsString('≈ 156,650원', $strip, '원화 병기(120.5×1300)가 없다');
        $this->assertStringContainsString("tab='savings'", $strip, '누르면 적립금 탭으로 가야 한다');
    }

    public function test_a_buyer_without_savings_says_so_and_the_create_panel_has_no_strip(): void
    {
        $b = $this->buyer();
        $this->actingAs($this->finance());

        $html = Volt::test('erp.buyers.index')->call('openEdit', $b->id)->html();
        $this->assertSame(1, preg_match('/<button[^>]*data-savings-header[\s\S]*?<\/button>/u', $html, $m));
        $this->assertStringContainsString('적립금 없음', $m[0]);

        $create = Volt::test('erp.buyers.index')->call('openCreate')->html();
        $this->assertStringNotContainsString('data-savings-header', $create, '신규 등록 패널엔 적립금 띠가 없어야 한다');
    }
}
