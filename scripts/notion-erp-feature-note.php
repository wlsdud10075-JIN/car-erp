<?php

/**
 * ERP 가이드(재무·관리통합)에 2026-06-30 신규 기능 노트를 in-place 삽입 (footer 바로 위).
 *  - 전체 교체 아님 (jin 재구성/편집분 보존). footer(running log) 앞에 PATCH children + after.
 *  - 멱등: 같은 제목 heading 이미 있으면 스킵.
 *
 * 확인:  php scripts/notion-erp-feature-note.php
 * 적용:  php scripts/notion-erp-feature-note.php --apply
 */
$token = getenv('NOTION_TOKEN') ?: '';
$apply = in_array('--apply', $argv, true);
$V = '2022-06-28';
$BASE = 'https://api.notion.com/v1';
if (! str_starts_with($token, 'ntn_') && ! str_starts_with($token, 'secret_')) {
    fwrite(STDERR, "NOTION_TOKEN 미설정\n");
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
        fwrite(STDERR, "❌ ($code) ".($j['message'] ?? $res)."\n");
        exit(1);
    }

    return $j;
}
function rt(array $b, string $type): string
{
    $s = '';
    foreach ($b[$type]['rich_text'] ?? [] as $seg) {
        $s .= $seg['plain_text'] ?? '';
    }

    return $s;
}
function tx(string $t): array
{
    return [['type' => 'text', 'text' => ['content' => $t]]];
}
function h2(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx($t)]];
}
function callout(string $e, string $t, string $c): array
{
    return ['object' => 'block', 'type' => 'callout', 'callout' => ['icon' => ['type' => 'emoji', 'emoji' => $e], 'color' => $c, 'rich_text' => tx($t)]];
}

// 대상 페이지 (notion-explore 로 확인된 id) + 삽입 블록.
$jobs = [
    '재무' => [
        'id' => '37345d82-bd83-8192-a6f5-c71b60d69551',
        'marker' => '정산관리 — 매입가 컬럼',
        'blocks' => [
            h2('💰 정산관리 — 매입가 컬럼 (2026-06-30 신규)'),
            callout('💰', '정산관리 목록에서 정산방식과 총마진 사이에 매입가가 표시됩니다. 사내직원 정산은 매입가 1억 이상이면 총마진×25%, 1억 미만이면 총마진 기준(100만 미만 10만 원 · 이상 20만 원)이라 — 매입가를 보면 정산방식이 왜 그 금액인지 바로 이해됩니다. 매입가 1억 이상은 강조 표시됩니다.', 'gray_background'),
        ],
    ],
    '관리 (통합)' => [
        'id' => '37645d82-bd83-81ea-92d4-dd1b46698ffe',
        'marker' => '영업에 [관리] 다수 배정',
        'blocks' => [
            h2('👥 영업에 [관리] 다수 배정 + 동시 편집 잠금 (2026-06-30 신규)'),
            callout('👥', '이제 영업 1명을 [관리] 여러 명이 함께 담당할 수 있습니다 — 사용자관리 → 해당 영업 사용자 편집 → "담당 [관리]" 칸에서 여러 명 체크. 예: 매입까지는 관리1, 그 이후는 관리2가 진행. 체크된 [관리]는 모두 그 영업의 차량/바이어를 조회·편집할 수 있습니다.', 'purple_background'),
            callout('🔒', '두 [관리]가 같은 차량을 동시에 열면, 나중에 연 사람 화면에 "○○○님이 수정 중 — 읽기 전용" 배너가 뜨고 저장이 막힙니다(서로 덮어쓰는 사고 방지). 앞사람이 닫거나 자리를 비우면 잠시 후 자동으로 풀려 이어서 편집할 수 있습니다.', 'gray_background'),
        ],
    ],
];

foreach ($jobs as $name => $job) {
    $pid = $job['id'];
    // 블록 스캔: 멱등 체크 + footer(running log) 앞 anchor 찾기.
    $blocks = [];
    $cur = null;
    do {
        $r = notion('GET', "$BASE/blocks/$pid/children?page_size=100".($cur ? "&start_cursor=$cur" : ''), [], $token, $V);
        foreach ($r['results'] as $b) {
            $blocks[] = $b;
        }
        $cur = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cur);

    $already = false;
    foreach ($blocks as $b) {
        $t = $b['type'];
        if (in_array($t, ['heading_2', 'heading_3'], true) && str_contains(rt($b, $t), $job['marker'])) {
            $already = true;
        }
    }
    if ($already) {
        echo "ℹ️ [$name] 이미 '{$job['marker']}' 섹션 존재 — 스킵.\n";

        continue;
    }

    // footer = 마지막 callout(running log). 그 앞 블록을 anchor 로 (footer 위에 삽입).
    $footerIdx = null;
    foreach ($blocks as $i => $b) {
        if (($b['type'] ?? '') === 'callout' && str_contains(rt($b, 'callout'), 'running log')) {
            $footerIdx = $i;
        }
    }
    if ($footerIdx === null || $footerIdx === 0) {
        fwrite(STDERR, "[$name] footer anchor 못 찾음 — 수동 확인\n");

        continue;
    }
    $anchorId = $blocks[$footerIdx - 1]['id'];   // footer 바로 앞 블록

    echo "+ [$name] '{$job['marker']}' 섹션 ".count($job['blocks'])."블록 삽입 (footer 위, anchor=$anchorId)\n";
    if ($apply) {
        notion('PATCH', "$BASE/blocks/$pid/children", ['children' => $job['blocks'], 'after' => $anchorId], $token, $V);
        echo "  ✅ 삽입 완료\n";
    }
}

echo "\n".($apply ? "✅ 완료.\n" : "ℹ️ 확인만. 적용:  php scripts/notion-erp-feature-note.php --apply\n");
