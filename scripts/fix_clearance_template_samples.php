<?php

/**
 * clearance_set.xlsx 잔존 샘플 리터럴 제거 (2026-06-11).
 *
 * `구매리스트` 마스터의 비매핑 셀 3개에 옛 샘플값이 남아 연동시트로 누출:
 *   - D3 = '4115-202508-054438'  → 말소증!B6 (가짜 매매업등록번호)
 *   - G3 = '202508-030501'       → 한글/영문등록증!C3 (가짜 매매업등록번호)
 *   - H13 = 13.9                 → 한글/영문등록증!F31 (가짜 연료소비율/연비)
 * 셋 다 매핑이 일부러 비우는 칸(D3/G3=매매업번호 수기, H13=연비 데이터소스 없음)인데
 * 노란셀이 아니라 DocumentFiller 클린업 대상 밖 → 모든 통관 서류에 가짜값 노출.
 * 해소: 해당 3칸을 비운다(스타일·수식·병합·노란셀 전부 보존).
 *
 *   php scripts/fix_clearance_template_samples.php
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$path = __DIR__.'/../resources/templates/system/clearance_set.xlsx';

$ss = IOFactory::load($path);
$master = $ss->getSheetByName('구매리스트');

$targets = ['D3', 'G3', 'H13'];
echo "변경 전:\n";
foreach ($targets as $c) {
    echo "  {$c} = '".$master->getCell($c)->getValue()."'\n";
}

foreach ($targets as $c) {
    $master->getCell($c)->setValueExplicit(null, DataType::TYPE_NULL);
}

(new Xlsx($ss))->save($path);
echo "\n저장 완료: {$path}\n";

// ── 검증: 재로드해서 (1) 3칸 비었나 (2) 수식/병합/노란셀 보존됐나 ──
$v = IOFactory::load($path);
$m = $v->getSheetByName('구매리스트');
echo "\n검증:\n";
foreach ($targets as $c) {
    $val = $m->getCell($c)->getValue();
    echo "  {$c} = ".($val === null || $val === '' ? '∅ 비움 OK' : "'{$val}' ❌ 남음")."\n";
}
$i13 = $m->getCell('I13')->getValue();
echo '  I13 수식 보존 = '.(is_string($i13) && str_starts_with($i13, '=') ? 'OK' : "❌ ('{$i13}')")."\n";
$i6 = $m->getCell('I6')->getValue();
echo '  I6 수식 보존  = '.(is_string($i6) && str_starts_with($i6, '=') ? 'OK' : "❌ ('{$i6}')")."\n";
echo '  시트 수       = '.count($v->getAllSheets()).' (기대 7)'."\n";
// 연동 참조 보존 확인
$h = $v->getSheetByName('한글등록증');
echo '  한글등록증!F31 = '.$h->getCell('F31')->getValue().' (=구매리스트!H13 참조 보존)'."\n";
