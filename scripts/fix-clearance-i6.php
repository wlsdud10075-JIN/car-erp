<?php

// 통관SET 구매리스트 I6 차종 영문변환 수식 교정 — 템플릿이 "중형승용"(붙임)인데
// 실제 NICE 차종은 "승용 중형"(띄움)이라 SUBSTITUTE 미스매치 → 한글로 남던 버그.
// jin 확인 포맷("승용 소형/중형/대형"·"중형/대형 승합"·"중형 화물")으로 교체. system+karaba 둘 다.
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$formula = '=SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE('
    .'G6,"승용 소형","Small Passenger"),"승용 중형","Medium Passenger"),"승용 대형","HEAVY Passenger"),'
    .'"중형 승합","Medium Ven"),"대형 승합","Large Ven"),"중형 화물","Medium Cargo")';

foreach (['system', 'karaba'] as $set) {
    $path = __DIR__."/../resources/templates/{$set}/clearance_set.xlsx";
    if (! is_file($path)) {
        echo "skip {$set} (없음)\n";

        continue;
    }
    $ss = IOFactory::createReader('Xlsx')->load($path);
    $sheet = $ss->getSheetByName('구매리스트');
    $before = $sheet->getCell('I6')->getValue();
    $sheet->getCell('I6')->setValueExplicit($formula, DataType::TYPE_FORMULA);

    $writer = new Xlsx($ss);
    $writer->setPreCalculateFormulas(false);   // 수식을 값으로 굳히지 않음 (생성 시 DocumentFiller 가 재계산)
    $writer->save($path);

    echo "[{$set}] I6 교체 완료\n  before: ".substr((string) $before, 0, 40)."...\n  after : ".substr($formula, 0, 40)."...\n";
}
