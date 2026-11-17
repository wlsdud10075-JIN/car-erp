<?php

namespace App\Console\Commands;

use App\Models\DailyExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * 💱 일별 **확정** 환율 수집 — 네이버 날짜별 이력에서 오늘 이전 영업일 행을 그 날짜로 저장 (🔀 2026-11-17 jin «진짜 마감으로»).
 *
 * 구(07-13): 09:00 에 「지금 값」을 받아 rate_date=어제로 저장했다. 실측(11-04~11-16 USD) 그건 **다음날 아침 장중 값**이라
 * 확정값과 1~2원씩 어긋났고, 주말 날짜에도 값이 찍혔다. 12월부터 판매잔금 환율을 사람 대신 「전날 마감」으로 기록하므로
 * 마감값이 정확해야 한다.
 *
 * 신: prices JSON 한 페이지(영업일 10행)를 받아 `localTradedAt < 오늘(KST)` 인 행만 그 날짜로 upsert —
 *     매번 10행을 다시 넣으므로 하루 빠져도 다음 실행이 메운다. 송금 받으실 때(rate) + 매매기준율(base_rate).
 *     주말·공휴일 행은 네이버에 없다 — 조회(`rateForDate`)가 직전 영업일로 넘긴다.
 *
 *   php artisan exchange:snapshot-daily                    # 최근 10영업일 (스케줄)
 *   php artisan exchange:snapshot-daily --pages=20 --prune # 과거 재수집 + 그 기간의 비영업일 naver 행 삭제(옛 방식 잔재)
 *   --dry-run 으로 바뀔 값만 본다.
 */
class SnapshotDailyExchangeRate extends Command
{
    protected $signature = 'exchange:snapshot-daily {--pages=1 : 받을 페이지 수(1페이지 = 10영업일)} {--prune : 받은 기간 안의 비영업일 naver 행 삭제} {--dry-run}';

    protected $description = '네이버 날짜별 확정 환율(송금 받으실 때·매매기준율)을 그 날짜로 저장';

    private const CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY'];

    public function handle(ExchangeRateService $service): int
    {
        $today = now()->toDateString();   // app.timezone = Asia/Seoul
        $pages = max(1, (int) $this->option('pages'));
        $dry = (bool) $this->option('dry-run');
        $saved = $changed = $pruned = 0;
        $failed = [];

        foreach (self::CURRENCIES as $cur) {
            $rows = [];
            for ($p = 1; $p <= $pages; $p++) {
                $page = $service->fetchHistoryPage($cur, $p);
                if ($page === null) {
                    $failed[] = "{$cur} p{$p}";
                    break;
                }
                if ($page === []) {
                    break;
                }
                $rows = array_merge($rows, $page);
            }
            $rows = array_values(array_filter($rows, fn ($r) => $r['date'] < $today));   // 오늘 행 = 장중(미확정)
            if ($rows === []) {
                continue;
            }

            foreach ($rows as $r) {
                $old = DailyExchangeRate::where('currency', $cur)->whereDate('rate_date', $r['date'])->first();
                $diff = ! $old || abs((float) $old->rate - $r['rate']) > 0.00001 || (float) $old->base_rate !== (float) $r['base_rate'];
                if ($diff) {
                    $changed++;
                    if ($dry || $this->output->isVerbose()) {
                        $this->line(sprintf('%s %s  %s → %s  (기준 %s)', $cur, $r['date'], $old ? $old->rate : '없음', $r['rate'], $r['base_rate'] ?? '-'));
                    }
                }
                if (! $dry) {
                    // 찾은 행을 직접 갱신 — updateOrCreate 의 날짜 문자열 비교는 드라이버마다 달라(SQLite datetime) 중복 insert 로 죽는다.
                    $old
                        ? $old->update(['rate' => $r['rate'], 'base_rate' => $r['base_rate'], 'source' => 'naver'])
                        : DailyExchangeRate::create(['currency' => $cur, 'rate_date' => $r['date'], 'rate' => $r['rate'], 'base_rate' => $r['base_rate'], 'source' => 'naver']);
                }
                $saved++;
            }

            // 옛 방식(09:00 스냅샷)이 비영업일 날짜에 찍은 값 — 받은 기간 안에서만 지운다(xlsx history 행은 안 건드림).
            if ($this->option('prune')) {
                $dates = array_column($rows, 'date');
                $q = DailyExchangeRate::where('currency', $cur)->where('source', 'naver')
                    ->whereDate('rate_date', '>=', min($dates))->whereDate('rate_date', '<=', max($dates))
                    ->get()->filter(fn ($x) => ! in_array($x->rate_date->toDateString(), $dates, true));
                $pruned += $q->count();
                if (! $dry) {
                    $q->each->delete();
                }
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."저장 {$saved}행 · 값이 바뀐/새 행 {$changed} · 비영업일 삭제 {$pruned}".($failed ? ' · 실패 '.implode(',', $failed) : ''));

        // 전부 실패면 실패로 끝낸다 — 아침 점검·스케줄 기록이 「멈춤」을 알 수 있게(구: 실패해도 SUCCESS).
        return count($failed) >= count(self::CURRENCIES) ? self::FAILURE : self::SUCCESS;
    }
}
