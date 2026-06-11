<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 금액 억/만 축약 포맷 (2026-06-11) — 반올림·carry·경계 검증. */
class MoneyKrwShortTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_krw_short(int $input, string $expected): void
    {
        $this->assertSame($expected, Money::krwShort($input));
    }

    public static function cases(): array
    {
        return [
            '0' => [0, '0'],
            '1만 미만은 원' => [3_000, '3,000원'],
            '경계 9,999원' => [9_999, '9,999원'],
            '1만' => [10_000, '1만'],
            '만 단위 반올림' => [9_907_926, '991만'],     // 990.79 → 991
            '3,500만' => [35_000_000, '3,500만'],
            '9,991만' => [99_907_926, '9,991만'],
            '1억 9,488만' => [194_880_636, '1억 9,488만'],
            '19억 4,881만' => [1_948_806_360, '19억 4,881만'],
            '딱 1억' => [100_000_000, '1억'],            // man=0 → 억만
            'carry 반올림→2억' => [199_995_000, '2억'],   // rem 99,995,000 → 10,000만 → 올림
            '음수' => [-35_000_000, '-3,500만'],
        ];
    }
}
