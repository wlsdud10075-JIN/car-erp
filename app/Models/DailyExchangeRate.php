<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
