<?php

/**
 * Notion 개발 현황판 — 4시스템 통합(respond.io·purchase-board) 단계별 개발예정 등록
 *
 * 2026-06-05 회의 확정 로드맵 기반. 제목 "[통합]" 접두어로 그룹.
 *
 * 확인(dry-run, 실제 삽입 X):
 *   php scripts/notion-integration-plan.php
 * 실제 삽입:
 *   php scripts/notion-integration-plan.php --apply
 *
 * (DB는 이름 "개발 현황판" 으로 자동 탐색. 토큰은 env NOTION_TOKEN.)
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
echo "▶ 데이터베이스 검색 중...\n";
$search = notion('POST', "$BASE/search", [
    'filter' => ['property' => 'object', 'value' => 'database'],
], $token, $NOTION_VERSION);

$target = null;
foreach ($search['results'] as $r) {
    $title = $r['title'][0]['plain_text'] ?? '(제목없음)';
    if ($title === $DB_NAME) {
        $target = $r;
        echo "   • [$title]  id={$r['id']}  ← 대상\n";
    }
}
if (! $target) {
    fwrite(STDERR, "❌ '$DB_NAME' DB를 못 찾았습니다. integration 연결을 확인하세요.\n");
    exit(1);
}
$dbId = $target['id'];

// ── 2. 기존 카드 (중복 skip 용) ──────────────────────────────
$existing = notion('POST', "$BASE/databases/$dbId/query", [], $token, $NOTION_VERSION);
$existingTitles = [];
foreach ($existing['results'] as $p) {
    $existingTitles[] = $p['properties']['제목']['title'][0]['plain_text'] ?? '';
}
echo '▶ 기존 카드 '.count($existingTitles)."건 (제목 중복 시 자동 skip)\n";

// ── 3. 넣을 항목 — 2026-06-05 통합 로드맵 단계별 ────────────────
// by: '진'=개발자 작업 / '대표'=대표 결정·승인 필요
$plan = fn (string $title, string $status, string $pri, string $by, array $tags) => [
    'title' => $title, 'status' => $status, 'priority' => $pri, 'by' => $by, 'tags' => $tags,
];

$items = [
    // ── 0단계: 지금 병행 (코드 무관) ──
    $plan('[통합] respond.io DPA 체결 (개인정보 국외이전 §28) — 요청메일 발송, 답장 대기', '진행중', '🔴 높음', '진', ['운영·배포']),
    $plan('[통합] respond.io 단계변경 방법 확인 (API 직접 A vs Custom Field 우회 B)', '요청됨', '🟡 보통', '진', ['기능']),
    $plan('[통합] 대표 승인: 연동용 컬럼 3개 + 큐워커 도입 (06-02 "API 1개 예외" 초과분)', '요청됨', '🔴 높음', '진', ['기능']),

    // ── 1단계: 인프라 (첫 삽) ──
    $plan('[통합] 큐 워커 설치 (Supervisor + queue:work) — 연동 C 절대선행, 다운타임0', '요청됨', '🔴 높음', '진', ['운영·배포']),

    // ── 2단계: 연동 C (첫 스프린트 핵심) ──
    $plan('[통합] 연동 C: 입금하면 respond.io 바이어 단계 자동전진 (export 채널, 8~11h)', '요청됨', '🔴 높음', '진', ['기능']),

    // ── 3~4단계: 앱·인프라 후행 ──
    $plan('[통합] purchase-board MVP: 매입-검차-경매 업무보드 (별도앱, 19~27h)', '요청됨', '🟡 보통', '진', ['기능']),

    // ── 5~6단계: 앱 완성 후 연동 ──
    $plan('[통합] 연동 B: 낙찰차 car-erp 자동등록 (카톡 수동등록 대체)', '요청됨', '🟡 보통', '진', ['기능']),
    $plan('[통합] 연동 A: 검차 사진 → 바이어 WhatsApp 공유 (S3 서명URL)', '요청됨', '🟡 보통', '진', ['기능']),

    // ── 7단계: 마지막 ──
    $plan('[통합] AI 1차응대 레이어 (가드레일3 + car-erp 실시간 조회 "내 차 어디?")', '요청됨', '⚪ 낮음', '진', ['아이디어']),
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
        echo "   +  [{$f['status']} / {$f['priority']} / {$f['by']}] {$f['title']}\n";

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
    echo "ℹ️  확인만 했습니다. 실제로 넣으려면:  php scripts/notion-integration-plan.php --apply\n";
}
