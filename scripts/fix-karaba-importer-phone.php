<?php

/**
 * karaba 말소신청서 — 수임자 「사업자번호」 칸(1.차량말소신청서!C39)의 731110-1041111 → 010-4703-0627 (jin 2026-09-22).
 *   흰칸·매핑 없음이라 양식 리터럴이 그대로 인쇄된다(SKILLS §8 #71). karaba 세트에만 있다(3세트 전수 스캔 2026-09-22).
 *
 *   php scripts/fix-karaba-importer-phone.php            # dry-run
 *   php scripts/fix-karaba-importer-phone.php --apply
 *   php scripts/fix-karaba-importer-phone.php --verify   # 3세트 전부에서 옛 번호 0 · karaba C39 = 새 번호
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const OLD_NO = '731110-1041111';
const NEW_NO = '010-4703-0627';
$path = __DIR__.'/../resources/templates/karaba/deregistration_application.xlsx';
$apply = in_array('--apply', $argv, true);
$verify = in_array('--verify', $argv, true);

$plain = fn ($v) => $v instanceof RichText ? $v->getPlainText() : $v;

if ($verify) {
    $bad = 0;
    foreach (['system', 'heyman', 'karaba'] as $set) {
        foreach (glob(__DIR__."/../resources/templates/$set/*.xlsx") as $f) {
            $ss = IOFactory::load($f);
            foreach ($ss->getAllSheets() as $ws) {
                foreach ($ws->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $v = $plain($cell->getValue());
                        if (is_string($v) && str_contains($v, OLD_NO)) {
                            printf("❌ 옛 번호 잔존: %s/%s %s!%s\n", $set, basename($f), $ws->getTitle(), $cell->getCoordinate());
                            $bad++;
                        }
                    }
                }
            }
        }
    }
    $c39 = (string) $plain(IOFactory::load($path)->getSheetByName('1.차량말소신청서')->getCell('C39')->getValue());
    printf("%s karaba C39 = %s\n", $c39 === NEW_NO ? '✅' : '❌', $c39);
    exit($bad === 0 && $c39 === NEW_NO ? 0 : 1);
}

$ss = IOFactory::load($path);
$ws = $ss->getSheetByName('1.차량말소신청서');
$cur = (string) $plain($ws->getCell('C39')->getValue());
printf("C39 현재 = %s → %s\n", $cur, NEW_NO);
if ($cur !== OLD_NO) {
    echo "대상이 아니다(이미 바뀌었거나 다른 값) — 아무것도 안 한다.\n";
    exit($cur === NEW_NO ? 0 : 1);
}
if (! $apply) {
    echo "dry-run — --apply 로 실제 기록.\n";
    exit(0);
}
$ws->setCellValue('C39', NEW_NO);
$w = new XlsxWriter($ss);
$w->setPreCalculateFormulas(false);
$w->save($path);
echo "✅ 기록했다.\n";
