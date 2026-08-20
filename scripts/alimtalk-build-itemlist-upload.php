<?php

/**
 * 아이템리스트형 알림톡 BizM 등록 xlsx 생성 (jin 2026-08-20).
 *
 * 기본형 전용인 `alimtalk-build-upload.php` 의 아이템리스트 판(그 스크립트는 카드 열 N~AM 을 안 채운다).
 * 코드의 `AlimtalkTemplates::TEMPLATES` + `ITEMLIST` 를 **단일 출처**로 삼아 3사 등록본을 만든다.
 *
 * 🧩 방식 = **기존 승인본 복제 + 행 치환/추가**.
 *   BizM 업로드 양식은 970행짜리 가이드·드롭다운·서식이 들어 있어 새로 만들면 거부된다
 *   (§12-B 의 그 실패). 그래서 승인본을 열어 대상 행만 손대고 저장한다.
 *
 * 사용법:
 *   php scripts/alimtalk-build-itemlist-upload.php <코드> [<코드>...] [--apply]
 *     --apply 없으면 dry-run(무엇이 바뀔지만 출력)
 *
 * 결과: Desktop/알림톡/{회사}확정알림톡/upload_erp_{회사}_{코드}_신규.xlsx
 *   기존 행이 있으면 **그 행을 그대로 옮겨 적어** 변경 등록으로, 없으면 맨 뒤에 신규 행으로 넣는다.
 *
 * 열 매핑(실측, 4행 헤더 기준):
 *   A 프로필ID / B 템플릿코드 / C 템플릿명 / D 메시지유형(BA) / E 본문 / H 보안(FALSE)
 *   I 카테고리코드 / J 강조유형(아이템리스트형) / N 헤더 / O·P 하이라이트(타이틀·설명)
 *   R·S / T·U / V·W / X·Y / Z·AA … 아이템 1~10(타이틀·설명) / AL·AM 요약(타이틀·설명)
 */

require __DIR__.'/../vendor/autoload.php';

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$codes = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')));

if (! $codes) {
    fwrite(STDERR, "사용법: php scripts/alimtalk-build-itemlist-upload.php <코드> [<코드>...] [--apply]\n");
    exit(1);
}

/** 회사 폴더·파일명 — 실측 경로. */
const TENANTS = [
    ['dir' => '싼카확정알림톡', 'name' => '싼카'],
    ['dir' => '헤이맨확정알림톡', 'name' => '헤이맨'],
    ['dir' => '카라바확정알림톡', 'name' => '카라바'],
];
const DESKTOP = 'C:/Users/User/Desktop/알림톡';

/** 아이템 N 의 타이틀 열 (1~10). 실측: R T V X Z AB AD AF AH AJ */
function itemCol(int $i): string
{
    $cols = ['R', 'T', 'V', 'X', 'Z', 'AB', 'AD', 'AF', 'AH', 'AJ'];

    return $cols[$i] ?? throw new RuntimeException('아이템은 최대 10개다');
}

/** 열 문자 +1 (R → S, AB → AC) */
function nextCol(string $c): string
{
    $c++;

    return $c;
}

