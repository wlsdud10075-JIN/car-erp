<?php

/**
 * ERP 재무 가이드에 2026-07-24 "정산 회계잠금 개편" 노트를 in-place 삽입 (footer 바로 위).
 *  - 전체 교체 아님. footer(running log) 앞에 PATCH children + after. 멱등(같은 marker 있으면 스킵).
 *
 * 확인:  php scripts/notion-erp-feature-note-2026-07-24.php
 * 적용:  php scripts/notion-erp-feature-note-2026-07-24.php --apply
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

$jobs = [
    '재무' => [
        'id' => '37345d82-bd83-8192-a6f5-c71b60d69551',
        'marker' => '정산 회계잠금 — 2차 마감 후로 완화',
        'blocks' => [
            h2('🔓 정산 회계잠금 — 2차 마감 후로 완화 (2026-07-24 개편)'),
            callout('🔓', '이제 매입/판매 잔금을 확정해도, 정산을 2차 마감하기 전에는 매입가·판매가·환율·비용·잔금을 자유롭게 수정할 수 있습니다. 수정한 만큼 정산이 자동으로 +/- 반영·이월되므로, 앞 단계 금액이 바뀌어도 정산에서 걸러집니다. (예전에는 잔금이 확정되면 바로 잠겼지만, 이제는 마감 전까지 열려 있습니다.)', 'green_background'),
            callout('🔒', '2차 정산을 "2차 완료"로 마감하면 그 차량의 회계값이 잠깁니다. 마감 후 다시 고쳐야 하면 정산 화면의 마감된 행에서 "회계 재조정" 버튼을 눌러 사유를 남기고 차량 편집으로 이동해 수정합니다. 단, 마감 후 재조정은 기록용입니다 — 이미 지급된 정산액을 자동으로 다시 계산하지는 않습니다.', 'gray_background'),
            callout('💡', '정산 없이 잔금만 확정된 재고 차량(딜러 대금만 먼저 지급한 경우)도 이제 막힘 없이 편집됩니다. 모든 금액 변경은 감사 로그에 남습니다.', 'gray_background'),
        ],
    ],
];

foreach ($jobs as $name => $job) {
    $pid = $job['id'];
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

    $footerIdx = null;
    foreach ($blocks as $i => $b) {
        if (($b['type'] ?? '') !== 'callout') {
            continue;
        }
        $ft = rt($b, 'callout');
        if (str_contains($ft, 'running log') || str_contains($ft, '남기세요') || (str_contains($ft, '사내 업무 가이드') && str_contains($ft, '갱신'))) {
            $footerIdx = $i;
        }
    }
    if ($footerIdx === null || $footerIdx === 0) {
        fwrite(STDERR, "[$name] footer(running log) anchor 못 찾음 — 수동 확인 필요\n");

        continue;
    }
    $anchorId = $blocks[$footerIdx - 1]['id'];

    echo "+ [$name] '{$job['marker']}' 섹션 ".count($job['blocks'])."블록 삽입 (footer 위, anchor=$anchorId)\n";
    if ($apply) {
        notion('PATCH', "$BASE/blocks/$pid/children", ['children' => $job['blocks'], 'after' => $anchorId], $token, $V);
        echo "  ✅ 삽입 완료\n";
    }
}

echo "\n".($apply ? "✅ 완료.\n" : "ℹ️ 확인만. 적용:  php scripts/notion-erp-feature-note-2026-07-24.php --apply\n");
