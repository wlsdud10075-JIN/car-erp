<?php

/**
 * 등록 xlsx 검증 — `alimtalk-build-upload.php` 산출물이 실제로 읽히고 코드와 일치하는지.
 *
 * 사용법: php scripts/alimtalk-verify-upload.php <템플릿코드>
 *
 * 🚨 검증의 핵심 = **본문이 `AlimtalkTemplates` 상수와 글자단위로 같은가**.
 *    승인본과 코드가 한 글자라도 다르면 **발송 시점에만** 반려된다(로컬·CI 는 100% 통과).
 * 💡 PhpSpreadsheet 로 읽히면 XML 도 정상이라는 뜻이라 파싱 자체가 1차 검증이다.
 */
require __DIR__.'/../vendor/autoload.php';

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$CODE = $argv[1] ?? null;
if (! $CODE || ! isset(AlimtalkTemplates::TEMPLATES[$CODE])) {
    fwrite(STDERR, "사용법: php scripts/alimtalk-verify-upload.php <템플릿코드>\n");
    exit(1);
}
$t = AlimtalkTemplates::TEMPLATES[$CODE];
$BASE = 'C:/Users/User/Desktop/알림톡';

$COMPANIES = [
    ['헤이맨확정알림톡', '헤이맨', '@heyman_con'],
    ['싼카확정알림톡', '싼카', '@site_condition'],
    ['카라바확정알림톡', '카라바', '@주식회사카라바'],
];

$fail = 0;
foreach ($COMPANIES as [$dir, $label, $profile]) {
    $path = "$BASE/$dir/upload_erp_{$label}_{$CODE}_신규.xlsx";
    echo "── $label ──\n";
    if (! is_file($path)) {
        echo "  ❌ 파일 없음: $path\n";
        $fail++;

        continue;
    }

    $sh = IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();
    $get = fn (string $c): string => trim((string) $sh->getCell($c)->getValue());

    $checks = [
        '발신프로필(A6)' => [$get('A6'), $profile],
        '템플릿코드(B6)' => [$get('B6'), $CODE],
        '템플릿명(C6)' => [$get('C6'), $t['name']],
        '메시지유형(D6)' => [$get('D6'), 'BA'],
        '본문(E6)' => [str_replace("\r\n", "\n", $get('E6')), trim($t['body'])],
        '강조유형(J6)' => [$get('J6'), '선택안함'],
    ];
    foreach ($checks as $what => [$got, $want]) {
        if ($got === $want) {
            echo "  ✅ $what\n";
        } else {
            echo "  ❌ $what\n     기대: ".str_replace("\n", '\n', $want)."\n     실제: ".str_replace("\n", '\n', $got)."\n";
            $fail++;
        }
    }

    // 예시 행이 남아 있으면 엉뚱한 템플릿 15개가 함께 등록된다.
    for ($r = 7; $r <= 21; $r++) {
        if ($get("B$r") !== '') {
            echo "  ❌ 예시 {$r}행이 남아 있다: ".$get("B$r")."\n";
            $fail++;
        }
    }
    // 버튼 칸이 남아 있으면 등록본에 버튼이 붙고, 발송에 버튼을 안 실으면 K108 로 실패한다.
    foreach (['AN6', 'AO6', 'AT6', 'DZ6', 'ED6'] as $c) {
        if ($get($c) !== '') {
            echo "  ❌ 버튼/링크 칸 $c 이 안 비었다: ".$get($c)."\n";
            $fail++;
        }
    }
    // 변수 선언 ↔ 본문 일치 (치환 누락 = 자리표시자 그대로 발송).
    preg_match_all('/#\{([^}]+)\}/u', $t['body'], $m);
    $inBody = array_values(array_unique($m[1]));
    if ($inBody !== array_values($t['vars'])) {
        echo '  ⚠️ 본문 변수 순서/구성이 vars 와 다름: '.implode(',', $inBody).' vs '.implode(',', $t['vars'])."\n";
    }
}

echo $fail === 0 ? "\n✅ 전부 통과 — 그대로 BizM 콘솔에 업로드하면 된다.\n" : "\n❌ 실패 {$fail}건\n";
exit($fail === 0 ? 0 : 1);
