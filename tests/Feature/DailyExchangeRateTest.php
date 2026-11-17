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

    /** 네이버 prices JSON 모양(2026-11-17 실측) — 최근이 먼저, 영업일만. */
    private function fakeNaver(array $rowsByCurrency): void
    {
        Http::fake(function ($request) use ($rowsByCurrency) {
            preg_match('/FX_([A-Z]{3})KRW/', $request->url(), $m);
            $rows = $rowsByCurrency[$m[1] ?? ''] ?? [];

            return Http::response(['isSuccess' => true, 'result' => array_map(fn ($r) => [
                'localTradedAt' => $r[0].'T15:30:00+09:00', 'receiveValue' => number_format($r[1], 2), 'closePrice' => number_format($r[2], 2),
            ], $rows)]);
        });
    }

    /** 🔀 2026-11-17 — 날짜별 **확정값**을 그 날짜로. 오늘 행(장중)은 안 넣는다. 매매기준율도 같이. */
    public function test_snapshot_stores_confirmed_rows_by_trading_date(): void
    {
        $this->travelTo('2026-11-17 00:30:00');
        $this->fakeNaver(['USD' => [['2026-11-17', 1323.9, 1337.0], ['2026-11-16', 1323.5, 1336.5], ['2026-11-13', 1324.9, 1338.0]]]);

        $this->artisan('exchange:snapshot-daily')->assertSuccessful();

        $this->assertSame(2, DailyExchangeRate::where('currency', 'USD')->count(), '오늘(11-17) 장중 행은 저장 안 함');
        $this->assertEqualsWithDelta(1323.5, DailyExchangeRate::rateForDate('USD', '2026-11-16'), 0.001);
        $this->assertEqualsWithDelta(1324.9, DailyExchangeRate::rateForDate('USD', '2026-11-15'), 0.001, '주말은 직전 영업일(금 11-13)');
        $this->assertSame(['rate' => 1336.5, 'date' => '2026-11-16'], DailyExchangeRate::closingBefore('USD', '2026-11-17', DailyExchangeRate::KIND_BASE));
        $this->assertSame(['rate' => 1324.9, 'date' => '2026-11-13'], DailyExchangeRate::closingBefore('USD', '2026-11-16'), '월요일 입금 = 금요일 마감');
    }

    /** 옛 방식(09:00 장중 값을 「어제」로)이 남긴 값은 덮이고, 비영업일 행은 --prune 으로 지운다. xlsx history 행은 그대로. */
    public function test_snapshot_overwrites_old_values_and_prunes_non_trading_days(): void
    {
        $this->travelTo('2026-11-17 09:00:00');
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-11-13', 'rate' => 1325.9, 'source' => 'naver']);   // 옛 장중 값
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-11-15', 'rate' => 1323.5, 'source' => 'naver']);   // 일요일 잔재
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-07-10', 'rate' => 1487.3, 'source' => 'history']);
        $this->fakeNaver(['USD' => [['2026-11-16', 1323.5, 1336.5], ['2026-11-13', 1324.9, 1338.0]]]);

        $this->artisan('exchange:snapshot-daily', ['--prune' => true])->assertSuccessful();

        $this->assertEqualsWithDelta(1324.9, DailyExchangeRate::rateForDate('USD', '2026-11-13'), 0.001, '확정값으로 덮음');
        $this->assertSame(0, DailyExchangeRate::whereDate('rate_date', '2026-11-15')->count(), '일요일 잔재 삭제');
        $this->assertSame(1, DailyExchangeRate::where('source', 'history')->count(), 'xlsx 이력은 안 건드림');
    }

    /** 전부 실패면 실패로 끝난다 — 구: 실패해도 SUCCESS 라 아무도 몰랐다. */
    public function test_snapshot_fails_when_all_fetches_fail(): void
    {
        Cache::forget('exchange_rates');
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('exchange:snapshot-daily')->assertFailed();
        $this->assertSame(0, DailyExchangeRate::count());
    }
}
