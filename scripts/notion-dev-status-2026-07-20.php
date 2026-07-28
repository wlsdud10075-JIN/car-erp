<?php

/**
 * Notion 개발 현황에 2026-07-15~18 ERP 완료 항목을 추가한다.
 *
 * 확인:  php scripts/notion-dev-status-2026-07-20.php
 * 적용:  php scripts/notion-dev-status-2026-07-20.php --apply
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
    doneItem('ERP 원부조회(carmodoo) 압류·저당·구조 조회', '🟡 보통', ['기능'], [
        h2('반영 내용'),
        bul('차량 편집 화면에서 필요할 때 원부조회로 압류, 저당, 구조 정보를 확인할 수 있게 했습니다.'),
        bul('원부조회 접속주소와 우회 프록시 사용 여부를 기능설정에서 관리할 수 있게 했습니다.'),
        para('관련 커밋: d7dc152, 2d635be'),
    ]),
    doneItem('ERP 알림톡 아이템리스트형 9종과 회사별 게이트', '🔴 높음', ['기능'], [
        h2('반영 내용'),
        bul('카톡 카드에 맞는 아이템리스트형 알림톡 발송코드 9종을 추가했습니다.'),
        bul('회사별 게이트를 적용해 heymanerp, ssancarerp, karabaerp 운영 차이를 분리했습니다.'),
        para('관련 커밋: af66663, 2115645'),
    ]),
    doneItem('ERP 포워딩 운임 청산과 선적 스케줄 달력', '🟡 보통', ['기능'], [
        h2('반영 내용'),
        bul('포워딩사 운임 인보이스를 미지급/완료로 구분하고 묶음 단위로 접고 펼쳐 확인할 수 있게 했습니다.'),
        bul('선적 스케줄 달력에 선적일~도착일 구간, 묶음 색상, ● 선적/○ 도착 표시와 툴팁을 추가했습니다.'),
        para('관련 커밋: 4ff4866, 79ca6fb, bb9f7f2, 6d226b3'),
    ]),
    doneItem('ERP 파일 업로드 드래그앤드랍과 진행 게이지', '🟡 보통', ['개선'], [
        h2('반영 내용'),
        bul('차량 사진과 서류 업로드 지점에 드래그앤드랍 업로드를 확장했습니다.'),
        bul('공통 업로드 컴포넌트에 진행 게이지를 추가해 업로드 상태를 눈으로 확인할 수 있게 했습니다.'),
        para('관련 커밋: 66e554c, 2b4b334'),
    ]),
    doneItem('ERP 매입취소 상태·채권·정산 차단 흐름', '🔴 높음', ['기능'], [
        h2('반영 내용'),
        bul('매입취소 상태 마커, 차량목록 배지와 필터, 재무 대시보드 카드, 채권관리 배지를 추가했습니다.'),
        bul('매입취소 차량은 판매실적과 정산 자동생성 대상에서 제외하고, 미수마감과 손실 동결 흐름을 분리했습니다.'),
        bul('월배치에는 매입취소 손실 요약을 보조 정보로 보여주도록 했습니다.'),
        para('관련 커밋: ed800e4, e294794, 64135e4, 6e4420d, dc2d16b, 35dd9b6, b0777b8, d3026e5, 5272892, 2107a10'),
    ]),
    doneItem('ERP 재고 2분류와 채권 선적전/후 기준 변경', '🔴 높음', ['기능'], [
        h2('반영 내용'),
        bul('재고를 일반재고와 선적전 재고로 구분해 볼 수 있게 했습니다.'),
        bul('채권의 선적 전/후 미수 판단 기준을 반입지가 아니라 출고일로 이동했습니다.'),
        para('관련 커밋: f97563e'),
    ]),
    doneItem('ERP B/L 체크빌 업로드·seawaybill·허용 항로 예외', '🔴 높음', ['기능'], [
        h2('반영 내용'),
        bul('B/L 탭에 발급 전 확인본인 체크빌 업로드를 추가했습니다.'),
        bul('B/L 방식에 seawaybill을 추가하고, 허용 항로 플래그가 켜진 RORO 차량의 선적대기 진입 예외를 반영했습니다.'),
        para('관련 커밋: cc291e1, 93afa8c'),
    ]),
    doneItem('ERP 말소신청서+계약서 병합본과 Proforma Invoice 번호 규칙', '🟡 보통', ['기능'], [
        h2('반영 내용'),
        bul('말소신청서와 계약서를 1개 파일 2개 탭으로 받을 수 있는 병합본을 추가했습니다.'),
        bul('Proforma Invoice No.를 영업담당자 이니셜 + MU + 차대번호 숫자 규칙으로 자동 생성합니다.'),
        para('관련 커밋: a1435c3, be368fb, c7f4709'),
    ]),
    doneItem('ERP 차량목록 운임비 검색·합계·필터 결과 요약', '🟡 보통', ['개선'], [
        h2('반영 내용'),
        bul('운임비 정확검색, 운임비 컬럼, 필터 결과 운임비 합계를 추가했습니다.'),
        bul('필터 결과 요약을 한 카드 안에서 매입총액, 판매총액, 운임비 합계로 나란히 보여주도록 정리했습니다.'),
        para('관련 커밋: 000a694, f78c8c8, 3bb3b1f, cf2a26e'),
    ]),
    doneItem('ERP 마감된 달 뒤늦은 정산 이월 처리', '🔴 높음', ['수정'], [
        h2('반영 내용'),
        bul('완납월이 이미 마감된 달이면 뒤늦게 귀속되는 정산을 현재 열린 달로 이월하도록 했습니다.'),
        bul('마감된 월배치에 과거 정산이 뒤늦게 섞이는 문제를 막았습니다.'),
        para('관련 커밋: 96e6530'),
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
    echo "ℹ️ 확인만 했습니다. 실제로 넣으려면: php scripts/notion-dev-status-2026-07-20.php --apply\n";
}
