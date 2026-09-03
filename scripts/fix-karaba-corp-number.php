<?php

/**
 * karaba 양식의 **법인등록번호 잔재** 교정 — 싼카 번호가 4 곳에 그대로 남아 있었다 (jin 2026-09-03).
 *
 * ## 무엇이 틀렸나
 * karaba 온보딩 때 `generate-karaba-templates.php` 의 치환맵이 상호·주소·사업자번호는 바꿨는데
 * **법인등록번호 칸은 아예 안 건드렸다.** 그래서 karaba 양식 4 곳에 싼카 번호 `120111-0922270` 이
 * 그대로 인쇄된다 — 그중 **위임장은 관공서에 제출하는 서류**다.
 *
 * heyman 은 같은 부류를 `docs/operations/heyman-company-info-cleanup.md` 로 이미 정리한 이력이 있다
 * (실측 확인: heyman 4 곳 전부 `110111-7526176` 정상). **heyman 때 잡은 걸 karaba 때 놓친 것**이다.
 *
 * ⚠️ 사업자등록번호(`801-81-01696`)는 이 칸에 못 쓴다 — 자릿수·성격이 다른 별개 번호다.
 *    아래 값은 jin 이 2026-09-03 에 직접 확인해 준 것이다.
 *
 * 🧭 교훈: **테넌트 파생 스크립트는 「바꾼 칸」만 보증한다.** 안 적힌 칸은 원본 회사 값이 그대로 남는다.
 *    새 회사를 붙일 땐 「무엇을 바꿨나」가 아니라 **「원본 회사 값이 어디에 남아 있나」를 전수 검색**할 것
 *    (13 자리 번호·상호·주소·계좌를 정규식으로 훑으면 나온다).
 *
 * 사용:
 *   php scripts/fix-karaba-corp-number.php            # dry-run (기본)
 *   php scripts/fix-karaba-corp-number.php --apply
 *   php scripts/fix-karaba-corp-number.php --verify    # 3사 전수 실측 (다른 회사 잔재까지)
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const WRONG = '120111-0922270';   // 싼카(system) 법인등록번호 — karaba 양식에 잘못 남아 있던 값
const RIGHT = '120111-1058941';   // karaba 법인등록번호 (jin 2026-09-03 확인)

/** 고칠 자리 — 파일 => [시트 => [셀…]]. */
const TARGETS = [
    'power_of_attorney.xlsx' => ['4.위임장' => ['C25']],
    'clearance_set.xlsx' => [
        '말소증' => ['K21'],
        '한글등록증' => ['M8'],
        '영문등록증' => ['M8'],
    ],
];

/** 회사별 정답 — `--verify` 가 3 사를 전부 훑어 「남의 번호」를 잡는다. */
const EXPECTED = [
    'system' => '120111-0922270',
    'heyman' => '110111-7526176',
    'karaba' => RIGHT,
];

$mode = $argv[1] ?? '--dry-run';
$dir = __DIR__.'/../resources/templates';

if ($mode === '--verify') {
    $bad = 0;
    foreach (EXPECTED as $set => $want) {
        echo "--- {$set} (정답 {$want}) ---\n";
        foreach (TARGETS as $file => $sheets) {
            $path = "{$dir}/{$set}/{$file}";
            if (! is_file($path)) {
                echo "  ⚠️  없음: {$file}\n";

                continue;
            }
            $ss = IOFactory::createReaderForFile($path)->load($path);
            foreach ($sheets as $sheetName => $cells) {
                foreach ($cells as $cell) {
                    $got = (string) $ss->getSheetByName($sheetName)->getCell($cell)->getValue();
                    $ok = $got === $want;
                    $bad += $ok ? 0 : 1;
                    printf("  %s %-26s %s!%-4s %s\n", $ok ? '✅' : '❌', $file, $sheetName, $cell,
                        $ok ? $got : "{$got}  ← 남의 번호");
                }
            }
        }
    }
    echo $bad === 0 ? "\n✅ 3사 전부 자기 번호.\n" : "\n❌ 잔재 {$bad}건.\n";
    exit($bad === 0 ? 0 : 1);
}

$apply = $mode === '--apply';
echo $apply ? "=== 적용 (--apply) ===\n" : "=== dry-run — 저장 안 함 ===\n";

$changed = 0;
foreach (TARGETS as $file => $sheets) {
    $path = "{$dir}/karaba/{$file}";
    $ss = IOFactory::createReaderForFile($path)->load($path);
    echo "\n--- karaba/{$file} ---\n";

    $touched = false;
    foreach ($sheets as $sheetName => $cells) {
        foreach ($cells as $cell) {
            $got = (string) $ss->getSheetByName($sheetName)->getCell($cell)->getValue();
            if ($got === RIGHT) {
                echo "  {$sheetName}!{$cell} 이미 정상 — skip\n";

                continue;
            }
            // 값 비교로 판단한다 — 「이 파일은 이미 고쳤다」는 목록 기억이 아니라(SKILLS §8 #71-D).
            if ($got !== WRONG) {
                fwrite(STDERR, "  ❌ {$sheetName}!{$cell} 이 예상 밖 값이다 ('{$got}') — 중단\n");
                exit(1);
            }
            $ss->getSheetByName($sheetName)->getCell($cell)->setValueExplicit(RIGHT, DataType::TYPE_STRING);
            echo "  {$sheetName}!{$cell} : ".WRONG.' → '.RIGHT."\n";
            $touched = true;
            $changed++;
        }
    }

    if ($apply && $touched) {
        $w = new XlsxWriter($ss);
        $w->setPreCalculateFormulas(false);   // 통관 SET cascade 보존
        $w->save($path);
        echo '  ✅ 저장: '.filesize($path)." bytes\n";
    }
}

echo $apply ? "\n완료 ({$changed}곳). `--verify` 로 대조하세요.\n" : "\ndry-run 끝 ({$changed}곳). 적용은 `--apply`.\n";
