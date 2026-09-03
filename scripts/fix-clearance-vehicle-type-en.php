<?php

/**
 * 통관 SET — 영문 차종을 수식에서 걷어내 `DocValue::vehicleFormEn()` 단일 출처로 옮긴다 (jin 2026-09-03).
 *
 * ## 무엇이 틀렸나
 * `구매리스트!I6` 의 중첩 SUBSTITUTE 가 **실제 NICE 값과 어긋나** 있었다:
 *   - 승합·화물을 「중형 승합」 순서로 찾는데 실제 값은 **「승합 중형」** → 안 잡히고 **한글이 그대로 인쇄**
 *   - 승용 대형만 `HEAVY Passenger`(다른 건 Small/Medium) · 승합은 `Ven`(Van 오타)
 * 그 값은 `영문등록증!M4 = 구매리스트!I6` 로 cascade 하므로 **영문등록증 차종 칸**이 그대로 영향을 받는다.
 * 운영 실측 heymanerp: 승합 중형 1 대(+ 쓰레기값 `205 004` 1 대는 어느 쪽이든 통과).
 *
 * ## 왜 수식을 고치지 않고 걷어내나
 * 같은 변환이 **3 사 양식에 각각 복제**돼 있었다. 말소증(2026-09-03 신설)이 PHP 로 같은 변환을 하면서
 * 출처가 넷이 됐다 — 하나만 고치면 서류마다 표기가 갈린다(SKILLS §8 #45).
 * ⇒ 수식을 비우고 `ClearanceSetMapping` 의 `I6` 매핑이 값을 쓰게 한다.
 *
 * 🚨 **비우는 것과 매핑을 반드시 같은 커밋에** — `DocumentFiller::writeCell` 은 `=` 로 시작하는 셀을
 *    안 덮어쓴다. 수식이 남아 있으면 매핑이 조용히 무시되고, 매핑 없이 비우면 칸이 빈 채로 나간다.
 *
 * 사용:
 *   php scripts/fix-clearance-vehicle-type-en.php            # dry-run (기본)
 *   php scripts/fix-clearance-vehicle-type-en.php --apply
 *   php scripts/fix-clearance-vehicle-type-en.php --verify    # 현재 상태 실측
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
const CELL = 'I6';
/** cascade 소비자 — 이게 살아 있어야 매핑한 값이 영문등록증에 뜬다. */
const CONSUMER = ['영문등록증', 'M4', '=구매리스트!I6'];

$mode = $argv[1] ?? '--dry-run';
$dir = __DIR__.'/../resources/templates';

if ($mode === '--verify') {
    $bad = 0;
    foreach (SETS as $set) {
        $path = "{$dir}/{$set}/".TEMPLATE;
        $ss = IOFactory::createReaderForFile($path)->load($path);

        $got = (string) $ss->getSheetByName(MASTER_SHEET)->getCell(CELL)->getValue();
        $ok = $got === '';
        $bad += $ok ? 0 : 1;
        printf("%s %-7s %s!%s %s\n", $ok ? '✅' : '❌', $set, MASTER_SHEET, CELL,
            $ok ? '비어 있음 (매핑이 채운다)' : "수식 잔존 — 매핑이 무시된다: {$got}");

        [$sheet, $cell, $want] = CONSUMER;
        $gotC = (string) $ss->getSheetByName($sheet)->getCell($cell)->getValue();
        $okC = $gotC === $want;
        $bad += $okC ? 0 : 1;
        printf("%s %-7s %s!%s cascade %s\n", $okC ? '✅' : '❌', $set, $sheet, $cell,
            $okC ? $gotC : "끊김 — got='{$gotC}' want='{$want}'");
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

    $before = (string) $sheet->getCell(CELL)->getValue();
    echo "\n--- {$set} ---\n  before: ".($before !== '' ? $before : '(빈칸)')."\n";

    if ($before === '') {
        echo "  이미 비어 있음 — skip\n";

        continue;
    }

    // cascade 소비자가 살아 있는지 먼저 확인 — 끊겨 있으면 비워도 영문등록증에 안 뜬다.
    [$cSheet, $cCell, $cWant] = CONSUMER;
    $cGot = (string) $ss->getSheetByName($cSheet)->getCell($cCell)->getValue();
    if ($cGot !== $cWant) {
        fwrite(STDERR, "  ❌ {$cSheet}!{$cCell} cascade 가 다르다 ('{$cGot}') — 중단\n");
        exit(1);
    }
    echo "  cascade 확인: {$cSheet}!{$cCell} = {$cGot}\n";

    $sheet->getCell(CELL)->setValueExplicit(null, DataType::TYPE_NULL);
    echo "  after : (빈칸) ← ClearanceSetMapping 의 I6 매핑이 채운다\n";

    if (! $apply) {
        continue;
    }

    $w = new XlsxWriter($ss);
    $w->setPreCalculateFormulas(false);
    $w->save($path);
    echo '  ✅ 저장: '.filesize($path)." bytes\n";
}

echo $apply ? "\n완료. `--verify` 로 대조하세요.\n" : "\ndry-run 끝. 적용은 `--apply`.\n";
