<?php

/**
 * heyman 통관 SET 디자인 (jin 2026-09-22 — 「파일과 다 같게」).
 *
 * 원본 = jin 이 손으로 고친 생성물 `통관SET_11무8205_318406.xlsx`(7월 생성물이라 시트 통째 복사는 금지 —
 * 8/31·9/03 수정이 되돌아간다). **달라진 항목만** 여기서 양식에 적용한다. 두 스크립트가 같은 함수를 부른다:
 *   - scripts/fix-heyman-clearance-design.php      (현행 heyman 양식에 1회 적용 · --verify)
 *   - scripts/generate-heyman-templates.php         (system → heyman 재생성 시 같이 적용 — 안 그러면 되돌아간다)
 *
 * 항목
 *   Travel Services Invoice : A1:B1 → A1:D1 병합 「HEYMAN」(Tahoma 72 bold 흰글자) · 1행 배경 0070C0 ·
 *                             E1 「COMMERCIAL INVOICE」 · E6 「ID」 · A1 싼카 로고 2장 삭제 · 직인 B28+(64,4) → B28+(0,21)
 *   차량인보이스 / 차량팩킹     : E6 「ID」 · E7 「HEYMAN」 · (팩킹) E8 비움 · 직인 G33+(26,21) → G34+(17,2) / G34+(18,7)
 *   한글등록증 / 영문등록증 / 말소증 : 관인(govt_seal) O13+(63,18)→P13+(14,9) · O13+(67,6)→P13+(34,4) · K33+(77,47)→K32+(138,10)
 * 🚫 건드리지 않는 것: F7~F9(매핑칸) · 금액 서식 `\$` · FFC000 표식 · 수식.
 * ⚠️ 직인 앵커를 옮겼으니 StampSlots::heymanSlots()['clearance'] 도 같은 앵커+오프셋이어야 한다 —
 *    removeDrawingsAt 이 「정확히 같은 앵커」만 지우므로(§8 #37 ③). 가드 = ClearanceStampAnchorTest.
 */

use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// 관인(govt_seal) — 한글·영문등록증·말소증 (jin 2026-09-22 「선이나 글자에 걸려 있어서 옮겼다 · 파일이 맞다」)
//   시트 => [옛 앵커, 새 앵커, dx, dy]. 앱은 관인을 슬롯에서 제외하므로 양식 drawing 만 옮기면 된다.
const HEYMAN_CLEARANCE_GOVT_SEALS = [
    '한글등록증' => ['O13', 'P13', 14, 9],
    '영문등록증' => ['O13', 'P13', 34, 4],
    '말소증' => ['K33', 'K32', 138, 10],
];

const HEYMAN_CLEARANCE_SEALS = [
    // 시트 => [앵커, dx, dy]  — StampSlots heyman clearance 와 글자 단위로 같아야 한다
    '차량인보이스' => ['G34', 17, 2],
    '차량팩킹' => ['G34', 18, 7],
    'Travel Services Invoice' => ['B28', 0, 21],
];

/**
 * 적용 계획을 돌려주고(문자열 목록), $apply 면 실제로 고친다.
 *
 * @return list<string>
 */
function applyHeymanClearanceDesign(Spreadsheet $ss, bool $apply): array
{
    $log = [];
    $travel = $ss->getSheetByName('Travel Services Invoice');
    $inv = $ss->getSheetByName('차량인보이스');
    $pack = $ss->getSheetByName('차량팩킹');
    if (! $travel || ! $inv || ! $pack) {
        throw new RuntimeException('통관 SET 시트(Travel/차량인보이스/차량팩킹)가 없다');
    }

    // ── Travel Services Invoice ────────────────────────────────────────────────
    foreach (array_keys($travel->getMergeCells()) as $range) {
        if (str_starts_with($range, 'A1:')) {
            $log[] = "Travel 병합 해제 {$range}";
            if ($apply) {
                $travel->unmergeCells($range);
            }
        }
    }
    $log[] = 'Travel A1:D1 병합 + 「HEYMAN」 Tahoma 72 bold 흰글자 가운데';
    if ($apply) {
        $travel->mergeCells('A1:D1');
        $travel->setCellValue('A1', 'HEYMAN');
        $st = $travel->getStyle('A1');
        $st->getFont()->setName('Tahoma')->setSize(72)->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    }
    $log[] = 'Travel A1:G1 배경 0070C0';
    if ($apply) {
        $travel->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0070C0');
    }
    $log[] = 'Travel E1 「COMMERCIAL INVOICE」 (Arial Black 26 흰글자)';
    if ($apply) {
        $travel->setCellValue('E1', 'COMMERCIAL INVOICE');
        $travel->getStyle('E1')->getFont()->setName('Arial Black')->setSize(26)->getColor()->setARGB('FFFFFFFF');
    }
    $log[] = 'Travel E6 「ID」';
    if ($apply) {
        $travel->setCellValue('E6', 'ID');
    }
    // 로고 2장 삭제 + 직인 이동
    $log[] = 'Travel A1 로고 drawing 삭제 · 직인 → B28+(0,21)';
    if ($apply) {
        removeDrawingsAtAnchor($travel, 'A1');
        moveDrawingAt($travel, 'B28', ...HEYMAN_CLEARANCE_SEALS['Travel Services Invoice']);
    }

    // ── 차량인보이스 · 차량팩킹 ───────────────────────────────────────────────
    foreach ([$inv, $pack] as $ws) {
        $name = $ws->getTitle();
        $log[] = "{$name} E6 「ID」 · E7 「HEYMAN」".($ws === $pack ? ' · E8 비움' : '');
        if ($apply) {
            $ws->setCellValue('E6', 'ID');
            $ws->setCellValue('E7', 'HEYMAN');
            if ($ws === $pack) {
                $ws->setCellValue('E8', null);
            }
        }
        [$anchor, $dx, $dy] = HEYMAN_CLEARANCE_SEALS[$name];
        $log[] = "{$name} 직인 G33 → {$anchor}+({$dx},{$dy})";
        if ($apply) {
            moveDrawingAt($ws, 'G33', $anchor, $dx, $dy);
        }
    }

    // ── 관인 3곳 ───────────────────────────────────────────────────────────
    foreach (HEYMAN_CLEARANCE_GOVT_SEALS as $name => [$from, $to, $dx, $dy]) {
        $ws = $ss->getSheetByName($name);
        if (! $ws) {
            throw new RuntimeException("시트 없음: {$name}");
        }
        $log[] = "{$name} 관인 {$from} → {$to}+({$dx},{$dy})";
        if ($apply && moveDrawingAt($ws, $from, $to, $dx, $dy, 'govt_seal') !== 1) {
            throw new RuntimeException("{$name} 관인(govt_seal @{$from}) 을 정확히 1개 찾지 못했다");
        }
    }

    return $log;
}

