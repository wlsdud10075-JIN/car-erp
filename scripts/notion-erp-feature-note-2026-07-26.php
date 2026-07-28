<?php

/**
 * ERP 재무·관리 가이드에 2026-07-26 대시보드 할일 변경 노트를 footer 바로 위에 삽입한다.
 *
 * 확인:  php scripts/notion-erp-feature-note-2026-07-26.php
 * 적용:  php scripts/notion-erp-feature-note-2026-07-26.php --apply
 */
$token = getenv('NOTION_TOKEN') ?: '';
$apply = in_array('--apply', $argv, true);
$V = '2022-06-28';
$BASE = 'https://api.notion.com/v1';
if (! str_starts_with($token, 'ntn_') && ! str_starts_with($token, 'secret_')) {
    fwrite(STDERR, "NOTION_TOKEN 미설정\n");
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

function rt(array $block, string $type): string
{
    $text = '';
    foreach ($block[$type]['rich_text'] ?? [] as $segment) {
        $text .= $segment['plain_text'] ?? '';
    }

    return $text;
}

function tx(string $text): array
{
    return [['type' => 'text', 'text' => ['content' => $text]]];
}

function h2(string $text): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx($text)]];
}

function callout(string $emoji, string $text, string $color): array
{
    return [
        'object' => 'block',
        'type' => 'callout',
        'callout' => [
            'icon' => ['type' => 'emoji', 'emoji' => $emoji],
            'color' => $color,
            'rich_text' => tx($text),
        ],
    ];
}

$jobs = [
    '재무' => [
        'id' => '37345d82-bd83-8192-a6f5-c71b60d69551',
        'marker' => '대시보드 재무 할일 — 운임 확정과 2차 마감',
        'blocks' => [
            h2('📌 대시보드 재무 할일 — 운임 확정과 2차 마감 (2026-07-26)'),
            callout('1️⃣', '업무 대시보드의 「운임(인코텀즈) 확정 필요」를 먼저 확인하세요. 판매대금이 완납됐는데 정산이 생기지 않으면 FOB/CFR 인코텀즈를 확정하고, CFR은 운임비까지 입력해야 정산이 생성됩니다.', 'yellow_background'),
            callout('2️⃣', '정산 지급 후에는 「2차 정산 마감 대기」를 확인하세요. 실제 비용과 환차를 보정한 뒤 「2차 완료」로 마감해야 회계 잠금까지 끝납니다.', 'blue_background'),
        ],
    ],
    '관리(통합)' => [
        'id' => '37645d82-bd83-81ea-92d4-dd1b46698ffe',
        'marker' => '대시보드 관리 할일 — 승인 대기와 재고 점검',
        'blocks' => [
            h2('📌 대시보드 관리 할일 — 승인 대기와 재고 점검 (2026-07-26)'),
            callout('✅', '업무 대시보드의 승인 대기 카드는 차량간이체·보증금 선지급·미수 우회의 실제 대기 건수를 표시합니다. 0으로 고정되던 문제가 해결됐으므로 숫자가 있으면 해당 승인 큐를 바로 확인하세요.', 'green_background'),
            callout('📦', '「재고 점검」에는 일반재고(투기·장기·바이어 미정) 점검 대상이 표시됩니다. 카드를 눌러 재고관리로 이동해 판매 여부와 출고 상태를 확인하세요.', 'blue_background'),
        ],
    ],
];

foreach ($jobs as $name => $job) {
    $pageId = $job['id'];
    $blocks = [];
    $cursor = null;
    do {
        $result = notion('GET', "$BASE/blocks/$pageId/children?page_size=100".($cursor ? "&start_cursor=$cursor" : ''), [], $token, $V);
        foreach ($result['results'] as $block) {
            $blocks[] = $block;
        }
        $cursor = ($result['has_more'] ?? false) ? ($result['next_cursor'] ?? null) : null;
    } while ($cursor);

    $already = false;
    foreach ($blocks as $block) {
        $type = $block['type'] ?? '';
        if (in_array($type, ['heading_2', 'heading_3'], true) && str_contains(rt($block, $type), $job['marker'])) {
            $already = true;
            break;
        }
    }
    if ($already) {
        echo "ℹ️ [$name] 이미 '{$job['marker']}' 섹션 존재 — 스킵.\n";

        continue;
    }

    $footerIndex = null;
    foreach ($blocks as $index => $block) {
        if (($block['type'] ?? '') !== 'callout') {
            continue;
        }
        $footerText = rt($block, 'callout');
        if (str_contains($footerText, 'running log')
            || str_contains($footerText, '남기세요')
            || (str_contains($footerText, '사내 업무 가이드') && str_contains($footerText, '갱신'))) {
            $footerIndex = $index;
        }
    }
    if ($footerIndex === null || $footerIndex === 0) {
        fwrite(STDERR, "[$name] footer(running log) anchor 못 찾음 — 수동 확인 필요\n");

        continue;
    }
    $anchorId = $blocks[$footerIndex - 1]['id'];

    echo "+ [$name] '{$job['marker']}' 섹션 ".count($job['blocks'])."블록 삽입 (footer 위, anchor=$anchorId)\n";
    if ($apply) {
        notion('PATCH', "$BASE/blocks/$pageId/children", ['children' => $job['blocks'], 'after' => $anchorId], $token, $V);
        echo "  ✅ 삽입 완료\n";
    }
}

echo "\n".($apply
    ? "✅ 완료.\n"
    : "ℹ️ 확인만. 적용: php scripts/notion-erp-feature-note-2026-07-26.php --apply\n");
