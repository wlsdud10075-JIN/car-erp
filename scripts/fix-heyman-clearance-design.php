<?php

/**
 * heyman 통관 SET 디자인 적용 (jin 2026-09-22 「파일과 다 같게」) — 항목·이유 = scripts/lib/heyman-clearance-design.php.
 *
 *   php scripts/fix-heyman-clearance-design.php            # dry-run (계획만)
 *   php scripts/fix-heyman-clearance-design.php --apply    # resources/templates/heyman/clearance_set.xlsx 에 기록
 *   php scripts/fix-heyman-clearance-design.php --verify   # 현행 양식이 디자인대로인지 (배포 후 운영에서도)
 *
 * 저장 = setPreCalculateFormulas(false)(cascade 보존) · 하이퍼링크 제거 · 등록증 3시트 Gridlines OFF 재적용
 * (reader 오독 — generate-heyman-templates.php 와 같은 처리).
 */

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/lib/heyman-clearance-design.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$verify = in_array('--verify', $argv, true);
$path = resource_path('templates/heyman/clearance_set.xlsx');

if ($verify) {
    $bad = verifyHeymanClearanceDesign(IOFactory::load($path));
    foreach ($bad as $b) {
        echo "❌ {$b}\n";
    }
    echo $bad ? '검증 실패 '.count($bad)."건\n" : "✅ heyman 통관 SET 디자인 일치\n";
    exit($bad ? 1 : 0);
}

$ss = IOFactory::load($path);
foreach (applyHeymanClearanceDesign($ss, $apply) as $line) {
    echo ($apply ? '  ✔ ' : '  · ')."{$line}\n";
}
if (! $apply) {
    echo "dry-run — --apply 로 실제 기록.\n";
    exit(0);
}
foreach (['한글등록증', '영문등록증', '말소증'] as $gsh) {
    if ($g = $ss->getSheetByName($gsh)) {
        $g->setShowGridlines(false);
    }
}
foreach ($ss->getWorksheetIterator() as $sh) {
    foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
        $sh->setHyperlink($hc, null);
    }
}
$w = new Xlsx($ss);
$w->setPreCalculateFormulas(false);
$w->save($path);
echo "✅ 저장: heyman/clearance_set.xlsx\n";
$bad = verifyHeymanClearanceDesign(IOFactory::load($path));
echo $bad ? '❌ 저장 후 검증 실패: '.implode(' / ', $bad)."\n" : "✅ 저장 후 재검증 통과\n";
exit($bad ? 1 : 0);
