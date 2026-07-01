<?php

/**
 * 일회성 — 원본 `Sales Contract.xlsx`(SSANCAR 다중차량 판매계약서, 10슬롯 백지)를
 * 운영 `resources/templates/system/sales_contract.xlsx` 로 가공:
 *   ① 2차 오버플로 섹션·trailing 제거  ② 중복 Received 행 삭제  ③ 30슬롯 확장(선적 방식)
 *   ④ 슬롯 행 스타일 균일화(행23 모델)  ⑤ 푸터 값칸 공란 유지(Subtotal만 런타임 SUM)
 * 회사정보(SSANCAR)는 원본 그대로 인쇄 유지. heyman/karaba 는 별도 셀치환 스크립트.
 *
 * 실행: php scripts/build-sales-contract-template.php   (원본 재이관 시 재실행)
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const SRC = __DIR__.'/../Sales Contract.xlsx';
const DST = __DIR__.'/../resources/templates/system/sales_contract.xlsx';
const SHEET_OLD = 'Travel Services Invoice';
const SHEET_NEW = 'CONTRACT';
const MODEL_ROW = 23;   // 슬롯 스타일 모델 (첫 데이터행)
const NORM_ROW = 24;    // 스타일 정규화 소스 (원본 그대로 클린한 중간 데이터행 — 경계행 23 은 깨질 수 있어 24 사용)
const FIRST = 23;       // 첫 슬롯
const TARGET = 30;      // 슬롯 수
const MAXCOL = 7;       // A..G (FOB 는 E:G 병합)

$ss = IOFactory::load(SRC);
$sheet = $ss->getSheetByName(SHEET_OLD);

// A) 2차 섹션 + trailing 제거 (signature block 44-53·drawing B52 는 보존)
$high = $sheet->getHighestRow();
if ($high >= 54) {
    $sheet->removeRow(54, $high - 54 + 1);
}

// B) 중복 Received amount 행(39) 삭제 → Balance 40→39
$sheet->removeRow(39, 1);

// C) 슬롯 확장: footer(Subtotal, 이제 33행) 앞에 20행 삽입
probe($sheet, 'before-insert');
$addRows = TARGET - 10;                 // 20
$footerStart = FIRST + 10;              // 33 (dup 삭제 후에도 Subtotal=33)
$sheet->insertNewRowBefore($footerStart, $addRows);

// D) 슬롯 행 균일화 — 값 클리어 + 행높이 + E:G 병합 + 스타일 정규화.
//    ⚠ setXfIndex(인덱스 공유)는 저장 재색인 때 경계행(23·52)을 title 스타일로 깨뜨림(실측) →
//    실체 복사 duplicateStyle 사용. 클린 데이터행(24, 원본 그대로) per-column 스타일을 23..52 전체에 복제.
$modelH = $sheet->getRowDimension(NORM_ROW)->getRowHeight();
for ($r = FIRST; $r <= FIRST + TARGET - 1; $r++) {
    $sheet->getRowDimension($r)->setRowHeight($modelH);
    for ($c = 1; $c <= MAXCOL; $c++) {
        $sheet->getCell(Coordinate::stringFromColumnIndex($c).$r)->setValue(null);   // 샘플 제거
    }
    // FOB 병합 E:G 균일 부여
    $rng = "E{$r}:G{$r}";
    try {
        $sheet->unmergeCells($rng);
    } catch (Throwable $e) {
    }
    $sheet->mergeCells($rng);
}
// 스타일 정규화 — 클린행(24) per-column 스타일을 전 슬롯(23-52)에 실체 복사.
$lastSlot = FIRST + TARGET - 1;
for ($c = 1; $c <= MAXCOL; $c++) {
    $col = Coordinate::stringFromColumnIndex($c);
    $sheet->duplicateStyle($sheet->getStyle($col.NORM_ROW), $col.FIRST.':'.$col.$lastSlot);
}

// D-2) 라벨/서식 보정
//  · "Dollar Rate"(B55) → "USD Rate": applyCurrency 가 'Dollar'→통화코드 치환하는데,
//    이 계약서는 Dollar/Euro Rate 2행을 별도로 두므로(jin #3) 치환되면 빈 USD행이 "EUR Rate"로 오표기됨.
//    'Dollar' 문구를 없애 치환 대상에서 제외(USD 통화면 USD Rate 그대로 값 기입).
$b55 = $sheet->getCell('B55')->getValue();
if (is_string($b55) && str_contains($b55, 'Dollar')) {
    $sheet->getCell('B55')->setValue(str_replace('Dollar', 'USD', $b55));
}
//  · 푸터 금액칸(Subtotal/Shipping/Other/Total/Received/Balance)에 슬롯 통화서식 부여
//    → applyCurrency 가 $→통화기호 변환(슬롯 FOB 와 동일 표기). C55/C56(환율)은 숫자라 제외.
$moneyFmt = $sheet->getStyle('E'.MODEL_ROW)->getNumberFormat()->getFormatCode();
foreach (['E53', 'E54', 'E55', 'E57', 'E58', 'E59'] as $fc) {
    $sheet->getStyle($fc)->getNumberFormat()->setFormatCode($moneyFmt);
}

// E) trailing 빈 행 제거 (signature 끝 72행·drawing B71 보존) + dimension 정정
$high2 = $sheet->getHighestRow();
if ($high2 >= 75) {
    $sheet->removeRow(75, $high2 - 75 + 1);
}
$sheet->garbageCollect();

// F) 시트 이름
$sheet->setTitle(SHEET_NEW);
$ss->getProperties()->setTitle('SALES CONTRACT');

// F) 저장
if (! is_dir(dirname(DST))) {
    mkdir(dirname(DST), 0777, true);
}
$w = new Xlsx($ss);
$w->setPreCalculateFormulas(false);
$w->save(DST);
echo 'saved: '.realpath(DST)."\n";

probe(IOFactory::load(DST)->getSheetByName(SHEET_NEW), 'FINAL (reloaded)');

function probe(Worksheet $s, string $tag): void
{
    echo "\n--- probe [$tag] highestRow={$s->getHighestRow()} drawings=".count($s->getDrawingCollection())."\n";
    foreach ($s->getDrawingCollection() as $d) {
        echo '   drawing @ '.$d->getCoordinates()."\n";
    }
    // 라벨 스캔 — footer/signature 라벨 위치 확인
    foreach (range(FIRST + TARGET - 2, min($s->getHighestRow(), FIRST + TARGET + 20)) as $r) {
        $line = "  R$r:";
        $found = false;
        foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
            $v = $s->getCell($col.$r)->getValue();
            if ($v === null || $v === '') {
                continue;
            }
            $v = $v instanceof RichText ? $v->getPlainText() : (string) $v;
            $line .= " [$col=".str_replace(["\n", "\r"], ' ', mb_substr($v, 0, 20)).']';
            $found = true;
        }
        if ($found) {
            echo $line."\n";
        }
    }
}
