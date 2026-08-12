<?php

/**
 * BizM 템플릿 등록 xlsx 생성기 — **기본형(버튼·카드 없는) 템플릿용**.
 *
 * 사용법:  php scripts/alimtalk-build-upload.php <템플릿코드> [카테고리코드]
 * 예)      php scripts/alimtalk-build-upload.php erp_purchase_paid 004001
 *
 * 출력:    Desktop\알림톡\{회사}확정알림톡\upload_erp_{회사}_{코드}_신규.xlsx  (3사)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🚨 **왜 zip 을 손으로 패치하는가** (2026-08-11 두 번 실패 후 확립 — docs §12-B)
 *
 *  1. ❌ PhpSpreadsheet/openpyxl 로 load→save 하면 컨테이너가 통째로 재작성된다
 *     (`[Content_Types].xml`·`docProps`·rels·문자열 저장방식) → BizM 업로더가 **"양식이 다릅니다"** 로 거부.
 *  2. ❌ 기존 `{회사}확정알림톡` xlsx 를 베이스로 삼아도 안 된다 — 그것들도 openpyxl 산출물이다.
 *  3. ✅ 베이스는 **BizM 콘솔에서 받은 공식 샘플**(`upload_sample_v2.xlsx`, 진짜 엑셀 산출물).
 *     zip 엔트리를 **바이트 그대로** 두고 `xl/worksheets/sheet1.xml` + `xl/sharedStrings.xml`
 *     **두 개만** 고친다.
 *
 * ⚠️ 샘플이 갱신되면(`_v3` 등) 그걸 새 베이스로 쓰고 **4·5행 헤더 배치를 먼저 대조**할 것.
 * ⚠️ 문구는 `AlimtalkTemplates` 상수에서 읽는다 — 손으로 옮기면 띄어쓰기가 어긋나 발송이 반려된다.
 */
require __DIR__.'/../vendor/autoload.php';

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$CODE = $argv[1] ?? null;
$CATEGORY = $argv[2] ?? '004001';

if (! $CODE || ! isset(AlimtalkTemplates::TEMPLATES[$CODE])) {
    fwrite(STDERR, "사용법: php scripts/alimtalk-build-upload.php <템플릿코드> [카테고리코드]\n");
    fwrite(STDERR, '알 수 있는 코드: '.implode(', ', array_keys(AlimtalkTemplates::TEMPLATES))."\n");
    exit(1);
}
if (isset(AlimtalkTemplates::ITEMLIST[$CODE])) {
    fwrite(STDERR, "❌ {$CODE} 은 아이템리스트형이다 — 이 스크립트는 기본형 전용이다(카드 열 N~AL 을 안 채운다).\n");
    exit(1);
}

$t = AlimtalkTemplates::TEMPLATES[$CODE];
$BASE = 'C:/Users/User/Desktop/알림톡';
$SAMPLE = $BASE.'/upload_sample_v2.xlsx';

/** 회사별 발신프로필(플러스친구 아이디) — 등록본에서 확인한 값. 회사를 늘리면 여기 한 줄. */
$COMPANIES = [
    ['헤이맨확정알림톡', '헤이맨', '@heyman_con'],
    ['싼카확정알림톡', '싼카', '@site_condition'],
    ['카라바확정알림톡', '카라바', '@주식회사카라바'],
];

/** 6행에 넣을 값. null = 그 칸을 **비운다**(샘플 예시의 버튼·미리보기 잔재 제거). */
$vals = [
    'A' => null,          // 회사별로 아래에서 채운다
    'B' => $CODE,
    'C' => $t['name'],
    'E' => $t['body'],
    'I' => $CATEGORY,
    // 샘플 6행에 박혀 있는 버튼·링크·미리보기 — 안 지우면 엉뚱한 버튼이 함께 등록된다.
    'AN' => null, 'AO' => null, 'AT' => null, 'AU' => null,
    'AZ' => null, 'BA' => null, 'BF' => null, 'BG' => null,
    'BL' => null, 'BM' => null, 'DZ' => null, 'ED' => null,
];
// D(메시지유형 BA)·H(보안 FALSE)·J(강조 선택안함)은 샘플 값 그대로 쓴다 — 건드리지 않는다.

$xmlEscape = fn (string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);

