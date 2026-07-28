<?php

/**
 * Notion 개발 현황에 2026-07-21 ERP 완료 항목을 추가한다.
 *
 * 확인:  php scripts/notion-dev-status-2026-07-21.php
 * 적용:  php scripts/notion-dev-status-2026-07-21.php --apply
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

function tx(string $t): array
{
    return [['type' => 'text', 'text' => ['content' => $t]]];
}

function para(string $t): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => tx($t)]];
}

function bul(string $t): array
{
    return ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx($t)]];
}

function h2(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx($t)]];
}

function doneItem(string $title, string $priority, array $tags, array $body): array
{
    return [
        'title' => $title,
        'status' => '완료',
        'by' => '진',
        'priority' => $priority,
        'tags' => $tags,
        'body' => $body,
    ];
}

echo "▶ 데이터베이스 검색 중...\n";
$search = notion('POST', "$BASE/search", [
    'filter' => ['property' => 'object', 'value' => 'database'],
], $token, $NOTION_VERSION);

$target = null;
$seenTitles = [];
foreach ($search['results'] as $r) {
    $title = $r['title'][0]['plain_text'] ?? '(제목없음)';
    $seenTitles[] = $title;
    if ($title === $DB_NAME) {
        $target = $r;
        echo "   ✔ [$title] id={$r['id']} 찾음\n";
        break;
    }
}
if (! $target) {
    echo '   검색된 DB: '.($seenTitles ? implode(', ', $seenTitles) : '(없음)')."\n";
    fwrite(STDERR, "❌ '$DB_NAME' DB를 찾지 못했습니다. integration 공유를 확인하세요.\n");
    exit(1);
}
$dbId = $target['id'];

$existing = notion('POST', "$BASE/databases/$dbId/query", ['page_size' => 100], $token, $NOTION_VERSION);
$existingTitles = [];
foreach ($existing['results'] as $p) {
    $existingTitles[] = $p['properties']['제목']['title'][0]['plain_text'] ?? '';
}
echo '▶ 기존 카드 '.count($existingTitles)."건 (제목 중복 시 skip)\n";

$items = [
    doneItem('ERP 보증금 매입 선지급(C2) 승인·재무확정 흐름', '🔴 높음', ['기능'], [
        h2('반영 내용'),
        bul('같은 바이어의 선적전 차량 보증금을 다른 차량 매입대금에 선지급하는 C2 흐름을 구현했습니다.'),
        bul('매입 탭에는 「보증금 매입 선지급」 버튼과 「보증금 사용 가능 금액」 라벨이 표시됩니다.'),
        bul('관리 승인 대기 → 재무 확정 대기 순서로 진행하고, 기안자 본인 승인 차단과 void 차단을 적용했습니다.'),
        bul('진행 중인 선지급은 소스 차량과 대상 차량 양쪽 편집 패널 상단 배너로 확인할 수 있습니다.'),
        para('관련 커밋: 5a46372, e6687fd, 56630d9, 2a71d99, 7afa619, 0801ac7'),
    ]),
    doneItem('ERP 매입일·판매일 미래 날짜 저장 확인 모달', '🟡 보통', ['개선'], [
        h2('반영 내용'),
        bul('매입일 또는 판매일이 오늘보다 미래 날짜이면 저장 전에 확인 모달을 띄우도록 했습니다.'),
        bul('연도 오타 등 날짜 오입력으로 미수, 미지급, 정산 적용 시점이 틀어지는 문제를 줄였습니다.'),
        bul('사용자가 「맞습니다, 저장」을 눌러야 저장되고, 「날짜 수정」을 누르면 저장을 보류합니다.'),
        para('관련 커밋: 131ab2e'),
    ]),
    doneItem('ERP 바이어 입력 UX와 사용자 노출 문구 정리', '🟡 보통', ['개선'], [
        h2('반영 내용'),
        bul('바이어 드로어의 [관리] 조회 범위 설명을 실무자용 문구로 단순화했습니다.'),
        bul('판매 탭 인라인 바이어 등록 모달에서 나라 드롭다운이 바깥 클릭과 Tab 이동으로 닫히도록 수정했습니다.'),
        bul('재무 확정, 장부, 판매 잔금, 감사 로그 등 사용자에게 보이던 기술 영어 표현을 한글 업무 용어로 정리했습니다.'),
        para('관련 커밋: f7d5cd2, 192d551'),
    ]),
];

echo "\n".($apply ? "▶ 카드 삽입 시작...\n" : "▶ [확인 모드] 추가될 항목 미리보기 (실제 삽입 안 함):\n");
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
            '분류' => ['multi_select' => array_map(fn ($t) => ['name' => $t], $item['tags'])],
        ],
        'children' => $item['body'],
    ], $token, $NOTION_VERSION);
    echo "   ✔  {$item['title']}\n";
    $added++;
}

echo "\n";
if ($apply) {
    echo "✅ 삽입 완료: 추가 $added 건 / 중복 건너뜀 $skipped 건\n";
} else {
    echo "ℹ️ 확인만 했습니다. 실제로 넣으려면: php scripts/notion-dev-status-2026-07-21.php --apply\n";
}
