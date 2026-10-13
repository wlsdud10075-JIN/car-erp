<?php

/**
 * 자금보고(erp_capital_weekly) 재등록 xlsx — 손익을 요약정보 → 아이템 줄로 옮긴 등록본을 **코드에서** 만든다 (jin 2026-10-13).
 *
 * 배경: 카카오 요약정보 description 은 숫자·통화기호·통화단위·쉼표·마침표만 허용 — 손실(음수)을 표기할 길이 없다
 *   (2026-10-13 ssancarerp 실측 7종 전부 K140: '-'·'−'·'△'·'▲'·'( )'·'₩-'·'ㅡ'). 아이템 줄은 '-5.99억' 이 통과한다.
 *   그래서 `AlimtalkTemplates::ITEMLIST['erp_capital_weekly']` 에서 summary 를 없애고 items 5번째로 넣었다.
 *
 * 🚨 형식은 **공식 샘플 zip 패치**(`alimtalk-build-itemlist-upload.php` 와 같은 방식)다. PhpSpreadsheet 로 load→save 한
 *   파일은 BizM 업로더가 「양식이 다릅니다」로 거부한다 — 2026-10-13 에 그렇게 만든 첫 판이 실제로 거부됐다.
 *   `xl/worksheets/sheet1.xml` 과 `xl/sharedStrings.xml` 두 엔트리만 고치고 나머지는 바이트 그대로.
 *
 * 사용:
 *   php scripts/alimtalk-capital-weekly-xlsx.php            # dry-run — 만들 셀을 찍기만 한다
 *   php scripts/alimtalk-capital-weekly-xlsx.php --apply    # 3사 폴더에 upload_erp_{회사}_자금보고_손익아이템_재등록.xlsx 저장
 *   php scripts/alimtalk-capital-weekly-xlsx.php --verify   # 저장된 파일을 읽어 코드와 셀 단위로 대조
 *
 * 템플릿명·버튼은 **현재 등록본과 같게** — 같은 코드로 수정 승인을 받는 것이라 이름이 바뀌면 다른 템플릿이 된다.
 *   버튼 = 웹링크 「자금 보고 보기」 `{도메인}/a/capital/#{url}` (발송 코드 AlimtalkCapitalWeekly 가 서명 링크로 채운다).
 */
use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$verify = in_array('--verify', $argv, true);
$root = getenv('ALIMTALK_XLSX_ROOT') ?: 'C:/Users/User/Desktop/알림톡';
$sample = $root.'/upload_sample_v2.xlsx';   // BizM 콘솔에서 받은 공식 샘플(진짜 엑셀 산출물) — 이것만 베이스로 쓴다
$code = 'erp_capital_weekly';

// 회사별 발신프로필·도메인·현재 등록본의 템플릿명.
$companies = [
    '헤이맨' => ['profile' => '@heyman_con', 'domain' => 'https://heysellcar.com', 'dir' => '헤이맨확정알림톡', 'name' => '자금보고'],
    '싼카' => ['profile' => '@site_condition', 'domain' => 'https://heymancar.com', 'dir' => '싼카확정알림톡', 'name' => '자금보고'],
    '카라바' => ['profile' => '@주식회사카라바', 'domain' => 'https://karaba-erp.com', 'dir' => '카라바확정알림톡', 'name' => '주간자금보고'],
];

/** 아이템 N(0-based)의 타이틀 열. 설명은 그 다음 열. */
const ITEM_COLS = ['R', 'T', 'V', 'X', 'Z', 'AB', 'AD', 'AF', 'AH', 'AJ'];

/** 우리가 값을 넣는 열 — 이 밖의 모든 열은 비운다(샘플은 행마다 예시가 달라 잔재가 남으면 「오입력 N행」). H 는 샘플 값 유지. */
const KEEP_COLS = ['A', 'B', 'C', 'D', 'E', 'H', 'I', 'J', 'N', 'O', 'P', 'AN', 'AO', 'AP', 'AQ'];

/** 열 문자 → 번호 (A=1). */
function colIndex(string $col): int
{
    $n = 0;
    foreach (str_split($col) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }

    return $n;
}

