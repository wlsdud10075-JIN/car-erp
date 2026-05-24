<?php

/**
 * 일회성 개발 도구 — 선적 4종 양식의 마지막 슬롯 블록을 복제해 TARGET_SLOTS 까지 확장.
 *
 * 배경 (2026-05-24 #3 다중차량 서류, advisor 채택안 "나"):
 *   런타임 DocumentFiller 는 양식 슬롯을 removeRow 로만 N대에 맞춰 줄인다(안전 방향).
 *   따라서 양식이 넉넉한 슬롯(30대)을 미리 보유해야 한다. 행 확장(복제) fidelity 는
 *   런타임 매 요청이 아니라 이 스크립트 1회로 국한 → 사람이 결과를 1회 검증.
 *   사용자가 새 양식을 재이관하면 이 스크립트를 재실행해 결정론적으로 재확장한다.
 *
 * 실행:  php scripts/extend_shipping_templates.php
 *
 * ⚠️ 입력 양식이 "확장 전(원본 슬롯 수)" 상태여야 한다. 이미 확장된 파일에 재실행 금지
 *    (count 가 원본 기준이라 중복 확장됨). 재이관 시 원본으로 교체 후 1회 실행.
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const TARGET_SLOTS = 30;

$dir = __DIR__.'/../resources/templates/system/';

/**
 * 각 양식의 슬롯 기하 + footer 집계수식 재기록 규칙.
 * - firstRow/stride/count : 원본 슬롯 영역 (probe 로 실측, _yellow_report 검증).
 * - maxCol               : 차량 표 우측 끝 열 인덱스 (그 너머 보조열[항구 리스트]은 복제 제외).
 * - indexCol             : 슬롯 번호(NO.) 열. null 이면 없음(contract 의 A 는 =RIGHT 수식).
 * - footerFixes          : 확장 후 슬롯 전 영역을 집계하도록 재기록할 footer 셀들.
 *                          insertNewRowBefore 가 footer 경계에서 range 를 자동확장하지
 *                          않으므로(새 행이 range 끝보다 아래) 명시 재기록 필수.
 */
$configs = [
    [
        'file' => 'container_invoice_packing.xlsx', 'sheet' => 'INVOICE',
        'firstRow' => 21, 'stride' => 3, 'count' => 5, 'maxCol' => 13, 'indexCol' => 'B',
        // 원본 footer SUBTOTAL = 행 36. I/J/K/L 가 슬롯 main 행을 명시리스트로 집계 → range 로 변환.
        // fuel/spacer 행의 I/J/K/L 은 공란이라 range SUM == 명시리스트 SUM.
        'footerFixes' => [
            ['col' => 'I', 'rowOrig' => 36, 'fmt' => '=SUM(I%1$d:I%2$d)'],
            ['col' => 'J', 'rowOrig' => 36, 'fmt' => '=SUM(J%1$d:J%2$d)'],
            ['col' => 'K', 'rowOrig' => 36, 'fmt' => '=SUM(K%1$d:K%2$d)'],
            ['col' => 'L', 'rowOrig' => 36, 'fmt' => '=SUM(L%1$d:L%2$d)'],
        ],
    ],
    [
        'file' => 'roro_invoice_packing.xlsx', 'sheet' => 'INVOICE',
        'firstRow' => 21, 'stride' => 1, 'count' => 10, 'maxCol' => 13, 'indexCol' => 'B',
        'footerFixes' => [
            ['col' => 'I', 'rowOrig' => 31, 'fmt' => '=SUM(I%1$d:I%2$d)'],
            ['col' => 'J', 'rowOrig' => 31, 'fmt' => '=SUM(J%1$d:J%2$d)'],
            ['col' => 'K', 'rowOrig' => 31, 'fmt' => '=SUM(K%1$d:K%2$d)'],
            ['col' => 'L', 'rowOrig' => 31, 'fmt' => '=SUM(L%1$d:L%2$d)'],
        ],
    ],
    [
        'file' => 'container_contract.xlsx', 'sheet' => 'HBB340.',
        'firstRow' => 16, 'stride' => 1, 'count' => 11, 'maxCol' => 9, 'indexCol' => null,
        // F27=SUM(F16:G26) 전체합 / I27=SUM(F16:F26) FOB합 / I28=SUM(G16:G26) 운임합
        'footerFixes' => [
            ['col' => 'F', 'rowOrig' => 27, 'fmt' => '=SUM(F%1$d:G%2$d)'],
            ['col' => 'I', 'rowOrig' => 27, 'fmt' => '=SUM(F%1$d:F%2$d)'],
            ['col' => 'I', 'rowOrig' => 28, 'fmt' => '=SUM(G%1$d:G%2$d)'],
        ],
    ],
    [
        'file' => 'roro_contract.xlsx', 'sheet' => 'HBB340.',
        'firstRow' => 16, 'stride' => 1, 'count' => 11, 'maxCol' => 9, 'indexCol' => null,
        'footerFixes' => [
            ['col' => 'F', 'rowOrig' => 27, 'fmt' => '=SUM(F%1$d:G%2$d)'],
            ['col' => 'I', 'rowOrig' => 27, 'fmt' => '=SUM(F%1$d:F%2$d)'],
            ['col' => 'I', 'rowOrig' => 28, 'fmt' => '=SUM(G%1$d:G%2$d)'],
        ],
    ],
];

