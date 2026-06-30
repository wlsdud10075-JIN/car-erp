<?php

/**
 * "사내 업무 가이드 > 공통" 페이지 타깃 수정 (전체 교체 아님 — fill-in 템플릿 보존).
 *  #5  업무 절차 role 목록: "영업/재무/수출통관" → "영업/수출통관/재무/관리" (in-place PATCH)
 *  #2+#3  "🖥 사용 시스템" 헤딩 뒤에 car-erp 길잡이 callout 1개 삽입 (업무 가이드 사이드바 링크 + 언어 전환)
 *
 * 확인:  php scripts/notion-guide-common-patch.php
 * 적용:  php scripts/notion-guide-common-patch.php --apply
 */
$token = getenv('NOTION_TOKEN') ?: '';
$apply = in_array('--apply', $argv, true);
$V = '2022-06-28';
$BASE = 'https://api.notion.com/v1';
if (! str_starts_with($token, 'ntn_') && ! str_starts_with($token, 'secret_')) {
    fwrite(STDERR, "NOTION_TOKEN 미설정\n");
    exit(1);
}

function notion(string $m, string $url, array $body, string $t, string $v): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$t, 'Content-Type: application/json', 'Notion-Version: '.$v],
        CURLOPT_POSTFIELDS => $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : '{}',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res, true) ?? [];
    if ($code >= 300) {
        fwrite(STDERR, "❌ Notion API ($code): ".($j['message'] ?? $res)."\n");
        exit(1);
    }

    return $j;
}
function rtText(array $b, string $type): string
{
    $s = '';
    foreach ($b[$type]['rich_text'] ?? [] as $seg) {
        $s .= $seg['plain_text'] ?? ($seg['text']['content'] ?? '');
    }

    return $s;
}
function tx(string $t): array
{
    return [['type' => 'text', 'text' => ['content' => $t]]];
}

// 허브 → 공통
$ps = notion('POST', "$BASE/search", ['query' => '사내 업무 가이드', 'filter' => ['property' => 'object', 'value' => 'page']], $token, $V);
$hubId = null;
foreach ($ps['results'] as $p) {
    foreach (($p['properties'] ?? []) as $prop) {
        if (($prop['type'] ?? '') === 'title' && ($prop['title'][0]['plain_text'] ?? '') === '사내 업무 가이드') {
            $hubId = $p['id'];
            break 2;
        }
    }
}
if (! $hubId) {
    fwrite(STDERR, "허브 못 찾음\n");
    exit(1);
}

$commonId = null;
$r = notion('GET', "$BASE/blocks/$hubId/children?page_size=100", [], $token, $V);
foreach ($r['results'] as $b) {
    if (($b['type'] ?? '') === 'child_page' && ($b['child_page']['title'] ?? '') === '공통') {
        $commonId = $b['id'];
        break;
    }
}
if (! $commonId) {
    fwrite(STDERR, "공통 페이지 못 찾음\n");
    exit(1);
}
echo "공통 page id = $commonId\n";

// 공통 블록 스캔
$blocks = [];
$cursor = null;
do {
    $r = notion('GET', "$BASE/blocks/$commonId/children?page_size=100".($cursor ? "&start_cursor=$cursor" : ''), [], $token, $V);
    foreach ($r['results'] as $b) {
        $blocks[] = $b;
    }
    $cursor = $r['has_more'] ? $r['next_cursor'] : null;
} while ($cursor);

// #5 — role 목록 항목 찾기 (numbered_list_item 중 "발급" 포함)
$roleBlock = null;
$sysHeading = null;
$alreadyGuide = false;
foreach ($blocks as $b) {
    $t = $b['type'];
    if ($t === 'numbered_list_item' && str_contains(rtText($b, $t), '권한 발급')) {
        $roleBlock = $b;
    }
    if (in_array($t, ['heading_2', 'heading_3'], true) && str_contains(rtText($b, $t), '사용 시스템')) {
        $sysHeading = $b;
    }
    if ($t === 'callout' && str_contains(rtText($b, $t), '업무 가이드」 버튼')) {
        $alreadyGuide = true;
    }
}

// #5 적용
if ($roleBlock) {
    $old = rtText($roleBlock, 'numbered_list_item');
    $new = '계정/권한 발급받기 (관리자에게 요청 — 본인 role 영업/수출통관/재무/관리)';
    echo "\n#5 role 항목:\n   기존: $old\n   변경: $new\n";
    if ($apply && $old !== $new) {
        notion('PATCH', "$BASE/blocks/{$roleBlock['id']}", ['numbered_list_item' => ['rich_text' => tx($new)]], $token, $V);
        echo "   ✔ PATCH 완료\n";
    } elseif (! $apply) {
        echo "   (확인 모드 — 변경 예정)\n";
    }
} else {
    echo "\n#5 ⚠ role 항목 못 찾음 (수동 확인 필요)\n";
}

// #2+#3 callout
$guideCallout = ['object' => 'block', 'type' => 'callout', 'callout' => [
    'icon' => ['type' => 'emoji', 'emoji' => '📖'],
    'color' => 'blue_background',
    'rich_text' => tx('이 가이드는 car-erp 사이드바 맨 아래 「📖 업무 가이드」 버튼으로 언제든 다시 열 수 있습니다. · 화면 언어는 상단바 우측에서 한국어·English 전환 가능 (관리자가 영어를 켠 경우에만 표시).'),
]];

echo "\n#2+#3 car-erp 길잡이 callout:\n";
if ($alreadyGuide) {
    echo "   (이미 존재 — 중복 삽입 스킵)\n";
} elseif ($sysHeading) {
    echo "   '사용 시스템' 헤딩 뒤에 삽입 예정\n";
    if ($apply) {
        notion('PATCH', "$BASE/blocks/$commonId/children", ['children' => [$guideCallout], 'after' => $sysHeading['id']], $token, $V);
        echo "   ✔ 삽입 완료\n";
    }
} else {
    echo "   '사용 시스템' 헤딩 못 찾음 → 페이지 끝에 append 예정\n";
    if ($apply) {
        notion('PATCH', "$BASE/blocks/$commonId/children", ['children' => [$guideCallout]], $token, $V);
        echo "   ✔ append 완료\n";
    }
}

echo "\n".($apply ? "✅ 공통 패치 완료.\n" : "ℹ️ 확인만. 적용:  php scripts/notion-guide-common-patch.php --apply\n");
