<?php

/**
 * 읽기 전용 — "사내 업무 가이드" 허브와 부서 페이지 블록을 텍스트로 덤프한다.
 * 라이브 Notion 게시 상태를 확인하기 위한 용도. 쓰기 작업 없음.
 */
$token = getenv('NOTION_TOKEN') ?: '';
if (! str_starts_with($token, 'ntn_') && ! str_starts_with($token, 'secret_')) {
    fwrite(STDERR, "NOTION_TOKEN 미설정\n");
    exit(1);
}
$V = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

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

function rt(array $block, string $type): string
{
    $arr = $block[$type]['rich_text'] ?? [];
    $s = '';
    foreach ($arr as $seg) {
        $s .= $seg['plain_text'] ?? ($seg['text']['content'] ?? '');
    }

    return $s;
}

function dumpBlocks(string $id, string $t, string $v, string $base, int $depth = 0): void
{
    $cursor = null;
    do {
        $url = "$base/blocks/$id/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] ?? [] as $b) {
            $type = $b['type'];
            $pad = str_repeat('  ', $depth);
            $text = match ($type) {
                'heading_1' => "\n{$pad}# ".rt($b, $type),
                'heading_2' => "\n{$pad}## ".rt($b, $type),
                'heading_3' => "{$pad}### ".rt($b, $type),
                'paragraph' => $pad.rt($b, $type),
                'bulleted_list_item' => "{$pad}- ".rt($b, $type),
                'numbered_list_item' => "{$pad}1. ".rt($b, $type),
                'to_do' => "{$pad}[ ] ".rt($b, $type),
                'callout' => "{$pad}[!] ".str_replace("\n", ' / ', rt($b, $type)),
                'divider' => "{$pad}----",
                'child_page' => "{$pad}[CHILD PAGE] ".($b['child_page']['title'] ?? ''),
                'toggle' => "{$pad}> ".rt($b, $type),
                'quote' => "{$pad}> ".rt($b, $type),
                'table', 'table_row', 'column_list', 'column' => "{$pad}({$type})",
                default => "{$pad}[{$type}] ".rt($b, $type),
            };
            if (trim($text) !== '') {
                echo $text."\n";
            }
            if (($b['has_children'] ?? false) && $type !== 'child_page') {
                dumpBlocks($b['id'], $t, $v, $base, $depth + 1);
            }
        }
        $cursor = $r['next_cursor'] ?? null;
    } while ($cursor);
}

// 1) 허브 검색
$ps = notion('POST', "$BASE/search", ['query' => '사내 업무 가이드', 'filter' => ['property' => 'object', 'value' => 'page']], $token, $V);
$hubId = null;
foreach ($ps['results'] ?? [] as $p) {
    $title = '';
    foreach (($p['properties']['title']['title'] ?? $p['properties']['Name']['title'] ?? []) as $seg) {
        $title .= $seg['plain_text'] ?? '';
    }
    echo "검색결과: {$title}  ({$p['id']})\n";
    if ($title === '사내 업무 가이드' && ! $hubId) {
        $hubId = $p['id'];
    }
}
echo str_repeat('=', 60)."\n";

if (! $hubId) {
    fwrite(STDERR, "허브 페이지 못 찾음\n");
    exit(1);
}

// 2) 허브 직속 자식 페이지 나열 + 각 페이지 내용 덤프
function childPages(string $id, string $t, string $v, string $base): array
{
    $out = [];
    $cursor = null;
    do {
        $url = "$base/blocks/$id/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'child_page') {
                $out[$b['child_page']['title']] = $b['id'];
            }
        }
        $cursor = $r['next_cursor'] ?? null;
    } while ($cursor);

    return $out;
}

$targets = $argv;
array_shift($targets); // script name

$kids = childPages($hubId, $token, $V, $BASE);
echo '허브 자식 페이지: '.implode(' | ', array_keys($kids))."\n";
echo str_repeat('=', 60)."\n";

foreach ($kids as $name => $id) {
    if ($targets && ! in_array($name, $targets, true)) {
        continue;
    }
    echo "\n\n";
    echo str_repeat('#', 60)."\n";
    echo "# 부서 페이지: {$name}\n";
    echo str_repeat('#', 60)."\n";
    dumpBlocks($id, $token, $V, $BASE);
    // 재무 밑 "관리(통합)" 같은 손자 페이지도
    foreach (childPages($id, $token, $V, $BASE) as $gName => $gId) {
        echo "\n\n";
        echo str_repeat('#', 50)."\n";
        echo "# (하위) {$name} > {$gName}\n";
        echo str_repeat('#', 50)."\n";
        dumpBlocks($gId, $token, $V, $BASE);
    }
}
