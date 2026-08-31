<?php

/**
 * 통관 SET — 컨테이너 NO 칸(G2·G3)에 값이 다 보이게 (jin 2026-08-31).
 *
 * ## 무엇이 문제였나
 * G 열 너비가 **12.25**(86px)인데 실제로 들어가는 값이 그보다 길어 **잘려 나갔다**.
 * 운영 실측(2026-08-31):
 *
 *   | 값 | 자수 | 필요 너비 | 건수 |
 *   |---|---|---|---|
 *   | `ABCD1234567` (ISO 규격) | 11 | 10.4 | ssancarerp 783 · heymanerp 75 |
 *   | `6.06_G RORO 11-27_10` | 20 | **19.3** | **ssancarerp 3,571**(17~20자) |
 *   | `EITU9093137 /  EMCKPM6885` | 25 | **24.0** | heymanerp 31(21~25자) |
 *
 * 양식은 **ISO 규격 11 자**만 가정했는데, 두 회사 모두 자체 관리코드·2 개 병기를 적고 있었다.
 * 🧭 **「이 칸에 실제로 뭐가 들어가나」를 길이 분포로 실측할 것** — 규격만 보면 다수가 잘린다.
 *
 * ## 왜 이 방법인가
 * - **자동축소만** 쓰면 25 자가 7pt 아래로 내려가 읽기 어렵다.
 * - **줄바꿈**은 행 높이를 키워야 하고, 2·3 행은 `E2:F2`·`H2:I3`·`A3:D7` 병합과 얽혀 있다.
 * - ⇒ **열을 넓히고**(12.25 → 19.5 · 20 자를 9pt 그대로 수용) **자동축소를 안전망으로** 켠다.
 *   21~25 자는 8pt 남짓으로 살짝 줄지만 **잘리지 않는다**.
 *
 * 🚨 **차량팩킹도 대상이다** (2026-08-31 2 차에 발견). 팩킹리스트는 `=차량인보이스!G2` 로 같은 값을
 *    미러하지만 **G 열 너비는 자기 것을 쓴다** — 인보이스만 넓히면 팩킹리스트에서는 그대로 잘린다.
 *    「미러하니 따라오겠지」가 아니다(SKILLS §8 #69 「공용으로 묶어도 놓인 자리가 결과를 바꾼다」).
 *
 * 🅿️ **인쇄 배율은 걱정 없다** — 두 시트 다 `fitToPage`(1×1)라 엑셀이 알아서 맞춘다.
 *    G 열은 라벨 2 개(`14) Unit price`·`Unit Price`)와 단가(G22)만 더 쓰고, 나머지 병합은
 *    대부분 E~I 를 걸쳐 있어 넓혀도 배치가 안 깨진다.
 *
 * 🚫 **heyman 도 대상이다** — 25 자짜리가 그쪽에 있다(용도 cascade 때와 반대 방향).
 *
 *   php scripts/fix-clearance-invoice-container-width.php            # dry-run
 *   php scripts/fix-clearance-invoice-container-width.php --apply
 *   php scripts/fix-clearance-invoice-container-width.php --verify
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const SHEETS = ['차량인보이스', '차량팩킹'];
const COL = 'G';
const WIDTH = 19.5;
const CELLS = ['G2', 'G3'];

$APPLY = in_array('--apply', $argv, true);
$VERIFY = in_array('--verify', $argv, true);
$root = __DIR__.'/../resources/templates/';

foreach (['system', 'heyman', 'karaba'] as $set) {
    $path = $root.$set.'/clearance_set.xlsx';
    if (! is_readable($path)) {
        echo "SKIP {$set} — 파일 없음\n";

        continue;
    }
    $ss = IOFactory::createReaderForFile($path)->load($path);
    $touched = 0;

    foreach (SHEETS as $sheetName) {
        $ws = $ss->getSheetByName($sheetName);
        $width = (float) $ws->getColumnDimension(COL)->getWidth();

        if ($VERIFY) {
            printf('== %-7s %-8s %s 너비=%-5s %s', $set, $sheetName, COL, $width, $width >= WIDTH ? '✅' : '❌');
            foreach (CELLS as $c) {
                $a = $ws->getStyle($c)->getAlignment();
                printf('  %s:%s', $c, $a->getShrinkToFit() && ! $a->getWrapText() ? '축소✅' : '❌');
            }
            echo "\n";

            continue;
        }

        echo "  {$set}/{$sheetName} — ".COL." 너비 {$width} → ".WIDTH."\n";
        foreach (CELLS as $c) {
            $a = $ws->getStyle($c)->getAlignment();
            echo "    {$c} shrinkToFit ".var_export($a->getShrinkToFit(), true)." → true\n";
        }

        if ($APPLY) {
            $ws->getColumnDimension(COL)->setWidth(WIDTH);
            foreach (CELLS as $c) {
                $a = $ws->getStyle($c)->getAlignment();
                // ⚠️ 엑셀에서 자동축소와 줄바꿈은 **동시에 못 켠다** — wrap 을 반드시 끈다.
                $a->setWrapText(false);
                $a->setShrinkToFit(true);
            }
            $touched++;
        }
    }

    if ($APPLY && $touched > 0) {
        // 외부 하이퍼링크(WebDAV file://) 제거 — writer 깨짐 방어 (SKILLS §12).
        foreach ($ss->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getHyperlinkCollection() as $coord => $_) {
                $sheet->setHyperlink($coord, null);
            }
        }
        $w = new Xlsx($ss);
        // 🚫 preCalc 켜면 크로스시트 수식이 값으로 굳어 통관 SET cascade 가 통째로 죽는다.
        $w->setPreCalculateFormulas(false);
        $w->save($path);
        echo "  ✅ {$set} 저장 ({$touched}시트)\n";
    }
}

if (! $APPLY && ! $VERIFY) {
    echo "\ndry-run 이다 — 아무것도 쓰지 않았다. 실제 반영은 --apply (그 뒤 --verify).\n";
}
