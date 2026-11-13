<?php

/**
 * 통관 SET 「말소증」 시트 K8(차종) 의 샘플 리터럴 「승용」 비우기 (jin 2026-11-12 · 11-13 확정).
 *
 * 증상 — 화물차(NICE 「화물 소형」)여도 통관 SET 말소증의 차종 칸에 늘 「승용」이 찍힌다.
 * 원인 — K8(병합 K8:M8)이 **흰칸 리터럴**이다. 수식도 노란칸도 매핑도 아니라 아무 데서도 안 온다(§8 #71 흰칸 샘플).
 *        같은 블록 K9(주행거리)·K14(용도)는 `=구매리스트!` cascade 인데 K8 만 빠져 있었다.
 * 조치 — 리터럴을 비운다. 값은 매핑 `'말소증!K8' => DocValue::vehicleKindKo()` 가 넣는다(승용·승합·화물·특수).
 *        🚫 비우지 않고 매핑만 달면 NICE 없는 차에 「승용」이 그대로 남는다(writeCell 은 빈 값을 안 쓴다).
 *
 * 대상 = 3세트(system·heyman·karaba) × Ver.1·Ver.2 = 6파일. 스타일·병합은 그대로 둔다.
 *
 *   php scripts/fix-clearance-deregistration-vehicle-kind.php            # dry-run (기본)
 *   php scripts/fix-clearance-deregistration-vehicle-kind.php --apply    # 저장
 *   php scripts/fix-clearance-deregistration-vehicle-kind.php --verify   # 6파일 K8 이 비었는지(배포 전·후)
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const SHEET = '말소증';
const CELL = 'K8';

$apply = in_array('--apply', $argv, true);
$verify = in_array('--verify', $argv, true);
$root = dirname(__DIR__);
$exit = 0;

foreach (['system', 'heyman', 'karaba'] as $set) {
    foreach (['clearance_set.xlsx', 'clearance_set_v2.xlsx'] as $file) {
        $tag = "[$set/$file]";
        $path = "$root/resources/templates/$set/$file";
        if (! file_exists($path)) {
            echo "$tag 파일 없음\n";
            $exit = 1;

            continue;
        }

        $ss = IOFactory::createReader('Xlsx')->load($path);
        $sheet = $ss->getSheetByName(SHEET);
        if (! $sheet) {
            echo "$tag '".SHEET."' 시트 없음\n";
            $exit = 1;

            continue;
        }

        $raw = $sheet->getCell(CELL)->getValue();
        $text = trim((string) (is_object($raw) && method_exists($raw, 'getPlainText') ? $raw->getPlainText() : $raw));

        if ($text === '') {
            echo "$tag K8 비어 있음 — OK\n";

            continue;
        }
        if ($verify) {
            echo "$tag K8 에 값이 남아 있음: ".var_export($text, true)."\n";
            $exit = 1;

            continue;
        }
        if (str_starts_with($text, '=')) {   // 누가 수식을 넣어 뒀다면 건드리지 않는다 — 매핑이 무시되므로 알린다
            echo "$tag K8 이 수식 ($text) — 손대지 않음. 매핑이 무시된다\n";
            $exit = 1;

            continue;
        }

        echo "$tag K8 ".var_export($text, true).' → 비움'.($apply ? '' : ' (dry-run)')."\n";
        if (! $apply) {
            continue;
        }

        $sheet->getCell(CELL)->setValue(null);

        // 하이퍼링크 제거 + 수식 보존 저장 — fix-clearance-deregistration-fill.php 와 같은 관례(§12).
        foreach ($ss->getAllSheets() as $sh) {
            foreach ($sh->getHyperlinkCollection() as $coord => $_) {
                $sh->setHyperlink($coord, null);
            }
        }
        $writer = new Xlsx($ss);
        $writer->setPreCalculateFormulas(false);   // 크로스시트 수식(=구매리스트!) 보존
        $writer->save($path);
    }
}

exit($exit);