/** 앵커가 정확히 일치하는 drawing 을 전부 지운다 (DocumentFiller::removeDrawingsAt 과 같은 판정). */
function removeDrawingsAtAnchor(Worksheet $ws, string $anchor): int
{
    $n = 0;
    $coll = $ws->getDrawingCollection();
    for ($i = $coll->count() - 1; $i >= 0; $i--) {
        if ($coll[$i]->getCoordinates() === $anchor) {
            $coll->offsetUnset($i);
            $n++;
        }
    }

    return $n;
}

/** 앵커 $from 의 drawing 을 $to+(dx,dy) 로 옮긴다. oneCell 로 바꿔 크기를 고정한다(twoCell 이면 끝좌표가 크기를 덮는다). */
function moveDrawingAt(Worksheet $ws, string $from, string $to, int $dx, int $dy, ?string $name = null): int
{
    $n = 0;
    foreach ($ws->getDrawingCollection() as $d) {
        if ($d->getCoordinates() !== $from || ($name !== null && $d->getName() !== $name)) {
            continue;
        }
        $w = $d->getWidth();
        $h = $d->getHeight();
        $d->setCoordinates($to)->setOffsetX($dx)->setOffsetY($dy);
        $d->setCoordinates2('')->setOffsetX2(0)->setOffsetY2(0);
        $d->setEditAs('oneCell');
        $d->setResizeProportional(false)->setWidthAndHeight($w, $h);
        $n++;
    }

    return $n;
}

/** 검증 — 현행 양식이 디자인대로인지. 어긋난 항목 목록(비면 통과). */
function verifyHeymanClearanceDesign(Spreadsheet $ss): array
{
    $bad = [];
    $travel = $ss->getSheetByName('Travel Services Invoice');
    $plain = static function ($v) {
        return $v instanceof RichText ? $v->getPlainText() : $v;
    };
    if ((string) $plain($travel->getCell('A1')->getValue()) !== 'HEYMAN') {
        $bad[] = 'Travel A1 ≠ HEYMAN';
    }
    if (! in_array('A1:D1', array_keys($travel->getMergeCells()), true)) {
        $bad[] = 'Travel A1:D1 병합 없음';
    }
    if (strtoupper($travel->getStyle('A1')->getFill()->getStartColor()->getRGB()) !== '0070C0') {
        $bad[] = 'Travel A1 배경 ≠ 0070C0';
    }
    if ((string) $plain($travel->getCell('E1')->getValue()) !== 'COMMERCIAL INVOICE') {
        $bad[] = 'Travel E1 ≠ COMMERCIAL INVOICE';
    }
    if ((string) $plain($travel->getCell('E6')->getValue()) !== 'ID') {
        $bad[] = 'Travel E6 ≠ ID';
    }
    foreach ($travel->getDrawingCollection() as $d) {
        if ($d->getCoordinates() === 'A1') {
            $bad[] = 'Travel A1 로고 drawing 잔존';
        }
    }
    foreach (['차량인보이스', '차량팩킹'] as $name) {
        $ws = $ss->getSheetByName($name);
        if ((string) $plain($ws->getCell('E6')->getValue()) !== 'ID' || trim((string) $plain($ws->getCell('E7')->getValue())) !== 'HEYMAN') {
            $bad[] = "{$name} E6/E7 ≠ ID/HEYMAN";
        }
        if ($name === '차량팩킹' && trim((string) $plain($ws->getCell('E8')->getValue())) !== '') {
            $bad[] = '차량팩킹 E8 이 비어 있지 않다';
        }
    }
    foreach (HEYMAN_CLEARANCE_GOVT_SEALS as $name => [, $to, $dx, $dy]) {
        $ws = $ss->getSheetByName($name);
        $found = false;
        foreach ($ws->getDrawingCollection() as $d) {
            if ($d->getName() === 'govt_seal' && $d->getCoordinates() === $to && $d->getOffsetX() === $dx && $d->getOffsetY() === $dy) {
                $found = true;
            }
        }
        if (! $found) {
            $bad[] = "{$name} 관인이 {$to}+({$dx},{$dy}) 에 없다";
        }
    }
    foreach (HEYMAN_CLEARANCE_SEALS as $name => [$anchor, $dx, $dy]) {
        $ws = $ss->getSheetByName($name);
        $found = false;
        foreach ($ws->getDrawingCollection() as $d) {
            if ($d->getCoordinates() === $anchor && $d->getOffsetX() === $dx && $d->getOffsetY() === $dy) {
                $found = true;
            }
        }
        if (! $found) {
            $bad[] = "{$name} 직인 drawing 이 {$anchor}+({$dx},{$dy}) 에 없다";
        }
    }

    return $bad;
}
