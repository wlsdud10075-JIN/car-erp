<?php

namespace App\Services;

use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 회의확장씬 #7 (2026-05-22) — 실시간 환율 스크래핑 (네이버 marketindex).
 *
 * 사용자 명세 (2026-05-21):
 *   "환율 실시간을(송금받을 때 환율) 판매쪽 잔금N+에 추가할 때 즉시 반영"
 *   "[관리]대시보드에 띄우고 잔금N+ 할때 그 시간에 떠 있는 환율을 자동으로 기입"
 *   "그게 안될 시나 실패시에만 수동 기입으로 되고"
 *
 * 결정 (2026-05-22):
 *   - 네이버 finance.naver.com/marketindex/ HTML 스크래핑 (메인 페이지 4종 USD/JPY/EUR/CNY)
 *   - GBP 만 detail 페이지 (exchangeDetail.naver?marketindexCd=FX_GBPKRW) 별도 호출
 *   - Cache::remember 1h TTL — 외부 호출 부담 최소화 + 사용자 환율 변동 한 시간 단위 충분
 *   - 실패 시 null 반환 — 호출자가 수동 입력 fallback 처리
 *   - HTML 변경 가능성 — try/catch 로 silent fail
 *
 * 통화: USD/JPY/EUR/GBP/CNY (vehicles.currency enum 5종, KRW 제외)
 *
 * Cache key: 'exchange_rates' (전체 5종 array). 단일 통화 호출도 전체 캐시 활용.
 */
class ExchangeRateService
{
    private const CACHE_KEY = 'exchange_rates';

    private const CACHE_TTL_SECONDS = 3600;   // 1시간

    private const SUPPORTED_CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY'];

    private const NAVER_MAIN_URL = 'https://finance.naver.com/marketindex/';

    private const NAVER_DETAIL_URL = 'https://finance.naver.com/marketindex/exchangeDetail.naver';

    /**
     * 5종 통화 환율 일괄 조회. Cache hit 시 즉시 반환.
     *
     * @return array<string, float>|null ['USD' => 1367.50, 'JPY' => 9.05, ...] 또는 null (전체 실패)
     */
    public function getRates(): ?array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return $this->fetchRatesFromNaver();
        });
    }

    /**
     * 단일 통화 환율 조회. getRates 의 일부 추출.
     *
     * @param  string  $currency  USD/JPY/EUR/GBP/CNY (KRW=1.0 직접 반환)
     */
    public function getRate(string $currency): ?float
    {
        if ($currency === 'KRW') {
            return 1.0;
        }
        $rates = $this->getRates();

        return $rates[$currency] ?? null;
    }

    /**
     * 캐시 강제 갱신 (운영자 수동 갱신 또는 scheduler).
     */
    public function refresh(): ?array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->getRates();
    }

    /**
     * 네이버 marketindex 페이지에서 환율 추출.
     * HTML 구조 변경 시 silent fail (Log warning + null 반환).
     *
     * @return array<string, float>|null
     */
    private function fetchRatesFromNaver(): ?array
    {
        try {
            $rates = [];

            // 메인 페이지: USD/JPY/EUR/CNY 4종
            $mainHtml = $this->fetchHtml(self::NAVER_MAIN_URL);
            if ($mainHtml !== null) {
                foreach (['USD', 'JPY', 'EUR', 'CNY'] as $cur) {
                    $rate = $this->parseMainRate($mainHtml, $cur);
                    if ($rate !== null) {
                        $rates[$cur] = $rate;
                    }
                }
            }

            // GBP 만 detail 페이지 별도 호출
            $gbpHtml = $this->fetchHtml(self::NAVER_DETAIL_URL.'?marketindexCd=FX_GBPKRW');
            if ($gbpHtml !== null) {
                $gbpRate = $this->parseDetailRate($gbpHtml);
                if ($gbpRate !== null) {
                    $rates['GBP'] = $gbpRate;
                }
            }

            return empty($rates) ? null : $rates;
        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->timeout(5)
                ->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService HTTP failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 메인 페이지 HTML 에서 통화별 환율 추출.
     * 구조: <a class="head {currency_lower}">...<span class="value">1,367.50</span>...</a>
     */
    private function parseMainRate(string $html, string $currency): ?float
    {
        $lower = strtolower($currency);
        // 통화 코드 anchor 안 첫 .value span 매칭 (간단한 regex — DOMDocument 대비 fragile 하지만 충분히 robust)
        if (preg_match('/<a[^>]*class="[^"]*head\s+'.$lower.'[^"]*"[^>]*>.*?<span[^>]*class="value"[^>]*>([\d,\.]+)<\/span>/is', $html, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }

    /**
     * Detail 페이지 HTML 에서 환율 추출 (GBP 용).
     * 구조: <p class="no_today"><em class="no_up">...<span class="value">...</span>...</em></p>
     */
    private function parseDetailRate(string $html): ?float
    {
        if (preg_match('/<p[^>]*class="no_today"[^>]*>.*?<span[^>]*class="value"[^>]*>([\d,\.]+)<\/span>/is', $html, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }
}
