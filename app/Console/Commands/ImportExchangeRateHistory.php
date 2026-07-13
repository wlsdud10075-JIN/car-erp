<?php

namespace App\Console\Commands;

use App\Models\DailyExchangeRate;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 과거 일별 환율 xlsx → daily_exchange_rates 적재 (2026-07-13).
 *   양식(jin 제공 `환율4월~현재.xlsx`): 시트명=통화(usd/eur…), B열=날짜("2026.07.13"), H열=송금 받으실때(전신환 매입률).
 *   네이버 송금받을때와 동일 유형(1원 내외 일치) — seam 없음. source='history'.
 */
class ImportExchangeRateHistory extends Command
{
    protected $signature = 'exchange:import-history {file : xlsx 경로 (시트=통화, B=날짜, H=송금받으실때)}';

    protected $description = '과거 일별 환율(송금 받으실때) xlsx → daily_exchange_rates 적재';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error('파일 없음: '.$file);

            return self::FAILURE;
        }

        $ss = IOFactory::load($file);
        $supported = ['USD', 'JPY', 'EUR', 'GBP', 'CNY'];
        $total = 0;

        foreach ($ss->getSheetNames() as $sheetName) {
            $cur = strtoupper(trim($sheetName));
            if (! in_array($cur, $supported, true)) {
                $this->warn("시트 건너뜀(통화 아님): {$sheetName}");

                continue;
            }

            $sheet = $ss->getSheetByName($sheetName);
            $count = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $r = $row->getRowIndex();
                $dateRaw = trim((string) $sheet->getCell('B'.$r)->getValue());
                // 날짜 형식 "2026.07.13" 인 행만 (헤더·빈행 skip)
                if (! preg_match('/^(\d{4})\.(\d{2})\.(\d{2})$/', $dateRaw, $m)) {
                    continue;
                }
                $rateRaw = str_replace(',', '', (string) $sheet->getCell('H'.$r)->getValue());
                if (! is_numeric($rateRaw)) {
                    continue;
                }

                DailyExchangeRate::updateOrCreate(
                    ['currency' => $cur, 'rate_date' => "{$m[1]}-{$m[2]}-{$m[3]}"],
                    ['rate' => (float) $rateRaw, 'source' => 'history'],
                );
                $count++;
            }
            $this->line("{$cur}: {$count}행");
            $total += $count;
        }

        $this->info("적재 완료: 총 {$total}행");

        return self::SUCCESS;
    }
}
