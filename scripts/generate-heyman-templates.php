<?php

/**
 * heyman 서류 템플릿 생성 — system/ 9종 복사 + SSANCAR 회사정보 셀을 HEYMAN 값으로 치환.
 *   php scripts/generate-heyman-templates.php           # dry-run (계획만)
 *   php scripts/generate-heyman-templates.php --apply    # resources/templates/heyman/ 생성
 *
 * generate-karaba-templates.php 와 동일 구조. 멱등: --apply 재실행 시 system/ 에서 새로 생성.
 *   'set'     => 셀 통째 새 값 (주소·연락처가 다른 회사정보 블록)
 *   'replace' => 부분 문자열 치환 (이름만 SSANCAR→HEYMAN, 패딩·접미사 보존)
 *
 * ⚠ 이번엔 jin 이 _HEYMAN 파일에서 "완전히" 바꾼 셀만 반영(2026-06-23). _HEYMAN 파일에
 *   ssancar 잔존했던 셀(sales A2·C13, 통관 Travel A2·C16·차량인보이스 A3,
 *   컨/RORO INVOICE N14·N17, 계약서 A2 이메일)은 누락분 → 차차 보강. 구조(30슬롯·D14·H13)는
 *   system 에서 복사돼 보존. 차량인보이스 A3 는 brandHeader(sidebar_brand) 런타임 치환이라 제외.
 */

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$APPLY = in_array('--apply', $argv, true);

$packing = "HEYMAN LTD.,\n163 Sangidaehak-ro, Siheung-si, Gyeonggi-do, Korea\nTEL:82-505-366-9977 \nFAX:82-505-366-9977\nEMAIL : man99777@naver.com";
$containerB3 = "HEYMAN LTD.,\n163 Sangidaehak-ro, Siheung-si,\nGyeonggi-do, Republic of Korea\nTEL:82-505-355-9977 \nFAX:82-505-366-9977\nEMAIL : man99777@naver.com";
$roroB3 = "HEYMAN LTD.,\n63, Seonghyeon-ro, Ilsandong-gu, Goyang-si, \nGyeonggi-do, Republic of Korea\nTEL:82-505-355-9977 \nFAX:82-505-366-9977\nEMAIL : man99777@naver.com";

$map = [
    'deregistration_contract.xlsx' => ['2.계약서' => [
        'A1' => ['set', 'HEYMAN CO., LTD'],   // _HEYMAN 파일 "HETMAN" 오타 교정
    ]],
    'power_of_attorney.xlsx' => ['4.위임장' => [
        'C23' => ['replace', ['주식회사 싼카' => '주식회사 헤이맨']],   // (날인) 접미사·패딩 보존
    ]],
    'deregistration_application.xlsx' => ['1.차량말소신청서' => [
        'C35' => ['replace', ['주식회사 싼카' => '주식회사 헤이맨']],
    ]],
    'clearance_set.xlsx' => [
        '한글등록증' => ['E8' => ['replace', ['주식회사 싼카' => '주식회사 헤이맨']]],   // (상품용) 보존
        '영문등록증' => ['E8' => ['set', 'HEYMAN LTD,. ']],
        '말소증' => ['E21' => ['set', '주식회사헤이맨']],
        '차량팩킹' => ['A3' => ['set', $packing]],
    ],
    'container_invoice_packing.xlsx' => ['INVOICE' => [
        'B3' => ['set', $containerB3],
    ]],
    'container_contract.xlsx' => ['HBB340.' => [
        'C11' => ['set', 'HEYMAN LTD.'],
    ]],
    'roro_invoice_packing.xlsx' => ['INVOICE' => [
        'B3' => ['set', $roroB3],
    ]],
    'roro_contract.xlsx' => ['HBB340.' => [
        'C11' => ['set', 'HEYMAN LTD.'],
    ]],
    // 패치 없이 복사만 (heyman 세트에 파일은 있어야 함 — 누락분은 차차):
    'sales_invoice.xlsx' => [],
];

$srcDir = resource_path('templates/system');
$dstDir = resource_path('templates/heyman');

echo $APPLY ? "════ APPLY → {$dstDir} ════\n" : "════ DRY-RUN ════\n";
if ($APPLY && ! is_dir($dstDir)) {
    mkdir($dstDir, 0775, true);
}

foreach ($map as $file => $sheets) {
    if ($sheets === null) {
        continue;
    }
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
        // 외부 하이퍼링크 제거 — writer 깨짐 방어 (SKILLS §12 stripHyperlinks)
        foreach ($ss->getWorksheetIterator() as $sh) {
            foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
                $sh->setHyperlink($hc, null);
            }
        }
        $writer = new Xlsx($ss);
        $writer->setPreCalculateFormulas(false);  // 크로스시트 수식 보존(clearance cascade, D14=B14)
        $writer->save($dstDir.'/'.$file);
        echo "  ✅ 저장: heyman/{$file}\n";
    }
    $ss->disconnectWorksheets();
    unset($ss);
}
echo "\n".($APPLY ? "완료. 도장(직인) 이식은 별도. scan 으로 SSANCAR 잔존 확인 권장.\n" : "dry-run. --apply 로 생성.\n");
