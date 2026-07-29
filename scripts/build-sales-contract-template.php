<?php

/**
 * 일회성 — jin 이 준 새 디자인 원본 `Sales Contract_Sample.xlsx`(26열 레이아웃, 4슬롯 예시)를
 * 운영 `resources/templates/system/sales_contract.xlsx` 로 가공한다.
 *
 * ⚠️ 원본은 **디자인 참고본이지 드롭인 템플릿이 아니다.** 그대로 쓰면 안 되는 것들:
 *   ① 시트명이 `CONTRACT (2)` (매핑은 `CONTRACT` 를 찾는다)
 *   ② 슬롯 4개 (운영은 30)  ③ 예시 차량 3대가 박혀 있음
 *   ④ **병합 안쪽에 옛 레이아웃 유령값**(C15·G15·O15 등) — 병합에 가려 안 보이지만 남아 있다.
 *      테넌트 스크립트는 앵커에만 쓰므로, 안 지우면 heyman/karaba 안쪽에 ssancar 값이 박제된다.
 *   ⑤ 금액 서식이 `[$€-2]` **유로 하드코딩** — `DocumentFiller::applyCurrency` 의 정규식
 *      `/\$(?!-)/` 가 `[$€-2]` 의 `$` 를 잡아 EUR→`[€€-2]`, JPY→`[¥€-2]` 로 **서식을 깨뜨린다**.
 *      USD 는 조기반환이라 달러 계약서가 €로 인쇄된다. → `\$\ #,##0` 로 교체(통화 치환이 정상 동작).
 *   ⑥ Balance 값칸만 `"₩"#,##0` 원화 하드코딩  ⑦ `X26=SUM(X22:Y25)` 로 range 가 Y열까지 새어 있음
 *   ⑧ 연락처가 기존 서류들과 다른 새 값 — jin 지시로 **기존 정본 유지**
 *
 * 푸터 배치 (jin 2026-07-29 확정 — 샘플엔 Other Charge 가 없어 2행 추가):
 *   Sub Total(표 3열 합) → Other Charge → Total Amount(=Sub+Other) → Received → Deposit(적립금) → Balance
 *   ※ Deposit = **적립금**(savings_used). 계약금이 아니다. 잔금에서 **차감**된다(샘플의 `+` 는 오류).
 *
 * 실행: php scripts/build-sales-contract-template.php
 *   → 이어서 php scripts/generate-sales-contract-tenants.php (heyman/karaba 파생)
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const SRC = __DIR__.'/../Sales Contract_Sample.xlsx';
const DST = __DIR__.'/../resources/templates/system/sales_contract.xlsx';
const SHEET_OLD = 'CONTRACT (2)';
const SHEET_NEW = 'CONTRACT';

const FIRST = 22;        // 첫 슬롯 행
const SAMPLE_SLOTS = 4;  // 원본 슬롯 수 (22~25)
const TARGET = 30;       // 운영 슬롯 수
const MAXCOL = 26;       // A..Z

/** 슬롯 컬럼 그룹 — [앵커 => 마지막열]. 병합 구조가 곧 표 컬럼이다. */
const SLOT_GROUPS = [
    'A' => 'D',   // Code
    'E' => 'H',   // Brand
    'I' => 'L',   // Model
    'M' => 'Q',   // Chassis No.
    'R' => 'T',   // FOB PRICE
    'U' => 'W',   // SHIPPING
    'X' => 'Z',   // TOTAL (per-row 수식)
];

/**
 * 통화중립 금액서식. `\$` 라서 applyCurrency 가 `€`·`¥`·`₩` 로 치환하고
 * 멀티바이트 앞 백슬래시까지 제거한다(SKILLS §12 실측 경로). `[$€-2]` 는 절대 쓰지 말 것.
 */
const MONEY_FMT = '\$\ #,##0;[Red]\-\$\ #,##0';

