<?php

/**
 * 자금보고(erp_capital_weekly) 재등록 xlsx — 손익을 요약정보 → 아이템 줄로 옮긴 등록본을 **코드에서** 만든다 (jin 2026-10-13).
 *
 * 배경: 카카오 요약정보 description 은 숫자·통화기호·통화단위·쉼표·마침표만 허용 — 손실(음수)을 표기할 길이 없다
 *   (2026-10-13 ssancarerp 실측 7종 전부 K140: '-'·'−'·'△'·'▲'·'( )'·'₩-'·'ㅡ'). 아이템 줄은 '-5.99억' 이 통과한다.
 *   그래서 `AlimtalkTemplates::ITEMLIST['erp_capital_weekly']` 에서 summary 를 없애고 items 5번째로 넣었다.
 *   이 스크립트는 그 단일 출처로 3사 등록본을 다시 만든다 — 손으로 치면 띄어쓰기가 어긋나 반려된다(§8 #40).
 *
 * 사용:
 *   php scripts/alimtalk-capital-weekly-xlsx.php            # dry-run — 만들 행을 찍기만 한다
 *   php scripts/alimtalk-capital-weekly-xlsx.php --apply    # 3사 폴더에 upload_erp_{회사}_자금보고_손익아이템_재등록.xlsx 저장
 *   php scripts/alimtalk-capital-weekly-xlsx.php --verify   # 저장된 파일과 코드를 셀 단위로 대조
 *
 * 형식 = 각 회사 폴더의 종전 자금보고 등록본(헤이맨·싼카 `자금보고_재등록.xlsx`, 카라바는 `아이템리스트.xlsx`)의
 *   머리 5행을 그대로 쓰고 6행에 1행을 채운다(대표링크 4종 스크립트와 같은 방식). ⚠️ 원본 파일은 손대지 않는다.
 * 템플릿명·버튼은 **현재 등록본과 같게** — 같은 코드로 수정 승인을 받는 것이라 이름이 바뀌면 다른 템플릿이 된다.
 *   버튼 = 웹링크 「자금 보고 보기」 `{도메인}/a/capital/#{url}` (발송 코드 AlimtalkCapitalWeekly 가 서명 링크로 채운다).
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
$code = 'erp_capital_weekly';

// 회사별 발신프로필·도메인·현재 등록본의 템플릿명·머리글을 빌릴 원본 파일.
$companies = [
    '헤이맨' => ['profile' => '@heyman_con', 'domain' => 'https://heysellcar.com', 'dir' => '헤이맨확정알림톡', 'name' => '자금보고', 'base' => 'upload_erp_헤이맨_자금보고_재등록.xlsx'],
    '싼카' => ['profile' => '@site_condition', 'domain' => 'https://heymancar.com', 'dir' => '싼카확정알림톡', 'name' => '자금보고', 'base' => 'upload_erp_싼카_자금보고_재등록.xlsx'],
    '카라바' => ['profile' => '@주식회사카라바', 'domain' => 'https://karaba-erp.com', 'dir' => '카라바확정알림톡', 'name' => '주간자금보고', 'base' => 'upload_erp_카라바_아이템리스트.xlsx'],
];

// 열 배치(1-based) — 등록본 4·5행 머리글 실측(대표링크 4종 스크립트와 동일).
$col = [
    'profile' => 1, 'code' => 2, 'name' => 3, 'type' => 4, 'body' => 5, 'secure' => 8, 'category' => 9, 'emphasis' => 10,
    'header' => 14, 'hl_title' => 15, 'hl_desc' => 16, 'item1' => 18, 'sum_title' => 38, 'sum_desc' => 39,
    'btn_type' => 40, 'btn_name' => 41, 'btn_mobile' => 42, 'btn_pc' => 43,
];

$rowFor = function (array $co) use ($col, $code): array {
    $t = AlimtalkTemplates::TEMPLATES[$code];
    $card = AlimtalkTemplates::ITEMLIST[$code];
    if (isset($card['summary'])) {
        throw new RuntimeException('erp_capital_weekly 카드에 summary 가 남아 있다 — 이 재등록의 목적과 어긋난다');
    }
    $url = $co['domain'].'/a/capital/#{url}';
    $cells = [
        $col['profile'] => $co['profile'], $col['code'] => $code, $col['name'] => $co['name'], $col['type'] => 'BA',
        $col['body'] => $t['body'], $col['secure'] => 'False', $col['category'] => '008002', $col['emphasis'] => '아이템리스트형',
        $col['header'] => $card['header'], $col['hl_title'] => $card['highlight']['title'], $col['hl_desc'] => $card['highlight']['description'],
        $col['btn_type'] => '웹링크', $col['btn_name'] => '자금 보고 보기', $col['btn_mobile'] => $url, $col['btn_pc'] => $url,
    ];
    foreach (array_values($card['items']) as $i => $it) {
        $cells[$col['item1'] + $i * 2] = $it['title'];
        $cells[$col['item1'] + $i * 2 + 1] = $it['description'];
    }

    return $cells;
};

$bad = 0;
foreach ($companies as $name => $co) {
    $src = "{$root}/{$co['dir']}/{$co['base']}";
    $out = "{$root}/{$co['dir']}/upload_erp_{$name}_자금보고_손익아이템_재등록.xlsx";
    echo "── {$name} ({$co['profile']} · {$co['domain']} · 템플릿명 {$co['name']})\n";
    $want = $rowFor($co);

    if ($verify) {
        if (! is_file($out)) {
            echo "   ❌ 없음: {$out}\n";
            $bad++;

            continue;
        }
        $ws = IOFactory::load($out)->getActiveSheet();
        foreach ($want as $c => $v) {
            $got = (string) $ws->getCellByColumnAndRow($c, 6)->getValue();
            if ($got !== (string) $v) {
                $bad++;
                echo "   ❌ 행6 열{$c}: 파일=".json_encode($got, JSON_UNESCAPED_UNICODE).' / 코드='.json_encode($v, JSON_UNESCAPED_UNICODE)."\n";
            }
        }
        for ($c = 1; $c <= 50; $c++) {
            $got = (string) $ws->getCellByColumnAndRow($c, 6)->getValue();
            if ($got !== '' && ! array_key_exists($c, $want)) {
                $bad++;
                echo "   ❌ 행6 열{$c}: 코드에 없는 값 ".json_encode($got, JSON_UNESCAPED_UNICODE)." (요약정보 잔재?)\n";
            }
        }
        if ((string) $ws->getCellByColumnAndRow(2, 7)->getValue() !== '') {
            $bad++;
            echo "   ❌ 7행에 잔재 — 이 파일은 1행(자금보고)만 담는다\n";
        }
        echo $bad === 0 ? "   ✅ 코드와 일치 (아이템 5줄 · 요약정보 없음 · 버튼 1)\n" : '';

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
        $ws->removeRow(6, $last - 5);
    }
    foreach ($want as $c => $v) {
        $ws->setCellValueByColumnAndRow($c, 6, $v);
    }
    $items = array_map(fn ($it) => $it['title'].'='.$it['description'], AlimtalkTemplates::ITEMLIST[$code]['items']);
    echo '   행6 '.$code.' · 아이템 '.implode(' / ', $items)." · 버튼 자금 보고 보기 → {$co['domain']}/a/capital/#{url}\n";
    if ($apply) {
        (new Xlsx($ss))->save($out);
        echo "   ✅ 저장: {$out}\n";
    }
}

if (! $apply && ! $verify) {
    echo "\n(dry-run — --apply 로 저장, --verify 로 대조)\n";
}
exit($bad === 0 ? 0 : 1);
