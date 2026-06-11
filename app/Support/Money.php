<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * 금액 표시 헬퍼 — 대시보드 KPI 등 큰 원화 금액을 한국식 억/만 단위로 축약.
 * (2026-06-11 사용자 결정: 억/만 단위, 만 단위까지 정확. 정확한 원 단위는 툴팁 보존.)
 *
 *   krwShort(194_880_636) === '1억 9,488만'
 *   krwShort(99_907_926)  === '9,991만'
 *   krwShort(1_948_806_360) === '19억 4,881만'
 *   krwShort(3_000)       === '3,000원'   (1만 미만은 원 단위 그대로)
 *   krwShort(0)           === '0'
 *
 * Blade 에서는 @krw($amount) 디렉티브 사용 → 툴팁(정확 금액) 포함 <span> 출력.
 */
class Money
{
    /** 억/만 단위 축약 문자열 (만 단위 반올림, 1만 미만은 원). */
    public static function krwShort(int|float|string|null $value): string
    {
        $n = (int) round((float) $value);
        if ($n === 0) {
            return '0';
        }

        $sign = $n < 0 ? '-' : '';
        $n = abs($n);

        // 1만 미만은 과한 반올림 방지 — 원 단위 그대로
        if ($n < 10_000) {
            return $sign.number_format($n).'원';
        }

        $eok = intdiv($n, 100_000_000);              // 억
        $man = (int) round(($n % 100_000_000) / 10_000); // 만 (반올림)

        // 반올림 carry: 만이 10,000 이 되면 1억으로 올림 (예: 199,995,000 → 2억)
        if ($man >= 10_000) {
            $eok += intdiv($man, 10_000);
            $man %= 10_000;
        }

        $parts = [];
        if ($eok > 0) {
            $parts[] = number_format($eok).'억';
        }
        if ($man > 0) {
            $parts[] = number_format($man).'만';
        }

        return $sign.implode(' ', $parts);
    }

    /** 정확 원 단위 ('₩1,234,567'). 툴팁·상세용. */
    public static function krwExact(int|float|string|null $value): string
    {
        return '₩'.number_format((int) round((float) $value));
    }

    /** Blade @krw 용 — 축약 표시 + 정확 금액 title 툴팁 <span>. */
    public static function krwTag(int|float|string|null $value): HtmlString
    {
        $short = e(self::krwShort($value));
        $exact = e(self::krwExact($value));

        return new HtmlString('<span class="cursor-help" title="'.$exact.'">'.$short.'</span>');
    }
}