$ss = IOFactory::load(SRC);
$sheet = $ss->getSheetByName(SHEET_OLD);
if (! $sheet) {
    fwrite(STDERR, "시트 '".SHEET_OLD."' 없음 — 원본이 바뀌었는지 확인.\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────
// A) 병합 안쪽 유령값 제거 (은행 블록 15~17행)
//    앵커(A/E/N/R)만 살리고 병합에 가려진 옛 좌표의 값을 비운다.
// ─────────────────────────────────────────────────────────────
$ghosts = ['C15', 'F15', 'G15', 'O15', 'C16', 'F16', 'G16', 'O16', 'C17', 'F17', 'G17', 'O17'];
$cleared = 0;
foreach ($ghosts as $g) {
    if (filled($sheet->getCell($g)->getValue())) {
        $sheet->getCell($g)->setValue(null);
        $cleared++;
    }
}
echo "유령값 제거: {$cleared}개\n";

// ─────────────────────────────────────────────────────────────
// B) 회사/바이어 블록 정리
//    셀러 연락처는 **기존 서류 정본 유지**(jin: "다른 서류들에 기입된 값에 맞춰라").
//    바이어 블록은 매핑이 채우므로 예시 데이터를 지우고 자리표시자만 남긴다.
// ─────────────────────────────────────────────────────────────
$sheet->getCell('B39')->setValue('Tel: +82-505-355-9977         Email: ssancar9977@gmail.com');
$sheet->getCell('P36')->setValue('000 CO., LTD');
$sheet->getCell('P38')->setValue('Passport/ID number : ');
$sheet->getCell('P39')->setValue('Tel:          Email: ');
$sheet->getCell('P40')->setValue('Address : ');
$sheet->getCell('E12')->setValue(null);   // Contract No — 매핑이 채움

// ─────────────────────────────────────────────────────────────
// C) 슬롯 확장 4 → 30 : 푸터(26행) 앞에 26행 삽입
// ─────────────────────────────────────────────────────────────
$addRows = TARGET - SAMPLE_SLOTS;              // 26
$footerRow = FIRST + SAMPLE_SLOTS;             // 26 (Sub Total 이 될 행)
$sheet->insertNewRowBefore($footerRow, $addRows);

$lastSlot = FIRST + TARGET - 1;                // 51
$modelRow = FIRST + 1;                         // 23 — 원본 그대로인 클린 데이터행

// 슬롯 균일화: 값 비우고, 행높이·병합·스타일을 모델행에서 실체 복사.
//   ⚠ setXfIndex(인덱스 공유)는 저장 재색인 때 경계행을 깨뜨린다(선대 스크립트 실측) → duplicateStyle.
$modelH = $sheet->getRowDimension($modelRow)->getRowHeight();
for ($r = FIRST; $r <= $lastSlot; $r++) {
    $sheet->getRowDimension($r)->setRowHeight($modelH);
    for ($c = 1; $c <= MAXCOL; $c++) {
        $sheet->getCell(Coordinate::stringFromColumnIndex($c).$r)->setValue(null);
    }
    foreach (SLOT_GROUPS as $from => $to) {
        $rng = "{$from}{$r}:{$to}{$r}";
        try {
            $sheet->unmergeCells($rng);
        } catch (Throwable $e) {
        }
        $sheet->mergeCells($rng);
    }
    // TOTAL = FOB + SHIPPING (슬롯에 박힌 per-row 수식 — removeRow 트림에도 안전)
    $sheet->getCell("X{$r}")->setValue("=SUM(R{$r}:W{$r})");
}
foreach (array_keys(SLOT_GROUPS) as $col) {
    $sheet->duplicateStyle($sheet->getStyle($col.$modelRow), $col.FIRST.':'.$col.$lastSlot);
}

// ─────────────────────────────────────────────────────────────
// D) 푸터 재구성
//    현재: 52 Sub Total(3열) / 53 Received / 54 Deposit / 55 Balance
//    목표: 52 Sub Total / 53 Other Charge / 54 Total Amount / 55 Received / 56 Deposit / 57 Balance
// ─────────────────────────────────────────────────────────────
$subTotalRow = $lastSlot + 1;                  // 52
$sheet->getCell('M'.$subTotalRow)->setValue('Sub Total');

// Received(53) 앞에 2행 삽입 → 53 Other Charge, 54 Total Amount
$sheet->insertNewRowBefore($subTotalRow + 1, 2);
$otherRow = $subTotalRow + 1;                  // 53
$totalRow = $subTotalRow + 2;                  // 54
$receivedRow = $subTotalRow + 3;               // 55
$depositRow = $subTotalRow + 4;                // 56
$balanceRow = $subTotalRow + 5;                // 57

// 삽입행은 빈 껍데기 → Received 행에서 스타일·행높이·병합을 복제한다.
$srcH = $sheet->getRowDimension($receivedRow)->getRowHeight();
foreach ([$otherRow, $totalRow] as $r) {
    $sheet->getRowDimension($r)->setRowHeight($srcH);
    foreach (['M' => 'Q', 'R' => 'Z'] as $from => $to) {
        $rng = "{$from}{$r}:{$to}{$r}";
        try {
            $sheet->unmergeCells($rng);
        } catch (Throwable $e) {
        }
        $sheet->mergeCells($rng);
    }
    for ($c = 1; $c <= MAXCOL; $c++) {
        $col = Coordinate::stringFromColumnIndex($c);
        $sheet->duplicateStyle($sheet->getStyle($col.$receivedRow), $col.$r);
    }
}
$sheet->getCell('M'.$otherRow)->setValue('Other Charge');
$sheet->getCell('M'.$totalRow)->setValue('Total Amount');
$sheet->getCell('M'.$receivedRow)->setValue('Received amount');   // 원본 오타 'Received amoun' 정정

// 푸터 값칸은 런타임(aggregates)이 값으로 채운다 — 수식 잔재를 비운다.
//   Sub Total 3칸만 footerAggregates 가 채운영역 SUM 으로 다시 쓴다.
foreach ([$otherRow, $totalRow, $receivedRow, $depositRow, $balanceRow] as $r) {
    $sheet->getCell('R'.$r)->setValue(null);
}
$sheet->getCell('R'.$subTotalRow)->setValue('=SUM(R'.FIRST.':R'.$lastSlot.')');
$sheet->getCell('U'.$subTotalRow)->setValue('=SUM(U'.FIRST.':U'.$lastSlot.')');
$sheet->getCell('X'.$subTotalRow)->setValue('=SUM(X'.FIRST.':X'.$lastSlot.')');   // 원본 SUM(X..:Y..) range 오류 교정

// ─────────────────────────────────────────────────────────────
// E) 금액 서식 통일 — 유로/원화 하드코딩 제거
// ─────────────────────────────────────────────────────────────
$moneyCells = [];
for ($r = FIRST; $r <= $lastSlot; $r++) {
    foreach (['R', 'U', 'X'] as $col) {
        $moneyCells[] = $col.$r;
    }
}
foreach (['R', 'U', 'X'] as $col) {
    $moneyCells[] = $col.$subTotalRow;
}
foreach ([$otherRow, $totalRow, $receivedRow, $depositRow, $balanceRow] as $r) {
    $moneyCells[] = 'R'.$r;
}
foreach ($moneyCells as $mc) {
    $sheet->getStyle($mc)->getNumberFormat()->setFormatCode(MONEY_FMT);
}
// 라벨칸에 남은 통화서식(M56 Deposit=유로, M57 Balance=달러 회계서식)은 텍스트로.
foreach ([$subTotalRow, $otherRow, $totalRow, $receivedRow, $depositRow, $balanceRow] as $r) {
    $sheet->getStyle('M'.$r)->getNumberFormat()->setFormatCode('@');
}
echo '금액서식 통일: '.count($moneyCells)."칸\n";

// ─────────────────────────────────────────────────────────────
// F) trailing 정리 + 시트명
//    서명 블록은 슬롯 26행 + 푸터 2행 = 28행 밀렸다 (원본 42 → 70).
// ─────────────────────────────────────────────────────────────
$signatureRow = 42 + $addRows + 2;             // 70
$high = $sheet->getHighestRow();
if ($high > $signatureRow + 2) {
    $sheet->removeRow($signatureRow + 3, $high - ($signatureRow + 2));
}
$sheet->garbageCollect();
$sheet->setTitle(SHEET_NEW);
$ss->getProperties()->setTitle('SALES CONTRACT');

if (! is_dir(dirname(DST))) {
    mkdir(dirname(DST), 0777, true);
}
$w = new Xlsx($ss);
$w->setPreCalculateFormulas(false);
$w->save(DST);
echo 'saved: '.realpath(DST)."\n";

echo "\n좌표 요약 (매핑에 반영할 값)\n";
echo '  슬롯      first='.FIRST.' stride=1 count='.TARGET." (last={$lastSlot})\n";
echo "  Sub Total R{$subTotalRow} / U{$subTotalRow} / X{$subTotalRow}\n";
echo "  Other     R{$otherRow}\n  Total     R{$totalRow}\n  Received  R{$receivedRow}\n";
echo "  Deposit   R{$depositRow}\n  Balance   R{$balanceRow}\n  Signature row {$signatureRow}\n";

probe(IOFactory::load(DST)->getSheetByName(SHEET_NEW), 'FINAL (reloaded)', $lastSlot, $balanceRow);

function probe(Worksheet $s, string $tag, int $lastSlot, int $balanceRow): void
{
    echo "\n--- probe [{$tag}] highestRow={$s->getHighestRow()} drawings=".count($s->getDrawingCollection())."\n";
    foreach (range($lastSlot - 1, min($s->getHighestRow(), $balanceRow + 16)) as $r) {
        $line = "  R{$r}:";
        $found = false;
        foreach (['A', 'B', 'M', 'P', 'R', 'U', 'X'] as $col) {
            $v = $s->getCell($col.$r)->getValue();
            if ($v === null || $v === '') {
                continue;
            }
            $v = $v instanceof RichText ? $v->getPlainText() : (string) $v;
            $line .= " [{$col}=".str_replace(["\n", "\r"], ' ', mb_substr($v, 0, 22)).']';
            $found = true;
        }
        if ($found) {
            echo $line."\n";
        }
    }
}
