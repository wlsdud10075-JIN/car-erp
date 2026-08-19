<?php

/**
 * Notion 개발 현황판에 2026-08-19 개발예정(보류) 항목을 추가한다.
 *
 * 확인: php scripts/notion-dev-status-2026-08-19.php
 * 적용: php scripts/notion-dev-status-2026-08-19.php --apply
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$apply = in_array('--apply', $argv, true);
$DB_NAME = '개발 현황판';
$NOTION_VERSION = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

if (str_contains($token, '여기에')) {
    fwrite(STDERR, "❌ NOTION_TOKEN 설정 필요\n");
    exit(1);
}

function notion(string $method, string $url, array $body, string $token, string $version): array
{
    $res = false;
    $code = 0;
    $err = '';
    $json = [];
    for ($try = 1; $try <= 4; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Content-Type: application/json',
                'Notion-Version: '.$version,
            ],
            CURLOPT_POSTFIELDS => $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : '{}',
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string) $res, true) ?? [];
        if ($res !== false && $code > 0 && ! in_array($code, [409, 429, 500, 502, 503, 504], true)) {
            break;
        }
        usleep(400000 * $try);
    }
    if ($res === false || $code === 0) {
        fwrite(STDERR, '❌ Notion API 연결 실패: '.($err ?: '응답 없음')."\n");
        exit(1);
    }
    if ($code >= 300) {
        fwrite(STDERR, "❌ Notion API 오류 ($code): ".($json['message'] ?? $res)."\n");
        exit(1);
    }

    return $json;
}

function tx(string $text): array
{
    return [['type' => 'text', 'text' => ['content' => $text]]];
}

function para(string $text): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => tx($text)]];
}

function bul(string $text): array
{
    return ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx($text)]];
}

function h2(string $text): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx($text)]];
}

function item(string $status, string $title, string $priority, array $tags, array $body): array
{
    return compact('status', 'title', 'priority', 'tags', 'body') + ['by' => '진'];
}

$items = [
    item('보류', '선박·컨테이너 위치 조회 (개발예정)', '🟢 낮음', ['아이디어'], [
        h2('무엇을 하려는 것인가'),
        bul('차량에 적힌 선박명이나 컨테이너번호를 눌러 「지금 어디쯤이고 언제 도착 예정인지」를 바로 확인하는 기능입니다.'),
        bul('지금은 선적일과 도착예정일을 사람이 적어 둔 값만 보이고, 실제 배가 어디 있는지는 ERP에서 알 수 없습니다.'),
        h2('검토 결과 — 선박은 가능, 컨테이너는 부실'),
        bul('선박: 실제로 확인했습니다. 자동차운반선 GMT ASTRO 기준 현재 위치(동지중해)·목적지(싱가포르)·도착예정(8/23)·직전 기항지까지 무료로 조회됩니다.'),
        bul('운영 선박 13척의 식별번호(IMO)를 미리 확보해 두었습니다. 같은 배를 MV HAE SHIN V.2608 · SCORPIUS 633E 처럼 제각각 적어도 한 척으로 알아보게 만드는 규칙도 검증을 마쳤습니다.'),
        bul('컨테이너: 조회 품질이 떨어집니다. 우리 컨테이너의 41%가 임대 장비라 번호만으로는 어느 선사인지 알 수 없고, 선사 공식 페이지는 외부 접근을 막고 있으며, 반납된 컨테이너는 이력이 사라져 지난 선적은 조회되지 않습니다.'),
        h2('보류 사유'),
        bul('선박 쪽 품질은 확인했으나 컨테이너까지 함께 쓰기에는 부족하다고 판단해 도입을 미뤘습니다. 2026-08-19 시험 구현분은 전량 되돌렸고 운영 시스템에는 반영하지 않았습니다.'),
        bul('현장에서 「이 배 어디쯤이냐」는 문의가 실제로 반복되면 그때 착수합니다. 조사와 검증은 이미 끝나 있어 재개 시 빠르게 붙일 수 있습니다.'),
        h2('곁다리로 정리한 것'),
        bul('선박명 칸에 컨테이너번호가 잘못 입력돼 있던 차량 26대를 찾아 제자리로 옮겼습니다(차량관리 검색·목록 표시가 어긋나던 문제).'),
        para('상태: 개발예정(보류) · 운영 반영 없음'),
    ]),
];

echo "▶ 데이터베이스 검색 중...\n";
$search = notion('POST', "$BASE/search", ['filter' => ['property' => 'object', 'value' => 'database']], $token, $NOTION_VERSION);
$target = null;
foreach ($search['results'] as $result) {
    if (($result['title'][0]['plain_text'] ?? '') === $DB_NAME) {
        $target = $result;
        break;
    }
}
if (! $target) {
    fwrite(STDERR, "❌ '$DB_NAME' DB를 찾지 못했습니다.\n");
    exit(1);
}
$dbId = $target['id'];
echo "   ✔ [$DB_NAME] id=$dbId 찾음\n";

$existingTitles = [];
$cursor = null;
do {
    $query = ['page_size' => 100];
    if ($cursor) {
        $query['start_cursor'] = $cursor;
    }
    $existing = notion('POST', "$BASE/databases/$dbId/query", $query, $token, $NOTION_VERSION);
    foreach ($existing['results'] as $page) {
        $existingTitles[] = $page['properties']['제목']['title'][0]['plain_text'] ?? '';
    }
    $cursor = ($existing['has_more'] ?? false) ? ($existing['next_cursor'] ?? null) : null;
} while ($cursor);

echo '▶ 기존 카드 '.count($existingTitles)."건 (제목 중복 시 skip)\n";
echo $apply ? "▶ 카드 삽입 시작...\n" : "▶ [확인 모드] 추가될 항목 미리보기:\n";
$added = 0;
$skipped = 0;
foreach ($items as $item) {
    if (in_array($item['title'], $existingTitles, true)) {
        echo "   ⏭  (이미 존재) {$item['title']}\n";
        $skipped++;
        continue;
    }
    if (! $apply) {
        echo "   +  [{$item['status']} / {$item['priority']}] {$item['title']}\n";
        continue;
    }
    notion('POST', "$BASE/pages", [
        'parent' => ['database_id' => $dbId],
        'properties' => [
            '제목' => ['title' => [['text' => ['content' => $item['title']]]]],
            '상태' => ['select' => ['name' => $item['status']]],
            '대표' => ['select' => ['name' => $item['by']]],
            '우선순위' => ['select' => ['name' => $item['priority']]],
            '분류' => ['multi_select' => array_map(fn ($tag) => ['name' => $tag], $item['tags'])],
        ],
        'children' => $item['body'],
    ], $token, $NOTION_VERSION);
    echo "   ✔  {$item['title']}\n";
    $added++;
}

echo $apply
    ? "✅ 삽입 완료: 추가 $added 건 / 중복 건너뜀 $skipped 건\n"
    : "ℹ️ 확인만 했습니다. 실제로 넣으려면 --apply\n";
