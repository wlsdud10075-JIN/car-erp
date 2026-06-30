<?php

/**
 * Notion 개발 현황판 자동 생성 스크립트 (1회용)
 *
 * 사용법 (PowerShell):
 *   $env:NOTION_TOKEN="ntn_xxxxx"; $env:NOTION_PARENT_PAGE_ID="2a3f9c1b...";  php scripts/notion-setup.php
 *
 * 또는 아래 두 변수에 직접 값을 넣고 실행: php scripts/notion-setup.php
 *   ⚠️ 토큰을 직접 적으면 git에 커밋하지 마세요 (.gitignore 처리됨).
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$parent = getenv('NOTION_PARENT_PAGE_ID') ?: '여기에_부모페이지ID_붙여넣기';

$NOTION_VERSION = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

if (str_contains($token, '여기에_') || str_contains($parent, '여기에_')) {
    fwrite(STDERR, "❌ NOTION_TOKEN / NOTION_PARENT_PAGE_ID 를 먼저 설정하세요.\n");
    exit(1);
}

/** 공통 HTTP 호출 */
function notion(string $method, string $url, array $body, string $token, string $version): array
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
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
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

echo "▶ 1/2  개발 현황판 데이터베이스 생성 중...\n";

$dbBody = [
    'parent' => ['type' => 'page_id', 'page_id' => $parent],
    'title' => [['type' => 'text', 'text' => ['content' => '개발 현황판']]],
    'description' => [['type' => 'text', 'text' => ['content' => '진(개발) ↔ 대표 공유. 대표 요청은 요청자=대표 / 상태=요청됨 으로 카드 추가.']]],
    'properties' => [
        '제목' => ['title' => (object) []],
        '상태' => ['select' => ['options' => [
            ['name' => '요청됨', 'color' => 'gray'],
            ['name' => '검토중', 'color' => 'yellow'],
            ['name' => '진행중', 'color' => 'blue'],
            ['name' => '확인요청', 'color' => 'orange'],
            ['name' => '완료', 'color' => 'green'],
            ['name' => '보류', 'color' => 'red'],
        ]]],
        '요청자' => ['select' => ['options' => [
            ['name' => '진', 'color' => 'purple'],
            ['name' => '대표', 'color' => 'pink'],
        ]]],
        '우선순위' => ['select' => ['options' => [
            ['name' => '🔴 높음', 'color' => 'red'],
            ['name' => '🟡 보통', 'color' => 'yellow'],
            ['name' => '⚪ 낮음', 'color' => 'default'],
        ]]],
        '분류' => ['multi_select' => ['options' => [
            ['name' => '기능', 'color' => 'blue'],
            ['name' => '버그', 'color' => 'red'],
            ['name' => '운영·배포', 'color' => 'orange'],
            ['name' => '문서', 'color' => 'gray'],
            ['name' => '아이디어', 'color' => 'green'],
        ]]],
        '업데이트일' => ['last_edited_time' => (object) []],
    ],
];

$db = notion('POST', "$BASE/databases", $dbBody, $token, $NOTION_VERSION);
$dbId = $db['id'];
$dbUrl = $db['url'] ?? '(URL 없음)';
echo "  ✔ DB 생성됨: $dbUrl\n";

echo "▶ 2/2  예시 카드 생성 중...\n";

/** 카드(행) 1건 생성 헬퍼 */
function addCard(string $dbId, array $f, array $bodyLines, string $token, string $version, string $base): void
{
    $props = [
        '제목' => ['title' => [['text' => ['content' => $f['title']]]]],
    ];
    if (! empty($f['status'])) {
        $props['상태'] = ['select' => ['name' => $f['status']]];
    }
    if (! empty($f['by'])) {
        $props['요청자'] = ['select' => ['name' => $f['by']]];
    }
    if (! empty($f['priority'])) {
        $props['우선순위'] = ['select' => ['name' => $f['priority']]];
    }
    if (! empty($f['tags'])) {
        $props['분류'] = ['multi_select' => array_map(fn ($t) => ['name' => $t], $f['tags'])];
    }

    $children = array_map(fn ($line) => [
        'object' => 'block',
        'type' => 'paragraph',
        'paragraph' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $line]]]],
    ], $bodyLines);

    notion('POST', "$base/pages", [
        'parent' => ['database_id' => $dbId],
        'properties' => $props,
        'children' => $children,
    ], $token, $version, $base ?? '');
}

addCard($dbId, [
    'title' => '[예시] B/L 게이트 100% 완납 적용',
    'status' => '완료', 'by' => '진', 'priority' => '🟡 보통', 'tags' => ['기능'],
], ['무엇을 했는지 2~3줄 + 스크린샷/링크를 여기에 적습니다.', '대표가 확인 후 댓글로 피드백 → 완료 처리.'], $token, $NOTION_VERSION, $BASE);

addCard($dbId, [
    'title' => '[예시] 차량 목록에 수출신고번호 검색 추가해주세요',
    'status' => '요청됨', 'by' => '대표', 'priority' => '🔴 높음', 'tags' => ['기능'],
], ['대표가 요청 시 제목만 적고 우선순위만 표시하면 됩니다.', '진이 검토 후 진행중으로 옮깁니다.'], $token, $NOTION_VERSION, $BASE);

echo "  ✔ 예시 카드 2건 생성됨\n\n";
echo "✅ 완료!  아래 DB 링크로 들어가서 뷰 3개만 추가하면 끝입니다:\n   $dbUrl\n";
