<?php

/**
 * Notion "사내 업무 가이드" 골격 생성 스크립트
 *
 * 신입 온보딩/인수인계용 허브 + 부서별 페이지(공통/영업/재무/수출통관)를
 * 동일한 7블록 템플릿으로 자동 생성한다. 실제 내용은 사람이 채운다(running log).
 *
 * 확인(dry-run, 쓰기 X):
 *   $env:NOTION_TOKEN=[Environment]::GetEnvironmentVariable('NOTION_TOKEN','User'); php scripts/notion-onboarding.php
 *
 * 실제 생성:
 *   ... ; php scripts/notion-onboarding.php --apply
 *
 * 부모 페이지: 기본은 "개발 현황판" DB가 들어있는 부모 페이지를 자동 탐색.
 *   다른 곳에 넣으려면: $env:NOTION_PARENT_PAGE_ID="페이지id"; ... --apply
 *   (그 페이지를 먼저 노션에서 integration 과 공유해야 함)
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$apply = in_array('--apply', $argv, true);
$forcedParent = getenv('NOTION_PARENT_PAGE_ID') ?: '';
$HUB_TITLE = '사내 업무 가이드';
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

// ── 블록 빌더 ────────────────────────────────────────────────
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
function callout(string $emoji, string $t, string $color = 'gray_background'): array
{
    return ['object' => 'block', 'type' => 'callout', 'callout' => [
        'icon' => ['type' => 'emoji', 'emoji' => $emoji],
        'color' => $color,
        'rich_text' => rt($t),
    ]];
}
function divider(): array
{
    return ['object' => 'block', 'type' => 'divider', 'divider' => (object) []];
}

/** 부서 페이지 7블록 템플릿 생성 */
function deptBlocks(array $steps, array $cautions): array
{
    $b = [];
    $b[] = h2('📌 오늘 해야 할 일');
    $b[] = para('(작성) 매일 출근 후 가장 먼저 확인·처리할 것을 적습니다.');
    $b[] = divider();

    $b[] = h2('🔄 업무 절차');
    if ($steps) {
        foreach ($steps as $s) {
            $b[] = num($s);
        }
    } else {
        $b[] = num('(작성) 1단계');
        $b[] = num('(작성) 2단계');
    }
    $b[] = divider();

    $b[] = h2('⚠️ 주의사항 / 실수하기 쉬운 부분');
    $b[] = callout('⚠️', '신입은 절차보다 "예외 상황"에서 막힙니다. 여기를 가장 자세히 적으세요.', 'yellow_background');
    if ($cautions) {
        foreach ($cautions as $c) {
            $b[] = bul($c);
        }
    } else {
        $b[] = bul('(작성) 자주 하는 실수 / 빠뜨리기 쉬운 것');
    }
    $b[] = divider();

    $b[] = h2('🖥 사용 시스템 (car-erp 어디서 무엇을)');
    $b[] = para('(작성) car-erp의 어느 메뉴에서 무엇을 하는지 + 화면 캡처. ⚠️ 민감 데이터(단가·마진·고객·RRN)는 여기 쓰지 말고 car-erp 안에서만 확인.');
    $b[] = divider();

    $b[] = h2('📎 관련 양식 · 파일');
    $b[] = para('(작성) 자주 쓰는 양식/파일을 첨부하거나 링크합니다.');
    $b[] = divider();

    $b[] = h2('☎ 담당자 · 연락처 / 문의처');
    $b[] = para('(작성) "이럴 땐 누구에게" — 에스컬레이션 경로 포함.');
    $b[] = divider();

    $b[] = h2('🕒 변경 이력');
    $b[] = para('YYYY-MM-DD · 작성자: ___ · 내용: ___');

    return $b;
}

