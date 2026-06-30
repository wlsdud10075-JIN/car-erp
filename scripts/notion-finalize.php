<?php

/**
 * Notion 개발 현황판 — 페이지 꾸미기 전용 (카드는 일절 건드리지 않음)
 *
 * 확인(dry-run):  $env:NOTION_TOKEN="ntn_xxx"; php scripts/notion-finalize.php
 * 실제 적용:      $env:NOTION_TOKEN="ntn_xxx"; php scripts/notion-finalize.php --apply
 *
 * 하는 일:
 *   1) '개발 현황판' DB 자동 탐색 + 그 DB가 들어있는 부모 페이지 id 추출
 *   2) 부모 페이지: 아이콘 🚗 + 그라데이션 커버 설정
 *   3) 부모 페이지: 사용법 / 지금집중 / 상태범례 콜아웃 추가 (중복 시 skip)
 *   ※ DB 카드(완료·예정·요청)는 읽지도 쓰지도 않음 — 전부 그대로 보존
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$apply = in_array('--apply', $argv, true);
$DB_NAME = '개발 현황판';
$COVER = 'https://www.notion.so/images/page-cover/gradients_8.png';
$NOTION_VERSION = '2022-06-28';
$BASE = 'https://api.notion.com/v1';
$DECOR_MARKER = '이 페이지 사용법';

if (str_contains($token, '여기에_')) {
    fwrite(STDERR, "❌ NOTION_TOKEN 를 먼저 설정하세요.\n");
    exit(1);
}

function notion(string $method, string $url, ?array $body, string $token, string $version): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Notion-Version: '.$version,
        ],
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res, true) ?? [];
    if ($code >= 300) {
        fwrite(STDERR, "❌ Notion API 오류 ($code): ".($json['message'] ?? $res)."\n");
        exit(1);
    }

    return $json;
}

// ── DB + 부모 페이지 탐색 ────────────────────────────────────
echo "▶ DB 탐색...\n";
$search = notion('POST', "$BASE/search", ['filter' => ['property' => 'object', 'value' => 'database']], $token, $NOTION_VERSION);
$target = null;
foreach ($search['results'] as $r) {
    if (($r['title'][0]['plain_text'] ?? '') === $DB_NAME) {
        $target = $r;
    }
}
if (! $target) {
    fwrite(STDERR, "❌ '$DB_NAME' DB를 못 찾았습니다.\n");
    exit(1);
}
$pageId = ($target['parent']['type'] ?? '') === 'page_id' ? $target['parent']['page_id'] : null;
if (! $pageId) {
    fwrite(STDERR, "❌ 부모가 페이지가 아니라 꾸미기 대상이 없습니다.\n");
    exit(1);
}
echo "   ✔ 부모 페이지 id=$pageId\n";

// ── 꾸미기 블록 정의 ─────────────────────────────────────────
$txt = fn (string $c, ?string $color = null) => $color
    ? ['type' => 'text', 'text' => ['content' => $c], 'annotations' => ['color' => $color]]
    : ['type' => 'text', 'text' => ['content' => $c]];
$callout = fn (string $emoji, string $color, array $rich) => [
    'object' => 'block', 'type' => 'callout',
    'callout' => ['rich_text' => $rich, 'icon' => ['type' => 'emoji', 'emoji' => $emoji], 'color' => $color],
];
$bullet = fn (array $rich) => ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => $rich]];

$decorBlocks = [
    ['object' => 'block', 'type' => 'divider', 'divider' => (object) []],
    $callout('📖', 'blue_background', [
        $txt('이 페이지 사용법 — ', 'blue'),
        $txt('대표님은 ‘대표 요청함’ 뷰에서 카드를 추가해주세요(제목만 적어도 OK). 진(개발자)이 검토 후 진행중→확인요청→완료로 옮깁니다.'),
    ]),
    $callout('🚀', 'yellow_background', [
        $txt('지금 집중 중: ', 'orange'),
        $txt('운영 기능 안정화 검증 + 도메인/HTTPS 전환 준비'),
    ]),
    ['object' => 'block', 'type' => 'heading_3', 'heading_3' => ['rich_text' => [$txt('🎨 상태 범례')]]],
    $bullet([$txt('요청됨', 'gray'), $txt(' — 백로그(아직 시작 전)')]),
    $bullet([$txt('검토중', 'yellow'), $txt(' — 진행 여부·범위 검토 중')]),
    $bullet([$txt('진행중', 'blue'), $txt(' — 개발 진행 중')]),
    $bullet([$txt('확인요청', 'orange'), $txt(' — 작업 완료, 대표 확인 대기')]),
    $bullet([$txt('완료', 'green'), $txt(' — 배포·반영 완료')]),
    $bullet([$txt('보류', 'red'), $txt(' — 추후로 미룸')]),
];

// ── dry-run ──────────────────────────────────────────────────
if (! $apply) {
    echo "\n[확인 모드] 실제 변경 없음. (DB 카드는 건드리지 않음)\n";
    echo "  • 아이콘 🚗 + 그라데이션 커버 설정\n";
    echo '  • 안내/지금집중/범례 콜아웃 '.count($decorBlocks)."블록 추가\n";
    echo "\nℹ️  적용하려면:  php scripts/notion-finalize.php --apply\n";
    exit(0);
}

// ── 적용 ─────────────────────────────────────────────────────
echo "\n▶ 아이콘 + 커버 설정...\n";
notion('PATCH', "$BASE/pages/$pageId", [
    'icon' => ['type' => 'emoji', 'emoji' => '🚗'],
    'cover' => ['type' => 'external', 'external' => ['url' => $COVER]],
], $token, $NOTION_VERSION);
echo "   ✔ 완료\n";

echo "▶ 콜아웃 추가...\n";
$children = notion('GET', "$BASE/blocks/$pageId/children?page_size=100", null, $token, $NOTION_VERSION);
$hasDecor = false;
foreach ($children['results'] as $b) {
    if (str_contains($b['callout']['rich_text'][0]['plain_text'] ?? '', $DECOR_MARKER)) {
        $hasDecor = true;
    }
}
if ($hasDecor) {
    echo "   ⏭ 이미 존재 → skip\n";
} else {
    notion('PATCH', "$BASE/blocks/$pageId/children", ['children' => $decorBlocks], $token, $NOTION_VERSION);
    echo "   ✔ 사용법/지금집중/범례 추가\n";
}

echo "\n✅ 꾸미기 완료! (DB 카드는 그대로 보존됨. 콜아웃을 표 위로 올리려면 블록 ⋮⋮ 드래그)\n";