$fail = 0;
foreach ($codes as $code) {
    $tpl = AlimtalkTemplates::TEMPLATES[$code] ?? null;
    $card = AlimtalkTemplates::ITEMLIST[$code] ?? null;
    if (! $tpl) {
        fwrite(STDERR, "❌ {$code}: TEMPLATES 에 없다\n");
        $fail++;

        continue;
    }
    if (! $card) {
        fwrite(STDERR, "❌ {$code}: ITEMLIST 에 없다 — 기본형은 alimtalk-build-upload.php 를 쓸 것\n");
        $fail++;

        continue;
    }

    // 규격 사전 검사 — 카카오 반려 조건(SKILLS §8 #40)
    $errs = [];
    if (mb_strlen($card['header']) > 16) {
        $errs[] = "헤더 {$card['header']} 가 16자 초과";
    }
    foreach ($card['items'] as $i => $it) {
        if (mb_strlen($it['title']) > 6) {
            $errs[] = '아이템'.($i + 1)." 타이틀 '{$it['title']}' 가 6자 초과";
        }
    }
    if (isset($card['summary']) && mb_strlen($card['summary']['title']) > 6) {
        $errs[] = "요약 타이틀 '{$card['summary']['title']}' 가 6자 초과";
    }
    if (count($card['items']) < 2 || count($card['items']) > 10) {
        $errs[] = '아이템은 2~10개여야 한다 (현재 '.count($card['items']).')';
    }
    if ($errs) {
        foreach ($errs as $e) {
            fwrite(STDERR, "❌ {$code}: {$e}\n");
        }
        $fail++;

        continue;
    }

    echo "\n━━ {$code} ({$tpl['name']}) ━━\n";

    foreach (TENANTS as $t) {
        $src = DESKTOP."/{$t['dir']}/upload_erp_{$t['name']}_아이템리스트.xlsx";
        if (! file_exists($src)) {
            echo "  {$t['name']}: 승인본 없음 — skip ({$src})\n";

            continue;
        }

        $ss = IOFactory::load($src);
        $w = $ss->getActiveSheet();

        // 이 코드의 기존 행 찾기 (없으면 맨 뒤 다음 행)
        $row = null;
        $last = 5;
        for ($r = 6; $r <= 300; $r++) {
            $b = trim((string) $w->getCell('B'.$r)->getValue());
            if ($b === '') {
                continue;
            }
            $last = $r;
            if ($b === $code) {
                $row = $r;
            }
        }
        $isNew = $row === null;
        $row ??= $last + 1;
        $profile = trim((string) $w->getCell('A6')->getValue());

        // ── 값 구성 ───────────────────────────────────────────────
        $set = [
            'A' => $profile,
            'B' => $code,
            'C' => $tpl['name'],
            'D' => 'BA',                       // 아이템리스트형 메시지유형
            'E' => $tpl['body'],
            'H' => 'FALSE',
            'I' => trim((string) $w->getCell('I6')->getValue()) ?: '008002',
            'J' => '아이템리스트형',
            'N' => $card['header'],
            'O' => $card['highlight']['title'],
            'P' => $card['highlight']['description'],
        ];
        foreach ($card['items'] as $i => $it) {
            $c = itemCol($i);
            $set[$c] = $it['title'];
            $set[nextCol($c)] = $it['description'];
        }
        if (isset($card['summary'])) {
            $set['AL'] = $card['summary']['title'];
            $set['AM'] = $card['summary']['description'];
        }

        // 기존 행을 재사용할 때 **남는 아이템 칸을 비운다** — 항목이 줄면 옛 값이 그대로 남아
        // 등록본에 유령 줄이 생긴다(일일요약이 2줄→4줄로 늘고 요약칸이 사라진 이번 개편이 그 경우).
        for ($i = count($card['items']); $i < 10; $i++) {
            $c = itemCol($i);
            $set[$c] = null;
            $set[nextCol($c)] = null;
        }
        if (! isset($card['summary'])) {
            $set['AL'] = null;
            $set['AM'] = null;
        }

        if ($apply) {
            foreach ($set as $col => $v) {
                $w->getCell($col.$row)->setValue($v);
            }
            $out = DESKTOP."/{$t['dir']}/upload_erp_{$t['name']}_{$code}_신규.xlsx";
            (new Xlsx($ss))->save($out);
            echo "  ✅ {$t['name']}: 행 {$row} ".($isNew ? '신규' : '변경').' → '.basename($out)."\n";
        } else {
            echo "  · {$t['name']}: 행 {$row} ".($isNew ? '신규 추가' : '기존 변경')." (프로필 {$profile})\n";
        }
        $ss->disconnectWorksheets();
        unset($ss);
    }

    // 사람이 눈으로 확인할 카드 미리보기
    echo "\n  [카드 미리보기]\n";
    echo "    헤더    {$card['header']}\n";
    echo "    강조    {$card['highlight']['title']} / {$card['highlight']['description']}\n";
    foreach ($card['items'] as $it) {
        printf("    항목    %-8s %s\n", $it['title'], $it['description']);
    }
    if (isset($card['summary'])) {
        echo "    요약    {$card['summary']['title']} / {$card['summary']['description']}\n";
    }
    echo '    본문    '.str_replace("\n", ' ⏎ ', $tpl['body'])."\n";
}

if (! $apply) {
    echo "\n※ dry-run 이다. 실제 파일을 만들려면 --apply 를 붙일 것.\n";
}
exit($fail > 0 ? 1 : 0);
