<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 일별 마감환율(송금 받으실때/전신환 매입률). 잔금 날짜의 환율 자동 기입 소스.
 * source: history(과거 xlsx backfill) | naver(매일 09:00 스냅샷).
 */
class DailyExchangeRate extends Model
{
    protected $fillable = ['currency', 'rate_date', 'rate', 'source'];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:4',
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
}
