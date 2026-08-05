<?php

/**
 * 통관 SET 「말소증」 시트 B6:E6 배경색 정정 (jin 2026-08-05).
 *
 * 증상 — 생성된 말소증의 B6:E6(병합)만 흰색으로 비어 보인다. 옆칸 A6("제")는 연파랑이다.
 * 원인 — B6 이 노란 마커(FFC000)로 칠해져 있어 DocumentFiller 가 fill 을 제거한다(§12).
 *        그런데 B6 는 `=구매리스트!D3` **수식 셀**이라 애초에 자동기입 대상이 아니다
 *        (수식은 값 보존·fill 만 제거) → 노란일 이유가 없는데 색만 지워지고 있었다.
 * 조치 — 병합 범위 전체를 A6 와 같은 FFDBE5F2 로 칠한다. 노란이 아니게 되므로 필러가
 *        fill 을 건드리지 않고, 수식·값은 그대로다.
 *
 * 실행: php scripts/fix-clearance-deregistration-fill.php [--dry-run]
 * 3사 양식(system·heyman·karaba) 전부 대상 — 세 파일 모두 같은 좌표·같은 색이다(실측).
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$dry = in_array('--dry-run', $argv, true);
$root = dirname(__DIR__);

const SHEET = '말소증';
const RANGE = 'B6:E6';
const SOURCE_CELL = 'A6';          // 색을 따올 기준 칸("제")

$sets = ['system', 'heyman', 'karaba'];
$exit = 0;

foreach ($sets as $set) {
    $path = "$root/resources/templates/$set/clearance_set.xlsx";
    if (! file_exists($path)) {
        echo "[$set] 파일 없음 — skip\n";

        continue;
    }

    $ss = IOFactory::createReader('Xlsx')->load($path);
    $sheet = $ss->getSheetByName(SHEET);
    if (! $sheet) {
        echo "[$set] '".SHEET."' 시트 없음 — skip\n";
        $exit = 1;

        continue;
    }

    $target = $sheet->getStyle(SOURCE_CELL)->getFill()->getStartColor()->getARGB();
    $before = $sheet->getStyle('B6')->getFill()->getStartColor()->getARGB();
    $formula = $sheet->getCell('B6')->getValue();

    if ($before === $target) {
        echo "[$set] 이미 $target — skip\n";

        continue;
    }

    $sheet->getStyle(RANGE)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB($target);

    echo "[$set] B6:E6 $before → $target (수식 ".var_export($formula, true).")\n";

    if ($dry) {
        continue;
    }

    // 하이퍼링크 제거 + 수식 보존 저장 — generate-karaba-templates.php 와 동일 관례(§12).
    foreach ($ss->getAllSheets() as $sh) {
        foreach ($sh->getHyperlinkCollection() as $coord => $_) {
            $sh->setHyperlink($coord, null);
        }
    }
    $writer = new Xlsx($ss);
    $writer->setPreCalculateFormulas(false);   // 크로스시트 수식(=구매리스트!) 보존
    $writer->save($path);
}

exit($exit);
