<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 일별 마감환율. `rate` = 송금 받으실 때(전신환 매입률) · `base_rate` = 매매기준율(2026-11-17~).
 * source: history(과거 xlsx backfill) | naver(네이버 날짜별 확정값 — 🔀 2026-11-17 부터, 구: 09:00 장중 스냅샷).
 */
class DailyExchangeRate extends Model
{
    /** 환율 종류 — 기능설정 「판매잔금 자동 기록 환율」(Setting::paymentRateKind) */
    public const KIND_RECEIVE = 'receive';   // 송금 받으실 때

    public const KIND_BASE = 'base';         // 매매기준율

    protected $fillable = ['currency', 'rate_date', 'rate', 'base_rate', 'source'];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:4',
        'base_rate' => 'decimal:4',
    ];

    /**
     * 해당 날짜(또는 그 이전 가장 최근 영업일)의 마감환율.
     *   - 주말·공휴일(마감 없는 날) → 직전 영업일 값으로 carry-forward (rate_date <= date desc).
     *   - 데이터 범위 이전 날짜 / 미보유 통화 → null (수기입력, 추측 안 함).
     *   - KRW → 1.0.
     */
    public static function rateForDate(string $currency, string $date): ?float
    {
        if ($currency === 'KRW') {
            return 1.0;
        }

        $row = static::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();

        return $row ? (float) $row->rate : null;
    }

    /**
     * 💱 **전날 마감** — `$date` 보다 앞선 가장 최근 영업일의 확정 환율 (jin 2026-11-17, 판매잔금 자동 기록).
     * 주말·공휴일은 네이버에 행이 없어 저절로 직전 영업일로 간다. 그 종류(매매기준율)가 비어 있는 옛 행은 건너뛴다.
     *
     * @return array{rate: float, date: string}|null 못 찾으면 null(호출자는 비워 두고 판매환율로 계산되게 한다)
     */
    public static function closingBefore(string $currency, string $date, string $kind = self::KIND_RECEIVE): ?array
    {
        if ($currency === 'KRW') {
            return ['rate' => 1.0, 'date' => $date];
        }
        $col = $kind === self::KIND_BASE ? 'base_rate' : 'rate';
        $row = static::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', '<', $date)
            ->whereNotNull($col)
            ->orderByDesc('rate_date')
            ->first();

        return $row ? ['rate' => (float) $row->{$col}, 'date' => $row->rate_date->toDateString()] : null;
    }

    /** 마감환율을 보여 주는 통화 · 종류 — 업무 대시보드와 board `/rates` 의 `closing` 이 같이 쓴다. */
    public const SUMMARY_CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY'];

    public const SUMMARY_KINDS = [self::KIND_RECEIVE, self::KIND_BASE];

    /**
     * 💱 마감환율 요약 — 업무 대시보드 「전날 마감」 줄과 board `/rates` 의 `closing` 블록의 **단일 출처** (jin 2026-11-26 · 11-27).
     *
     * - `$onDate` 없음 = **지금 기준 전날 마감**(오늘보다 앞선 가장 최근 영업일). `stale` = 받아야 할 날(직전 평일)보다 늦다.
     * - `$onDate` 있음 = **그 날짜 기준**(그날, 주말·공휴일이면 직전 영업일 — `rate_date <= 날짜`). 과거 조회라 `stale` 은 늘 false.
     * - `date` = 실제 값이 있는 날짜. 통화마다 다르면 가장 오래된 날(하나라도 늦으면 받아오기가 필요하다).
     * - 값이 하나도 없으면 date 는 null. 매매기준율이 없는 옛 행은 그 칸만 null.
     *
     * @return array{date: ?string, stale: bool, rows: array<string, array<string, ?float>>}
     */
    public static function closingSummary(?string $onDate = null): array
    {
        // closingBefore 는 「그 날짜보다 앞」을 본다 — 날짜를 지정했으면 그날까지 포함하도록 하루 뒤를 넘긴다.
        $before = $onDate !== null ? Carbon::parse($onDate)->addDay()->toDateString() : now()->toDateString();
        $rows = [];
        $dates = [];
        foreach (self::SUMMARY_CURRENCIES as $cur) {
            foreach (self::SUMMARY_KINDS as $kind) {
                $hit = static::closingBefore($cur, $before, $kind);
                $rows[$kind][$cur] = $hit['rate'] ?? null;
                if ($hit) {
                    $dates[] = $hit['date'];
                }
            }
        }
        $date = $dates ? min($dates) : null;
        if ($onDate !== null) {
            return ['date' => $date, 'stale' => false, 'rows' => $rows];
        }
        // 받아야 할 날 = 직전 평일(공휴일은 모른다 — 공휴일 다음 날엔 「지난 마감」 표시가 정상이다)
        $expected = now()->subDay();
        while ($expected->isWeekend()) {
            $expected->subDay();
        }

        return ['date' => $date, 'stale' => $date === null || $date < $expected->toDateString(), 'rows' => $rows];
    }
}
