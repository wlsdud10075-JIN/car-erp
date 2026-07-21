<?php

/**
 * ssancar(system) 서류 템플릿 회사정보 정정 — resources/templates/system/ 를 제자리(in-place) 수정.
 *   php scripts/generate-ssancar-templates.php           # dry-run (계획만)
 *   php scripts/generate-ssancar-templates.php --apply    # system/ 에 덮어쓰기
 *
 * 배경(2026-07-21 jin): system 세트가 ssancarerp 정본. 스캔 결과 주소·전화·이메일이 비균질·오염:
 *   - 인천(499 Aam-dero)·고양(63 성현로) 주소 잔재 → 전부 시흥 산기대학로 163 으로 통일(jin 확정)
 *   - 이메일 man99777@naver.com(heyman 것) / sales@ssancar.com → ssancar9977@gmail.com
 *   - 전화 82-505-366-9977 오기 → 82-505-355-9977 (FAX 만 366, jin: txt fax 정본)
 *   - 오타 Sangideahak → Sangidaehak / 말소신청서 주소 A동 누락
 * 정본(사업자등록증 + ssancar_English_Adress.txt): TEL 82-505-355-9977 / FAX 82-505-366-9977 /
 *   EMAIL ssancar9977@gmail.com / 663 아님 662-81-00898 / 조태신.
 *
 * ⚠ 보존(맵 제외): container/roro INVOICE!N14·N17 = "SSANCAR MADAGASCAR"(현지 컨사이니, 셀러 아님).
 * ⚠ 순서: clearance_set 은 item9(구매리스트 cascade 해체) 재구축 후 회사정보 재적용 권장 —
 *   지금은 현행 구조에 회사정보만 얹음(item9 미착수 상태 기준).
 *
 * generate-heyman-templates.php 와 동일 구조. 멱등: 이미 정정된 값이면 replace 는 no-op, set 은 동일값.
 *   'set'     => 셀 통째 새 값 / 'replace' => 부분 문자열 치환(패딩·접미사 보존).
 */

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$APPLY = in_array('--apply', $argv, true);

// 인보이스/팩킹 회사정보 블록(정본, 멀티라인) — 기존 house 서식(트레일링 스페이스) 유지.
$invBlock = "SSANCAR LTD.,\n163 Sangidaehak-ro, Siheung-si,\nGyeonggi-do, Republic of Korea\nTEL:82-505-355-9977  \nFAX:82-505-366-9977 \nEMAIL : ssancar9977@gmail.com";
// A2 단일라인 헤더(상호·대표·주소·전화·이메일) — 인천 주소 → 시흥, 전화 366→355.
$a2Line = 'Ssancar LTD. Cho Tae Shin. 163 Sangidaehak-ro, Siheung-si, Gyeonggi-do, Korea  Phone: +82-505-355-9977 Email: ssancar9977@gmail.com';

$map = [
    'clearance_set.xlsx' => [
        '차량인보이스' => ['A3' => ['set', $invBlock]],   // 전화 366→355·이메일 naver→gmail·주소 truncate 수정
        '차량팩킹' => ['A3' => ['set', $invBlock]],
        'Travel Services Invoice' => [
            'A2' => ['replace', ['82-505-366-9977' => '82-505-355-9977']],   // Phone 만(주소 이미 시흥)
        ],
    ],
    'container_contract.xlsx' => ['HBB340.' => [
        'A2' => ['set', $a2Line],   // 인천 Aam-dero → 시흥, 전화 366→355
    ]],
    'roro_contract.xlsx' => ['HBB340.' => [
        'A2' => ['set', $a2Line],
    ]],
    'container_invoice_packing.xlsx' => ['INVOICE' => [
        'B3' => ['replace', ['man99777@naver.com' => 'ssancar9977@gmail.com']],   // 이메일만(TEL/FAX/주소 이미 정본)
        // ⚠ N14 SSANCAR MADAGASCAR = 보존(맵 제외)
    ]],
    'roro_invoice_packing.xlsx' => ['INVOICE' => [
        'B3' => ['set', $invBlock],   // 고양 성현로 → 시흥 + 이메일 수정
        // ⚠ N17 SSANCAR MADAGASCAR = 보존(맵 제외)
    ]],
    'sales_invoice.xlsx' => ['Invoice' => [
        'A2' => ['set', $a2Line],   // 인천 Aam-dero → 시흥, 전화 366→355
        'E15' => ['replace', ['Sangideahak' => 'Sangidaehak']],   // 오타 교정
    ]],
    'sales_contract.xlsx' => ['CONTRACT' => [
        'B69' => ['set', 'Tel: +82-505-355-9977         Email: ssancar9977@gmail.com'],   // 엉뚱한 전화·sales@ssancar.com 교정
    ]],
    'deregistration_application.xlsx' => ['1.차량말소신청서' => [
        'C34' => ['replace', ['163, 328호(정왕동)' => '163, A동 328호(정왕동)']],   // A동 누락 보정
    ]],
];

$dir = resource_path('templates/system');

echo $APPLY ? "════ APPLY (in-place) → {$dir} ════\n" : "════ DRY-RUN ════\n";

foreach ($map as $file => $sheets) {
    $path = $dir.'/'.$file;
    if (! file_exists($path)) {
        echo "❌ 없음: {$file}\n";

        continue;
    }
    echo "\n── {$file}\n";
    $ss = IOFactory::load($path);
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
            } else {
                $new = strtr($cur, $arg);
                if ($new === $cur) {
                    echo "  ⚠ {$sheetName}!{$coord} 치환대상 없음(이미 정정?): '".str_replace("\n", '⏎', mb_substr($cur, 0, 50))."'\n";
                }
            }
            printf("  %s!%s:\n     현재: '%s'\n     신규: '%s'\n", $sheetName, $coord,
                str_replace("\n", '⏎', $cur), str_replace("\n", '⏎', $new));
            if ($APPLY) {
                $sheet->getCell($coord)->setValue($new === '' ? null : $new);
            }
        }
    }
    // 통관 정부양식 시트 gridlines OFF 재설정(reader 오독 방어 — heyman 스크립트와 동일)
    if ($file === 'clearance_set.xlsx') {
        foreach (['한글등록증', '영문등록증', '말소증'] as $gsh) {
            if ($g = $ss->getSheetByName($gsh)) {
                $g->setShowGridlines(false);
            }
        }
    }
    if ($APPLY) {
        foreach ($ss->getWorksheetIterator() as $sh) {
            foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
                $sh->setHyperlink($hc, null);
            }
        }
        $writer = new Xlsx($ss);
        $writer->setPreCalculateFormulas(false);   // clearance cascade 수식 보존
        $writer->save($path);
        echo "  ✅ 저장: system/{$file}\n";
    }
    $ss->disconnectWorksheets();
    unset($ss);
}
echo "\n".($APPLY ? "완료. scan 재실행으로 잔재(naver·인천·고양·366오기) 확인 권장. MADAGASCAR 는 정상 잔존.\n" : "dry-run. --apply 로 반영.\n");