// ── 부서 페이지 정의 (업무 절차는 car-erp 도메인 기반 시작점 prefill) ──
$pages = [
    [
        'emoji' => '🧭', 'title' => '공통',
        'steps' => [
            '계정/권한 발급받기 (관리자에게 요청 — 본인 role 영업/재무/수출통관)',
            'car-erp 로그인 (테스트가 아닌 본인 계정)',
            '사이드바 메뉴 구조 익히기',
            '도메인 용어 익히기 (진행상태 단계·채널·정산 등)',
            '막히면 문의처 확인',
        ],
        'cautions' => ['(작성) 신입이 처음에 헷갈리는 공통 사항'],
    ],
    [
        'emoji' => '💼', 'title' => '영업',
        'steps' => [
            '고객 응대',
            '차량 매입 상담',
            '견적 / 금액 산정 (제시가)',
            '검차(바이어 구매의사) 요청',
            '경매 / 구매 확정 확인',
            'car-erp 차량 등록 확인 (본인 재고로 잡혔는지)',
            '재고 관리',
            '판매 / 출고 처리',
        ],
        'cautions' => [
            '(작성) 제시가 입력 실수 / 마감 시간(10:00) 놓침',
            '(작성) 본인 차량만 보이는지 확인',
        ],
    ],
    [
        'emoji' => '💰', 'title' => '재무',
        'steps' => [
            '입금 확인 (판매 입금 내역)',
            '매입 지급 처리',
            '정산 생성 / 확정',
            '지급 승인 흐름 (재무는 직접 paid 불가 — 관리 승인)',
            '환율 / 환차 처리 (2차 정산)',
            '채권 / 미수금 관리',
        ],
        'cautions' => [
            '(작성) 확정(confirmed)·지급(paid) 후 금액 수정 잠금 주의',
            '(작성) 환율 미입력 외화 차량 처리',
        ],
    ],
    [
        'emoji' => '🚢', 'title' => '수출통관',
        'steps' => [
            '반입 / 선적지 입력',
            '수출신고서 업로드',
            '통관 신청',
            'B/L 발급 (잔금 100% 완납 필요 — 관리 승인 우회)',
            'DHL 발송',
            '거래완료 확인',
        ],
        'cautions' => [
            '(작성) export 채널만 해당 — 헤이맨/카풀은 통관 흐름 없음',
            '(작성) 50% 미만 입금 시 통관 차단',
        ],
    ],
];

// ── 1. 부모 페이지 결정 ──────────────────────────────────────
echo "▶ 부모 페이지 결정 중...\n";
$parentId = $forcedParent;
$parentLabel = '';

if ($parentId) {
    echo "   • NOTION_PARENT_PAGE_ID 지정됨: $parentId\n";
    $parentLabel = '(지정된 페이지)';
} else {
    // 개발 현황판 DB 의 부모 페이지 재사용
    $search = notion('POST', "$BASE/search", ['filter' => ['property' => 'object', 'value' => 'database']], $token, $NOTION_VERSION);
    foreach ($search['results'] as $r) {
        $title = $r['title'][0]['plain_text'] ?? '';
        if ($title === $DB_NAME && ($r['parent']['type'] ?? '') === 'page_id') {
            $parentId = $r['parent']['page_id'];
            $parentLabel = "'$DB_NAME' 가 들어있는 부모 페이지";
            break;
        }
    }
    if (! $parentId) {
        fwrite(STDERR, "❌ 부모 페이지를 못 찾았습니다. NOTION_PARENT_PAGE_ID 로 직접 지정하거나, 페이지를 integration 과 공유하세요.\n");
        exit(1);
    }
    echo "   • 자동 탐색: $parentLabel  (id=$parentId)\n";
}

