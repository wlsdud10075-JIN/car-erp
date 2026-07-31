<?php

/**
 * Notion 개발 현황에 2026-07-31 ERP 완료 항목을 추가한다.
 *
 * 확인:  php scripts/notion-dev-status-2026-07-31.php
 * 적용:  php scripts/notion-dev-status-2026-07-31.php --apply
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
    doneItem('ERP 전자서명 요청 무르기', '🔴 높음', ['기능', '버그'], [
        h2('반영 내용'),
        bul('전자서명을 잘못 보냈을 때 화면에서 되돌릴 방법이 없어, 서버에서 손으로 처리해야 했습니다.'),
        bul('서명 대기·열람됨 상태 옆에 「무르기」 버튼을 넣어, 누르면 바이어에게 보낸 링크가 즉시 무효가 됩니다.'),
        bul('차량 서류 탭과 선적요청 묶음 양쪽에서 쓸 수 있고, 서명이 끝난 계약은 무를 수 없습니다(법적 증거물).'),
        bul('무른 사람과 시각은 감사로그에 남습니다.'),
        para('관련 커밋: 43ffef0'),
    ]),
    doneItem('ERP 월 결산 보고 발송 시점 변경', '🔴 높음', ['기능', '재무'], [
        h2('반영 내용'),
        bul('대표 월 결산 알림톡이 익월 첫 영업일에 무조건 나가고 있었습니다. 그 시점에는 전월 정산이 아직 확정 전이라 총마진·지급총액·회사이익이 실제보다 적게 보고됐습니다.'),
        bul('이제 그 달 월배치 정산이 최종 승인된 때 발송합니다. 날짜 추측 대신 확정 신호를 씁니다.'),
        bul('집계 기준을 확정일에서 귀속월로 바꿔 월배치와 같은 기준을 쓰도록 맞췄습니다.'),
        bul('정산이 없는 달, 아직 승인 전인 달은 보내지 않고 사유를 알림톡 로그에 남깁니다.'),
        para('관련 커밋: 411f75d'),
    ]),
    doneItem('ERP 자금 보고 알림톡 정합', '🔴 높음', ['버그', '재무'], [
        h2('반영 내용'),
        bul('대표 자금 보고 알림톡이 카카오 규격 위반으로 발송이 거부되고 있었습니다. 승인받은 서식과 글자가 달랐던 것이 원인입니다.'),
        bul('제목·강조문구·금액 표기를 승인본과 글자 단위로 맞춰 정상 발송을 확인했습니다.'),
        bul('손익이 마이너스인 달은 규격상 표기할 방법이 없어, 잘못 보고하는 대신 발송을 보류하고 사유를 남깁니다.'),
        para('관련 커밋: c121637'),
    ]),
    doneItem('ERP 통장 잔액 입력 이력 조회', '🟡 보통', ['기능', '재무'], [
        h2('반영 내용'),
        bul('통장 마감잔액은 입력만 하고 과거에 얼마를 넣었는지 확인할 방법이 없었습니다.'),
        bul('이제 바뀐 잔액이 감사로그에 이전값과 새값으로 남아, 로그 화면에서 날짜별로 볼 수 있습니다.'),
        bul('「지금 값으로 다시 계산」은 별도 한 줄로 기록되어 입력 이력과 섞이지 않습니다.'),
        para('관련 커밋: bde195c'),
    ]),
    doneItem('ERP 로그 화면 한글화', '🟡 보통', ['운영·배포', '문서'], [
        h2('반영 내용'),
        bul('감사로그와 알림톡 로그가 내부 영문 코드를 그대로 보여주고 있었습니다. 새 기능을 만들 때 한글 표기를 함께 넣지 않아 반복되던 문제입니다.'),
        bul('운영 데이터를 실측해 액션·대상·컬럼·발송 사유의 빠진 한글 표기를 채웠습니다.'),
        bul('앞으로 빠지지 않도록, 한글 표기가 없는 새 기록이 생기면 자동 검사에서 걸리도록 했습니다.'),
        para('관련 커밋: bde195c'),
    ]),
    doneItem('ERP 감사로그 필터 정리', '🟡 보통', ['기능', '버그'], [
        h2('반영 내용'),
        bul('컬럼 목록에 컬럼이 아닌 항목(챗봇 질문 유형, 바이어별 항목)이 섞여 목록이 지저분했습니다.'),
        bul('챗봇 질문은 액션 필터로, 바이어 변경은 한 항목으로 모아 정리했습니다.'),
        bul('「대상」 필터를 새로 넣어 차량·통장 잔액·전자서명 계약처럼 종류로 먼저 고를 수 있게 했습니다.'),
        bul('목록 정렬을 한글 표기 기준으로 바꿔 순서가 뒤죽박죽으로 보이던 것을 고쳤습니다.'),
        para('관련 커밋: 7e34af4'),
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
    echo "ℹ️ 확인만 했습니다. 실제로 넣으려면: php scripts/notion-dev-status-2026-07-31.php --apply\n";
}
