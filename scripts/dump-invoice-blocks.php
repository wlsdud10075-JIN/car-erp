<?php

/** 인보이스류 템플릿의 셀러/은행 블록 전체 덤프 (읽기전용). php scripts/dump-invoice-blocks.php */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;

// [파일, 시트, 행범위, 열범위]
$targets = [
    ['sales_invoice.xlsx', 'Invoice', 1, 18, 'A', 'G'],
    ['container_invoice_packing.xlsx', 'INVOICE', 1, 20, 'A', 'P'],
    ['roro_invoice_packing.xlsx', 'INVOICE', 1, 22, 'A', 'P'],
    ['container_contract.xlsx', 'HBB340.', 1, 16, 'A', 'I'],
    ['roro_contract.xlsx', 'HBB340.', 1, 16, 'A', 'I'],
    ['clearance_set.xlsx', 'Travel Services Invoice', 12, 22, 'A', 'G'],
];

foreach ($targets as [$file, $sheetName, $r1, $r2, $c1, $c2]) {
    echo "\n======== {$file} [{$sheetName}] ========\n";
    $ss = IOFactory::load(resource_path('templates/system/'.$file));
    $sheet = $ss->getSheetByName($sheetName);
    if (! $sheet) {
        echo "  시트 없음\n";

        continue;
    }
    foreach (range($c1, $c2) as $col) {
        for ($r = $r1; $r <= $r2; $r++) {
            $v = (string) $sheet->getCell($col.$r)->getValue();
            if (trim($v) !== '') {
                printf("  %s%d: %s\n", $col, $r, str_replace("\n", '⏎', $v));
            }
        }
    }
    $ss->disconnectWorksheets();
    unset($ss);
}
