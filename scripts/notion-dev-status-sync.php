<?php

/**
 * Notion 「개발 현황판」 ← 배포 기록(`docs/operations/aws-deployment-record.md`) 동기화 (2026-10-27, jin «(가)로 해줘. 내 기록용이야»).
 *
 *   원본 = 배포 기록 문서의 날짜 머리 항목(`## 2026-10-27 (점심) — master …`). 항목 하나 = 카드 하나.
 *   카드 제목 = 「📦 」 + 머리글(백틱·굵게 제거). 상태 완료 · 대표 진 · 분류 운영·배포. 본문 = 그 항목의 줄들.
 *   한 번 올린 카드는 다시 안 건드린다(기록용 — 문서를 고쳐도 카드는 그대로). 제목이 같으면 이미 있는 것으로 본다.
 *
 * 사용:
 *   php scripts/notion-dev-status-sync.php --verify            # 읽기 전용 — 빠진 카드 목록. 빠진 게 있으면 exit 1(실패 아님)
 *   php scripts/notion-dev-status-sync.php --apply             # 빠진 카드를 날짜순으로 올린다
 *   php scripts/notion-dev-status-sync.php --apply --since=2026-10-01
 *
 * 야간: 회사 PC `nightly-notion.ps1` 이 04:30 에 --verify → (-Apply 면) --apply 를 직접 돌린다(LLM 없음, Codex 안 거침).
 * 🚫 08-29 까지의 수동 카드(`notion-dev-status-2026-*.php`)와 겹치지 않게 시작일 = 2026-08-30.
 */
$token = getenv('NOTION_TOKEN') ?: '';
$apply = in_array('--apply', $argv, true);
$since = '2026-08-30';
foreach ($argv as $a) {
    if (str_starts_with($a, '--since=')) {
        $since = substr($a, 8);
    }
}
$base = 'https://api.notion.com/v1';
$version = '2022-06-28';
$record = __DIR__.'/../docs/operations/aws-deployment-record.md';

if ($token === '') {
    fwrite(STDERR, "❌ NOTION_TOKEN 설정 필요\n");
    exit(1);
}
if (! is_file($record)) {
    fwrite(STDERR, "❌ 배포 기록 문서가 없다: $record\n");
    exit(1);
}

function notion(string $method, string $url, array $body, string $token, string $version): array
{
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
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string) $raw, true) ?? [];
        if ($raw !== false && $code > 0 && ! in_array($code, [409, 429, 500, 502, 503, 504], true)) {
            if ($code >= 300) {
                fwrite(STDERR, "❌ Notion API 오류 ($code): ".($json['message'] ?? $raw)."\n");
                exit(1);
            }

            return $json;
        }
        usleep(400000 * $try);
    }
    fwrite(STDERR, '❌ Notion API 연결 실패: '.($error ?: '응답 없음')."\n");
    exit(1);
}

/** 마크다운 장식만 걷는다 — 백틱·굵게·링크 괄호. 뜻은 그대로. */
function plain(string $s): string
{
    $s = preg_replace('/`([^`]*)`/u', '$1', $s);
    $s = preg_replace('/\*\*([^*]*)\*\*/u', '$1', $s);
    $s = preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $s);

    return trim($s);
}

/** rich_text 조각 — Notion 은 조각 하나 2000자 상한. */
function rich(string $text): array
{
    $out = [];
    foreach (mb_str_split($text, 1900) as $chunk) {
        $out[] = ['type' => 'text', 'text' => ['content' => $chunk]];
    }

    return $out ?: [['type' => 'text', 'text' => ['content' => '']]];
}

function block(string $type, string $text): array
{
    return ['object' => 'block', 'type' => $type, $type => ['rich_text' => rich($text)]];
}

// ── 1. 배포 기록 파싱 ───────────────────────────────────────────────────
$lines = preg_split('/\r?\n/', file_get_contents($record));
$entries = [];
$cur = null;
foreach ($lines as $line) {
    if (preg_match('/^## (20\d\d-\d\d-\d\d)(.*)$/u', $line, $m)) {
        if ($cur) {
            $entries[] = $cur;
        }
        $cur = ['date' => $m[1], 'heading' => plain($m[1].$m[2]), 'body' => []];

        continue;
    }
    if (str_starts_with($line, '## ') || str_starts_with($line, '# ')) {   // 날짜 없는 절(번호 절·기타) = 항목 끝
        if ($cur) {
            $entries[] = $cur;
        }
        $cur = null;

        continue;
    }
    if ($cur !== null) {
        $cur['body'][] = $line;
    }
}
if ($cur) {
    $entries[] = $cur;
}
$entries = array_values(array_filter($entries, fn ($e) => $e['date'] >= $since));
usort($entries, fn ($a, $b) => [$a['date'], $a['heading']] <=> [$b['date'], $b['heading']]);

