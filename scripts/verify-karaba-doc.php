<?php

/** karaba 템플릿 end-to-end 검증 — COMPANY_TEMPLATE_SET=karaba 로 실제 서류 생성, 셀러/은행 셀 확인. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Contracts\Console\Kernel;

config(['company.template_set' => 'karaba']);   // 런타임 테넌트 분기
echo 'template_set = '.config('company.template_set')."\n\n";

$v = Vehicle::where('sales_channel', 'export')->where('sale_price', '>', 0)->first();
echo "테스트 차량: {$v->vehicle_number} (export)\n\n";

// 1) sales_invoice — 셀러 A2 + 은행 C13~15·E13~15
$ss = (new DocumentFiller($v))->spreadsheet('invoice');
$sheet = $ss->getSheetByName('Invoice');
echo "── invoice (Invoice 시트)\n";
foreach (['A2', 'C13', 'C14', 'C15', 'E13', 'E14', 'E15'] as $c) {
    echo "  {$c}: ".str_replace("\n", '⏎', (string) $sheet->getCell($c)->getValue())."\n";
}

// 2) clearance — 크로스시트 복잡 양식이 깨지지 않고 karaba 은행 나오는지
$ss2 = (new DocumentFiller($v))->spreadsheet('clearance');
$tsi = $ss2->getSheetByName('Travel Services Invoice');
echo "\n── clearance (Travel Services Invoice 시트)\n";
foreach (['C16', 'C17', 'C18', 'F16', 'F17', 'F18'] as $c) {
    echo "  {$c}: ".str_replace("\n", '⏎', (string) $tsi->getCell($c)->getValue())."\n";
}
echo "\n✅ 두 양식 모두 예외 없이 생성됨 (엔진 정상).\n";
