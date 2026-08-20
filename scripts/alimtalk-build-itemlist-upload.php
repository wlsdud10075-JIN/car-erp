<?php

/**
 * BizM 템플릿 등록 xlsx 생성기 — **아이템리스트형** (jin 2026-08-20).
 *
 * `alimtalk-build-upload.php`(기본형 전용)의 아이템리스트 판. zip 패치 방식은 **그대로 계승**한다.
 *
 * 사용법:  php scripts/alimtalk-build-itemlist-upload.php <코드> [<코드>...]
 * 예)      php scripts/alimtalk-build-itemlist-upload.php erp_receivable_status erp_daily_summary
 *
 * 출력:    Desktop\알림톡\{회사}확정알림톡\upload_erp_{회사}_아이템리스트_신규.xlsx  (3사)
 *          → **인자로 준 코드만** 들어간다. 승인본 전체를 복제하지 않으므로 나머지 20종이
 *            같이 재등록되는 일이 없다(jin 지적).
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
 * ⚠️ 문구는 `AlimtalkTemplates` 상수에서 읽는다 — 손으로 옮기면 띄어쓰기가 어긋나 발송이 반려된다.
 * ⚠️ 샘플이 갱신되면(`_v3` 등) 4·5행 헤더 배치를 **먼저 대조**할 것.
 *
 * 열 매핑(실측, 승인본 4행 헤더 기준):
 *   A 프로필ID / B 코드 / C 템플릿명 / D 메시지유형(BA) / E 본문 / I 카테고리 / J 강조유형
 *   N 헤더 / O·P 하이라이트 / R·S T·U V·W X·Y Z·AA AB·AC AD·AE AF·AG AH·AI AJ·AK 아이템1~10 / AL·AM 요약
 */
require __DIR__.'/../vendor/autoload.php';

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$CODES = array_values(array_filter(array_slice($argv, 1), fn ($a) => ! str_starts_with($a, '--')));
if (! $CODES) {
    fwrite(STDERR, "사용법: php scripts/alimtalk-build-itemlist-upload.php <코드> [<코드>...]\n");
    fwrite(STDERR, '아이템리스트형 코드: '.implode(', ', array_keys(AlimtalkTemplates::ITEMLIST))."\n");
    exit(1);
}
if (count($CODES) > 16) {
    fwrite(STDERR, "❌ 샘플 데이터 행은 6~21행(16개)뿐이다 — 코드를 나눠서 실행할 것\n");
    exit(1);
}

$BASE = 'C:/Users/User/Desktop/알림톡';
$SAMPLE = $BASE.'/upload_sample_v2.xlsx';

/** 회사별 발신프로필(플러스친구 아이디) — 등록본에서 확인한 값. */
$COMPANIES = [
    ['헤이맨확정알림톡', '헤이맨', '@heyman_con'],
    ['싼카확정알림톡', '싼카', '@site_condition'],
    ['카라바확정알림톡', '카라바', '@주식회사카라바'],
];

/** 아이템 N(0-based)의 타이틀 열. 설명은 그 다음 열. */
const ITEM_COLS = ['R', 'T', 'V', 'X', 'Z', 'AB', 'AD', 'AF', 'AH', 'AJ'];

/**
 * 우리가 값을 넣는 열 — **이 목록 밖의 모든 열은 비운다**.
 *
 * ⚠️ 잔재 열을 손으로 나열하면 안 된다 — 샘플은 **행마다 예시가 다르다**(실측):
 *   6행 AO·AU·BA·BG·BM / 7행 거기에 **K·L(강조표기 문구)** 추가 / 9·10행 수십 개 / 17행 카드 전체…
 *   실제로 K·L 을 안 비워 7행이 BizM 업로드에서 「오입력 7행」으로 거부됐다(jin 2026-08-20).
 *   그래서 화이트리스트로 뒤집는다. 코드를 몇 행에 넣든 안전하다.
 *
 * H(보안 여부)는 샘플 값을 그대로 둔다 — 문자열이 아니라 불린이라 손대면 형식이 깨진다.
 */
const KEEP_COLS = ['A', 'B', 'C', 'D', 'E', 'H', 'I', 'J', 'N', 'O', 'P', 'AL', 'AM'];

