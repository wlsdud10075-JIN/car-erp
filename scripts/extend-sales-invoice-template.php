<?php

/**
 * 일회성 개발 도구 — Proforma Invoice 양식(sales_invoice.xlsx)의 차량 슬롯을 1 → TARGET_SLOTS 로 확장.
 *
 * 배경 (2026-07-31, jin "기존 양식 행만 늘리기"):
 *   런타임 DocumentFiller::fillMulti 는 슬롯을 removeRow 로만 N대에 맞춰 줄인다(안전 방향).
 *   따라서 양식이 넉넉한 슬롯(30대)을 미리 보유해야 한다. 행 확장 fidelity 는 런타임이 아니라
 *   이 스크립트 1회로 국한 → 사람이 결과를 1회 검증. 선적 4종의 extend_shipping_templates.php 와 같은 방식.
 *
 * 3사(system·heyman·karaba) 파일을 **제자리** 확장한다. 회사정보(은행·주소)는 각 파일에 인쇄돼
 * 있으므로 건드리지 않는다 — sales_contract 처럼 원본에서 파생하지 않는 이유다.
 *
 * 실행:  php scripts/extend-sales-invoice-template.php
 *
 * ⚠️ 이미 확장된 파일에는 재실행되지 않는다(아래 guard). 양식을 새로 이관하면 원본으로 교체 후 1회 실행.
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const TARGET_SLOTS = 30;

/** 원본 기하 — 차량 슬롯은 18행 1개, 표 우측 끝은 F(6). */
const SHEET = 'Invoice';
const FIRST_ROW = 18;
const ORIG_COUNT = 1;
const MAX_COL = 6;          // F (Shipping cost)
const CHARGE_ROW = 24;      // 원본 COMMISSION 행 — 확장 여부 판별 기준
/** DEPOSIT 라벨(비노란이라 clearYellowFill 이 못 지움) — 원본 좌표. 확장 후 blank 처리. */
const DEPOSIT_LABEL_ROWS = [28, 29];
/**
 * 푸터 합계 수식 셀 — 원본 좌표 (SUB TOTAL 27 / TOTAL 31 / BALANCE 34).
 *
 * 🚨 **반드시 비워야 한다.** DocumentFiller::writeCell 은 수식 셀을 절대 덮어쓰지 않으므로
 *    (cascade 보존), 수식이 남아 있으면 매핑의 aggregates 값이 **조용히 무시**된다.
 *    게다가 SUB TOTAL 의 `=SUM(E18:F55)` 는 슬롯 + 비용행을 함께 덮는 range 라
 *    미사용 슬롯 트림 후엔 자기 자신까지 삼켜 순환참조가 된다. → 런타임 값 기입으로 일원화.
 */
const FOOTER_FORMULA_ROWS = [27, 31, 34];

$tenants = ['system', 'heyman', 'karaba'];
$addRows = (TARGET_SLOTS - ORIG_COUNT);   // 29
$insertAt = FIRST_ROW + ORIG_COUNT;       // 19

foreach ($tenants as $tenant) {
    $path = __DIR__.'/../resources/templates/'.$tenant.'/sales_invoice.xlsx';
    if (! is_file($path)) {
        echo "skip {$tenant}: 파일 없음\n";

        continue;
    }

    $ss = IOFactory::load($path);
    $sheet = $ss->getSheetByName(SHEET);
    if (! $sheet) {
        echo "skip {$tenant}: 시트 '".SHEET."' 없음\n";

        continue;
    }

    // guard — 이미 확장됐으면(원본 COMMISSION 행이 비어 있으면) 재실행 금지. 중복 확장 방지.
    $chargeLabel = (string) $sheet->getCell('C'.CHARGE_ROW)->getValue();
    if (! str_contains($chargeLabel, 'COMMISSION')) {
        echo "skip {$tenant}: 이미 확장됨 (C".CHARGE_ROW." = '{$chargeLabel}')\n";

        continue;
    }

    // 1) 슬롯 바로 아래에 빈 행 삽입 — 아래쪽(스페이서·푸터)과 그 수식이 자동으로 하향 이동한다.
    $sheet->insertNewRowBefore($insertAt, $addRows);

    // 2) 원본 슬롯행(18)의 스타일·행높이를 새 슬롯 전부에 복제.
    //    ⚠ 값은 복제하지 않는다 — 슬롯행의 값은 전부 샘플 데이터(차량번호·HYUNDAI…)라 라벨이 아니다.
    //      (남겨도 clearYellowFill 이 지우지만, 애초에 안 넣는 쪽이 명확하다.)
    //    ⚠ getCell() 을 **중첩 호출하지 말 것** — 안쪽 호출이 셀 컬렉션의 current cell 을 바꿔
    //      바깥에서 받아둔 Cell 객체가 무효화된다. 실측: 원본 슬롯행(18)의 값이 통째로 지워졌다.
    //      (DocumentFiller:283 의 getStyle() 중첩 경고와 같은 부류.) → 원본 xf 를 먼저 배열로 읽는다.
    $srcHeight = $sheet->getRowDimension(FIRST_ROW)->getRowHeight();
    $srcXf = [];
    for ($c = 1; $c <= MAX_COL; $c++) {
        $col = Coordinate::stringFromColumnIndex($c);
        $srcXf[$col] = $sheet->getCell($col.FIRST_ROW)->getXfIndex();
    }
    for ($i = 1; $i < TARGET_SLOTS; $i++) {
        $dstRow = FIRST_ROW + $i;
        $sheet->getRowDimension($dstRow)->setRowHeight($srcHeight);
        foreach ($srcXf as $col => $xf) {
            $sheet->getCell($col.$dstRow)->setXfIndex($xf);
        }
    }

    // 3) DEPOSIT 라벨 제거 — DEPOSIT 행은 합계 수식에 이중계상돼 2026-06-24 에 폐기됐다.
    //    비노란 라벨이라 clearYellowFill 이 못 지우고, 런타임 clearCells 는 trim 으로 좌표가
    //    움직여 다중차량에선 못 쓴다 → 양식에서 아예 비운다.
    foreach (DEPOSIT_LABEL_ROWS as $r) {
        $sheet->getCell('C'.($r + $addRows))->setValueExplicit(null, DataType::TYPE_NULL);
    }

    // 4) 푸터 합계 수식 제거 — 런타임이 값으로 채운다(위 상수 docblock 참조).
    foreach (FOOTER_FORMULA_ROWS as $r) {
        $sheet->getCell('E'.($r + $addRows))->setValueExplicit(null, DataType::TYPE_NULL);
    }

    (new Xlsx($ss))->save($path);

    $slotEnd = FIRST_ROW + TARGET_SLOTS - 1;
    echo "extended {$tenant}: 슬롯 ".FIRST_ROW."~{$slotEnd} (".ORIG_COUNT.' -> '.TARGET_SLOTS."), +{$addRows} rows\n";
}

echo "\nDONE.\n";
