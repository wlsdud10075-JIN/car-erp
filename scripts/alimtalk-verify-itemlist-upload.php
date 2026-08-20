<?php

/**
 * 아이템리스트형 등록 xlsx 검증 (jin 2026-08-20) — `alimtalk-build-itemlist-upload.php` 의 짝.
 *
 * 사용법:  php scripts/alimtalk-verify-itemlist-upload.php <코드> [<코드>...]
 *
 * 무엇을 보는가:
 *  · 파일이 **엑셀로 열리는가** (PhpSpreadsheet 로 읽힘 = 컨테이너가 안 깨졌다)
 *  · 본문·헤더·하이라이트·아이템·요약이 `AlimtalkTemplates` 와 **글자단위로** 같은가
 *    (손으로 옮기면 띄어쓰기가 어긋나 발송이 반려된다 — SKILLS §8 #40)
 *  · 안 쓰는 아이템 칸과 샘플 버튼 잔재가 **비어 있는가** (안 지우면 유령 줄·엉뚱한 버튼이 등록된다)
 *  · 데이터 행이 인자로 준 코드 **그 개수만큼만** 있는가 (전체를 올려 20종이 재등록되는 사고 방지)
 */
require __DIR__.'/../vendor/autoload.php';

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$CODES = array_values(array_slice($argv, 1));
if (! $CODES) {
    fwrite(STDERR, "사용법: php scripts/alimtalk-verify-itemlist-upload.php <코드> [<코드>...]\n");
    exit(1);
}

$BASE = 'C:/Users/User/Desktop/알림톡';
$COMPANIES = [
    ['헤이맨확정알림톡', '헤이맨'],
    ['싼카확정알림톡', '싼카'],
    ['카라바확정알림톡', '카라바'],
];
const ITEM_COLS = ['R', 'T', 'V', 'X', 'Z', 'AB', 'AD', 'AF', 'AH', 'AJ'];
const JUNK_COLS = ['AN', 'AO', 'AT', 'AU', 'AZ', 'BA', 'BF', 'BG', 'BL', 'BM'];

$fail = 0;
foreach ($COMPANIES as [$dir, $label]) {
    $f = "$BASE/$dir/upload_erp_{$label}_아이템리스트_신규.xlsx";
    echo "\n━━ {$label} ━━\n";
    if (! file_exists($f)) {
        echo "  ❌ 파일 없음: $f\n";
        $fail++;

        continue;
    }

    try {
        $w = IOFactory::load($f)->getActiveSheet();
    } catch (Throwable $e) {
        echo '  ❌ 엑셀로 열리지 않는다 — 컨테이너가 깨졌다: '.$e->getMessage()."\n";
        $fail++;

        continue;
    }
    echo '  파일 '.number_format(filesize($f))." bytes · 엑셀 열림 ✅\n";

    // 데이터 행 개수 — 인자로 준 개수와 같아야 한다
    $dataRows = 0;
    for ($r = 6; $r <= 30; $r++) {
        if (trim((string) $w->getCell('B'.$r)->getValue()) !== '') {
            $dataRows++;
        }
    }
    if ($dataRows !== count($CODES)) {
        printf("  ❌ 데이터 행 %d개 (인자 %d개와 다르다 — 다른 템플릿이 함께 등록된다)\n", $dataRows, count($CODES));
        $fail++;
    }

    foreach ($CODES as $n => $code) {
        $row = 6 + $n;
        $tpl = AlimtalkTemplates::TEMPLATES[$code] ?? null;
        $card = AlimtalkTemplates::ITEMLIST[$code] ?? null;
        if (! $tpl || ! $card) {
            echo "  ❌ {$code}: 코드에 없다\n";
            $fail++;

            continue;
        }
        $g = fn (string $c): string => str_replace("\r\n", "\n", (string) $w->getCell($c.$row)->getValue());

        $checks = [
            '코드' => [$g('B'), $code],
            '템플릿명' => [$g('C'), $tpl['name']],
            '메시지유형' => [$g('D'), 'BA'],
            '본문' => [$g('E'), $tpl['body']],
            '강조유형' => [$g('J'), '아이템리스트형'],
            '헤더' => [$g('N'), $card['header']],
            '하이라이트T' => [$g('O'), $card['highlight']['title']],
            '하이라이트D' => [$g('P'), $card['highlight']['description']],
            '요약T' => [$g('AL'), $card['summary']['title'] ?? ''],
            '요약D' => [$g('AM'), $card['summary']['description'] ?? ''],
        ];
        foreach (ITEM_COLS as $i => $c) {
            $next = $c;
            $next++;
            $checks['아이템'.($i + 1).'T'] = [$g($c), $card['items'][$i]['title'] ?? ''];
            $checks['아이템'.($i + 1).'D'] = [$g($next), $card['items'][$i]['description'] ?? ''];
        }
        foreach (JUNK_COLS as $c) {
            $checks['잔재'.$c] = [$g($c), ''];
        }

        $bad = [];
        foreach ($checks as $label2 => [$got, $want]) {
            if ($got !== $want) {
                $bad[] = sprintf('%s: got=%s want=%s', $label2,
                    json_encode($got, JSON_UNESCAPED_UNICODE), json_encode($want, JSON_UNESCAPED_UNICODE));
            }
        }
        if ($bad) {
            echo "  ❌ 행 {$row} {$code}\n";
            foreach ($bad as $b) {
                echo "       $b\n";
            }
            $fail++;
        } else {
            printf("  ✅ 행 %d %-24s (%d항목 검사 통과)\n", $row, $code, count($checks));
        }
    }
}

echo "\n".($fail === 0 ? "🟢 전부 통과 — BizM 에 업로드해도 된다.\n" : "🔴 {$fail}건 실패 — 업로드하지 말 것.\n");
exit($fail === 0 ? 0 : 1);