/** 코드 → 그 행에 쓸 값 배열. null = 그 칸을 비운다. */
function rowValues(string $code, string $profile): array
{
    $t = AlimtalkTemplates::TEMPLATES[$code] ?? null;
    $card = AlimtalkTemplates::ITEMLIST[$code] ?? null;
    if (! $t || ! $card) {
        fwrite(STDERR, "❌ {$code}: 아이템리스트형이 아니다 — 기본형은 alimtalk-build-upload.php 를 쓸 것\n");
        exit(1);
    }

    // 규격 사전 검사 — 카카오 반려 조건(SKILLS §8 #40). 여기서 걸러야 등록 후에 안 튕긴다.
    $errs = [];
    if (mb_strlen($card['header']) > 16) {
        $errs[] = "헤더 '{$card['header']}' 16자 초과";
    }
    foreach ($card['items'] as $i => $it) {
        if (mb_strlen($it['title']) > 6) {
            $errs[] = '아이템'.($i + 1)." 타이틀 '{$it['title']}' 6자 초과";
        }
    }
    if (isset($card['summary']) && mb_strlen($card['summary']['title']) > 6) {
        $errs[] = "요약 타이틀 '{$card['summary']['title']}' 6자 초과";
    }
    if (count($card['items']) < 2 || count($card['items']) > 10) {
        $errs[] = '아이템은 2~10개 (현재 '.count($card['items']).')';
    }
    foreach ($errs as $e) {
        fwrite(STDERR, "❌ {$code}: {$e}\n");
    }
    if ($errs) {
        exit(1);
    }

    $v = [
        'A' => $profile,
        'B' => $code,
        'C' => $t['name'],
        'E' => $t['body'],
        'I' => '008002',                 // 대표 보고류 카테고리 — 기존 등록본 전부 이 값
        'J' => '아이템리스트형',
        'N' => $card['header'],
        'O' => $card['highlight']['title'],
        'P' => $card['highlight']['description'],
        'AL' => $card['summary']['title'] ?? null,
        'AM' => $card['summary']['description'] ?? null,
    ];
    // 아이템 1~10 — 쓰는 만큼 채우고 **나머지는 명시적으로 비운다**(샘플 잔재 제거).
    foreach (ITEM_COLS as $i => $c) {
        $next = $c;
        $next++;
        $v[$c] = $card['items'][$i]['title'] ?? null;
        $v[$next] = $card['items'][$i]['description'] ?? null;
    }
    // 쓰는 열 밖은 전부 비운다 — 행마다 다른 샘플 예시(버튼·강조문구·미리보기)를 싹 제거한다.
    $keep = KEEP_COLS;
    foreach (ITEM_COLS as $c) {
        $n = $c;
        $n++;
        $keep[] = $c;
        $keep[] = $n;
    }
    for ($col = 'A'; $col !== 'EE'; $col++) {
        if (! in_array($col, $keep, true)) {
            $v[$col] = null;
        }
    }

    return $v;
}

$xmlEscape = fn (string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);

foreach ($COMPANIES as [$dir, $label, $profile]) {
    $outPath = "$BASE/$dir/upload_erp_{$label}_아이템리스트_신규.xlsx";

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
    $index = [];   // [행번호][열] => si 인덱스
    foreach ($CODES as $n => $code) {
        $row = 6 + $n;
        foreach (rowValues($code, $profile) as $col => $value) {
            if ($value === null) {
                continue;
            }
            $index[$row][$col] = $siCount + count($index, COUNT_RECURSIVE) - count($index);
            $append .= '<si><t xml:space="preserve">'.$xmlEscape($value).'</t></si>';
        }
    }
    $strings = str_replace('</sst>', $append.'</sst>', $strings);

    // ── ② 각 행의 셀 교체 ────────────────────────────────────────────────
    foreach ($CODES as $n => $code) {
        $row = 6 + $n;
        if (! preg_match('/<row r="'.$row.'"[^>]*>.*?<\/row>/s', $sheet, $m)) {
            fwrite(STDERR, "❌ {$row}행을 못 찾았다 — 샘플이 바뀌었는지 확인할 것\n");
            exit(1);
        }
        $orig = $m[0];
        $new = $orig;
        foreach (rowValues($code, $profile) as $col => $value) {
            $re = '/<c r="'.$col.$row.'"([^>]*?)(\/>|>.*?<\/c>)/s';
            if (! preg_match($re, $new, $cm)) {
                if ($value === null) {
                    continue;   // 원래 없는 칸 = 이미 비어 있다(비우려던 것이므로 정상)
                }
                fwrite(STDERR, "❌ 셀 {$col}{$row} 이 없다 — 샘플 열 배치가 바뀌었는지 4·5행을 대조할 것\n");
                exit(1);
            }
            preg_match('/\ss="\d+"/', $cm[1], $sm);   // 스타일 보존
            $style = $sm[0] ?? '';
            $new = str_replace($cm[0], $value === null
                ? '<c r="'.$col.$row.'"'.$style.'/>'
                : '<c r="'.$col.$row.'"'.$style.' t="s"><v>'.$index[$row][$col].'</v></c>', $new);
        }
        $sheet = str_replace($orig, $new, $sheet);
    }

    // ── ③ 안 쓰는 예시 행 제거 + dimension 축소 ──────────────────────────
    $lastUsed = 6 + count($CODES) - 1;
    for ($r = $lastUsed + 1; $r <= 21; $r++) {
        $sheet = preg_replace('/<row r="'.$r.'"[^>]*>.*?<\/row>/s', '', $sheet, 1);
    }
    $sheet = preg_replace('/<dimension ref="A1:[A-Z]+\d+"\/>/', '<dimension ref="A1:ED'.$lastUsed.'"/>', $sheet, 1);

    // ── ④ sst count/uniqueCount 재계산 ───────────────────────────────────
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
    echo "✅ $outPath  (".count($CODES).'종: '.implode(', ', $CODES).")\n";
}

echo "\n검증: php scripts/alimtalk-verify-itemlist-upload.php ".implode(' ', $CODES)."\n";
