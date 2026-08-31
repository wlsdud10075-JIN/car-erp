<?php

/**
 * 통관 SET — 등록증 「용도/Usage」 칸을 구매리스트 cascade 로 되돌린다 (jin 2026-08-31).
 *
 * ## 무슨 일이 있었나
 * `heyman` 세트에만 수식이 있고 `system`(ssancarerp)·`karaba` 는 **샘플 리터럴**이 박혀 있었다.
 *
 *   | 세트 | 한글등록증!P4 | 영문등록증!P4 (노란칸) | 결과 |
 *   |---|---|---|---|
 *   | heyman | `=구매리스트!B8` | SUBSTITUTE 수식 | 실제 용도 ✅ |
 *   | system·karaba | 리터럴 `자가용` | 리터럴 `Private car` | 아래 두 증상 ❌ |
 *
 * - **영문**은 노란칸이라 `DocumentFiller` 가 「매핑 없는 샘플값」으로 보고 **비운다**
 *   (SKILLS §12 노란셀 분기). ⇒ Usage 가 공란으로 인쇄된다. jin 이 본 증상이 이것.
 * - **한글**은 흰칸이라 안 지워지고 **모든 차량에 「자가용」이 그대로 인쇄**된다.
 *   비어 있으면 눈에 띄지만 「자가용」은 그럴듯해 보여서 **더 조용하다** — 영업용·관용 차량에서 거짓 인쇄.
 *
 * ## 왜 매핑이 아니라 양식을 고치나
 * 통관 SET 의 설계가 **「구매리스트 마스터 → 6시트 자동 연동」**이다(§12). `구매리스트!B8` 은 이미
 * 매핑돼 있고(`nice_reg_use_type ?: resUseType`), heyman 은 그 cascade 로 정상 동작 중이다.
 * 여기에만 별도 매핑을 만들면 **같은 값의 출처가 둘**이 되어 갈린다.
 *
 *   php scripts/fix-clearance-usage-cascade.php            # dry-run (현재 상태만 출력)
 *   php scripts/fix-clearance-usage-cascade.php --apply
 *   php scripts/fix-clearance-usage-cascade.php --verify   # 3 세트 실측 대조 (읽기 전용)
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$APPLY = in_array('--apply', $argv, true);
$VERIFY = in_array('--verify', $argv, true);

/** heyman 세트에서 실측한 수식 그대로 — 새로 짜지 않는다(운영에서 검증된 문구). */
const KOR_FORMULA = '=구매리스트!B8';
const ENG_FORMULA = '=SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(구매리스트!B8,"자가용","Private"),"영업용","Business"),"관용","Official")';

$targets = [
    '한글등록증' => KOR_FORMULA,
    '영문등록증' => ENG_FORMULA,
];

// heyman 은 이미 정상 — 건드리지 않는다.
$sets = ['system', 'karaba'];
$root = __DIR__.'/../resources/templates/';

if ($VERIFY) {
    foreach (['system', 'heyman', 'karaba'] as $set) {
        $path = $root.$set.'/clearance_set.xlsx';
        $ss = IOFactory::createReaderForFile($path)->load($path);
        echo "== {$set}\n";
        foreach ($targets as $sheet => $want) {
            $cell = $ss->getSheetByName($sheet)->getCell('P4');
            $val = $cell->getValue();
            $txt = is_object($val) ? 'RichText:'.$val->getPlainText() : (string) $val;
            $ok = is_string($val) && $val === $want;
            printf("   %-8s P4  %s  %s\n", $sheet, $ok ? '✅' : '❌ 리터럴', mb_substr($txt, 0, 70));
        }
    }
    exit(0);
}

foreach ($sets as $set) {
    $path = $root.$set.'/clearance_set.xlsx';
    if (! is_readable($path)) {
        echo "SKIP {$set} — 파일 없음\n";

        continue;
    }
    $ss = IOFactory::createReaderForFile($path)->load($path);
    $changed = 0;

    foreach ($targets as $sheet => $formula) {
        $ws = $ss->getSheetByName($sheet);
        $cur = $ws->getCell('P4')->getValue();
        $curTxt = is_object($cur) ? 'RichText:'.$cur->getPlainText() : var_export($cur, true);

        if (is_string($cur) && $cur === $formula) {
            echo "  {$set}/{$sheet}!P4 — 이미 수식\n";

            continue;
        }
        echo "  {$set}/{$sheet}!P4 : {$curTxt}\n      → {$formula}\n";
        if ($APPLY) {
            // RichText 를 수식 문자열로 대체. 서식(노란 채움 포함)은 스타일이라 값 교체와 무관하게 보존된다.
            $ws->getCell('P4')->setValueExplicit($formula, DataType::TYPE_FORMULA);
            $changed++;
        }
    }

    if ($APPLY && $changed > 0) {
        // 외부 하이퍼링크(WebDAV file://) 제거 — writer 깨짐 방어 (SKILLS §12).
        foreach ($ss->getWorksheetIterator() as $ws) {
            foreach ($ws->getHyperlinkCollection() as $coord => $_) {
                $ws->setHyperlink($coord, null);
            }
        }
        $w = new Xlsx($ss);
        // 🚫 preCalc 켜면 크로스시트 수식이 값으로 굳어 cascade 가 통째로 죽는다.
        $w->setPreCalculateFormulas(false);
        $w->save($path);
        echo "  ✅ {$set} 저장 ({$changed}칸)\n";
    }
}

if (! $APPLY) {
    echo "\ndry-run 이다 — 아무것도 쓰지 않았다. 실제 반영은 --apply (그 뒤 --verify 로 확인).\n";
}
