<?php

/**
 * 판매계약서 테넌트 생성 — system(SSANCAR) sales_contract.xlsx 복사 후 회사정보 셀을
 * heyman / karaba 값으로 치환. (build-sales-contract-template.php 로 system 생성 뒤 실행)
 *
 * 회사값 출처(권위):
 *  - heyman: docs/operations/heyman-company-info-cleanup.md + 배포 heyman 템플릿(사업자 535-87-01734,
 *            선유동1로 THE PARK 365, TEL 82-10-9009-9977, heyman99888@gmail.com, 신한 180-012-458342).
 *  - karaba: scripts/generate-karaba-templates.php (KEB Hana·KOEXKRSEXXX·433 910007 14938·801-81-01696).
 *
 * 실행: php scripts/generate-sales-contract-tenants.php
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const SYS = __DIR__.'/../resources/templates/system/sales_contract.xlsx';

// 회사정보 셀(system 최종 좌표): 은행블록 C16~E18 / SELLER 블록 B66·B68·B69·B70.
$tenants = [
    'heyman' => [
        'C16' => 'HEYMAN LTD',                                    // Beneficiary
        'E16' => 'SHINHAN BANK',                                  // Bank Name (동일 은행)
        'C17' => 'SHBKKRSE',                                      // Swift (신한)
        'E17' => '20,Sejong-Daero9-Gil,Jung-Gu Seoul South Korea', // Bank Address (신한 본점)
        'C18' => '180-012-458342',                               // Account (heyman)
        'E18' => "#513, THE PARK 365, 50 Seonyudong1-ro,\nYeongdeungpo-gu, Seoul, Korea", // Beneficiary Address
        'B66' => 'HEYMAN CO., LTD',                              // SELLER 상호
        'B68' => 'Registration Number : 535-87-01734',          // 사업자번호
        'B69' => 'Tel: +82-10-9009-9977         Email: heyman99888@gmail.com',
        'B70' => 'Address : #513, THE PARK 365, 50 Seonyudong1-ro, Yeongdeungpo-gu, Seoul, Korea',
    ],
    'karaba' => [
        'C16' => 'KARABA CO., LTD',
        'E16' => 'KEB Hana Bank',
        'C17' => 'KOEXKRSEXXX',
        'E17' => '216-55, Hogupo-ro, Namdong-gu, Incheon, South Korea',
        'C18' => '433 910007 14938',
        'E18' => "#303, 178 Injung-ro, Jung-gu,\nIncheon, Korea",
        'B66' => 'KARABA CO., LTD',
        'B68' => 'Registration Number : 801-81-01696',
        'B69' => 'Tel: +82-32-710-7979         Email: sales@karaba.co.kr',
        'B70' => 'Address : #303, 178 Injung-ro, Jung-gu, Incheon, Korea',
    ],
];

foreach ($tenants as $set => $cells) {
    $ss = IOFactory::load(SYS);
    $sheet = $ss->getSheetByName('CONTRACT');
    foreach ($cells as $coord => $val) {
        $sheet->getCell($coord)->setValue($val);   // 셀 스타일 보존, 값만 교체
    }
    $dst = __DIR__."/../resources/templates/{$set}/sales_contract.xlsx";
    $w = new Xlsx($ss);
    $w->setPreCalculateFormulas(false);
    $w->save($dst);
    echo "saved: {$set}/sales_contract.xlsx (".count($cells)." cells)\n";
}
echo "DONE.\n";
