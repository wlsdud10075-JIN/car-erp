<?php

/**
 * Notion 개발 현황판 — car-erp 완료/예정 항목 채우기
 *
 * 확인(dry-run, 실제 삽입 X):
 *   $env:NOTION_TOKEN="ntn_xxx"; php scripts/notion-populate.php
 *
 * 실제 삽입:
 *   $env:NOTION_TOKEN="ntn_xxx"; php scripts/notion-populate.php --apply
 *
 * (DB는 이름 "개발 현황판" 으로 자동 탐색 — id 안 넣어도 됨)
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$apply = in_array('--apply', $argv, true);
$DB_NAME = '개발 현황판';
$NOTION_VERSION = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

if (str_contains($token, '여기에_')) {
    fwrite(STDERR, "❌ NOTION_TOKEN 를 먼저 설정하세요.\n");
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

// ── 1. DB 탐색 ───────────────────────────────────────────────
echo "▶ 접근 가능한 데이터베이스 검색 중...\n";
$search = notion('POST', "$BASE/search", [
    'filter' => ['property' => 'object', 'value' => 'database'],
], $token, $NOTION_VERSION);

$target = null;
foreach ($search['results'] as $r) {
    $title = $r['title'][0]['plain_text'] ?? '(제목없음)';
    $mark = ($title === $DB_NAME) ? '  ← 대상' : '';
    echo "   • [$title]  id={$r['id']}$mark\n";
    if ($title === $DB_NAME) {
        $target = $r;
    }
}
if (! $target) {
    fwrite(STDERR, "❌ '$DB_NAME' DB를 못 찾았습니다. integration 연결을 확인하세요.\n");
    exit(1);
}
$dbId = $target['id'];

echo "\n▶ 대상 DB 속성:\n";
foreach ($target['properties'] as $name => $def) {
    echo "   - $name ({$def['type']})\n";
}

// ── 2. 기존 카드 목록 ────────────────────────────────────────
$existing = notion('POST', "$BASE/databases/$dbId/query", [], $token, $NOTION_VERSION);
echo "\n▶ 현재 들어있는 카드 (".count($existing['results'])."건):\n";
$existingTitles = [];
foreach ($existing['results'] as $p) {
    $t = $p['properties']['제목']['title'][0]['plain_text'] ?? '(제목없음)';
    $existingTitles[] = $t;
    echo "   - $t\n";
}

// ── 3. 넣을 항목 정의 ────────────────────────────────────────
$done = fn (string $title, array $tags) => [
    'title' => $title, 'status' => '완료', 'by' => '진', 'priority' => '🟡 보통', 'tags' => $tags,
];
$plan = fn (string $title, string $status, string $pri, array $tags) => [
    'title' => $title, 'status' => $status, 'by' => '진', 'priority' => $pri, 'tags' => $tags,
];

$items = [
    // ✅ 완료사항
    $done('AWS Lightsail 운영 배포 + 자동 SSH 배포', ['운영·배포']),
    $done('NICE API 연동 (ssancar 미들웨어 경유)', ['기능']),
    $done('S3 연동 (차량 사진·서류 저장)', ['기능']),
    $done('DB 백업 cron (익일 03:00)', ['운영·배포']),
    $done('서류 자동생성 xlsx 9종 + 다중차량 선적', ['기능']),
    $done('B/L 게이트 100% 완납 + 승인 우회', ['기능']),
    $done('컨사이니 일괄 import (consignees:import)', ['기능']),
    $done('차량 일괄 import (vehicles:import 헤이맨 현황표)', ['기능']),
    $done('E2E 정산 테스트 + 데모 시더', ['기능']),
    $done('차량 검색 수출신고번호 추가', ['기능']),

    // 🔜 개발예정
    $plan('기능 안정화 검증 (NICE숫자·기통수·force-delete cascade·선적Excel·cron)', '요청됨', '🔴 높음', ['운영·배포']),
    $plan('도메인 + HTTPS (heysellcar.com→certbot→APP_URL)', '보류', '🟡 보통', ['운영·배포']),
    $plan('통관 SET 다중차량 (인보이스 3시트 N대)', '보류', '⚪ 낮음', ['기능']),
    $plan('별건 3: 사이드바 재구성 + 로그 화면 + audit_logs UI', '요청됨', '🟡 보통', ['기능']),
    $plan('말소 시 주소 필수가드 / NICE키 env분리 / 2-2 잔금 layout', '요청됨', '⚪ 낮음', ['기능']),
];

// ── 4. 미리보기 또는 삽입 ────────────────────────────────────
echo "\n".($apply ? "▶ 카드 삽입 시작...\n" : "▶ [확인 모드] 추가될 항목 미리보기 (실제 삽입 안 함):\n");
$added = 0;
$skipped = 0;
foreach ($items as $f) {
    if (in_array($f['title'], $existingTitles, true)) {
        echo "   ⏭  (이미 존재) {$f['title']}\n";
        $skipped++;

        continue;
    }
    if (! $apply) {
        echo "   +  [{$f['status']}] {$f['title']}\n";

        continue;
    }
    notion('POST', "$BASE/pages", [
        'parent' => ['database_id' => $dbId],
        'properties' => [
            '제목' => ['title' => [['text' => ['content' => $f['title']]]]],
            '상태' => ['select' => ['name' => $f['status']]],
            '대표' => ['select' => ['name' => $f['by']]],
            '우선순위' => ['select' => ['name' => $f['priority']]],
            '분류' => ['multi_select' => array_map(fn ($t) => ['name' => $t], $f['tags'])],
        ],
    ], $token, $NOTION_VERSION);
    echo "   ✔  {$f['title']}\n";
    $added++;
}

echo "\n";
if ($apply) {
    echo "✅ 삽입 완료: 추가 $added 건 / 중복 건너뜀 $skipped 건\n";
} else {
    echo "ℹ️  확인만 했습니다. 실제로 넣으려면:  php scripts/notion-populate.php --apply\n";
}
