<?php

/**
 * 일회성 — 템플릿에 잘못 박힌 전화번호 정정 (jin 2026-07-29 확인).
 *
 * 판매계약서 정본을 맞추려고 3사 × 5서류의 연락처를 전수 대조하다 발견한 교차오염:
 *   ① heyman roro/container 계약서 A2 = `TEL:82-505-355-9977` → **ssancar 대표번호**가 박혀 있었다.
 *   ② heyman 인보이스 A2 = `Phone: +82-505-366-9977` → 그건 **팩스번호**다.
 *   ③ karaba 말소계약서 E4 = `82 -10-9009-9977` → **heyman 번호**가 박혀 있었다.
 *
 * jin 확정 정본:
 *   heyman  Tel `82-10-9009-9977` / Fax `82-505-366-9977`
 *   karaba  Tel `82-32-710-7979`
 *
 * ⚠️ 대상 셀은 전부 **RichText(단일 run)** 다. `setValue()` 로 통째 덮으면 그 헤더 블록의 서식이 날아가므로,
 *    run 의 텍스트만 부분치환한다(폰트 객체 유지).
 *
 * 실행: php scripts/fix-template-phone-numbers.php  [--dry]
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$dry = in_array('--dry', $argv, true);

/** [set, file, sheet(null=첫시트), coord, 찾을 문자열, 바꿀 문자열] */
$fixes = [
    ['heyman', 'sales_invoice', 'Invoice', 'A2', 'Phone: +82-505-366-9977', 'Phone: +82-10-9009-9977'],
    ['heyman', 'roro_contract', null, 'A2', 'TEL:82-505-355-9977', 'TEL:82-10-9009-9977'],
    ['heyman', 'container_contract', null, 'A2', 'TEL:82-505-355-9977', 'TEL:82-10-9009-9977'],
    ['karaba', 'deregistration_contract', '2.계약서', 'E4', '82 -10-9009-9977', '82-32-710-7979'],
];

$changed = 0;
foreach ($fixes as [$set, $file, $sheetName, $coord, $find, $replace]) {
    $path = __DIR__."/../resources/templates/{$set}/{$file}.xlsx";
    if (! is_file($path)) {
        echo "SKIP (없음): {$set}/{$file}\n";

        continue;
    }

    $ss = IOFactory::createReader('Xlsx')->load($path);
    $sheet = $sheetName ? $ss->getSheetByName($sheetName) : $ss->getSheet(0);
    $cell = $sheet->getCell($coord);
    $val = $cell->getValue();

    $before = $val instanceof RichText ? $val->getPlainText() : (string) $val;
    if (! str_contains($before, $find)) {
        echo "SKIP (이미 정상이거나 문자열 불일치): {$set}/{$file} {$coord}\n";

        continue;
    }

    if ($val instanceof RichText) {
        // run 단위 부분치환 — 폰트/서식 유지. 찾는 문자열이 run 을 걸치면 못 잡으므로 결과를 검증한다.
        foreach ($val->getRichTextElements() as $el) {
            $t = $el->getText();
            if (str_contains($t, $find)) {
                $el->setText(str_replace($find, $replace, $t));
            }
        }
        $after = $val->getPlainText();
    } else {
        $after = str_replace($find, $replace, $before);
        $cell->setValue($after);
    }

    if (str_contains($after, $find)) {
        echo "!! 실패(치환 안 됨 — run 경계 가능성): {$set}/{$file} {$coord}\n";

        continue;
    }

    echo "FIX {$set}/{$file} {$coord}\n   before: {$before}\n   after : {$after}\n";
    $changed++;

    if (! $dry) {
        $w = new Xlsx($ss);
        $w->setPreCalculateFormulas(false);   // 외부참조·수식 재계산으로 인한 손상 방지 (기존 스크립트 관례)
        $w->save($path);
    }
}

echo ($dry ? "[DRY] " : '')."DONE — {$changed} cell(s).\n";
