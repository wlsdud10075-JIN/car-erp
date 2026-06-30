<?php

/**
 * Notion "사내 업무 가이드" — 수출통관 / 재무 페이지를 car-erp 코드 근거 워크플로우로 교체.
 * 영업·공통 페이지는 건드리지 않음 (사용자 요청: 영업 제외).
 *
 * 기존 placeholder 부서 페이지를 archive(휴지통) 후, 같은 제목으로 코드 기반 내용 재생성.
 *
 * 확인(dry-run):
 *   $env:NOTION_TOKEN=[Environment]::GetEnvironmentVariable('NOTION_TOKEN','User'); php scripts/notion-onboarding-update.php
 * 실제 교체:
 *   ... ; php scripts/notion-onboarding-update.php --apply
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰';
$apply = in_array('--apply', $argv, true);
$HUB_TITLE = '사내 업무 가이드';
$TARGETS = ['수출통관', '재무'];   // 영업·공통 제외
$NOTION_VERSION = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

if (str_contains($token, '여기에_')) {
    fwrite(STDERR, "❌ NOTION_TOKEN 설정 필요\n");
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
function notionGet(string $url, string $t, string $v): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$t, 'Notion-Version: '.$v]]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res, true) ?? [];
    if ($code >= 300) {
        fwrite(STDERR, "❌ Notion API GET ($code): ".($j['message'] ?? $res)."\n");
        exit(1);
    }

    return $j;
}

// 블록 빌더
function rt(string $t): array
{
    return [['type' => 'text', 'text' => ['content' => $t]]];
}
function h2(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => rt($t)]];
}
function para(string $t): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => rt($t)]];
}
function num(string $t): array
{
    return ['object' => 'block', 'type' => 'numbered_list_item', 'numbered_list_item' => ['rich_text' => rt($t)]];
}
function bul(string $t): array
{
    return ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => rt($t)]];
}
function callout(string $e, string $t, string $c = 'gray_background'): array
{
    return ['object' => 'block', 'type' => 'callout', 'callout' => ['icon' => ['type' => 'emoji', 'emoji' => $e], 'color' => $c, 'rich_text' => rt($t)]];
}
function divider(): array
{
    return ['object' => 'block', 'type' => 'divider', 'divider' => (object) []];
}

function buildPage(array $steps, array $cautions, array $systems): array
{
    $b = [];
    $b[] = callout('📌', '이 절차는 car-erp 코드 기준으로 작성한 초안입니다. 실제와 다르면 바로 고쳐주세요.', 'blue_background');
    $b[] = h2('📌 오늘 해야 할 일');
    $b[] = para('(작성) 매일 출근 후 가장 먼저 확인할 것. 보통은 대시보드(본인 role 뷰)의 "할일" 목록.');
    $b[] = divider();
    $b[] = h2('🔄 업무 절차');
    foreach ($steps as $s) {
        $b[] = num($s);
    }
    $b[] = divider();
    $b[] = h2('⚠️ 주의사항 / 실수하기 쉬운 부분');
    $b[] = callout('⚠️', '신입은 절차보다 "예외 상황"에서 막힙니다. 아래는 코드에 박혀 있는 가드(차단 규칙)입니다.', 'yellow_background');
    foreach ($cautions as $c) {
        $b[] = bul($c);
    }
    $b[] = divider();
    $b[] = h2('🖥 사용 시스템 (car-erp 어디서 무엇을)');
    foreach ($systems as $s) {
        $b[] = bul($s);
    }
    $b[] = para('⚠️ 민감 데이터(단가·마진·고객·RRN)는 노션에 적지 말고 car-erp 안에서만 확인하세요.');
    $b[] = divider();
    $b[] = h2('📎 관련 양식 · 파일');
    $b[] = para('(작성) 자주 쓰는 양식/파일 첨부 또는 링크.');
    $b[] = divider();
    $b[] = h2('☎ 담당자 · 연락처 / 문의처');
    $b[] = para('(작성) "이럴 땐 누구에게" — 에스컬레이션 경로.');
    $b[] = divider();
    $b[] = h2('🕒 변경 이력');
    $b[] = para('2026-06-02 · car-erp 코드 기반 초안 자동 생성 · 실제 업무와 맞춰 수정 필요');

    return $b;
}

// ── 부서별 코드 근거 내용 ───────────────────────────────────
$content = [
    '수출통관' => [
        'emoji' => '🚢',
        'steps' => [
            '통관 대상 확인 — 사이드바 "수출통관" 클릭(통관 후보 차량 목록) 또는 대시보드(통관 뷰)의 "할일"에서 처리할 차량 확인',
            '통관 정보 입력 — 차량 편집 → "수출통관" 탭: 통관 바이어/컨사이니, 포워딩사, 면장금액(USD), 선적일, 도착일(ETA), 운송방식(RORO/CONTAINER), Port of Loading',
            '수출신고서(면장) 업로드 — 같은 "수출통관" 탭에서 수출신고서 첨부 → 진행상태가 통관 단계로 전이',
            '반입/선적 입력 — "선적(B/L)" 탭: 반입지(필수), 선적 바이어/컨사이니, 선박(VSL) → 진행상태 "선적중/선적완료"',
            'B/L 발급 — "선적(B/L)" 탭: B/L번호, 컨테이너 No, B/L 문서 첨부 → 진행상태 "거래완료"  ⚠️ 판매 잔금 100% 완납 필수',
            'DHL 발송 — "DHL" 탭: 수취인/발송인/중량·크기 입력 + 발송신청 체크',
            '진행상태 모니터링 — 대시보드(통관 뷰) 파이프라인에서 선적중/선적완료/통관중/거래완료 카운트 확인',
        ],
        'cautions' => [
            'export(수출) 채널만 해당 — 헤이맨/카풀 채널은 통관·B/L·DHL 흐름이 없음',
            'B/L 발급은 판매 잔금 100% 완납 필수. 미완납이면 차단되고, 관리/관리자의 "미입금 우회 승인"으로만 우회 가능',
            '통관 진입은 최소 50% 입금 필요 — 미달 시 "말소 우선" 항목으로 분류되어 통관이 막힘',
            '통관 바이어·선적일·포워딩사 중 하나라도 비면 대시보드에 빨강(긴급)으로 뜸 — 빠뜨리지 말 것',
            '진행상태는 자동 계산됨. 별도 "상태 변경" 버튼 없음 — 해당 탭에 값을 저장하면 단계가 자동으로 넘어감',
        ],
        'systems' => [
            '일반사용자 대시보드(통관 뷰): /erp/dashboard',
            '통관 후보 목록: 사이드바 "수출통관" → /erp/vehicles?action=clearance_candidates',
            '차량 편집 탭: 수출통관 / 선적(B/L) / DHL',
        ],
    ],
    '재무' => [
        'emoji' => '💰',
        'steps' => [
            '대시보드(재무 뷰) "할일" 확인 — 매입 미지급 / 판매 미입금 / 환율 미입력 / 정산 생성·확정·지급 / 채권 위험',
            '환율 입력(외화 차량) — 차량 편집 "판매" 탭의 환율. 미입력이면 미수금 계산 자체가 안 됨',
            '입금/잔금 확정 — "판매" 탭 입금 행의 [확정]. 확정하면 회계 영향 필드가 잠김(Ledger Lock)',
            '정산 생성 — "정산 처리"(/erp/settlements)에서 거래완료 차량 정산 생성(상태 pending). 정산 유형·비율 자동 채움(프리랜서 50% / 사내직원 10만원)',
            '정산 1차 확정 — 마진 검토 후 [확정](confirmed). 미수금이 남아 있으면 정산이 차단됨',
            '정산 지급 — 재무는 직접 지급(paid) 불가. [지급 승인 요청] → 관리/관리자 승인 후 처리(직무 분리)',
            '2차 정산(지급 1개월 후, 선택) — 비용 9개 실측치·정산 환율 입력 → [2차 완료](closed). 환차·이월 자동 계산',
            '자금 이체 처리 — "재무 처리"(/erp/transfers): 영업 요청 → 관리 승인 → 재무 확정(한 차량 입금을 다른 차량으로 이전)',
            '채권 관리 — "채권관리"(/erp/receivables)에서 회수 위험·심각 등급 차량 모니터링',
        ],
        'cautions' => [
            '재무는 정산 1차 확정(confirmed)·2차 완료(closed)까지. 지급(paid)은 직접 못 함 → 관리/관리자 승인 필요(SoD 직무 분리)',
            '확정·지급 후에는 금액·환율 등 회계 컬럼이 잠김. 수정은 관리자(admin)만 잠금 해제 가능',
            '외화 차량 환율 미입력 시 미수금이 "불명"이라 완납으로 오판하기 쉬움 → 환율부터 입력',
            '정산은 거래완료(B/L 발급) 차량만 생성 가능. 미수금이 남으면 "정산 차단"으로 표시됨',
            '환차 반영: 프리랜서(비율제)만 본인 정산에 +/- 반영, 사내직원(건당 고정)은 회사가 부담(미반영)',
        ],
        'systems' => [
            '일반사용자 대시보드(재무 뷰): /erp/dashboard',
            '정산 처리: /erp/settlements',
            '재무 처리(자금 이체·잔금 확정): /erp/transfers',
            '채권관리: /erp/receivables',
            '차량 입금·환율 입력: /erp/vehicles → 판매 탭',
        ],
    ],
];

// ── 1. 허브 찾기 ─────────────────────────────────────────────
echo "▶ 허브 '$HUB_TITLE' 검색...\n";
$search = notion('POST', "$BASE/search", ['query' => $HUB_TITLE, 'filter' => ['property' => 'object', 'value' => 'page']], $token, $NOTION_VERSION);
$hubId = null;
foreach ($search['results'] as $p) {
    foreach (($p['properties'] ?? []) as $prop) {
        if (($prop['type'] ?? '') === 'title' && (($prop['title'][0]['plain_text'] ?? '') === $HUB_TITLE)) {
            $hubId = $p['id'];
            break 2;
        }
    }
}
if (! $hubId) {
    fwrite(STDERR, "❌ 허브를 못 찾음. 먼저 notion-onboarding.php 로 생성하세요.\n");
    exit(1);
}
echo "   ✔ 허브 id=$hubId\n";

// ── 2. 자식 부서 페이지 매핑 ────────────────────────────────
$children = notionGet("$BASE/blocks/$hubId/children?page_size=100", $token, $NOTION_VERSION);
$pageByTitle = [];
foreach ($children['results'] as $blk) {
    if (($blk['type'] ?? '') === 'child_page') {
        $pageByTitle[$blk['child_page']['title'] ?? ''] = $blk['id'];
    }
}
echo '   현재 부서 페이지: '.implode(', ', array_keys($pageByTitle))."\n";

// ── 3. 대상별 교체 계획 ─────────────────────────────────────
echo "\n▶ ".($apply ? "교체 시작 (기존 archive → 새로 생성)...\n" : "[확인 모드] 교체 계획 (영업·공통 제외):\n");
foreach ($TARGETS as $title) {
    $exists = isset($pageByTitle[$title]) ? '기존 archive 후 재생성' : '신규 생성';
    $steps = count($content[$title]['steps']);
    echo "   {$content[$title]['emoji']} $title — $exists (업무 절차 {$steps}단계 + 주의 ".count($content[$title]['cautions']).' + 시스템 '.count($content[$title]['systems']).")\n";
}
echo "   🔒 그대로 둠: 영업, 공통\n";

if (! $apply) {
    echo "\nℹ️  실제 교체:  php scripts/notion-onboarding-update.php --apply\n";
    exit(0);
}

// ── 4. 실행 ─────────────────────────────────────────────────
foreach ($TARGETS as $title) {
    if (isset($pageByTitle[$title])) {
        notion('PATCH', "$BASE/pages/{$pageByTitle[$title]}", ['archived' => true], $token, $NOTION_VERSION);
        echo "   🗑  기존 '$title' archive\n";
    }
    $c = $content[$title];
    $page = notion('POST', "$BASE/pages", [
        'parent' => ['type' => 'page_id', 'page_id' => $hubId],
        'icon' => ['type' => 'emoji', 'emoji' => $c['emoji']],
        'properties' => ['title' => ['title' => rt($title)]],
        'children' => buildPage($c['steps'], $c['cautions'], $c['systems']),
    ], $token, $NOTION_VERSION);
    echo "   ✔ {$c['emoji']} $title 재생성\n";
}

echo "\n✅ 완료! 영업·공통은 그대로, 수출통관·재무만 코드 기반으로 교체됨.\n";
