<?php

/**
 * Notion 개발 현황에 2026-07-24 ERP 완료 항목을 추가한다.
 *
 * 확인:  php scripts/notion-dev-status-2026-07-24.php
 * 적용:  php scripts/notion-dev-status-2026-07-24.php --apply
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
    doneItem('ERP 차량목록 운임비 앞자리 검색 안정화', '🟡 보통', ['수정', '테스트'], [
        h2('반영 내용'),
        bul('차량목록 통합 검색에서 운임비를 앞자리 일부로 찾을 수 있도록 prefix 검색으로 전환했습니다.'),
        bul('DB별 숫자 함수 차이로 테스트가 깨지지 않도록 FLOOR 의존을 제거하고 SQLite 호환 테스트를 추가했습니다.'),
        para('관련 커밋: e9ff924, d565e45'),
    ]),
    doneItem('ERP 자금현황 통장잔액 입력 UX 및 그래프 복구', '🟡 보통', ['수정', '대시보드'], [
        h2('반영 내용'),
        bul('대표/관리 대시보드의 자금추이 그래프가 다시 정상 표시되도록 차트 상태 갱신을 보정했습니다.'),
        bul('통장잔액 저장 후 입력칸을 비우고, 페이지 진입 시에도 빈칸으로 시작하도록 정리했습니다.'),
        bul('자금 현황 회귀 테스트를 보강해 입력칸 초기화 동작을 고정했습니다.'),
        para('관련 커밋: 00d8cb4, b444449'),
    ]),
    doneItem('ERP 포워딩 운임 인보이스 차액 정산', '🔴 높음', ['기능', '재무', '수출통관'], [
        h2('반영 내용'),
        bul('포워딩 운임 인보이스에 수기 환율, 환산 KRW, 실제 송금액, 버림/차액, 비고 필드를 추가했습니다.'),
        bul('모델·마이그레이션·한/영 문구·운임 청산 화면을 함께 반영해 송금 후 차액 사유를 화면에서 남길 수 있게 했습니다.'),
        bul('운임 인보이스 정산 계산과 저장 동작을 Feature 테스트로 검증했습니다.'),
        para('관련 커밋: 404cdfe'),
    ]),
    doneItem('ERP 로컬 LLM 챗봇 B단계 설계 확정', '🟡 보통', ['문서', '설계'], [
        h2('반영 내용'),
        bul('매출·미수금 같은 DB 숫자 조회는 car-erp가 소유하고, LLM은 숫자를 생성하지 않는다는 불변식을 문서화했습니다.'),
        bul('ERP 내장 Livewire 위젯, 결정적 키워드 라우팅, 서버 템플릿 숫자 삽입, 권한 2단계 기준을 정리했습니다.'),
        bul('프로덕션 배포 전에는 로컬 우선으로 검증하고, Ollama URL·모델명·index 경로는 env/config 기반으로 이식 가능하게 설계했습니다.'),
        para('관련 커밋: 6af2150'),
    ]),
    doneItem('ERP 사내 업무 도우미 v1 로컬 LLM 챗봇 구현', '🔴 높음', ['기능', 'AI', 'ERP'], [
        h2('반영 내용'),
        bul('ERP 사이드바에 사내 업무 도우미 Livewire 위젯을 추가했습니다.'),
        bul('미수·채권·자금 조회는 서버가 검증된 조회 함수로 DB에서 계산하고, 숫자는 LLM을 거치지 않고 템플릿에 삽입합니다.'),
        bul('업무가이드 질문은 Notion 색인 기반 RAG로 답변하며, Ollama(qwen3:8b, bge-m3)를 사용하는 완전 로컬 구조로 구성했습니다.'),
        bul('Ollama URL, 모델명, Notion 색인 경로, timeout을 모두 env/config 기반으로 분리해 회사 GPU PC로 이식 가능하게 했습니다.'),
        bul('AssistantService, AssistantQueries, OllamaClient와 Feature 테스트를 함께 추가했습니다.'),
        para('관련 커밋: a7a637b'),
    ]),
    doneItem('ERP 챗봇 Notion 가이드 색인 스코프 분리', '🟡 보통', ['수정', 'AI', 'Notion'], [
        h2('반영 내용'),
        bul('공용 Notion 색인에서 ERP 챗봇이 ERP 가이드 청크만 검색하도록 스코프 필터를 추가했습니다.'),
        bul('board 가이드 내용이 ERP 답변에 섞이지 않도록 ASSISTANT_INDEX_SCOPE 설정을 분리했습니다.'),
        bul('색인 파일도 car-erp 전용 index-erp.json 기준으로 문서화해 운영 혼입 가능성을 줄였습니다.'),
        para('관련 커밋: 2cbba7a, ab39721'),
    ]),
    doneItem('ERP 업무 도우미 기능설정 토글 추가', '🟡 보통', ['기능', '설정', 'AI'], [
        h2('반영 내용'),
        bul('시스템관리자 기능설정에 사내 업무 도우미 on/off 토글을 추가했습니다.'),
        bul('배포 여부와 실제 노출 여부를 분리해, env 인프라 설정과 관리자 토글이 모두 켜져야 챗봇이 보이도록 했습니다.'),
        bul('한/영 설정 문구와 노출 조건 테스트를 함께 반영했습니다.'),
        para('관련 커밋: 623f901'),
    ]),
    doneItem('BOARD Notion 업무가이드 RAG 챗봇 PoC 인계', '🟡 보통', ['문서', 'AI', 'BOARD'], [
        h2('반영 내용'),
        bul('board에서 만든 완전 로컬 LLM 챗봇 PoC를 car-erp 구현으로 넘기기 위한 인계 문서를 추가했습니다.'),
        bul('Notion 사내 업무가이드 재귀 색인, 청크 임베딩, index.json 기반 RAG, Ollama 모델 구성(qwen3:8b/bge-m3)을 정리했습니다.'),
        bul('A단계 업무가이드 Q&A와 B단계 ERP DB 숫자 조회의 책임 경계를 분리해 후속 구현 방향을 명확히 했습니다.'),
        para('관련 커밋: board d548bba'),
    ]),
    doneItem('ERP 정산 회계잠금 개편 — 마감 후로 완화', '🔴 높음', ['기능', '재무'], [
        h2('반영 내용'),
        bul('회계 잠금 시점을 "매입/판매 잔금 확정" → "2차 정산 마감"으로 옮겼습니다. 이제 정산을 마감하기 전에는 매입가·판매가·환율·비용·잔금을 자유롭게 수정할 수 있고, 수정된 만큼 정산이 자동으로 반영·이월됩니다.'),
        bul('정산 없이 잔금만 확정된 재고 차량(딜러 대금만 지급한 경우)도 이제 막힘 없이 편집됩니다.'),
        bul('마감된 정산을 다시 손봐야 할 땐 정산 화면 마감 행의 "회계 재조정" 버튼으로 사유를 남기고 수정합니다(모든 변경은 감사 로그).'),
        bul('마감 후 재조정은 기록용입니다 — 이미 나간 지급액을 다시 계산하진 않습니다.'),
        para('관련 커밋: a463fce 외 (락 트리거 1/N~4/N)'),
    ]),
    doneItem('ERP 사내 업무 도우미 안내 카드 38장', '🟡 보통', ['문서', 'AI'], [
        h2('반영 내용'),
        bul('챗봇이 "이 기능 어디서 해?" 같은 질문에 정확히 답하도록, 사이드바 탭별 안내 카드 38장을 작성해 사내 업무 가이드에 넣고 재색인했습니다.'),
        bul('각 카드는 "어디서 · 무엇을 · 무엇을 적나 · 누가 · 어디에 반영되나"로 정리했고, 적립금·미수금·정산 같은 공통 개념은 별도 카드로 단일 관리합니다.'),
        bul('이제 "바이어 정보 어디서 봐?", "면장금액 어디 넣어?", "2차 정산이 뭐야?" 같은 질문에 바로 답합니다.'),
        para('출처: 사내 업무 가이드 › ERP › 📇 기능 카드 (챗봇용)'),
    ]),
    doneItem('ERP 정산 지급 후 매입 잔금 지급 허용', '🟡 보통', ['버그', '기능'], [
        h2('반영 내용'),
        bul('바이어에게는 대금을 다 받았지만 회사가 국내 매입처에 잔금을 나중에 치르는 경우, 정산이 이미 지급된 차량이라도 매입 잔금을 정상 처리할 수 있게 했습니다(54가6191 사례).'),
        bul('마감 전이면 매입 잔금 확정이 자유로워지는 위 정산 락 개편과 맞물립니다.'),
        para('관련 커밋: fcde4fa'),
    ]),
    doneItem('ERP 차대번호 검색 분리 + 재고 필터 추가', '🟢 낮음', ['기능'], [
        h2('반영 내용'),
        bul('차량관리 통합검색에서 차대번호(VIN)를 별도 칸으로 분리해, 다른 항목과 겹칠 때도 정확히 찾도록 했습니다.'),
        bul('재고관리에 차대번호 검색·컬럼과 바이어별·컨사이니별 드롭다운 필터를 추가했습니다.'),
        para('관련 커밋: ef66db3 · 525f6f7 · fae9046'),
    ]),
    doneItem('ERP 단계 진행 필드 노란 배경 경고', '🟢 낮음', ['기능'], [
        h2('반영 내용'),
        bul('입력하면 진행 단계가 넘어가고 잠금·게이트가 걸리는 칸(선적일·반입지·통관·B/L 등)에 계좌 입력칸처럼 노란 배경과 "입력 시 단계 진행" 힌트를 넣어, 실수로 단계를 넘기는 걸 줄였습니다.'),
        para('관련 커밋: 8a147b8'),
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
    echo "ℹ️ 확인만 했습니다. 실제로 넣으려면: php scripts/notion-dev-status-2026-07-24.php --apply\n";
}
