<?php

/**
 * 통관 SET — **영문 변환 칸**(차종·연료)을 수식에서 걷어내 `DocValue` 단일 출처로 옮긴다 (jin 2026-09-03).
 *
 * ## 무엇이 틀렸나 — 두 칸 다 「실제 NICE 값과 어긋난 SUBSTITUTE」다
 *
 * **I6 차종** (→ `영문등록증!M4`)
 *   - 승합·화물을 「중형 승합」 순서로 찾는데 실제 값은 **「승합 중형」** → 안 잡히고 **한글이 그대로 인쇄**
 *   - 승용 대형만 `HEAVY Passenger`(다른 건 Small/Medium) · 승합은 `Ven`(Van 오타)
 *   - 실측 heymanerp 승합 중형 1 대(쓰레기값 `205 004` 1 대는 어느 쪽이든 통과)
 *
 * **I13 연료** (→ `영문등록증!D31`) — 이쪽이 훨씬 크다. 실측 heymanerp 254 대 중 **39 대(15%)**:
 *   `휘발유(무연)` 20 → `GASOLINE(무연)` / `하이브리드(경유+전기)` 8 → `HYBRID(DIESEL+전기)`
 *   `하이브리드(휘발유+전기)` 7 → `HYBRID(GASOLINE+전기)` / `가솔린` 2 · `엘피지` 1 · `디젤` 1 → 한글 그대로
 *   (ssancarerp 도 같은 형태로 15 대)
 *
 * ## 왜 수식을 고치지 않고 걷어내나
 * 같은 변환이 **3 사 양식에 각각 복제**돼 있었고, PHP 에도 이미 있었다 —
 * `DocValue::fuelEn()` 은 컨테이너·RORO 인보이스가 쓰는데 **같은 값을 다르게** 계산했다.
 * 말소증(2026-09-03 신설)이 합류하며 출처가 더 늘어 하나만 고치면 서류마다 표기가 갈린다(SKILLS §8 #45).
 * ⇒ 수식을 비우고 `ClearanceSetMapping` 의 매핑이 값을 쓰게 한다.
 *
 * 🚨 **비우는 것과 매핑을 반드시 같은 커밋에** — `DocumentFiller::writeCell` 은 `=` 로 시작하는 셀을
 *    안 덮어쓴다. 수식이 남아 있으면 매핑이 조용히 무시되고, 매핑 없이 비우면 칸이 빈 채로 나간다.
 *
 * 사용:
 *   php scripts/fix-clearance-english-conversion.php            # dry-run (기본)
 *   php scripts/fix-clearance-english-conversion.php --apply
 *   php scripts/fix-clearance-english-conversion.php --verify    # 현재 상태 실측
 *
 * ⚠️ 저장은 `setPreCalculateFormulas(false)` — preCalc 를 켜면 크로스시트 cascade 가 값으로 굳어 통째로 죽는다.
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

const SETS = ['system', 'heyman', 'karaba'];
const TEMPLATE = 'clearance_set.xlsx';
const MASTER_SHEET = '구매리스트';
/** 비울 칸 => [설명, cascade 소비자 시트, 셀, 그 셀에 있어야 할 수식]. */
const CELLS = [
    'I6' => ['차종', '영문등록증', 'M4', '=구매리스트!I6'],
    'I13' => ['연료', '영문등록증', 'D31', '=구매리스트!I13'],
];

$mode = $argv[1] ?? '--dry-run';
$dir = __DIR__.'/../resources/templates';

if ($mode === '--verify') {
    $bad = 0;
    foreach (SETS as $set) {
        $path = "{$dir}/{$set}/".TEMPLATE;
        $ss = IOFactory::createReaderForFile($path)->load($path);

        foreach (CELLS as $cellRef => [$label, $sheet, $cell, $want]) {
            $got = (string) $ss->getSheetByName(MASTER_SHEET)->getCell($cellRef)->getValue();
            $ok = $got === '';
            $bad += $ok ? 0 : 1;
            printf("%s %-7s %s!%-4s %-4s %s\n", $ok ? '✅' : '❌', $set, MASTER_SHEET, $cellRef, $label,
                $ok ? '비어 있음 (매핑이 채운다)' : "수식 잔존 — 매핑이 무시된다: {$got}");

            $gotC = (string) $ss->getSheetByName($sheet)->getCell($cell)->getValue();
            $okC = $gotC === $want;
            $bad += $okC ? 0 : 1;
            printf("%s %-7s %s!%-4s cascade %s\n", $okC ? '✅' : '❌', $set, $sheet, $cell,
                $okC ? $gotC : "끊김 — got='{$gotC}' want='{$want}'");
        }
    }
    echo $bad === 0 ? "\n✅ 전부 정상.\n" : "\n❌ 불일치 {$bad}건.\n";
    exit($bad === 0 ? 0 : 1);
}

$apply = $mode === '--apply';
echo $apply ? "=== 적용 (--apply) ===\n" : "=== dry-run — 저장 안 함 ===\n";

foreach (SETS as $set) {
    $path = "{$dir}/{$set}/".TEMPLATE;
    $ss = IOFactory::createReaderForFile($path)->load($path);
    $sheet = $ss->getSheetByName(MASTER_SHEET);

    echo "\n--- {$set} ---\n";
    $touched = false;

    foreach (CELLS as $cellRef => [$label, $cSheet, $cCell, $cWant]) {
        $before = (string) $sheet->getCell($cellRef)->getValue();
        echo "  [{$label}] {$cellRef} before: ".($before !== '' ? mb_substr($before, 0, 70).'…' : '(빈칸)')."\n";

        if ($before === '') {
            echo "    이미 비어 있음 — skip\n";

            continue;
        }

        // cascade 소비자가 살아 있는지 먼저 확인 — 끊겨 있으면 비워도 영문등록증에 안 뜬다.
        $cGot = (string) $ss->getSheetByName($cSheet)->getCell($cCell)->getValue();
        if ($cGot !== $cWant) {
            fwrite(STDERR, "    ❌ {$cSheet}!{$cCell} cascade 가 다르다 ('{$cGot}') — 중단\n");
            exit(1);
        }
        echo "    cascade 확인: {$cSheet}!{$cCell} = {$cGot}\n";

        $sheet->getCell($cellRef)->setValueExplicit(null, DataType::TYPE_NULL);
        echo "    after : (빈칸) ← ClearanceSetMapping 매핑이 채운다\n";
        $touched = true;
    }

    if (! $apply || ! $touched) {
        continue;
    }

    $w = new XlsxWriter($ss);
    $w->setPreCalculateFormulas(false);
    $w->save($path);
    echo '  ✅ 저장: '.filesize($path)." bytes\n";
}

echo $apply ? "\n완료. `--verify` 로 대조하세요.\n" : "\ndry-run 끝. 적용은 `--apply`.\n";
