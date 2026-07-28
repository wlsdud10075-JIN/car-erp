<?php

/**
 * Notion 개발 현황에 2026-07-26 ERP 완료 항목을 추가한다.
 *
 * 확인:  php scripts/notion-dev-status-2026-07-26.php
 * 적용:  php scripts/notion-dev-status-2026-07-26.php --apply
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

$existingTitles = [];
$cursor = null;
do {
    $body = ['page_size' => 100];
    if ($cursor) {
        $body['start_cursor'] = $cursor;
    }
    $existing = notion('POST', "$BASE/databases/$dbId/query", $body, $token, $NOTION_VERSION);
    foreach ($existing['results'] as $p) {
        $existingTitles[] = $p['properties']['제목']['title'][0]['plain_text'] ?? '';
    }
    $cursor = ($existing['has_more'] ?? false) ? ($existing['next_cursor'] ?? null) : null;
} while ($cursor);
echo '▶ 기존 카드 '.count($existingTitles)."건 (제목 중복 시 skip)\n";

$items = [
    doneItem('ERP 재고관리 검색 확장', '🟡 보통', ['기능'], [
        h2('반영 내용'),
        bul('재고관리 통합검색에 수출신고번호·선박명·컨테이너번호를 추가했습니다.'),
        bul('차량번호·차대번호뿐 아니라 선적·통관 정보로도 필요한 재고를 바로 찾을 수 있습니다.'),
        para('관련 커밋: 868bc15'),
    ]),
    doneItem('ERP 검색 성능 개선', '🟢 낮음', ['성능'], [
        h2('반영 내용'),
        bul('재고관리 목록의 N+1 조회를 제거해 20행 기준 약 78개 쿼리를 약 6개로 줄였습니다.'),
        bul('차량관리 요약카드의 중복 쿼리도 제거해 목록·요약 조회 부담을 낮췄습니다.'),
        para('관련 커밋: 868bc15'),
    ]),
    doneItem('ERP 자금추이 차트 개편', '🟡 보통', ['AI', '기능'], [
        h2('반영 내용'),
        bul('관리자 대시보드 자금추이 그래프가 화면 이동·자동 갱신 후 사라지던 문제를 공용 차트 재렌더 등록기로 해결했습니다.'),
        bul('일·주·월·년 단위 전환과 최신 청산가치·범위·스냅샷 수치 표시를 추가했습니다.'),
        para('관련 커밋: 94758bb'),
    ]),
    doneItem('ERP 대시보드 점검·반영', '🔴 높음', ['기능', '버그', '재무'], [
        h2('반영 내용'),
        bul('자금현황 미지급에 매입 미지급뿐 아니라 포워딩 운임 미지급과 재무 확정 정산 지급대기를 포함했습니다.'),
        bul('업무·관리자 대시보드의 대상과 역할별 할일을 재정리하고, 승인 대기 실제 건수·운임 확정·2차 정산 마감·재고 점검을 반영했습니다.'),
        bul('관리자 대시보드에는 2차 정산 마감 대기와 일반재고·선적전 재고 통계를 추가했습니다.'),
        para('관련 커밋: 91b6dff, 4d38268'),
    ]),
    doneItem('ERP 컨사이니 목록 IDOR 차단', '🔴 높음', ['보안', '버그'], [
        h2('반영 내용'),
        bul('컨사이니 독립목록에서 영업 사용자는 본인 담당 바이어의 컨사이니만 조회·편집·삭제할 수 있도록 서버 권한 검사를 보강했습니다.'),
        bul('다른 담당자의 여권·ID 등 개인정보 접근을 403으로 차단하고, 컨사이니 등록 시 바이어 지정을 필수화했습니다.'),
        para('관련 커밋: a22ac9b'),
    ]),
    doneItem('ERP heyman 배포 안정화', '🟢 낮음', ['운영', 'CI'], [
        h2('반영 내용'),
        bul('heyman 서버 SSH 연결 타임아웃을 30초에서 120초로 늘리고 간헐 연결 실패 시 1회 자동 재시도하도록 개선했습니다.'),
        bul('SSH 연결 지연으로 배포 스크립트가 시작되기 전에 실패하는 경우를 줄였습니다.'),
        para('관련 커밋: 3214c59'),
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
    echo "ℹ️ 확인만 했습니다. 실제로 넣으려면: php scripts/notion-dev-status-2026-07-26.php --apply\n";
}
