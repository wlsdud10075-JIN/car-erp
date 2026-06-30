<?php

/** 시스템 템플릿 9종에서 SSANCAR 회사정보 셀 스캔 (읽기전용). php scripts/scan-template-company.php */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;

$dir = resource_path('templates/'.($argv[1] ?? 'system'));
$files = glob($dir.'/*.xlsx');

// SSANCAR 식별 토큰 (대소문자 무시 substring)
$tokens = ['싼카', 'SSANCAR', '662-81-00898', '02-4115-000476', '조태신', 'Cho Tae Shin',
    'SHINHAN', '신한', 'KOEXKRSE', '430-910063', '499-1988', '499-1989', 'man99777',
    '산기대학로', 'Sangideahak', 'Siheung', '시흥', 'Sejong', '430910063'];

foreach ($files as $f) {
    $name = basename($f);
    echo "\n======== {$name} ========\n";
    try {
        $ss = IOFactory::load($f);
    } catch (Throwable $e) {
        echo "  로드실패: {$e->getMessage()}\n";

        continue;
    }
    $hits = 0;
    foreach ($ss->getWorksheetIterator() as $sheet) {
        $sheetName = $sheet->getTitle();
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $v = (string) $cell->getValue();
                if ($v === '') {
                    continue;
                }
                foreach ($tokens as $t) {
                    if (stripos($v, $t) !== false) {
                        printf("  [%s!%s] %s\n", $sheetName, $cell->getCoordinate(), str_replace("\n", '⏎', $v));
                        $hits++;
                        break;
                    }
                }
            }
        }
    }
    if ($hits === 0) {
        echo "  (SSANCAR 토큰 없음 — 회사정보 미포함 양식)\n";
    }
    $ss->disconnectWorksheets();
    unset($ss);
}
