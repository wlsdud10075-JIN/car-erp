<?php

/**
 * HEYMAN 양식 세트 회사정보·라벨 교정 (jin 2026-06-25).
 *
 * 대상: resources/templates/heyman/ 의 2개 파일.
 *
 * [말소신청서] deregistration_application.xlsx (시트 1.차량말소신청서)
 *   - C34(위임자 주소)  : 싼카 주소 → HEYMAN 상암 주소
 *   - C36(위임자 사업자번호): 662-81-00898 → 535-87-01734
 *   (C35 성명 "주식회사 헤이맨" 유지)
 *
 * [통관 SET] clearance_set.xlsx
 *   - 영문등록증 M8 / 한글등록증 M8 / 말소증 K21 (법인등록번호): 120111-0922270 → 110111-7526176
 *   - 한글등록증 E8(소유자 성명): "주식회사 헤이맨 (상품용)" → "주식회사 헤이맨"  ((상품용) 제거)
 *   - 한글등록증 P4 / 영문등록증 P4(용도): 리터럴 → 수식 =구매리스트!B8 (NICE resUseType cascade)
 *   - 구매리스트 A8: 빈칸 → "용도/Usage" (라벨). B8(데이터)·D11(선적일)은 매핑 코드에서 채움.
 *   - 말소증 E21:G22(소유자 성명, 2행 병합) → 2줄 분리:
 *       E21:G21 = "주식회사헤이맨" / E22:G22 = "HEYMAN LTD,."
 *     (scripts/split-malso-plate-cells.php 패턴 — duplicateStyle + 행 구분선 제거)
 *
 *   php scripts/heyman-doc-fixes-2026-06-25.php          # dry-run
 *   php scripts/heyman-doc-fixes-2026-06-25.php --apply  # 적용
 *
 * ⚠ 저장은 preCalc=false (크로스시트 cascade 수식 보존 — 템플릿 컨벤션).
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$TEMPLATES = __DIR__.'/../resources/templates/heyman';

$APPLY = in_array('--apply', $argv, true);
echo $APPLY ? "════ APPLY ════\n" : "════ DRY-RUN ════\n";

$HEYMAN_ADDR = '서울특별시 마포구 매봉산로 31, 에스플랙스센터 시너지움 7층 1인 미디어파트너스 (상암동)';
$BIZ_NO = '535-87-01734';
$CORP_NO = '110111-7526176';

/** RichText 셀이면 첫 run 텍스트만 교체(폰트/볼드 보존), 아니면 문자열 기입. */
$setText = function ($sheet, string $coord, string $newText) {
    $cell = $sheet->getCell($coord);
    $v = $cell->getValue();
    if ($v instanceof RichText) {
        $els = $v->getRichTextElements();
        if (! empty($els)) {
            $els[0]->setText($newText);
            for ($i = 1, $n = count($els); $i < $n; $i++) {
                $els[$i]->setText('');
            }

            return;
        }
    }
    $cell->setValueExplicit($newText, DataType::TYPE_STRING);
};

$stripLinks = function ($ss) {
    foreach ($ss->getWorksheetIterator() as $sh) {
        foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
            $sh->setHyperlink($hc, null);
        }
    }
};

// ───────────────────────── 1) 말소신청서 ─────────────────────────
$path = $TEMPLATES.'/deregistration_application.xlsx';
echo "\n── 말소신청서: $path\n";
$ss = IOFactory::load($path);
$sh = $ss->getSheet(0);  // 1.차량말소신청서
echo '  C34(주소) 현재 = '.plain($sh->getCell('C34')->getValue())."\n";
echo '  C36(사업자번호) 현재 = '.plain($sh->getCell('C36')->getValue())."\n";
echo "  → C34 = $HEYMAN_ADDR\n  → C36 = $BIZ_NO\n";
if ($APPLY) {
    $setText($sh, 'C34', $HEYMAN_ADDR);
    $setText($sh, 'C36', $BIZ_NO);
    $stripLinks($ss);
    $w = new Xlsx($ss);
    $w->setPreCalculateFormulas(false);
    $w->save($path);
    echo "  ✅ 저장\n";
    $ss->disconnectWorksheets();
    unset($ss);
}

