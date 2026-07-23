<?php

namespace Tests\Feature;

use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 환율 조회 성능 (jin 2026-07-23) — 판매탭 잔금 날짜 입력 시 환율 자동기입.
 *   getRate(단일 통화)가 캐시 미스 때 5개 통화 순차 스크래핑 대신 필요한 1개만 조회.
 */
class ExchangeRateSingleFetchTest extends TestCase
{
    public function test_uses_all_cache_without_network(): void
    {
        Cache::put('exchange_rates', ['USD' => 1450.0, 'EUR' => 1680.0], 3600);
        Http::fake();

        $rate = app(ExchangeRateService::class)->getRate('USD');

        $this->assertSame(1450.0, $rate);
        Http::assertNothingSent();   // 전체 캐시 히트 → 네트워크 0
    }

    public function test_fetches_only_needed_currency_on_miss(): void
    {
        Cache::flush();
        Http::fake([
            '*FX_USDKRW*' => Http::response('<th class="th_ex5"><span>x</span></th><td> 1,458.10 </td>'),
            '*' => Http::response('nope', 500),
        ]);

        $rate = app(ExchangeRateService::class)->getRate('USD');

        $this->assertSame(1458.1, $rate);
        Http::assertSentCount(1);   // USD 1개만 — 5개 스크래핑 안 함 (핵심)
    }

    public function test_unsupported_currency_returns_null_without_network(): void
    {
        Cache::flush();
        Http::fake();

        $this->assertNull(app(ExchangeRateService::class)->getRate('AUD'));
        Http::assertNothingSent();
    }
}
