<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 과거 일별 마감환율(송금 받으실때) 백필 (2026-07-13) — 3사 자동 적재.
 *   출처: jin 제공 `환율4월~현재.xlsx` (USD/EUR, 2026.04.29~07.13, H열 전신환매입률). 환율=회사 무관 보편값.
 *   insertOrIgnore = 멱등(재실행·이후 snapshot 중복 무해). 이후는 exchange:snapshot-daily(매일 09:00)로 누적.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 테스트 DB 는 각 테스트가 자체 환율 데이터를 심으므로 백필 제외(daily_exchange_rates 오염 방지).
        if (app()->runningUnitTests()) {
            return;
        }

        $now = now()->toDateTimeString();
        $rows = array_map(
            fn ($r) => $r + ['source' => 'history', 'created_at' => $now, 'updated_at' => $now],
            [
                ['currency' => 'EUR', 'rate_date' => '2026-04-29', 'rate' => 1720.09],
                ['currency' => 'EUR', 'rate_date' => '2026-04-30', 'rate' => 1713.17],
                ['currency' => 'EUR', 'rate_date' => '2026-05-04', 'rate' => 1702.57],
                ['currency' => 'EUR', 'rate_date' => '2026-05-06', 'rate' => 1685.56],
                ['currency' => 'EUR', 'rate_date' => '2026-05-07', 'rate' => 1693.43],
                ['currency' => 'EUR', 'rate_date' => '2026-05-08', 'rate' => 1705.1],
                ['currency' => 'EUR', 'rate_date' => '2026-05-11', 'rate' => 1720.4],
                ['currency' => 'EUR', 'rate_date' => '2026-05-12', 'rate' => 1733.87],
                ['currency' => 'EUR', 'rate_date' => '2026-05-13', 'rate' => 1727.14],
                ['currency' => 'EUR', 'rate_date' => '2026-05-14', 'rate' => 1725.35],
                ['currency' => 'EUR', 'rate_date' => '2026-05-15', 'rate' => 1723.58],
                ['currency' => 'EUR', 'rate_date' => '2026-05-18', 'rate' => 1722.21],
                ['currency' => 'EUR', 'rate_date' => '2026-05-19', 'rate' => 1732.75],
                ['currency' => 'EUR', 'rate_date' => '2026-05-20', 'rate' => 1724.81],
                ['currency' => 'EUR', 'rate_date' => '2026-05-21', 'rate' => 1731.38],
                ['currency' => 'EUR', 'rate_date' => '2026-05-22', 'rate' => 1742.67],
                ['currency' => 'EUR', 'rate_date' => '2026-05-26', 'rate' => 1736.14],
                ['currency' => 'EUR', 'rate_date' => '2026-05-27', 'rate' => 1728.99],
                ['currency' => 'EUR', 'rate_date' => '2026-05-28', 'rate' => 1726.14],
                ['currency' => 'EUR', 'rate_date' => '2026-05-29', 'rate' => 1738.9],
                ['currency' => 'EUR', 'rate_date' => '2026-06-01', 'rate' => 1744.28],
                ['currency' => 'EUR', 'rate_date' => '2026-06-02', 'rate' => 1762.57],
                ['currency' => 'EUR', 'rate_date' => '2026-06-04', 'rate' => 1764.84],
                ['currency' => 'EUR', 'rate_date' => '2026-06-05', 'rate' => 1777.58],
                ['currency' => 'EUR', 'rate_date' => '2026-06-08', 'rate' => 1744.54],
                ['currency' => 'EUR', 'rate_date' => '2026-06-09', 'rate' => 1741.53],
                ['currency' => 'EUR', 'rate_date' => '2026-06-10', 'rate' => 1740.18],
                ['currency' => 'EUR', 'rate_date' => '2026-06-11', 'rate' => 1740.59],
                ['currency' => 'EUR', 'rate_date' => '2026-06-12', 'rate' => 1741.06],
                ['currency' => 'EUR', 'rate_date' => '2026-06-15', 'rate' => 1738.6],
                ['currency' => 'EUR', 'rate_date' => '2026-06-16', 'rate' => 1736.96],
                ['currency' => 'EUR', 'rate_date' => '2026-06-17', 'rate' => 1737.09],
                ['currency' => 'EUR', 'rate_date' => '2026-06-18', 'rate' => 1745.16],
                ['currency' => 'EUR', 'rate_date' => '2026-06-19', 'rate' => 1740.17],
                ['currency' => 'EUR', 'rate_date' => '2026-06-22', 'rate' => 1740.54],
                ['currency' => 'EUR', 'rate_date' => '2026-06-23', 'rate' => 1727.6],
                ['currency' => 'EUR', 'rate_date' => '2026-06-24', 'rate' => 1735.43],
                ['currency' => 'EUR', 'rate_date' => '2026-06-25', 'rate' => 1739.41],
                ['currency' => 'EUR', 'rate_date' => '2026-06-26', 'rate' => 1732.94],
                ['currency' => 'EUR', 'rate_date' => '2026-06-29', 'rate' => 1743.48],
                ['currency' => 'EUR', 'rate_date' => '2026-06-30', 'rate' => 1750.3],
                ['currency' => 'EUR', 'rate_date' => '2026-07-01', 'rate' => 1749.01],
                ['currency' => 'EUR', 'rate_date' => '2026-07-02', 'rate' => 1745.15],
                ['currency' => 'EUR', 'rate_date' => '2026-07-03', 'rate' => 1731.47],
                ['currency' => 'EUR', 'rate_date' => '2026-07-06', 'rate' => 1733.99],
                ['currency' => 'EUR', 'rate_date' => '2026-07-07', 'rate' => 1713.07],
                ['currency' => 'EUR', 'rate_date' => '2026-07-08', 'rate' => 1703.42],
                ['currency' => 'EUR', 'rate_date' => '2026-07-09', 'rate' => 1705.66],
                ['currency' => 'EUR', 'rate_date' => '2026-07-10', 'rate' => 1695.9],
                ['currency' => 'EUR', 'rate_date' => '2026-07-13', 'rate' => 1689.63],
                ['currency' => 'USD', 'rate_date' => '2026-04-29', 'rate' => 1473.5],
                ['currency' => 'USD', 'rate_date' => '2026-04-30', 'rate' => 1461.1],
                ['currency' => 'USD', 'rate_date' => '2026-05-04', 'rate' => 1454.7],
                ['currency' => 'USD', 'rate_date' => '2026-05-06', 'rate' => 1434.8],
                ['currency' => 'USD', 'rate_date' => '2026-05-07', 'rate' => 1443.8],
                ['currency' => 'USD', 'rate_date' => '2026-05-08', 'rate' => 1449.7],
                ['currency' => 'USD', 'rate_date' => '2026-05-11', 'rate' => 1460.6],
                ['currency' => 'USD', 'rate_date' => '2026-05-12', 'rate' => 1477.4],
                ['currency' => 'USD', 'rate_date' => '2026-05-13', 'rate' => 1475],
                ['currency' => 'USD', 'rate_date' => '2026-05-14', 'rate' => 1478.9],
                ['currency' => 'USD', 'rate_date' => '2026-05-15', 'rate' => 1483.4],
                ['currency' => 'USD', 'rate_date' => '2026-05-18', 'rate' => 1477.4],
                ['currency' => 'USD', 'rate_date' => '2026-05-19', 'rate' => 1493.1],
                ['currency' => 'USD', 'rate_date' => '2026-05-20', 'rate' => 1483.9],
                ['currency' => 'USD', 'rate_date' => '2026-05-21', 'rate' => 1490.8],
                ['currency' => 'USD', 'rate_date' => '2026-05-22', 'rate' => 1497.2],
                ['currency' => 'USD', 'rate_date' => '2026-05-26', 'rate' => 1492.8],
                ['currency' => 'USD', 'rate_date' => '2026-05-27', 'rate' => 1487.3],
                ['currency' => 'USD', 'rate_date' => '2026-05-28', 'rate' => 1481.9],
                ['currency' => 'USD', 'rate_date' => '2026-05-29', 'rate' => 1492.8],
                ['currency' => 'USD', 'rate_date' => '2026-06-01', 'rate' => 1499.7],
                ['currency' => 'USD', 'rate_date' => '2026-06-02', 'rate' => 1520],
                ['currency' => 'USD', 'rate_date' => '2026-06-04', 'rate' => 1519],
                ['currency' => 'USD', 'rate_date' => '2026-06-05', 'rate' => 1544.3],
                ['currency' => 'USD', 'rate_date' => '2026-06-08', 'rate' => 1512.9],
                ['currency' => 'USD', 'rate_date' => '2026-06-09', 'rate' => 1509.6],
                ['currency' => 'USD', 'rate_date' => '2026-06-10', 'rate' => 1509.6],
                ['currency' => 'USD', 'rate_date' => '2026-06-11', 'rate' => 1504.2],
                ['currency' => 'USD', 'rate_date' => '2026-06-12', 'rate' => 1499.9],
                ['currency' => 'USD', 'rate_date' => '2026-06-15', 'rate' => 1500.7],
                ['currency' => 'USD', 'rate_date' => '2026-06-16', 'rate' => 1496.2],
                ['currency' => 'USD', 'rate_date' => '2026-06-17', 'rate' => 1510.6],
                ['currency' => 'USD', 'rate_date' => '2026-06-18', 'rate' => 1523],
                ['currency' => 'USD', 'rate_date' => '2026-06-19', 'rate' => 1518],
                ['currency' => 'USD', 'rate_date' => '2026-06-22', 'rate' => 1523.5],
                ['currency' => 'USD', 'rate_date' => '2026-06-23', 'rate' => 1518.5],
                ['currency' => 'USD', 'rate_date' => '2026-06-24', 'rate' => 1528.4],
                ['currency' => 'USD', 'rate_date' => '2026-06-25', 'rate' => 1529.9],
                ['currency' => 'USD', 'rate_date' => '2026-06-26', 'rate' => 1522.5],
                ['currency' => 'USD', 'rate_date' => '2026-06-29', 'rate' => 1526.4],
                ['currency' => 'USD', 'rate_date' => '2026-06-30', 'rate' => 1532.9],
                ['currency' => 'USD', 'rate_date' => '2026-07-01', 'rate' => 1537.3],
                ['currency' => 'USD', 'rate_date' => '2026-07-02', 'rate' => 1527.4],
                ['currency' => 'USD', 'rate_date' => '2026-07-03', 'rate' => 1514.1],
                ['currency' => 'USD', 'rate_date' => '2026-07-06', 'rate' => 1515.6],
                ['currency' => 'USD', 'rate_date' => '2026-07-07', 'rate' => 1502.4],
                ['currency' => 'USD', 'rate_date' => '2026-07-08', 'rate' => 1492.3],
                ['currency' => 'USD', 'rate_date' => '2026-07-09', 'rate' => 1492.3],
                ['currency' => 'USD', 'rate_date' => '2026-07-10', 'rate' => 1487.3],
                ['currency' => 'USD', 'rate_date' => '2026-07-13', 'rate' => 1478.5],
            ]
        );

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('daily_exchange_rates')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        DB::table('daily_exchange_rates')->where('source', 'history')->delete();
    }
};
