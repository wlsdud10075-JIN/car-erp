<?php

/**
 * 서류 양식 영문 오타 일괄 정정 — 3사(system·heyman·karaba) 공통.
 *   php scripts/fix-invoice-typos.php           # dry-run
 *   php scripts/fix-invoice-typos.php --apply    # 3세트 전부 제자리 수정
 *
 * 배경(2026-07-21 jin): Proforma Invoice 등 양식 제목·라벨 오타. 3사 동일 base 파생이라 전부 동일.
 *   PROFORM → PROFORMA (제목) / Swift Cose → Swift Code / Adress → Address (Bank/Beneficiary 포함)
 * 회사정보가 아니라 양식 텍스트라 3세트 모두 동일 정정. 멱등(재실행 안전).
 *   - 'PROFORM ' 트레일링 스페이스 가드로 PROFORMA 재치환(PROFORMAA) 방지.
 *   - 'Adress' 는 'Address' 의 부분문자열이 아니라 재실행 no-op.
 * 정상 철자(Chassis No. / sales_contract 의 Swift Code)는 대상 아님(치환맵에 없음).
 *
 * ⚠ 수식 셀(=)은 건너뜀 — clearance cascade(=구매리스트!) 보존.
 */

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$APPLY = in_array('--apply', $argv, true);

// 안전 치환맵(순서 무관, 부분문자열 오검출 없음)
$fix = [
    'PROFORM ' => 'PROFORMA ',   // 트레일링 스페이스 = 멱등 가드
    'Swift Cose' => 'Swift Code',
    'Adress' => 'Address',      // "Bank Adress"/"Beneficiary Adress" 포함 자동 처리
];

echo $APPLY ? "════ APPLY (3세트 in-place) ════\n" : "════ DRY-RUN ════\n";

$total = 0;
foreach (['system', 'heyman', 'karaba'] as $set) {
    $dir = resource_path('templates/'.$set);
    foreach (glob($dir.'/*.xlsx') as $path) {
        $file = basename($path);
        $ss = IOFactory::load($path);
        $changed = 0;
        $isClearance = ($file === 'clearance_set.xlsx');
        foreach ($ss->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $v = $cell->getValue();

                    // RichText(서식 있는 제목·라벨) — element 별 텍스트만 치환해 서식 보존
                    if ($v instanceof RichText) {
                        foreach ($v->getRichTextElements() as $el) {
                            $t = $el->getText();
                            $nt = strtr($t, $fix);
                            if ($nt !== $t) {
                                printf("  [%s] %s!%s!%s: '%s' → '%s'\n", $set, $file, $sheet->getTitle(),
                                    $cell->getCoordinate(), str_replace("\n", '⏎', $t), str_replace("\n", '⏎', $nt));
                                $changed++;
                                $total++;
                                if ($APPLY) {
                                    $el->setText($nt);
                                }
                            }
                        }

                        continue;
                    }

                    if (! is_string($v) || $v === '' || $v[0] === '=') {
                        continue;   // 빈칸·수식 제외
                    }
                    $new = strtr($v, $fix);
                    if ($new !== $v) {
                        printf("  [%s] %s!%s!%s: '%s' → '%s'\n", $set, $file, $sheet->getTitle(),
                            $cell->getCoordinate(), str_replace("\n", '⏎', $v), str_replace("\n", '⏎', $new));
                        $changed++;
                        $total++;
                        if ($APPLY) {
                            $cell->setValue($new);
                        }
                    }
                }
            }
        }
        if ($APPLY && $changed > 0) {
            if ($isClearance) {
                foreach (['한글등록증', '영문등록증', '말소증'] as $gsh) {
                    if ($g = $ss->getSheetByName($gsh)) {
                        $g->setShowGridlines(false);
                    }
                }
            }
            foreach ($ss->getWorksheetIterator() as $sh) {
                foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
                    $sh->setHyperlink($hc, null);
                }
            }
            $writer = new Xlsx($ss);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            echo "  ✅ 저장: {$set}/{$file} ({$changed}건)\n";
        }
        $ss->disconnectWorksheets();
        unset($ss);
    }
}
echo "\n총 {$total}건 ".($APPLY ? "정정 완료.\n" : "(dry-run). --apply 로 반영.\n");
