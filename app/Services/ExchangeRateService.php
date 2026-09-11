<?php

namespace App\Services;

use App\Models\DailyExchangeRate;
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
 * ⚠️ 환율 종류 = **송금 받으실 때(전신환 매입률, T/T buying)** — 2026-07-03 jin 결정.
 *   회사가 외화를 받을 때 실제 적용되는 환율. 매매기준율(mid)보다 낮음(전 통화 실측:
 *   USD 1516 vs 기준 1531, JPY100 941 vs 950 등). 구 버전은 매매기준율을 긁었으나
 *   "송금받을 때"라는 라벨과 실제 값이 불일치 → 송금받을때(전신환 매입률)로 정정.
 *   ※ 자동환율만 변경 — 정산 마진 기준(차량 exchange_rate, 관리 판매시점 지정)은 불변(재무 무영향).
 *
 * 🚨 2026-09-11 — **스크래핑 사망, JSON API 로 교체.** 네이버가 marketindex 를 Next.js 로
 *   개편해 상세페이지 HTML 에 **환율 값 자체가 없다**(클라이언트 렌더). `th_ex5` 도 `tbl_exchange`
 *   도 사라졌다. HTTP 는 계속 200 이라 아무 데서도 안 터지고 **조용히 null** 만 돌았다.
 *   실측 blast radius = 3사 전부 · 대시보드 위젯 · 잔금N+ 자동기입 · 마감환율 스냅샷
 *   (karabaerp daily_exchange_rates 09-09 이후 중단) · board `/rates`. 이틀간 무음.
 *   ⚠️ 테스트는 옛 HTML 을 fake 해서 **내내 초록**이었다 — fake 는 원리상 포맷 변경을 못 잡는다.
 *
 * 결정 (2026-09-11, 구 2026-07-03 대체):
 *   - 통화별 JSON 1회 호출: m.stock.naver.com/front-api/marketIndex/prices
 *       ?category=exchange&reutersCode=FX_{CUR}KRW&page=1
 *   - `result[0].receiveValue` = **송금 받으실 때**(전신환 매입률). 같은 행에
 *     cashBuyValue(현찰살때)·cashSellValue(현찰팔때)·sendValue(송금보낼때)·closePrice(매매기준율)가
 *     함께 온다 — **필드를 헷갈리면 값이 통째로 달라진다**(실측 USD recv 1,335.3 vs send 1,361.7).
 *   - `result[0]` = 최신 영업일. 주말·공휴일은 직전 영업일이 그대로 첫 행에 온다.
 *   - JPY 는 100엔 기준(네이버 관례 그대로, 단위 불변)
 *   - Cache::remember 1h TTL — 외부 호출 부담 최소화 + 환율 변동 한 시간 단위 충분
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

    private const FETCHED_AT_KEY = 'exchange_rates_fetched_at';

    private const CACHE_TTL_SECONDS = 3600;   // 1시간

    private const SUPPORTED_CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY'];

    private const NAVER_PRICES_URL = 'https://m.stock.naver.com/front-api/marketIndex/prices';

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
        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return null;
        }
        // 전체 캐시(getRates·대시보드 위젯이 채움) 히트면 즉시.
        $all = Cache::get(self::CACHE_KEY);
        if (is_array($all) && isset($all[$currency])) {
            return $all[$currency];
        }

        // 미스 → 필요한 통화 1개만 조회 (성능: 5개 순차 스크래핑 회피, jin 2026-07-23). 개별 1h 캐시.
        return Cache::remember(self::CACHE_KEY.'_'.$currency, self::CACHE_TTL_SECONDS, function () use ($currency) {
            return $this->fetchOneFromNaver($currency);
        });
    }

    /**
     * 특정 날짜의 마감환율(과거 포함) — daily_exchange_rates 조회(주말·공휴일은 직전 영업일 carry-forward).
     * 없으면(데이터 이전·미보유 통화) null → 호출자 수기입력 fallback. 잔금 날짜 지정 자동기입용.
     * ⚠️ 오늘/미래 날짜는 이력에 아직 없을 수 있으니(스냅샷은 전날까지) 호출자가 today 는 getRate() 우선 사용.
     */
    public function getRateForDate(string $currency, string $date): ?float
    {
        return DailyExchangeRate::rateForDate($currency, $date);
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
     * 마지막으로 네이버에서 긁은 시각 ('Y-m-d H:i') — 없으면 null.
     * board 연동(/rates)의 신선도 표시용. rates 캐시와 동일 TTL 로 함께 기록.
     */
    public function fetchedAt(): ?string
    {
        return Cache::get(self::FETCHED_AT_KEY);
    }

    /**
     * 네이버 marketindex 상세페이지에서 통화별 '송금 받으실 때'(전신환 매입률) 추출.
     * 통화별 detail 페이지 5회 호출. HTML 구조 변경 시 silent fail (Log warning + null 반환).
     *
     * @return array<string, float>|null
     */
    private function fetchRatesFromNaver(): ?array
    {
        try {
            $rates = [];

            foreach (self::SUPPORTED_CURRENCIES as $cur) {
                $rate = $this->fetchOneFromNaver($cur);   // 통화별 graceful — 실패한 통화만 빠짐
                if ($rate !== null) {
                    $rates[$cur] = $rate;
                }
            }

            if (empty($rates)) {
                return null;
            }

            // 긁은 시각 기록 (rates 캐시와 동일 TTL) — board /rates 신선도 표시용.
            Cache::put(self::FETCHED_AT_KEY, now()->format('Y-m-d H:i'), self::CACHE_TTL_SECONDS);

            return $rates;
        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** 단일 통화 네이버 조회 (getRate 미스·getRates 루프 공용). */
    private function fetchOneFromNaver(string $currency): ?float
    {
        $body = $this->fetchHtml(
            self::NAVER_PRICES_URL.'?category=exchange&reutersCode=FX_'.$currency.'KRW&page=1'
        );

        return $body === null ? null : $this->parseTtBuyingRate($body);
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->timeout(3)
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
     * prices JSON 에서 '송금 받으실 때'(전신환 매입률) 추출 — `result[0].receiveValue`.
     * 응답: {"isSuccess":true,"result":[{"localTradedAt":"2026-09-11","closePrice":"1,348.50",
     *        "cashBuyValue":"1,372.09","cashSellValue":"1,324.91","sendValue":"1,361.71",
     *        "receiveValue":"1,335.29"}, ...]}
     *
     * 🚫 **옆 필드를 잡지 말 것** — 전부 같은 행에 있고 전부 그럴듯한 환율이다.
     *    receiveValue(받을때) ≠ sendValue(보낼때) ≠ closePrice(매매기준율).
     * 형식이 또 바뀌면 null 로 닫는다(호출자가 수기 fallback).
     */
    private function parseTtBuyingRate(string $body): ?float
    {
        $json = json_decode($body, true);
        if (! is_array($json) || ($json['isSuccess'] ?? false) !== true) {
            return null;
        }
        $raw = $json['result'][0]['receiveValue'] ?? null;
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }
        $value = (float) str_replace(',', '', (string) $raw);

        return $value > 0 ? $value : null;
    }
}
