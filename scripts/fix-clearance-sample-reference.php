<?php

/**
 * 통관 SET — 인보이스·팩킹리스트에 박힌 **샘플 컨테이너 번호**를 지운다 (jin 2026-08-31).
 *
 * ## 무엇이 있었나
 * `차량인보이스`·`차량팩킹` 의 `E2`·`E3` 에 라벨과 함께 **양식 제작 당시 차량의 번호**가 박혀 있었다:
 *
 *   E2 = "Reference No.      DFSU6646075"
 *   E3 = "Reference code.   DFSU6646075"
 *
 * 3 사 양식 전부 동일했다. **흰칸이라 `DocumentFiller` 가 안 지운다**(노란칸이었으면 비워졌을 것 —
 * 같은 샘플 잔재인데 칸 색깔로 운명이 갈린다, SKILLS §8 #71).
 *
 * 🫥 **지금까지 아무도 못 본 이유** — `E2:F2` 병합 폭이 9.9 밖에 안 되고 옆 `G2` 에 값이 있어
 *    글자가 **잘려서 보이지 않았다**. jin: *"이거 잘려서 내가 못봤네 클릭하기 전까지는."*
 *    ⇒ **폭·병합을 건드리면 드러난다.** 잘려서 안 보이는 것은 「없는 것」이 아니다.
 *
 * ## 무엇을 하나
 * 번호만 떼고 **라벨은 남긴다**(칸의 뜻이 사라지면 안 된다). 서식은 셀 스타일(Arial Narrow 9pt)에
 * 있고 RichText run 이 1 개뿐이라, 평문으로 바꿔도 글꼴이 유지된다(실측).
 *
 * 🔒 **현재 값이 예상과 정확히 같을 때만 바꾼다.** 다르면 건드리지 않고 보고한다 —
 *    누군가 손으로 고쳐 둔 것을 이 스크립트가 조용히 덮어쓰지 않게.
 *
 *   php scripts/fix-clearance-sample-reference.php            # dry-run
 *   php scripts/fix-clearance-sample-reference.php --apply
 *   php scripts/fix-clearance-sample-reference.php --verify
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** 시트 => [셀 => [현재 예상값, 남길 라벨]] */
const TARGETS = [
    '차량인보이스' => [
        'E2' => ['Reference No.      DFSU6646075', 'Reference No.'],
        'E3' => ['Reference code.   DFSU6646075', 'Reference code.'],
    ],
    '차량팩킹' => [
        'E2' => ['Reference No.      DFSU6646075', 'Reference No.'],
        'E3' => ['Reference code.   DFSU6646075', 'Reference code.'],
    ],
];

$APPLY = in_array('--apply', $argv, true);
$VERIFY = in_array('--verify', $argv, true);
$root = __DIR__.'/../resources/templates/';

foreach (['system', 'heyman', 'karaba'] as $set) {
    $path = $root.$set.'/clearance_set.xlsx';
    if (! is_readable($path)) {
        echo "SKIP {$set} — 파일 없음\n";

        continue;
    }
    $ss = IOFactory::createReaderForFile($path)->load($path);
    $changed = 0;

    foreach (TARGETS as $sheetName => $cells) {
        $ws = $ss->getSheetByName($sheetName);
        foreach ($cells as $coord => [$expected, $label]) {
            $raw = $ws->getCell($coord)->getValue();
            $text = is_object($raw) ? $raw->getPlainText() : (string) $raw;

            if ($VERIFY) {
                $clean = $text === $label;
                printf("  %-7s %-8s %s  %s  %s\n", $set, $sheetName, $coord,
                    $clean ? '✅' : '❌', $text);

                continue;
            }

            if ($text === $label) {
                echo "  {$set}/{$sheetName}!{$coord} — 이미 정리됨\n";

                continue;
            }
            if ($text !== $expected) {
                echo "  ⚠️ {$set}/{$sheetName}!{$coord} — 예상과 달라 **건드리지 않는다**: ".var_export($text, true)."\n";

                continue;
            }

            echo "  {$set}/{$sheetName}!{$coord} : ".var_export($text, true).' → '.var_export($label, true)."\n";
            if ($APPLY) {
                // RichText run 1 개 · 개별 서식 없음(실측) → 평문 교체해도 셀 글꼴이 유지된다.
                $ws->getCell($coord)->setValueExplicit($label, DataType::TYPE_STRING);
                $changed++;
            }
        }
    }

    if ($APPLY && $changed > 0) {
        foreach ($ss->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getHyperlinkCollection() as $c => $_) {
                $sheet->setHyperlink($c, null);
            }
        }
        $w = new Xlsx($ss);
        // 🚫 preCalc 켜면 크로스시트 수식이 값으로 굳어 통관 SET cascade 가 통째로 죽는다.
        $w->setPreCalculateFormulas(false);
        $w->save($path);
        echo "  ✅ {$set} 저장 ({$changed}칸)\n";
    }
}

if (! $APPLY && ! $VERIFY) {
    echo "\ndry-run 이다 — 아무것도 쓰지 않았다. 실제 반영은 --apply (그 뒤 --verify).\n";
}