/** 회사별 6행에 쓸 값(열 => 값). null = 그 칸을 비운다. */
$rowValues = function (array $co) use ($code): array {
    $t = AlimtalkTemplates::TEMPLATES[$code];
    $card = AlimtalkTemplates::ITEMLIST[$code];
    if (isset($card['summary'])) {
        throw new RuntimeException('erp_capital_weekly 카드에 summary 가 남아 있다 — 이 재등록의 목적과 어긋난다');
    }
    $errs = [];
    if (mb_strlen($card['header']) > 16) {
        $errs[] = "헤더 '{$card['header']}' 16자 초과";
    }
    foreach ($card['items'] as $i => $it) {
        if (mb_strlen($it['title']) > 6) {
            $errs[] = '아이템'.($i + 1)." 타이틀 '{$it['title']}' 6자 초과";
        }
        if (mb_strlen($it['description']) > 20) {
            $errs[] = '아이템'.($i + 1).' 설명 원문 20자 초과';
        }
    }
    if (count($card['items']) < 2 || count($card['items']) > 10) {
        $errs[] = '아이템은 2~10개';
    }
    if ($errs) {
        throw new RuntimeException(implode(' / ', $errs));
    }

    $url = $co['domain'].'/a/capital/#{url}';
    $v = [
        'A' => $co['profile'], 'B' => $code, 'C' => $co['name'], 'D' => 'BA', 'E' => $t['body'],
        'I' => '008002', 'J' => '아이템리스트형',
        'N' => $card['header'], 'O' => $card['highlight']['title'], 'P' => $card['highlight']['description'],
        'AN' => '웹링크', 'AO' => '자금 보고 보기', 'AP' => $url, 'AQ' => $url,
    ];
    foreach (ITEM_COLS as $i => $c) {
        $next = $c;
        $next++;
        $v[$c] = $card['items'][$i]['title'] ?? null;
        $v[$next] = $card['items'][$i]['description'] ?? null;
    }
    $keep = KEEP_COLS;
    foreach (ITEM_COLS as $c) {
        $n = $c;
        $n++;
        $keep[] = $c;
        $keep[] = $n;
    }
    for ($col = 'A'; $col !== 'EE'; $col++) {
        if (! in_array($col, $keep, true) && ! array_key_exists($col, $v)) {
            $v[$col] = null;
        }
    }

    return $v;
};

