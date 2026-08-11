<?php

namespace App\Console\Commands;

use App\Services\KoreanHolidayService;
use Illuminate\Console\Command;

/**
 * 공휴일 자동 수집 — 올해 + 내년.
 *
 * 매일 도는 이유: **임시공휴일·대체공휴일·선거일은 연중에 갑자기 지정된다.** 연 1회만 받으면
 * 그 사이에 지정된 날을 놓쳐 그날 담당자에게 알림이 간다. 요청 2건/일이라 부담도 없다.
 * 내년치를 함께 받는 이유 = 연말에 다음 해 달력이 먼저 나오므로 1월 1일에 비어 있지 않게.
 */
class SyncKoreanHolidays extends Command
{
    protected $signature = 'holidays:sync {--year= : 특정 연도만}';

    protected $description = '대한민국 공휴일을 한국천문연구원 특일 API 에서 받아 저장 (알림톡 수신자 시각 규칙용)';

    public function handle(KoreanHolidayService $svc): int
    {
        if (! KoreanHolidayService::isConfigured()) {
            $this->warn('HOLIDAY_API_KEY 미설정 — 건너뜁니다. 내장 고정 공휴일 + 수기 등록분으로만 판정합니다.');

            return self::SUCCESS;   // 미설정은 오류가 아니다(스케줄을 빨갛게 만들지 않는다)
        }

        $years = $this->option('year')
            ? [(int) $this->option('year')]
            : [(int) now()->year, (int) now()->year + 1];

        foreach ($years as $year) {
            $n = $svc->syncYear($year);
            $n === null
                ? $this->warn("{$year}년 — 수집 실패 (직전 저장분 유지). 상세는 laravel.log")
                : $this->info("{$year}년 — 공휴일 {$n}일 저장");
        }

        return self::SUCCESS;
    }
}
