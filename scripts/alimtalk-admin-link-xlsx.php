<?php

/**
 * 대표 알림톡 링크 후속본 4종(`erp_*_v2`) — BizM 업로드 xlsx 를 **코드에서** 만든다 (jin 2026-09-22/23).
 *
 * jin: «재등록은 엑셀파일로 경로에 맞게 올려주면 내가 승인요청 하겠음».
 * 문구는 `AlimtalkTemplates::TEMPLATES`(본문·버튼) + `ITEMLIST`(카드, 구 코드 공유)가 단일 출처다 —
 * 손으로 다시 치면 드리프트가 나서 발송이 전량 반려된다(2026-07-30 자금보고 실사고).
 *
 * 사용:
 *   php scripts/alimtalk-admin-link-xlsx.php            # dry-run — 만들 행을 찍기만 한다
 *   php scripts/alimtalk-admin-link-xlsx.php --apply    # 회사 3곳 폴더에 upload_erp_{회사}_대표링크4종_신규.xlsx 저장
 *   php scripts/alimtalk-admin-link-xlsx.php --verify   # 저장된 파일과 코드를 셀 단위로 대조
 *
 * 형식 = 각 회사 폴더의 `upload_erp_{회사}_아이템리스트_신규.xlsx`(08-20 접수본) 머리 5행을 그대로 쓰고
 * 6행부터 4행을 채운다. ⚠️ 접수본 파일 자체는 손대지 않는다(`_2`·`_신규` 는 jin 이 이미 낸 것).
 * 버튼 URL 은 회사 도메인 리터럴 + path — 발송 코드(`AlimtalkTemplates::linkButtons`, APP_URL + path)와
 * 글자 단위로 같아야 한다(K108). `--verify` 가 그 대조까지 한다.
 */

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$verify = in_array('--verify', $argv, true);

$root = getenv('ALIMTALK_XLSX_ROOT') ?: 'C:/Users/User/Desktop/알림톡';
$companies = [
    '헤이맨' => ['profile' => '@heyman_con', 'domain' => 'https://heysellcar.com', 'dir' => '헤이맨확정알림톡'],
    '싼카' => ['profile' => '@site_condition', 'domain' => 'https://heymancar.com', 'dir' => '싼카확정알림톡'],
    '카라바' => ['profile' => '@주식회사카라바', 'domain' => 'https://karaba-erp.com', 'dir' => '카라바확정알림톡'],
];
$codes = array_keys(AlimtalkTemplates::SUCCESSOR_OF);

// 열 배치(1-based) — 접수본 4·5행 머리글 실측: A 프로필 · B 코드 · C 명 · D 유형 · E 본문 · H 보안 · I 카테고리 · J 강조유형
//   N 헤더 · O/P 하이라이트(타이틀/디스크립션) · R~ 아이템(명/내용 쌍, 최대 10) · AL/AM 요약(명/내용) · AN~AQ 버튼1(타입/명/모바일/PC)
$col = [
    'profile' => 1, 'code' => 2, 'name' => 3, 'type' => 4, 'body' => 5, 'secure' => 8, 'category' => 9, 'emphasis' => 10,
    'header' => 14, 'hl_title' => 15, 'hl_desc' => 16, 'item1' => 18, 'sum_title' => 38, 'sum_desc' => 39,
    'btn_type' => 40, 'btn_name' => 41, 'btn_mobile' => 42, 'btn_pc' => 43,
];

