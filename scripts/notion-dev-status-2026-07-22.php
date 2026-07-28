<?php

/**
 * Notion 개발 현황에 2026-07-22 ERP 완료 항목을 추가한다.
 *
 * 확인:  php scripts/notion-dev-status-2026-07-22.php
 * 적용:  php scripts/notion-dev-status-2026-07-22.php --apply
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
    doneItem('ERP Proforma Invoice 번호 규칙 교정', '🟡 보통', ['수정'], [
        h2('반영 내용'),
        bul('Proforma Invoice No.에서 고정 MU 접두를 제거하고, 영업담당자 이니셜 + 차대번호 끝자리 숫자 규칙으로 정리했습니다.'),
        bul('차대번호 중간 숫자가 섞이지 않도록 끝자리 연속 숫자 6~7자리만 사용합니다.'),
        bul('무사백 담당자의 이니셜 MU가 고정 접두 MU와 겹쳐 MUMU처럼 중복되던 문제를 막았습니다.'),
        para('관련 커밋: b5d730a'),
    ]),
    doneItem('ERP 회사별 서류 양식 정정', '🔴 높음', ['수정'], [
        h2('반영 내용'),
        bul('ssancar 회사정보를 시흥 산기대학로 163, ssancar9977@gmail, TEL 355 / FAX 366 기준으로 정리했습니다.'),
        bul('system·heyman·karaba 템플릿의 PROFORMA, Swift Code, Address 표기 오타를 수정했습니다.'),
        bul('Proforma Invoice에 바이어 Email 행을 추가하고, Name·Phone·Email·Rate 위치에 맞춰 매핑을 조정했습니다.'),
        bul('말소계약서 회사정보를 ssancar·heyman 정본 기준으로 정리했습니다. karaba 말소계약서는 정본 확보 후 별도 처리 대상으로 남겼습니다.'),
        para('관련 커밋: e6768b4'),
    ]),
    doneItem('ERP SaaS화 테넌시 이식 청사진 작성', '🟡 보통', ['문서', '설계'], [
        h2('반영 내용'),
        bul('Project-SaaS 테넌시층과 현 car-erp 구조를 대조해 multi-DB 테넌시 이식 방향을 정리했습니다.'),
        bul('업무 모델 수정 0줄, 크로스DB 쿼리 0건 기준으로 테넌시 커스텀 약 6파일 500줄, car-erp 2~2.5주 + board 1주 견적을 남겼습니다.'),
        bul('고위험 항목은 heyman 데이터 컷오버와 RRN 재암호화로 분리했고, 테넌트화 위생 규칙을 문서화했습니다.'),
        para('관련 커밋: 4b71b15'),
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
    echo "ℹ️ 확인만 했습니다. 실제로 넣으려면: php scripts/notion-dev-status-2026-07-22.php --apply\n";
}