$xmlEscape = fn (string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
$bad = 0;

foreach ($companies as $label => $co) {
    $out = "{$root}/{$co['dir']}/upload_erp_{$label}_자금보고_손익아이템_재등록.xlsx";
    $values = $rowValues($co);
    $filled = array_filter($values, fn ($x) => $x !== null);
    echo "── {$label} ({$co['profile']} · {$co['domain']} · 템플릿명 {$co['name']})\n";

    if ($verify) {
        if (! is_file($out)) {
            echo "   ❌ 없음: {$out}\n";
            $bad++;

            continue;
        }
        $ws = IOFactory::load($out)->getActiveSheet();
        foreach ($filled as $c => $want) {
            $got = (string) $ws->getCell($c.'6')->getValue();
            if ($got !== (string) $want) {
                $bad++;
                echo "   ❌ {$c}6: 파일=".json_encode($got, JSON_UNESCAPED_UNICODE).' / 코드='.json_encode($want, JSON_UNESCAPED_UNICODE)."\n";
            }
        }
        for ($c = 'A'; $c !== 'EE'; $c++) {
            if ($c === 'H' || array_key_exists($c, $filled)) {
                continue;
            }
            $got = (string) $ws->getCell($c.'6')->getValue();
            if ($got !== '') {
                $bad++;
                echo "   ❌ {$c}6: 코드에 없는 값 ".json_encode($got, JSON_UNESCAPED_UNICODE)." (샘플 잔재)\n";
            }
        }
        if ((string) $ws->getCell('B7')->getValue() !== '') {
            $bad++;
            echo "   ❌ 7행에 잔재\n";
        }
        echo $bad === 0 ? "   ✅ 코드와 일치 (아이템 5줄 · 요약정보 없음 · 버튼 1 · H 샘플값 유지)\n" : '';

        continue;
    }

    foreach (['A', 'B', 'C', 'N', 'O', 'P', 'R', 'S', 'Z', 'AA', 'AN', 'AO', 'AP'] as $c) {
        echo "   {$c}6 = ".mb_substr(str_replace("\n", '⏎', (string) $values[$c]), 0, 60)."\n";
    }
    if (! $apply) {
        continue;
    }

    $zin = new ZipArchive;
    if ($zin->open($sample) !== true) {
        fwrite(STDERR, "❌ 공식 샘플을 못 연다: {$sample}\n");
        exit(1);
    }
    $sheet = $zin->getFromName('xl/worksheets/sheet1.xml');
    $strings = $zin->getFromName('xl/sharedStrings.xml');
    if ($sheet === false || $strings === false) {
        fwrite(STDERR, "❌ sheet1.xml / sharedStrings.xml 이 없다\n");
        exit(1);
    }

    // ① 새 문자열을 sharedStrings 끝에 붙인다
    $siCount = preg_match_all('/<si>/', $strings);
    $append = '';
    $index = [];
    foreach ($filled as $c => $value) {
        $index[$c] = $siCount + count($index);
        $append .= '<si><t xml:space="preserve">'.$xmlEscape((string) $value).'</t></si>';
    }
    $strings = str_replace('</sst>', $append.'</sst>', $strings);

    // ② 6행 셀 교체 — 없는 셀은 열 순서에 맞춰 삽입(샘플 6행에 버튼 타입/링크 칸이 없을 수 있다)
    if (! preg_match('/<row r="6"[^>]*>.*?<\/row>/s', $sheet, $m)) {
        fwrite(STDERR, "❌ 6행을 못 찾았다\n");
        exit(1);
    }
    $orig = $m[0];
    $new = $orig;
    foreach ($values as $c => $value) {
        $re = '/<c r="'.$c.'6"([^>]*?)(\/>|>.*?<\/c>)/s';
        if (preg_match($re, $new, $cm)) {
            preg_match('/\ss="\d+"/', $cm[1], $sm);
            $style = $sm[0] ?? '';
            $new = str_replace($cm[0], $value === null
                ? '<c r="'.$c.'6"'.$style.'/>'
                : '<c r="'.$c.'6"'.$style.' t="s"><v>'.$index[$c].'</v></c>', $new);

            continue;
        }
        if ($value === null) {
            continue;
        }
        // 삽입: 자기보다 뒤 열의 첫 셀 앞에, 없으면 </row> 앞에
        $cell = '<c r="'.$c.'6" t="s"><v>'.$index[$c].'</v></c>';
        preg_match_all('/<c r="([A-Z]+)6"/', $new, $all, PREG_OFFSET_CAPTURE);
        $pos = null;
        foreach ($all[1] as $k => [$colName]) {
            if (colIndex($colName) > colIndex($c)) {
                $pos = $all[0][$k][1];
                break;
            }
        }
        $new = $pos === null
            ? str_replace('</row>', $cell.'</row>', $new)
            : substr($new, 0, $pos).$cell.substr($new, $pos);
    }
    $sheet = str_replace($orig, $new, $sheet);

    // ③ 7~21행(샘플 예시) 제거 + dimension
    for ($r = 7; $r <= 21; $r++) {
        $sheet = preg_replace('/<row r="'.$r.'"[^>]*>.*?<\/row>/s', '', $sheet, 1);
    }
    $sheet = preg_replace('/<dimension ref="A1:[A-Z]+\d+"\/>/', '<dimension ref="A1:ED6"/>', $sheet, 1);

    // ④ sst count 재계산
    $tsCount = preg_match_all('/ t="s"/', $sheet);
    $siTotal = preg_match_all('/<si>/', $strings);
    $strings = preg_replace('/<sst([^>]*?)count="\d+" uniqueCount="\d+"/', '<sst$1count="'.$tsCount.'" uniqueCount="'.$siTotal.'"', $strings, 1);

    // ⑤ 나머지 엔트리는 바이트 그대로 — 기존 파일은 백업
    if (is_file($out)) {
        $bak = $out.'.bak-'.date('YmdHi');
        if (! is_file($bak) && ! @copy($out, $bak)) {
            fwrite(STDERR, "❌ 백업 실패 — 중단: {$out}\n");
            exit(1);
        }
        echo '   ↳ 기존 파일 백업: '.basename($bak)."\n";
    }
    @unlink($out);
    $zout = new ZipArchive;
    if ($zout->open($out, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "❌ 출력 파일을 못 만든다: {$out}\n");
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
        fwrite(STDERR, "❌ 고친 엔트리가 2개가 아니다({$touched})\n");
        exit(1);
    }
    echo "   ✅ 저장: {$out}\n";
}

if (! $apply && ! $verify) {
    echo "\n(dry-run — --apply 로 저장, --verify 로 대조)\n";
}
exit($bad === 0 ? 0 : 1);
