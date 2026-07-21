<?php

/**
 * Proforma Invoice(sales_invoice) 레이아웃 — A안 (jin 2026-07-21). 3세트(system·heyman·karaba) 공통.
 *   php scripts/proforma-invoice-layout.php           # dry-run
 *   php scripts/proforma-invoice-layout.php --apply
 *
 * 변경(정보블록 D=라벨 / E:F=값):
 *   ① D5 라벨 "Buyer Name" → "Name"
 *   ② Phone(9행)과 Dollar Rate 사이에 Email 행 추가 = 빈 11행 활용(행 삽입 없음, 아래 안 밀림).
 *      - D10 라벨 "Dollar Rate" → "Email", E10 = Phone 스타일 복제(텍스트/노랑) → 바이어 이메일
 *      - D11 라벨 "Dollar Rate"(신규), E11 = 기존 Rate 스타일 복제(통화/노랑) + E11:F11 병합 → 환율
 *   ⇒ 결과: 9 Phone / 10 Email / 11 Dollar Rate / 12~ Bank(불변).
 * 매핑(SalesInvoiceMapping)도 E10=이메일 / E11=환율로 함께 수정(별도).
 * ⚠ 정보블록과 은행블록 사이 빈 구분줄이 사라짐(코스메틱, jin A안 승인).
 */

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$APPLY = in_array('--apply', $argv, true);

// RichText 첫 element 텍스트만 교체(서식 보존). 없으면 plain set.
$setLabel = function ($sheet, string $coord, string $text) {
    $v = $sheet->getCell($coord)->getValue();
    if ($v instanceof RichText) {
        $els = $v->getRichTextElements();
        if ($els) {
            $els[0]->setText($text);
            // 첫 element 외 잔재 제거(2개 이상이면 이어붙은 텍스트 방지)
            if (count($els) > 1) {
                $v->setRichTextElements([$els[0]]);
            }

            return;
        }
    }
    $sheet->setCellValue($coord, $text);
};

echo $APPLY ? "════ APPLY (3세트) ════\n" : "════ DRY-RUN ════\n";

foreach (['system', 'heyman', 'karaba'] as $set) {
    $path = resource_path('templates/'.$set.'/sales_invoice.xlsx');
    echo "\n── {$set}/sales_invoice.xlsx\n";
    $ss = IOFactory::load($path);
    $sh = $ss->getSheetByName('Invoice');

    $before = [
        'D5' => (string) $sh->getCell('D5')->getValue(),
        'D10' => (string) $sh->getCell('D10')->getValue(),
        'D11' => (string) $sh->getCell('D11')->getValue(),
        'E11merged' => in_array('E11:F11', $sh->getMergeCells()),
    ];
    printf("  현재 D5='%s' D10='%s' D11='%s' E11병합=%s\n", $before['D5'], $before['D10'], $before['D11'],
        $before['E11merged'] ? 'y' : 'n');

    if ($APPLY) {
        // 스타일 복제는 값 변경 전에 — 원본 참조 보존
        $sh->duplicateStyle($sh->getStyle('E10'), 'E11');   // 기존 Rate 스타일(통화/노랑) → E11
        $sh->duplicateStyle($sh->getStyle('D10'), 'D11');   // Rate 라벨 스타일 → D11
        $sh->duplicateStyle($sh->getStyle('E9'), 'E10');    // Phone 값 스타일(텍스트/노랑) → E10(email)
        $sh->getStyle('E10')->getNumberFormat()->setFormatCode('@');   // email = 텍스트
        $sh->duplicateStyle($sh->getStyle('D9'), 'D10');    // Phone 라벨 스타일 → D10(email 라벨)

        // 라벨 텍스트
        $setLabel($sh, 'D5', 'Name');
        $setLabel($sh, 'D10', 'Email');
        $sh->setCellValue('D11', 'Dollar Rate');

        // D5/D6 오렌지 채움(FFC000) 제거 — 엔진 isYellow 가 오렌지도 노랑으로 판정해 라벨을
        // 샘플로 오인·삭제하던 버그. 채움 없애면 라벨(Name/Client Name) 생성 시 보존.
        $sh->getStyle('D5')->getFill()->setFillType(Fill::FILL_NONE);
        $sh->getStyle('D6')->getFill()->setFillType(Fill::FILL_NONE);

        // E11:F11 병합 + 행 높이(row10 처럼)
        if (! in_array('E11:F11', $sh->getMergeCells())) {
            $sh->mergeCells('E11:F11');
        }
        $sh->getRowDimension(11)->setRowHeight($sh->getRowDimension(10)->getRowHeight());
        // E10/E11 값은 매핑이 채움 — 노란셀 유지 위해 공백 placeholder
        $sh->setCellValue('E10', ' ');
        $sh->setCellValue('E11', ' ');

        echo "  → D5=Name, D10=Email(E10 텍스트/노랑), D11=Dollar Rate(E11 통화/노랑, 병합)\n";

        foreach ($ss->getWorksheetIterator() as $s2) {
            foreach (array_keys($s2->getHyperlinkCollection()) as $hc) {
                $s2->setHyperlink($hc, null);
            }
        }
        $w = new Xlsx($ss);
        $w->setPreCalculateFormulas(false);
        $w->save($path);
        echo "  ✅ 저장\n";
    }
    $ss->disconnectWorksheets();
    unset($ss);
}
echo "\n".($APPLY ? "완료. 매핑(E10=email/E11=rate) 별도 수정 + Excel 눈검증 필요.\n" : "dry-run.\n");