// ───────────────────────── 2) 통관 SET ─────────────────────────
$path = $TEMPLATES.'/clearance_set.xlsx';
echo "\n── 통관 SET: $path\n";
$ss = IOFactory::load($path);
$han = $ss->getSheetByName('한글등록증');
$eng = $ss->getSheetByName('영문등록증');
$mal = $ss->getSheetByName('말소증');
$pur = $ss->getSheetByName('구매리스트');

echo '  한글등록증 M8 현재 = '.plain($han->getCell('M8')->getValue())." → $CORP_NO\n";
echo '  영문등록증 M8 현재 = '.plain($eng->getCell('M8')->getValue())." → $CORP_NO\n";
echo '  말소증     K21 현재 = '.plain($mal->getCell('K21')->getValue())." → $CORP_NO\n";
echo '  한글등록증 E8 현재 = '.plain($han->getCell('E8')->getValue())." → 주식회사 헤이맨\n";
echo '  한글등록증 P4 현재 = '.plain($han->getCell('P4')->getValue())." → =구매리스트!B8\n";
echo '  영문등록증 P4 현재 = '.plain($eng->getCell('P4')->getValue())." → =구매리스트!B8\n";
echo '  구매리스트 A8 현재 = "'.plain($pur->getCell('A8')->getValue()).'" → 용도/Usage'."\n";
$merges = $mal->getMergeCells();
echo '  말소증 E21:G22 병합 존재? '.(isset($merges['E21:G22']) ? 'Y' : 'N (이미 분리/구조변경)')."\n";
echo '  말소증 E21 현재 = '.plain($mal->getCell('E21')->getValue())." → E21:G21=주식회사헤이맨 / E22:G22=HEYMAN LTD,.\n";

if ($APPLY) {
    // 법인등록번호 3곳
    $setText($han, 'M8', $CORP_NO);
    $setText($eng, 'M8', $CORP_NO);
    $setText($mal, 'K21', $CORP_NO);
    // 한글등록증 (상품용) 제거
    $setText($han, 'E8', '주식회사 헤이맨');
    // 용도 cascade — P4 를 수식으로 (RichText 전체 교체)
    $han->getCell('P4')->setValue('=구매리스트!B8');
    $eng->getCell('P4')->setValue('=구매리스트!B8');
    // 구매리스트 라벨
    $setText($pur, 'A8', '용도/Usage');

    // 말소증 E21:G22 → 2줄 분리 (split-malso-plate-cells.php 패턴)
    if (isset($merges['E21:G22'])) {
        $mal->unmergeCells('E21:G22');
        $mal->mergeCells('E21:G21');
        $mal->mergeCells('E22:G22');
        $setText($mal, 'E21', '주식회사헤이맨');
        $mal->duplicateStyle($mal->getStyle('E21'), 'E22:G22');
        $mal->getStyle('E22:G22')->getBorders()->getTop()->setBorderStyle(Border::BORDER_NONE);
        $mal->getCell('E22')->setValueExplicit('HEYMAN LTD,.', DataType::TYPE_STRING);
    }

    $stripLinks($ss);
    $w = new Xlsx($ss);
    $w->setPreCalculateFormulas(false);
    $w->save($path);
    echo "  ✅ 저장\n";
    $ss->disconnectWorksheets();
    unset($ss);
}

echo "\n".($APPLY ? "완료.\n" : "dry-run. --apply 로 적용.\n");

function plain($v): string
{
    if ($v instanceof RichText) {
        return '[RT]'.$v->getPlainText();
    }

    return (string) $v;
}