/** 코드가 정한 한 행의 셀 값(열 번호 => 값). */
$rowFor = function (string $code, array $co) use ($col): array {
    $t = AlimtalkTemplates::TEMPLATES[$code];
    $card = AlimtalkTemplates::ITEMLIST[AlimtalkTemplates::baseCode($code)];
    $buttons = AlimtalkTemplates::linkButtons($code, $co['domain']);
    if (count($buttons) !== 1) {
        throw new RuntimeException("{$code}: 버튼이 1개여야 한다");
    }
    $cells = [
        $col['profile'] => $co['profile'], $col['code'] => $code, $col['name'] => $t['name'], $col['type'] => 'BA',
        $col['body'] => $t['body'], $col['secure'] => 'False', $col['category'] => '008002', $col['emphasis'] => '아이템리스트형',
        $col['header'] => $card['header'], $col['hl_title'] => $card['highlight']['title'], $col['hl_desc'] => $card['highlight']['description'],
        $col['btn_type'] => '웹링크', $col['btn_name'] => $buttons[0]['name'], $col['btn_mobile'] => $buttons[0]['url'], $col['btn_pc'] => $buttons[0]['url'],
    ];
    foreach (array_values($card['items']) as $i => $it) {
        $cells[$col['item1'] + $i * 2] = $it['title'];
        $cells[$col['item1'] + $i * 2 + 1] = $it['description'];
    }
    if (isset($card['summary'])) {
        $cells[$col['sum_title']] = $card['summary']['title'];
        $cells[$col['sum_desc']] = $card['summary']['description'];
    }

    return $cells;
};

$bad = 0;
foreach ($companies as $name => $co) {
    $src = "{$root}/{$co['dir']}/upload_erp_{$name}_아이템리스트_신규.xlsx";
    $out = "{$root}/{$co['dir']}/upload_erp_{$name}_대표링크4종_신규.xlsx";
    echo "── {$name} ({$co['profile']} · {$co['domain']})\n";

    if ($verify) {
        if (! is_file($out)) {
            echo "   ❌ 없음: {$out}\n";
            $bad++;

            continue;
        }
        $ws = IOFactory::load($out)->getActiveSheet();
        foreach ($codes as $i => $code) {
            $r = 6 + $i;
            foreach ($rowFor($code, $co) as $c => $want) {
                $got = (string) $ws->getCellByColumnAndRow($c, $r)->getValue();
                if ($got !== (string) $want) {
                    $bad++;
                    echo "   ❌ {$code} 행{$r} 열{$c}: 파일=".json_encode($got, JSON_UNESCAPED_UNICODE).' / 코드='.json_encode($want, JSON_UNESCAPED_UNICODE)."\n";
                }
            }
            // 파일에만 있는 칸(코드가 안 적는 열에 값이 남아 있으면 옛 행 잔재)
            for ($c = 1; $c <= 50; $c++) {
                $got = (string) $ws->getCellByColumnAndRow($c, $r)->getValue();
                if ($got !== '' && ! array_key_exists($c, $rowFor($code, $co))) {
                    $bad++;
                    echo "   ❌ {$code} 행{$r} 열{$c}: 코드에 없는 값 ".json_encode($got, JSON_UNESCAPED_UNICODE)."\n";
                }
            }
        }
        $extra = (string) $ws->getCellByColumnAndRow(2, 6 + count($codes))->getValue();
        if ($extra !== '') {
            $bad++;
            echo "   ❌ 5번째 데이터 행에 잔재: {$extra}\n";
        }
        echo $bad === 0 ? "   ✅ 4행 전부 코드와 일치\n" : '';

        continue;
    }

    if (! is_file($src)) {
        echo "   ❌ 원본 형식 파일 없음: {$src}\n";
        $bad++;

        continue;
    }
    $ss = IOFactory::load($src);
    $ws = $ss->getActiveSheet();
    $last = $ws->getHighestRow();
    if ($last > 5) {
        $ws->removeRow(6, $last - 5);   // 접수본의 데이터 행을 비우고 머리 5행만 남긴다
    }
    foreach ($codes as $i => $code) {
        $r = 6 + $i;
        foreach ($rowFor($code, $co) as $c => $v) {
            $ws->setCellValueByColumnAndRow($c, $r, $v);
        }
        $b = AlimtalkTemplates::linkButtons($code, $co['domain'])[0];
        echo "   행{$r} {$code} · {$b['name']} → {$b['url']}\n";
    }
    if ($apply) {
        (new Xlsx($ss))->save($out);
        echo "   ✅ 저장: {$out}\n";
    }
}

if (! $apply && ! $verify) {
    echo "\n(dry-run — --apply 로 저장, --verify 로 대조)\n";
}
exit($bad === 0 ? 0 : 1);
