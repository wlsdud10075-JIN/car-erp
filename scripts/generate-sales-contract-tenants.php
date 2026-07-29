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

// 회사정보 셀 — ⚠️ 2026-07-29 레이아웃 개편으로 좌표가 전부 바뀌었다.
//   은행블록: Beneficiary E15 / Bank Name R15 / Swift E16 / Bank Address R16 / Account E17 / Beneficiary Address R17
//   SELLER 블록: 상호 B64 / 사업자번호 B66 / Tel·Email B67 / 주소 B68
//   (구 좌표 C16~E18 · B66·B68·B69·B70 은 폐기 — 그대로 두면 엉뚱한 칸에 회사정보가 박힌다.)
// ⚠️ 병합 안쪽 유령값은 build 스크립트가 이미 비웠다. 여기서는 앵커에만 쓴다.
$tenants = [
    'heyman' => [
        'E15' => 'HEYMAN LTD',                                    // Beneficiary
        'R15' => 'SHINHAN BANK',                                  // Bank Name (동일 은행)
        'E16' => 'SHBKKRSE',                                      // Swift (신한)
        'R16' => '20,Sejong-Daero9-Gil,Jung-Gu Seoul South Korea', // Bank Address (신한 본점)
        'E17' => '180-012-458342',                               // Account (heyman)
        'R17' => '#513, THE PARK 365, 50 Seonyudong1-ro, Yeongdeungpo-gu, Seoul, Korea', // Beneficiary Address
        'B64' => 'HEYMAN CO., LTD',                              // SELLER 상호
        'B66' => 'Registration Number : 535-87-01734',          // 사업자번호
        'B67' => 'Tel: +82-10-9009-9977         Email: heyman99888@gmail.com',
        'B68' => 'Address : #513, THE PARK 365, 50 Seonyudong1-ro, Yeongdeungpo-gu, Seoul, Korea',
    ],
    'karaba' => [
        'E15' => 'KARABA CO., LTD',
        'R15' => 'KEB Hana Bank',
        'E16' => 'KOEXKRSEXXX',
        'R16' => '216-55, Hogupo-ro, Namdong-gu, Incheon, South Korea',
        'E17' => '433 910007 14938',
        'R17' => '#303, 178 Injung-ro, Jung-gu, Incheon, Korea',
        'B64' => 'KARABA CO., LTD',
        'B66' => 'Registration Number : 801-81-01696',
        'B67' => 'Tel: +82-32-710-7979         Email: sales@karaba.co.kr',
        'B68' => 'Address : #303, 178 Injung-ro, Jung-gu, Incheon, Korea',
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
