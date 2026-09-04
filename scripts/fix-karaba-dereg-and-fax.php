<?php

/**
 * karaba 전용 양식 교정 (jin 2026-09-04) — 말소신청서 수임자 3줄 + FAX 번호 3곳.
 *
 *   php scripts/fix-karaba-dereg-and-fax.php            # dry-run (계획만)
 *   php scripts/fix-karaba-dereg-and-fax.php --apply    # 실제 수정
 *   php scripts/fix-karaba-dereg-and-fax.php --verify   # 3사 현재 상태 실측 대조
 *
 * 🚫 **generate-karaba-templates.php 를 다시 돌려서 고치지 말 것.**
 *    그 스크립트는 system/ 에서 통째로 새로 만드는데, karaba 파일에는 그 맵에 없는 후속 교정이
 *    이미 얹혀 있다(2026-09-03 법인등록번호 4곳 = `fix-karaba-corp-number.php`).
 *    재생성하면 그것들이 조용히 사라진다. 그래서 이 스크립트도 **대상 칸만** 고친다(SKILLS §8 #75).
 *
 * 🚨 수임자 3칸(C37~C39)은 주황 채움(F4B184)이라 `DocumentFiller::isYellow()` 가 「노란칸」으로 본다.
 *    매핑 없는 노란칸은 서류 생성 때 **값이 지워진다**(SKILLS §8 #71). 값만 넣으면 인쇄가 안 된다.
 *    ⇒ 값을 넣으면서 **채움도 함께 없앤다**(바로 위 위임자 블록 34~36행이 이미 그 상태다).
 */
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$APPLY = in_array('--apply', $argv, true);
$VERIFY = in_array('--verify', $argv, true);

/** karaba FAX (jin 2026-09-04) — 032)710-1881. */
const KARABA_FAX = '82-32-710-1881';

/**
 * 고칠 곳. op:
 *   'print' = 값 기입 + 채움 제거(노란칸 판정 회피) — 양식에 인쇄되는 고정값
 *   'set'   = 값만 기입
 */
$FIXES = [
    'deregistration_application.xlsx' => ['1.차량말소신청서' => [
        // 수임자 블록 — 지금까지 3사 전부 공란으로 나가던 칸이다(어느 매핑에도 없다).
        'C37' => ['print', '경기도 고양시 덕양구 유산길 9, 203호(내유동)'],
        'C38' => ['print', '길영채'],
        'C39' => ['print', '11-95-278048-01'],
    ]],
    'clearance_set.xlsx' => [
        // 🚨 싼카 번호(82-505-366-9977)가 그대로 남아 있던 자리 — 파생 스크립트가 A3 만 바꿨다.
        '차량인보이스' => ['A35' => ['set', 'Fax No          : 82- 32 - 710 - 1881']],
        '차량팩킹' => ['A35' => ['set', 'Fax No          : 82- 32 - 710 - 1881']],
    ],
    'deregistration_contract.xlsx' => ['2.계약서' => [
        // 엉뚱한 번호(82 - 031 - 499 - 1989)가 박혀 있었다.
        'E5' => ['set', 'FAX : '.KARABA_FAX],
    ]],
];

$dir = resource_path('templates/karaba');

// ── --verify : 3사 현재 상태를 그대로 보여준다 (고치지 않는다) ──────────────
if ($VERIFY) {
    echo "════ VERIFY — 3사 현재 값 ════\n";
    foreach (['system', 'heyman', 'karaba'] as $set) {
        echo "\n── {$set}\n";
        foreach ($FIXES as $file => $sheets) {
            $path = resource_path("templates/{$set}/{$file}");
            if (! file_exists($path)) {
                echo "  ❌ 없음: {$file}\n";

                continue;
            }
            $ss = IOFactory::createReader('Xlsx')->load($path);
            foreach ($sheets as $sheetName => $cells) {
                $sheet = $ss->getSheetByName($sheetName);
                if (! $sheet) {
                    echo "  ❌ 시트 없음: {$file}!{$sheetName}\n";

                    continue;
                }
                foreach ($cells as $coord => [$op, $want]) {
                    $cur = (string) $sheet->getCell($coord)->getValue();
                    $fill = $sheet->getStyle($coord)->getFill();
                    $isSolid = $fill->getFillType() === Fill::FILL_SOLID;
                    $rgb = $isSolid ? $fill->getStartColor()->getRGB() : '-';
                    $mark = ($set === 'karaba' && $cur === $want && ! ($op === 'print' && $isSolid)) ? '✅' : '  ';
                    echo "  {$mark} {$file}!{$sheetName}!{$coord} = '".str_replace("\n", '⏎', $cur)."'".
                         ($isSolid ? " [채움 {$rgb}]" : '')."\n";
                }
            }
            $ss->disconnectWorksheets();
            unset($ss);
        }
    }
    exit(0);
}

// ── dry-run / --apply ──────────────────────────────────────────────────────
echo $APPLY ? "════ APPLY → {$dir} ════\n" : "════ DRY-RUN (--apply 로 실제 수정) ════\n";
$changed = 0;

foreach ($FIXES as $file => $sheets) {
    $path = $dir.'/'.$file;
    if (! file_exists($path)) {
        echo "❌ 원본 없음: {$file}\n";

        continue;
    }
    echo "\n── {$file}\n";
    $ss = IOFactory::createReader('Xlsx')->load($path);
    $touched = false;

    foreach ($sheets as $sheetName => $cells) {
        $sheet = $ss->getSheetByName($sheetName);
        if (! $sheet) {
            echo "  ❌ 시트 없음: {$sheetName}\n";

            continue;
        }
        foreach ($cells as $coord => [$op, $new]) {
            $cur = (string) $sheet->getCell($coord)->getValue();
            $fill = $sheet->getStyle($coord)->getFill();
            $hadFill = $fill->getFillType() === Fill::FILL_SOLID;
            $needsFillClear = $op === 'print' && $hadFill;

            if ($cur === $new && ! $needsFillClear) {
                echo "  = {$sheetName}!{$coord} 이미 정상\n";

                continue;
            }
            echo "  ✎ {$sheetName}!{$coord}\n";
            echo "      전: '".str_replace("\n", '⏎', $cur)."'".($hadFill ? " [채움 {$fill->getStartColor()->getRGB()}]" : '')."\n";
            echo "      후: '".str_replace("\n", '⏎', $new)."'".($needsFillClear ? ' [채움 제거]' : '')."\n";

            if ($APPLY) {
                $sheet->getCell($coord)->setValue($new);
                if ($needsFillClear) {
                    // 노란칸 판정을 벗어나야 DocumentFiller 가 값을 지우지 않는다(SKILLS §8 #71).
                    $fill->setFillType(Fill::FILL_NONE);
                }
                $touched = true;
            }
            $changed++;
        }
    }

    if ($APPLY && $touched) {
        $writer = new Xlsx($ss);
        // 하이퍼링크 잔재 제거 + 수식을 값으로 굳히지 않기 — 통관 SET 의 cascade 가 죽는다(SKILLS §12).
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        echo "  💾 저장\n";
    }
    $ss->disconnectWorksheets();
    unset($ss);
}

echo "\n".($APPLY ? "완료 — {$changed}칸 수정\n" : "대상 {$changed}칸 (--apply 로 실행)\n");