// 참고용: 접근 가능한 페이지 목록
$pageSearch = notion('POST', "$BASE/search", ['filter' => ['property' => 'object', 'value' => 'page']], $token, $NOTION_VERSION);
echo "\n▶ integration 이 접근 가능한 페이지 (참고 — 다른 곳에 넣고 싶으면 이 중 id 를 NOTION_PARENT_PAGE_ID 로):\n";
$dupHub = null;
foreach (array_slice($pageSearch['results'], 0, 20) as $p) {
    $t = '(제목없음)';
    foreach (($p['properties'] ?? []) as $prop) {
        if (($prop['type'] ?? '') === 'title') {
            $t = $prop['title'][0]['plain_text'] ?? '(제목없음)';
            break;
        }
    }
    echo "   • [$t]  id={$p['id']}\n";
    if ($t === $HUB_TITLE) {
        $dupHub = $p['id'];
    }
}

if ($dupHub) {
    fwrite(STDERR, "\n⚠️  이미 '$HUB_TITLE' 페이지가 존재합니다 (id=$dupHub). 중복 생성을 막기 위해 중단합니다.\n");
    fwrite(STDERR, "    다시 만들려면 노션에서 기존 페이지를 지운 뒤 실행하세요.\n");
    exit($apply ? 1 : 0);
}

// ── 2. 생성 계획 미리보기 ────────────────────────────────────
echo "\n▶ ".($apply ? "생성 시작...\n" : "[확인 모드] 생성될 구조 (실제 생성 X):\n");
echo "   📘 $HUB_TITLE   (허브)\n";
foreach ($pages as $pg) {
    echo "      {$pg['emoji']} {$pg['title']}  — 7블록 템플릿".(count($pg['steps']) ? ' (업무 절차 '.count($pg['steps']).'단계 prefill)' : '')."\n";
}

if (! $apply) {
    echo "\nℹ️  확인만 했습니다. 실제로 만들려면:  php scripts/notion-onboarding.php --apply\n";
    exit(0);
}

// ── 3. 허브 페이지 생성 ──────────────────────────────────────
$hubChildren = [
    callout('📘', '신입이 들어오면 이 페이지 링크 하나로 본인 부서 업무 흐름을 따라갈 수 있게 합니다.', 'blue_background'),
    h2('작성 3원칙'),
    bul('① 절차 + "실수하기 쉬운 부분" 중심으로 (글 나열 X)'),
    bul('② running log — 매일 "한 일 + 막힌 것" 1~2줄씩 쌓기 (완벽주의 X)'),
    bul('③ 민감 데이터(단가·마진·고객·RRN)는 여기 금지 → car-erp 안에서만'),
    divider(),
    h2('부서별 가이드'),
    para('아래 하위 페이지에서 본인 부서를 선택하세요. (권한: 노션에서 부서별 공유를 수동 설정 — 재무·수출통관은 부서원에게만 공유 권장)'),
];

$hub = notion('POST', "$BASE/pages", [
    'parent' => ['type' => 'page_id', 'page_id' => $parentId],
    'icon' => ['type' => 'emoji', 'emoji' => '📘'],
    'properties' => ['title' => ['title' => rt($HUB_TITLE)]],
    'children' => $hubChildren,
], $token, $NOTION_VERSION);
$hubId = $hub['id'];
echo '   ✔ 허브 생성: '.($hub['url'] ?? $hubId)."\n";

// ── 4. 부서 페이지 생성 (허브의 자식) ────────────────────────
foreach ($pages as $pg) {
    $page = notion('POST', "$BASE/pages", [
        'parent' => ['type' => 'page_id', 'page_id' => $hubId],
        'icon' => ['type' => 'emoji', 'emoji' => $pg['emoji']],
        'properties' => ['title' => ['title' => rt($pg['title'])]],
        'children' => deptBlocks($pg['steps'], $pg['cautions']),
    ], $token, $NOTION_VERSION);
    echo "   ✔ {$pg['emoji']} {$pg['title']} 생성\n";
}

echo "\n✅ 완료! 허브 페이지로 들어가서 내용을 채우면 됩니다:\n   ".($hub['url'] ?? $hubId)."\n";
echo "   (권한: 노션 UI 에서 부서별 공유 수동 설정 / 뷰는 API 생성 불가)\n";