foreach ($COMPANIES as [$dir, $label, $profile]) {
    $vals['A'] = $profile;
    $outPath = "$BASE/$dir/upload_erp_{$label}_{$CODE}_신규.xlsx";

    $zin = new ZipArchive;
    if ($zin->open($SAMPLE) !== true) {
        fwrite(STDERR, "❌ 공식 샘플을 못 연다: $SAMPLE\n");
        exit(1);
    }

    $sheet = $zin->getFromName('xl/worksheets/sheet1.xml');
    $strings = $zin->getFromName('xl/sharedStrings.xml');
    if ($sheet === false || $strings === false) {
        fwrite(STDERR, "❌ sheet1.xml / sharedStrings.xml 이 없다 — 샘플이 바뀌었는지 확인할 것\n");
        exit(1);
    }

    // ── ① 새 문자열을 sharedStrings 끝에 붙이고 인덱스를 받는다 ──────────────
    $siCount = preg_match_all('/<si>/', $strings);
    $append = '';
    $index = [];
    foreach ($vals as $col => $value) {
        if ($value === null) {
            continue;
        }
        $index[$col] = $siCount + count($index);
        $append .= '<si><t xml:space="preserve">'.$xmlEscape($value).'</t></si>';
    }
    $strings = str_replace('</sst>', $append.'</sst>', $strings);

    // ── ② 6행 셀 교체 ────────────────────────────────────────────────────
    if (! preg_match('/<row r="6"[^>]*>.*?<\/row>/s', $sheet, $m)) {
        fwrite(STDERR, "❌ 6행을 못 찾았다\n");
        exit(1);
    }
    $row6 = $m[0];
    $newRow6 = $row6;
    foreach ($vals as $col => $value) {
        $re = '/<c r="'.$col.'6"([^>]*?)(\/>|>.*?<\/c>)/s';
        if (! preg_match($re, $newRow6, $cm)) {
            fwrite(STDERR, "❌ 셀 {$col}6 이 없다 — 샘플 열 배치가 바뀌었는지 4·5행을 대조할 것\n");
            exit(1);
        }
        preg_match('/\ss="\d+"/', $cm[1], $sm);   // 스타일은 보존
        $style = $sm[0] ?? '';
        $newRow6 = str_replace($cm[0], $value === null
            ? '<c r="'.$col.'6"'.$style.'/>'
            : '<c r="'.$col.'6"'.$style.' t="s"><v>'.$index[$col].'</v></c>', $newRow6);
    }
    $sheet = str_replace($row6, $newRow6, $sheet);

    // ── ③ 예시 15행(7~21) 제거 + dimension 축소 ──────────────────────────
    for ($r = 7; $r <= 21; $r++) {
        $sheet = preg_replace('/<row r="'.$r.'"[^>]*>.*?<\/row>/s', '', $sheet, 1);
    }
    $sheet = preg_replace('/<dimension ref="A1:[A-Z]+\d+"\/>/', '<dimension ref="A1:ED6"/>', $sheet, 1);

    // ── ④ sst count/uniqueCount 재계산 ───────────────────────────────────
    //    count = 시트에 남은 t="s" 개수 / uniqueCount = <si> 개수. 안 맞으면 엑셀이 파일을 의심한다.
    $tsCount = preg_match_all('/ t="s"/', $sheet);
    $siTotal = preg_match_all('/<si>/', $strings);
    $strings = preg_replace('/<sst([^>]*?)count="\d+" uniqueCount="\d+"/',
        '<sst$1count="'.$tsCount.'" uniqueCount="'.$siTotal.'"', $strings, 1);

    // ── ⑤ 나머지 엔트리는 **바이트 그대로** 복사 ──────────────────────────
    @unlink($outPath);
    $zout = new ZipArchive;
    if ($zout->open($outPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "❌ 출력 파일을 못 만든다: $outPath\n");
        exit(1);
    }
    $touched = 0;
    for ($i = 0; $i < $zin->numFiles; $i++) {
        $name = $zin->getNameIndex($i);
        if ($name === 'xl/worksheets/sheet1.xml') {
            $zout->addFromString($name, $sheet);
            $touched++;
        } elseif ($name === 'xl/sharedStrings.xml') {
            $zout->addFromString($name, $strings);
            $touched++;
        } else {
            $zout->addFromString($name, $zin->getFromIndex($i));
        }
    }
    $zout->close();
    $zin->close();

    if ($touched !== 2) {
        fwrite(STDERR, "❌ 고친 엔트리가 2개가 아니다($touched) — 중단\n");
        exit(1);
    }
    echo "✅ $outPath\n";
}

echo "\n검증: php scripts/alimtalk-verify-upload.php $CODE\n";
