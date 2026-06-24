<?php

/**
 * 통관 SET 말소증 시트 — 자동차등록번호 값칸을 두 줄로 분리.
 *   기존: E8:G9 (2행 병합) = `=구매리스트!B4`  (차량번호 한 줄만)
 *   변경: E8:G8 = `=구매리스트!B4` (차량번호) / E9:G9 = `=구매리스트!D4` (영문차량번호)
 *
 * 라벨칸(C8:D8 자동차등록번호 / C9:D9 Registration No.)이 이미 두 줄이라
 * 값칸도 한·영 두 줄로 맞춘다. 라벨칸처럼 행8-9 사이 구분선 없음(top border none).
 *
 *   php scripts/split-malso-plate-cells.php          # dry-run
 *   php scripts/split-malso-plate-cells.php --apply  # 3개 세트 모두 수정
 *
 * 멱등: 이미 E8:G8 분리돼 있으면 skip.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$APPLY = in_array('--apply', $argv, true);
echo $APPLY ? "════ APPLY ════\n" : "════ DRY-RUN ════\n";

foreach (['system', 'heyman', 'karaba'] as $set) {
    $path = resource_path("templates/$set/clearance_set.xlsx");
    if (! file_exists($path)) {
        echo "❌ 없음: $set/clearance_set.xlsx\n";

        continue;
    }
    echo "\n── $set\n";
    $ss = IOFactory::load($path);
    $sheet = $ss->getSheetByName('말소증');
    if (! $sheet) {
        echo "  ❌ 말소증 시트 없음\n";

        continue;
    }

    $merges = $sheet->getMergeCells();
    if (! isset($merges['E8:G9'])) {
        echo "  ⏭ E8:G9 병합 없음 (이미 분리됐거나 구조 변경) — skip\n";

        continue;
    }

    echo '  E8(현재)='.$sheet->getCell('E8')->getValue().' → E8:G8 유지 / E9:G9 = =구매리스트!D4\n';

    if (! $APPLY) {
        continue;
    }

    // 1) 2행 병합 해제 → 1행 병합 2개
    $sheet->unmergeCells('E8:G9');
    $sheet->mergeCells('E8:G8');
    $sheet->mergeCells('E9:G9');

    // 2) 값 — E8 은 기존 차량번호 유지, E9 에 영문차량번호 cascade
    $sheet->setCellValue('E9', '=구매리스트!D4');

    // 3) 스타일 — E8 서식(폰트/정렬/테두리)을 E9:G9 에 복제 후, 행8-9 구분선 제거(라벨칸과 동일)
    $sheet->duplicateStyle($sheet->getStyle('E8'), 'E9:G9');
    $sheet->getStyle('E9:G9')->getBorders()->getTop()->setBorderStyle(Border::BORDER_NONE);

    // 4) 외부 하이퍼링크 제거(writer 깨짐 방어) — 전 시트
    foreach ($ss->getWorksheetIterator() as $sh) {
        foreach (array_keys($sh->getHyperlinkCollection()) as $hc) {
            $sh->setHyperlink($hc, null);
        }
    }

    $writer = new Xlsx($ss);
    $writer->setPreCalculateFormulas(false);   // 크로스시트 cascade 수식 보존
    $writer->save($path);
    echo "  ✅ 저장: $set/clearance_set.xlsx\n";

    $ss->disconnectWorksheets();
    unset($ss);
}
echo "\n".($APPLY ? "완료.\n" : "dry-run. --apply 로 적용.\n");
