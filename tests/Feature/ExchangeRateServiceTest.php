<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 회의확장씬 #7 회귀 — 환율 스크래핑 Service + 잔금 row 환율 저장 + 2차 정산 환차 계산.
 */
class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function fakeNaverHtml(): array
    {
        $mainHtml = '
            <html>
                <a href="..." class="head usd">
                    <span class="value">1,367.50</span>
                </a>
                <a href="..." class="head jpy">
                    <span class="value">9.05</span>
                </a>
                <a href="..." class="head eur">
                    <span class="value">1,470.20</span>
                </a>
                <a href="..." class="head cny">
                    <span class="value">189.30</span>
                </a>
            </html>
        ';
        $gbpHtml = '
            <html>
                <p class="no_today">
                    <em><span class="value">1,720.40</span></em>
                </p>
            </html>
        ';

        return [
            'https://finance.naver.com/marketindex/' => Http::response($mainHtml, 200),
            'https://finance.naver.com/marketindex/exchangeDetail.naver*' => Http::response($gbpHtml, 200),
        ];
    }

    public function test_settlements_has_exchange_difference_column(): void
    {
        $this->assertTrue(Schema::hasColumn('settlements', 'exchange_difference_krw'));
        $this->assertTrue(Schema::hasColumn('final_payments', 'exchange_rate'));
    }

    public function test_service_parses_naver_html_and_returns_5_currencies(): void
    {
        Http::fake($this->fakeNaverHtml());
        Cache::forget('exchange_rates');

        $rates = app(ExchangeRateService::class)->getRates();

        $this->assertIsArray($rates);
        $this->assertSame(1367.50, $rates['USD'] ?? null);
        $this->assertSame(9.05, $rates['JPY'] ?? null);
        $this->assertSame(1470.20, $rates['EUR'] ?? null);
        $this->assertSame(189.30, $rates['CNY'] ?? null);
        $this->assertSame(1720.40, $rates['GBP'] ?? null);
    }

    public function test_service_uses_cache_within_ttl(): void
    {
        Cache::put('exchange_rates', ['USD' => 1350.0], 60);

        $rates = app(ExchangeRateService::class)->getRates();

        $this->assertSame(1350.0, $rates['USD']);
    }

    public function test_get_rate_returns_1_for_krw(): void
    {
        // testing env Cache miss → null 분기지만 KRW 는 그 전 처리.
        $rate = app(ExchangeRateService::class)->getRate('KRW');
        $this->assertSame(1.0, $rate);
    }

    public function test_close_secondary_settlement_captures_exchange_difference(): void
    {
        // 환차익 시나리오: 입금 시점 환율 1350 → 정산 시점 1380 → +30 × foreign 차이
        Cache::put('exchange_rates', ['USD' => 1380.0], 60);

        $manager = User::factory()->create([
            'permission' => 'user', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
        $v = Vehicle::create([
            'vehicle_number' => 'EXC-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400,
            'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        // 잔금 4건 — 각각 다른 환율로 입금 (회의확장씬 예시 단순화: 400 한 번에)
        $v->finalPayments()->create([
            'amount' => 400, 'exchange_rate' => 1350,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id,
        ]);
        // 자동 secondary='pending' 진입을 위해 paid Settlement 생성
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($manager);
        Volt::test('erp.settlements.index')
            ->call('closeSecondarySettlement', $v->settlements()->first()->id);

        $s = $v->settlements()->first()->fresh();
        $this->assertSame('closed', $s->secondary_status);
        // 환차 = 400×1380 − 400×1350 = 552000 − 540000 = +12000
        $this->assertSame(12000.0, (float) $s->exchange_difference_krw);
    }

    public function test_close_secondary_krw_vehicle_has_zero_diff(): void
    {
        Cache::put('exchange_rates', ['USD' => 1380.0], 60);

        $manager = User::factory()->create([
            'permission' => 'user', 'role' => '관리',
            'email_verified_at' => now(),
        ]);
        $v = Vehicle::create([
            'vehicle_number' => 'KRW-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 1_000_000,
            'sale_date' => '2026-05-01',
            'purchase_date' => '2026-04-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 1_000_000, 'exchange_rate' => 1,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $manager->id,
        ]);
        Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($manager);
        Volt::test('erp.settlements.index')
            ->call('closeSecondarySettlement', $v->settlements()->first()->id);

        $s = $v->settlements()->first()->fresh();
        $this->assertSame(0.0, (float) $s->exchange_difference_krw);
    }

    public function test_sale_received_krw_accumulated_uses_row_exchange_rates(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'ACC-'.++$this->counter,
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400,
            'sale_date' => '2026-05-01',
        ]);
        $v->finalPayments()->create([
            'amount' => 100, 'exchange_rate' => 1350,
            'payment_date' => '2026-05-01', 'confirmed_at' => now(),
        ]);
        $v->finalPayments()->create([
            'amount' => 200, 'exchange_rate' => 1360,
            'payment_date' => '2026-05-10', 'confirmed_at' => now(),
        ]);
        $v->finalPayments()->create([
            'amount' => 100, 'exchange_rate' => 1370,
            'payment_date' => '2026-05-21', 'confirmed_at' => now(),
        ]);

        $v->refresh();
        // 100×1350 + 200×1360 + 100×1370 = 135,000 + 272,000 + 137,000 = 544,000
        $this->assertSame(544_000, $v->sale_received_krw_accumulated);
    }
}