$cards = [];
foreach ($entries as $e) {
    $title = '📦 '.mb_substr($e['heading'], 0, 180);
    $blocks = [];
    foreach ($e['body'] as $raw) {
        $t = plain($raw);
        if ($t === '' || $t === '---') {
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)$/u', $raw, $m)) {
            $blocks[] = block('bulleted_list_item', plain($m[1]));
        } elseif (preg_match('/^\s*\d+\.\s+(.*)$/u', $raw, $m)) {
            $blocks[] = block('numbered_list_item', plain($m[1]));
        } elseif (preg_match('/^###\s+(.*)$/u', $raw, $m)) {
            $blocks[] = block('heading_3', plain($m[1]));
        } else {
            $blocks[] = block('paragraph', $t);
        }
    }
    if (count($blocks) > 95) {   // children 한 번에 100블록 상한
        $blocks = array_slice($blocks, 0, 95);
        $blocks[] = block('paragraph', '(이하 생략 — 레포 docs/operations/aws-deployment-record.md 참조)');
    }
    $blocks[] = block('paragraph', '원본: car-erp docs/operations/aws-deployment-record.md · 자동 동기화 '.date('Y-m-d'));
    $cards[$title] = ['date' => $e['date'], 'title' => $title, 'body' => $blocks];
}
echo '📄 배포 기록 항목 '.count($cards).'건 ('.$since." 이후)\n";

// ── 2. 현황판 찾기 + 기존 제목 ────────────────────────────────────────────
echo "🔍 개발 현황판 검색 중...\n";
$search = notion('POST', "$base/search", ['filter' => ['property' => 'object', 'value' => 'database']], $token, $version);
$db = null;
foreach ($search['results'] as $result) {
    if (($result['title'][0]['plain_text'] ?? '') === '개발 현황판') {
        $db = $result;
        break;
    }
}
if (! $db) {
    fwrite(STDERR, "❌ 개발 현황판을 찾지 못했습니다.\n");
    exit(1);
}
$dbId = $db['id'];
echo "   ✅ id=$dbId\n";

$titles = [];
$cursor = null;
do {
    $query = ['page_size' => 100];
    if ($cursor) {
        $query['start_cursor'] = $cursor;
    }
    $pages = notion('POST', "$base/databases/$dbId/query", $query, $token, $version);
    foreach ($pages['results'] as $page) {
        $t = '';
        foreach ($page['properties']['제목']['title'] ?? [] as $part) {
            $t .= $part['plain_text'] ?? '';
        }
        $titles[] = $t;
    }
    $cursor = ($pages['has_more'] ?? false) ? ($pages['next_cursor'] ?? null) : null;
} while ($cursor);
echo '📊 기존 카드 '.count($titles)."건\n";

// ── 3. 대조 / 발행 ───────────────────────────────────────────────────────
$missing = array_filter($cards, fn ($c) => ! in_array($c['title'], $titles, true));
if ($missing === []) {
    echo "✅ 정합 — 배포 기록 항목이 전부 현황판에 있습니다.\n";
    exit(0);
}
echo '🔎 대조 — 현황판에 없는 배포 기록 '.count($missing)."건\n";
foreach ($missing as $c) {
    echo '  ❌ '.$c['title'].' (본문 '.count($c['body'])."블록)\n";
}
if (! $apply) {
    echo '⚠️ 불일치 '.count($missing)."건. --apply 로 올리세요.\n";
    exit(1);
}

$added = 0;
foreach ($missing as $c) {
    notion('POST', "$base/pages", [
        'parent' => ['database_id' => $dbId],
        'properties' => [
            '제목' => ['title' => [['text' => ['content' => $c['title']]]]],
            '상태' => ['select' => ['name' => '완료']],
            '대표' => ['select' => ['name' => '진']],
            '분류' => ['multi_select' => [['name' => '운영·배포']]],
        ],
        'children' => $c['body'],
    ], $token, $version);
    echo '  ✅ '.$c['title']."\n";
    $added++;
}
echo "✅ 발행 완료: {$added}건\n";
exit(0);
