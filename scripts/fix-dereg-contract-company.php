<?php

/**
 * 말소 계약서(deregistration_contract) 2.계약서 탭 회사정보(주소·TEL·FAX) 테넌트별 정정.
 *   php scripts/fix-dereg-contract-company.php system           # dry-run (기본 system)
 *   php scripts/fix-dereg-contract-company.php system --apply
 *   php scripts/fix-dereg-contract-company.php heyman --apply    # (heyman 값 채운 뒤)
 *
 * 배경(2026-07-21 jin): 2.계약서 A2:E2/A3:E3 = 옛 인천·고양 주소 잔재, E4 TEL=heyman번호, E5 FAX=옛번호.
 *   heyman 생성 스크립트가 "차차 보강"으로 남겨둔 테넌트 잔재(A1 이름만 바꿨었음).
 * RichText 라 element 텍스트만 교체(서식 보존). A1(상호)은 손대지 않음(이미 세트별 정정됨).
 *
 * ⚠ heyman·karaba 값은 정본 확인 후 아래 $COMPANY 에 채울 것(현재 system=ssancar 만 확정).
 */

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// 세트별 2.계약서 A2(주소1)/A3(주소2)/E4(TEL)/E5(FAX)
$COMPANY = [
    'system' => [   // ssancar (사업자등록증 + ssancar_English_Adress.txt)
        'A2' => '163 Sangidaehak-ro, Siheung-si,',
        'A3' => 'Gyeonggi-do, Republic of Korea',
        'E4' => 'TEL : 82-505-355-9977',
        'E5' => 'FAX : 82-505-366-9977',
    ],
    'heyman' => [   // #513, THE PARK 365, 50 Seonyudong1-ro, Yeongdeungpo-gu, Seoul (인보이스·판매계약서 테넌트값과 동일)
        'A2' => '#513, THE PARK 365, 50 Seonyudong1-ro,',
        'A3' => 'Yeongdeungpo-gu, Seoul, Korea',
        'E4' => 'TEL : 82-10-9009-9977',
        'E5' => 'FAX : 82-505-366-9977',
    ],
    // 'karaba' => [...],   // TODO: karaba 정본 (jin 미제공)
];

$set = $argv[1] ?? 'system';
$APPLY = in_array('--apply', $argv, true);

if (! isset($COMPANY[$set])) {
    echo "❌ '{$set}' 정본 미정 — \$COMPANY 에 값 채운 뒤 실행.\n";
    exit(1);
}

$path = resource_path('templates/'.$set.'/deregistration_contract.xlsx');
$ss = IOFactory::load($path);
$sh = $ss->getSheetByName('2.계약서');

echo $APPLY ? "════ APPLY {$set} ════\n" : "════ DRY-RUN {$set} ════\n";
foreach ($COMPANY[$set] as $coord => $text) {
    $v = $sh->getCell($coord)->getValue();
    $cur = (string) $v;
    printf("  %s: '%s' → '%s'\n", $coord, str_replace("\n", '⏎', $cur), $text);
    if ($APPLY) {
        if ($v instanceof RichText) {
            $els = $v->getRichTextElements();
            if ($els) {
                $els[0]->setText($text);
                if (count($els) > 1) {
                    $v->setRichTextElements([$els[0]]);
                }
            } else {
                $sh->setCellValue($coord, $text);
            }
        } else {
            $sh->setCellValue($coord, $text);
        }
    }
}

if ($APPLY) {
    foreach ($ss->getWorksheetIterator() as $s2) {
        foreach (array_keys($s2->getHyperlinkCollection()) as $h) {
            $s2->setHyperlink($h, null);
        }
    }
    $w = new Xlsx($ss);
    $w->setPreCalculateFormulas(false);
    $w->save($path);
    echo "  ✅ 저장: {$set}/deregistration_contract.xlsx\n";
}
$ss->disconnectWorksheets();
echo $APPLY ? "완료.\n" : "dry-run.\n";
