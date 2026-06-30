<?php

/**
 * karaba 서류 템플릿 생성 — system/ 9종 복사 + SSANCAR 회사정보 셀을 karaba 값으로 치환.
 *   php scripts/generate-karaba-templates.php           # dry-run (계획만)
 *   php scripts/generate-karaba-templates.php --apply    # resources/templates/karaba/ 생성
 *
 * 멱등: --apply 재실행 시 system/ 에서 새로 생성(덮어씀). 셀별 명시 op:
 *   'set'     => 셀 통째 새 값
 *   'replace' => 부분 문자열 치환(레이아웃/접미사 보존)
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$APPLY = in_array('--apply', $argv, true);

// karaba 공통 문자열
$sellerLine = 'Karaba Co., Ltd. Kim Hee-chul. #303, 178 Injung-ro, Jung-gu, Incheon, Korea  Phone: +82-32-710-7979 Email: sales@karaba.co.kr';
$shipperBlock = "KARABA CO., LTD.,\n#303, 178 Injung-ro, Jung-gu,\nIncheon, Republic of Korea\nTEL:82-32-710-7979 \nFAX:82-32-710-1881\nEMAIL : sales@karaba.co.kr";
$clearanceSeller = "KARABA CO., LTD.,\n#303, 178 Injung-ro, Jung-gu, Incheon\nTEL:82-32-710-7979 \nFAX:82-32-710-1881\nEMAIL : sales@karaba.co.kr";
$bankAddr = '216-55, Hogupo-ro, Namdong-gu, Incheon, South Korea';
$benefAddr = '#303, 178 Injung-ro, Jung-gu, Incheon, Korea';
$benefAddr2 = "#303, 178 Injung-ro, Jung-gu,\nIncheon, Korea";

// 은행 4종 공통 (Beneficiary / Swift / Account / Bank Name)
$bankSet = fn ($benef, $swift, $acct, $bank) => compact('benef', 'swift', 'acct', 'bank');

$map = [
    'sales_invoice.xlsx' => ['Invoice' => [
        'A2' => ['set', $sellerLine],
        'C13' => ['set', 'KARABA CO., LTD'],
        'C14' => ['set', 'KOEXKRSEXXX'],
        'C15' => ['set', '433 910007 14938'],
        'E13' => ['set', 'KEB Hana Bank'],
        'E14' => ['set', $bankAddr],
        'E15' => ['set', $benefAddr2],
    ]],
    'container_invoice_packing.xlsx' => ['INVOICE' => [
        'B3' => ['set', $shipperBlock],
        'N14' => ['set', ''],
    ]],
    'roro_invoice_packing.xlsx' => ['INVOICE' => [
        'B3' => ['set', $shipperBlock],
        'N17' => ['set', ''],
    ]],
    'container_contract.xlsx' => ['HBB340.' => [
        'A2' => ['set', $sellerLine],
        'C11' => ['set', 'KARABA CO., LTD'],
        'C12' => ['set', 'KOEXKRSEXXX'],
        'C13' => ['set', '433 910007 14938'],
        'F11' => ['set', 'KEB Hana Bank'],
        'F12' => ['set', $bankAddr],
    ]],
    'roro_contract.xlsx' => ['HBB340.' => [
        'A2' => ['set', $sellerLine],
        'C11' => ['set', 'KARABA CO., LTD'],
        'C12' => ['set', 'KOEXKRSEXXX'],
        'C13' => ['set', '433 910007 14938'],
        'F11' => ['set', 'KEB Hana Bank'],
        'F12' => ['set', $bankAddr],
    ]],
    'deregistration_application.xlsx' => ['1.차량말소신청서' => [
        'C34' => ['set', '인천광역시 중구 인중로 178 정우빌딩 303호'],
        'C35' => ['set', '주식회사 카라바'],
        'C36' => ['set', '801-81-01696'],
    ]],
    'deregistration_contract.xlsx' => ['2.계약서' => [
        'A1' => ['set', 'KARABA CO., LTD '],
    ]],
    'power_of_attorney.xlsx' => ['4.위임장' => [
        'C23' => ['replace', ['주식회사 싼카' => '주식회사 카라바']],  // (날인) 접미사·패딩 보존
        'C24' => ['set', '인천광역시 중구 인중로 178 정우빌딩 303호'],
    ]],
    'clearance_set.xlsx' => [
        '한글등록증' => [
            'E7' => ['set', '인천광역시 중구 인중로 178 정우빌딩 303호'],
            'E8' => ['replace', ['주식회사 싼카' => '주식회사 카라바']],  // (상품용) 보존
        ],
        '영문등록증' => [
            'E7' => ['set', '#303, 178 Injung-ro, Jung-gu, Incheon, Korea '],
            'E8' => ['set', 'KARABA CO., LTD. '],
        ],
        '말소증' => [
            'E21' => ['set', '주식회사카라바'],
        ],
        '차량인보이스' => [
            'A3' => ['set', $clearanceSeller],
        ],
        '차량팩킹' => [
            'A3' => ['set', $clearanceSeller],
        ],
        'Travel Services Invoice' => [
            'A2' => ['set', 'Karaba Co., Ltd #303, 178 Injung-ro, Jung-gu, Incheon, Korea   Phone: +82-32-710-7979'],
            'C16' => ['set', 'KARABA CO., LTD'],
            'C17' => ['set', 'KOEXKRSEXXX'],
            'C18' => ['set', '433 910007 14938'],
            'F16' => ['set', 'KEB Hana Bank'],
            'F17' => ['set', $bankAddr],
            'F18' => ['set', $benefAddr],
        ],
    ],
];

$srcDir = resource_path('templates/system');
$dstDir = resource_path('templates/karaba');

echo $APPLY ? "════ APPLY → {$dstDir} ════\n" : "════ DRY-RUN ════\n";
if ($APPLY && ! is_dir($dstDir)) {
    mkdir($dstDir, 0775, true);
}

foreach ($map as $file => $sheets) {
    $src = $srcDir.'/'.$file;
    if (! file_exists($src)) {
        echo "❌ 원본 없음: {$file}\n";

        continue;
    }
    echo "\n── {$file}\n";
    $ss = IOFactory::load($src);
    foreach ($sheets as $sheetName => $cells) {
        $sheet = $ss->getSheetByName($sheetName);
        if (! $sheet) {
            echo "  ❌ 시트 없음: {$sheetName}\n";

            continue;
        }
        foreach ($cells as $coord => [$op, $arg]) {
            $cur = (string) $sheet->getCell($coord)->getValue();
            if ($op === 'set') {
                $new = $arg;
            } else { // replace
                $new = strtr($cur, $arg);
                if ($new === $cur) {
                    echo "  ⚠ {$sheetName}!{$coord} 치환대상 없음: '".str_replace("\n", '⏎', $cur)."'\n";
                }
            }
            printf("  %s!%s: '%s' → '%s'\n", $sheetName, $coord,
                mb_substr(str_replace("\n", '⏎', $cur), 0, 40), mb_substr(str_replace("\n", '⏎', $new), 0, 40));
            if ($APPLY) {
                $sheet->getCell($coord)->setValue($new === '' ? null : $new);
            }
        }
    }
    if ($APPLY) {
        // 외부 하이퍼링크(WebDAV file:// 등) 제거 — writer 깨짐 방어 (SKILLS §12 stripHyperlinks)
        foreach ($ss->getWorksheetIterator() as $sh) {
            foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
                $sh->setHyperlink($hc, null);
            }
        }
        $writer = new Xlsx($ss);
        $writer->setPreCalculateFormulas(false);  // 크로스시트 수식 보존(clearance cascade)
        $writer->save($dstDir.'/'.$file);
        echo "  ✅ 저장: karaba/{$file}\n";
    }
    $ss->disconnectWorksheets();
    unset($ss);
}
echo "\n".($APPLY ? "완료. scan 으로 SSANCAR 잔존 확인 권장.\n" : "dry-run. --apply 로 생성.\n");
