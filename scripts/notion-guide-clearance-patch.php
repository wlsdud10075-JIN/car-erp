<?php

/**
 * ⚠️ 은퇴(2026-06-30) — 이 알람 섹션은 이제 notion-guide-publish.php 의 blocks_clearance() 가
 *    단일 출처로 관리합니다(전체 교체 발행에 포함). 다시 실행하지 마세요 — publish 후 재실행하면
 *    "통관서류 알람" 헤딩이 중복 삽입됩니다(멱등 가드는 정확히 일치하는 헤딩명에만 동작). 기록용 보존.
 *
 * "사내 업무 가이드 > 수출통관" 페이지에 ETA 통관서류 알람 섹션을 in-place 삽입.
 *  - 전체 교체 아님 (running-log 보존). 인트로 callout 뒤에 PATCH children + after 로 삽입.
 *  - 멱등: 이미 "통관서류 알람" 헤딩 있으면 스킵.
 *
 * 확인:  php scripts/notion-guide-clearance-patch.php
 * 적용:  php scripts/notion-guide-clearance-patch.php --apply
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

// 허브 → 수출통관
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

$pageId = null;
$r = notion('GET', "$BASE/blocks/$hubId/children?page_size=100", [], $token, $V);
foreach ($r['results'] as $b) {
    if (($b['type'] ?? '') === 'child_page' && ($b['child_page']['title'] ?? '') === '수출통관') {
        $pageId = $b['id'];
        break;
    }
}
if (! $pageId) {
    fwrite(STDERR, "수출통관 페이지 못 찾음\n");
    exit(1);
}
echo "수출통관 page id = $pageId\n";

// 블록 스캔 — 멱등 체크 + 앵커(인트로 callout) 찾기
$blocks = [];
$cursor = null;
do {
    $r = notion('GET', "$BASE/blocks/$pageId/children?page_size=100".($cursor ? "&start_cursor=$cursor" : ''), [], $token, $V);
    foreach ($r['results'] as $b) {
        $blocks[] = $b;
    }
    $cursor = $r['has_more'] ? $r['next_cursor'] : null;
} while ($cursor);

$already = false;
$anchorId = null;
foreach ($blocks as $b) {
    $t = $b['type'];
    if (in_array($t, ['heading_2', 'heading_3'], true) && str_contains(rtText($b, $t), '통관서류 알람')) {
        $already = true;
    }
    if ($anchorId === null && $t === 'callout' && str_contains(rtText($b, $t), '반입(선적)')) {
        $anchorId = $b['id'];   // 인트로 callout — 이 뒤에 삽입
    }
}

if ($already) {
    echo "ℹ️ 이미 '통관서류 알람' 섹션 존재 — 스킵.\n";
    exit(0);
}
if (! $anchorId) {
    fwrite(STDERR, "앵커(인트로 callout) 못 찾음 — 수동 확인 필요\n");
    exit(1);
}

// 삽입할 블록 (인트로 callout 뒤)
$children = [
    ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx('🔔 통관서류 알람 (2026-06-18 신규)')]],
    ['object' => 'block', 'type' => 'callout', 'callout' => [
        'icon' => ['type' => 'emoji', 'emoji' => '🔔'],
        'color' => 'blue_background',
        'rich_text' => tx('도착(ETA) 10일 전 수출 차량이 사이드바 「알림」 + 화면 우하단 카드에 떠요. 통관서류를 미리 준비하세요. (기본 10일, 시스템관리자가 조정 가능)'),
    ]],
    ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx('카드/알림함 클릭 → 해당 차량 통관탭으로 이동. [확인] = "봤음" 표시. 수출신고서를 올리면 그 알람은 자동으로 사라집니다.')]],
    ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx('✕로 닫으면 새 알람이 올 때까지 카드가 접혀 있고(벨 숫자만), 페이지를 옮겨도 다시 안 뜹니다.')]],
    ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx('도착일(ETA)이 없는 차량은 알림함의 「데이터 보정」에서 날짜를 바로 입력하세요 → 채우면 도착 10일 전 알람이 자동 예약됩니다.')]],
    ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx('이 알람은 수출통관·관리·시스템관리자에게만 보입니다. 시스템관리자가 기능설정에서 켜야 작동합니다.')]],
    ['object' => 'block', 'type' => 'divider', 'divider' => new stdClass],
];

echo "삽입 위치: 인트로 callout 뒤 (anchor $anchorId)\n";
echo '삽입 블록 수: '.count($children)." (heading + callout + 4 bullets + divider)\n";

if (! $apply) {
    echo "\nℹ️ 확인만. 적용:  php scripts/notion-guide-clearance-patch.php --apply\n";
    exit(0);
}

notion('PATCH', "$BASE/blocks/$pageId/children", ['children' => $children, 'after' => $anchorId], $token, $V);
echo "✅ 수출통관 가이드에 ETA 통관서류 알람 섹션 삽입 완료 (running-log 보존).\n";
