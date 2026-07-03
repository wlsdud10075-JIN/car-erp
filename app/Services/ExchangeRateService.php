<?php

namespace App\Services;

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
 * 결정 (2026-07-03):
 *   - 통화별 detail 페이지(exchangeDetail.naver?marketindexCd=FX_{CUR}KRW) 5회 호출
 *   - 각 페이지 tbl_exchange 의 th_ex5 행(= 송금 받으실 때) 값 파싱 (클래스 기반 → EUC-KR 라벨 무관)
 *     행 순서: th_ex2 현찰살때 / th_ex3 현찰팔때 / th_ex4 송금보낼때 / th_ex5 송금받을때
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
                $html = $this->fetchHtml(self::NAVER_DETAIL_URL.'?marketindexCd=FX_'.$cur.'KRW');
                if ($html === null) {
                    continue;   // 통화별 graceful — 실패한 통화만 빠지고 나머지 유지
                }
                $rate = $this->parseTtBuyingRate($html);
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
     * 상세페이지 tbl_exchange 에서 '송금 받으실 때'(전신환 매입률) 값 추출.
     * 행 구조: <th class="th_ex5"><span>송금 받으실 때</span></th><td> 1,516.00 </td>
     *   th_ex2 현찰살때 / th_ex3 현찰팔때 / th_ex4 송금보낼때 / th_ex5 송금받을때.
     * 클래스(th_ex5) 기반 매칭 → EUC-KR 라벨 인코딩 무관, 자리수 span 분리도 없음.
     */
    private function parseTtBuyingRate(string $html): ?float
    {
        if (preg_match('/th_ex5[^>]*>.*?<td[^>]*>\s*([\d,]+(?:\.\d+)?)\s*<\/td>/is', $html, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }
}
