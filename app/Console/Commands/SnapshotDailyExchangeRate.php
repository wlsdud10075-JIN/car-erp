<?php

namespace App\Console\Commands;

use App\Models\DailyExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * 매일 09:00 네이버 현재 환율을 "전날 마감"으로 스냅샷 저장 (2026-07-13, jin).
 *   ⚠️ 매일(주말 포함) 실행 — 금요일 마감이 토요일 09:00 에 (전날=금) 로 정확히 잡히게. weekdays 로 하면 금요일 유실.
 *   09:00 시점 네이버값 = 전날 마감으로 간주 → rate_date = 어제. 이후 잔금 날짜 조회에 사용.
 *   getRates() 실패 시 skip(수기 fallback). source='naver'.
 */
class SnapshotDailyExchangeRate extends Command
{
    protected $signature = 'exchange:snapshot-daily';

    protected $description = '네이버 현재 환율을 전날 마감으로 스냅샷 (매일 09:00)';

    public function handle(ExchangeRateService $service): int
    {
        $rates = $service->getRates();
        if (empty($rates)) {
            $this->warn('네이버 환율 조회 실패 — 스냅샷 skip');

            return self::SUCCESS;
        }

        $date = now()->subDay()->toDateString();   // 09:00 스냅샷 = 전날 마감
        $n = 0;
        foreach ($rates as $cur => $rate) {
            DailyExchangeRate::updateOrCreate(
                ['currency' => $cur, 'rate_date' => $date],
                ['rate' => $rate, 'source' => 'naver'],
            );
            $n++;
        }

        $this->info("스냅샷 {$date}: {$n}종 통화");

        return self::SUCCESS;
    }
}
