<?php

namespace Tests\Feature;

use App\Models\DailyExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 일별 마감환율 이력 — 날짜별 조회(carry-forward/null) + 스냅샷 (2026-07-13).
 */
class DailyExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_for_date_exact_carry_forward_and_null(): void
    {
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-07-10', 'rate' => 1487.3, 'source' => 'history']);
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-07-13', 'rate' => 1478.5, 'source' => 'history']);

        $this->assertEqualsWithDelta(1487.3, DailyExchangeRate::rateForDate('USD', '2026-07-10'), 0.001);   // 정확일
        $this->assertEqualsWithDelta(1487.3, DailyExchangeRate::rateForDate('USD', '2026-07-12'), 0.001);   // 토/일 → 직전 영업일(07-10)
        $this->assertEqualsWithDelta(1478.5, DailyExchangeRate::rateForDate('USD', '2026-07-20'), 0.001);   // 이후 → 가장 최근(07-13)
        $this->assertNull(DailyExchangeRate::rateForDate('USD', '2026-07-01'));   // 데이터 이전 → null(수기)
        $this->assertNull(DailyExchangeRate::rateForDate('JPY', '2026-07-10'));   // 미보유 통화 → null
        $this->assertSame(1.0, DailyExchangeRate::rateForDate('KRW', '2026-07-10'));
    }

    public function test_snapshot_stores_yesterday_as_naver(): void
    {
        // 실서비스가 읽는 캐시를 직접 심어 네트워크 없이 결정론적으로. (getRates = Cache::remember('exchange_rates'))
        Cache::put('exchange_rates', ['USD' => 1500.0, 'EUR' => 1700.0], 3600);

        $this->artisan('exchange:snapshot-daily')->assertSuccessful();

        $yesterday = now()->subDay()->toDateString();
        // rate_date 는 SQLite 에서 datetime 문자열로 저장돼 문자열 매칭이 어긋남 → rateForDate 로 날짜+값 검증.
        $this->assertDatabaseHas('daily_exchange_rates', ['currency' => 'USD', 'source' => 'naver']);
        $this->assertEqualsWithDelta(1500.0, DailyExchangeRate::rateForDate('USD', $yesterday), 0.001);
    }

    public function test_snapshot_skips_when_fetch_fails(): void
    {
        // 네이버 실패 유도 → getRates null → 스냅샷 skip, 행 0.
        Cache::forget('exchange_rates');
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('exchange:snapshot-daily')->assertSuccessful();
        $this->assertSame(0, DailyExchangeRate::count());
    }
}