/** A1 참조의 행 번호만 delta 만큼 이동 (열·인자 상수는 불변). */
function shiftFormulaRows(string $formula, int $delta): string
{
    return preg_replace_callback('/(\$?[A-Z]{1,3}\$?)(\d+)/', function (array $m) use ($delta) {
        return $m[1].((int) $m[2] + $delta);
    }, $formula);
}

foreach ($configs as $cfg) {
    $path = $dir.$cfg['file'];
    $ss = IOFactory::load($path);
    $sheet = $ss->getSheetByName($cfg['sheet']);

    ['firstRow' => $first, 'stride' => $stride, 'count' => $count, 'maxCol' => $maxCol] = $cfg;
    $addBlocks = TARGET_SLOTS - $count;
    if ($addBlocks <= 0) {
        echo "skip {$cfg['file']} (count {$count} >= target ".TARGET_SLOTS.")\n";

        continue;
    }

    $srcStart = $first + ($count - 1) * $stride;   // 마지막 원본 슬롯 첫 행
    $insertAt = $first + $count * $stride;          // 첫 footer 행
    $addRows = $addBlocks * $stride;

    // 1) 소스 블록 내 병합 수집 (표 영역 A..maxCol 한정)
    $srcMerges = [];
    foreach ($sheet->getMergeCells() as $range) {
        [$s, $e] = Coordinate::rangeBoundaries($range);
        if ($s[1] >= $srcStart && $e[1] <= $srcStart + $stride - 1 && $e[0] <= $maxCol) {
            $srcMerges[] = $range;
        }
    }

    // 2) footer 앞에 빈 행 삽입 (footer·수식 자동 하향 이동)
    $sheet->insertNewRowBefore($insertAt, $addRows);

    // 3) 소스 블록을 신규 블록마다 복제 (스타일 xf + 값/수식 row-shift + 행높이)
    for ($b = 0; $b < $addBlocks; $b++) {
        $dstStart = $insertAt + $b * $stride;
        $delta = $dstStart - $srcStart;

        for ($o = 0; $o < $stride; $o++) {
            $srcRow = $srcStart + $o;
            $dstRow = $dstStart + $o;
            $sheet->getRowDimension($dstRow)->setRowHeight($sheet->getRowDimension($srcRow)->getRowHeight());

            for ($c = 1; $c <= $maxCol; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $srcCell = $sheet->getCell($col.$srcRow);
                $dstCell = $sheet->getCell($col.$dstRow);
                $dstCell->setXfIndex($srcCell->getXfIndex());   // 스타일(테두리 포함) 공유

                $v = $srcCell->getValue();
                if (is_string($v) && str_starts_with($v, '=')) {
                    $dstCell->setValueExplicit(shiftFormulaRows($v, $delta), DataType::TYPE_FORMULA);
                } elseif ($v !== null && $v !== '') {
                    $dstCell->setValue($v);   // 리터럴 라벨("TYPE OF FUEL :" 등) 보존
                }
            }
        }

        foreach ($srcMerges as $range) {
            $sheet->mergeCells(shiftFormulaRows($range, $delta));
        }

        if ($cfg['indexCol']) {
            $sheet->getCell($cfg['indexCol'].$dstStart)->setValue($count + $b + 1);   // 슬롯 번호 6..30
        }
    }

    // 4) footer 집계수식을 슬롯 전 영역(30대) range 로 재기록
    $regionStart = $first;
    $regionEnd = $first + TARGET_SLOTS * $stride - 1;
    foreach ($cfg['footerFixes'] as $fix) {
        $row = $fix['rowOrig'] + $addRows;
        $formula = sprintf($fix['fmt'], $regionStart, $regionEnd);
        $sheet->getCell($fix['col'].$row)->setValueExplicit($formula, DataType::TYPE_FORMULA);
    }

    (new Xlsx($ss))->save($path);
    echo "extended {$cfg['file']}: {$count} -> ".TARGET_SLOTS." slots (+{$addRows} rows, footer fixes ".count($cfg['footerFixes']).")\n";
}

echo "\nDONE.\n";
