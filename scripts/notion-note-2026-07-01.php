<?php

/**
 * Notion 업무 가이드 — 2026-07-01 배포분 반영.
 *   (D 정정) 수출통관 페이지의 "미입금 우회 단계" callout 을 통관·선적 진입 통합 문구로 in-place 교체.
 *   (A/B/C/D) 수출통관 · 재무 페이지에 신규 기능 how-to 섹션을 footer(running log) 앞에 append.
 *   - 멱등: 교체는 이미 새 문구(진입(통관·선적))면 스킵 / append 는 같은 marker heading 있으면 스킵.
 *
 * 확인:  php scripts/notion-note-2026-07-01.php
 * 적용:  php scripts/notion-note-2026-07-01.php --apply
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
function allBlocks(string $pid, string $t, string $v, string $base): array
{
    $blocks = [];
    $cur = null;
    do {
        $r = notion('GET', "$base/blocks/$pid/children?page_size=100".($cur ? "&start_cursor=$cur" : ''), [], $t, $v);
        foreach ($r['results'] as $b) {
            $blocks[] = $b;
        }
        $cur = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cur);

    return $blocks;
}

$CLEARANCE = '37345d82-bd83-8181-abab-ced89ae3b496';
$FINANCE = '37345d82-bd83-8192-a6f5-c71b60d69551';

// ── D 정정: 수출통관 "미입금 우회 단계" callout 교체 ──────────────
$NEW_GATE = '미입금 우회 승인은 진입(통관·선적)과 B/L 발행 두 갈래입니다 — 통관·선적 진입(50%)은 이제 승인 1건이면 둘 다 통과합니다(예전처럼 통관/선적을 따로 승인하지 않음). B/L 발행(100%)은 그대로 별도 승인. 50% 미만 차량을 B/L까지 보내려면 진입 1건 + B/L 1건, 총 두 건의 승인이 필요합니다.';

echo "▶ [D 정정] 수출통관 미입금 우회 블록 점검...\n";
$cBlocks = allBlocks($CLEARANCE, $token, $V, $BASE);
$fixTarget = null;
$fixType = null;
foreach ($cBlocks as $b) {
    $type = $b['type'] ?? '';
    if (! in_array($type, ['callout', 'paragraph'], true)) {
        continue;
    }
    $txt = rt($b, $type);
    if (str_contains($txt, '진입(통관·선적)')) {   // 이미 새 문구
        echo "   ⏭  이미 통합 문구로 교체됨 — 스킵.\n";
        $fixTarget = 'done';
        break;
    }
    if (str_contains($txt, '미입금 우회 승인은 단계별로')) {   // 옛 3단계 문구
        $fixTarget = $b['id'];
        $fixType = $type;
    }
}
if ($fixTarget && $fixTarget !== 'done') {
    echo "   +  교체 대상 $fixType 발견 (id=$fixTarget)\n";
    echo "      → \"$NEW_GATE\"\n";
    if ($apply) {
        notion('PATCH', "$BASE/blocks/$fixTarget", [$fixType => ['rich_text' => tx('※ '.$NEW_GATE)]], $token, $V);
        echo "   ✅ 교체 완료\n";
    }
} elseif (! $fixTarget) {
    fwrite(STDERR, "   ⚠️ 옛 우회 블록 못 찾음 — 수동 확인 필요\n");
}

// ── A/B/C/D append: 신규 기능 how-to 섹션 ─────────────────────────
$jobs = [
    '수출통관' => [
        'id' => $CLEARANCE,
        'marker' => '2026-07-01 신규 (판매계약서·선적요청·2차 비용·우회 통합)',
        'blocks' => [
            h2('🆕 2026-07-01 신규 (판매계약서·선적요청·2차 비용·우회 통합)'),
            callout('📄', '수출 판매계약서를 여러 대 한 장으로 발급할 수 있습니다. 차량 목록에서 export 차량을 체크(같은 바이어·같은 통화만) → 상단 「판매계약서」 버튼, 또는 차량 편집 「서류」 탭. 외국인용이라 차량번호·브랜드·목적항이 로마자/영문으로 나갑니다.', 'blue_background'),
            callout('🚢', '선적요청 화면이 "할 일" 중심으로 바뀌었습니다 — 처리할 요청이 기본으로 보이고, 검색과 페이지 넘김이 생겼습니다.', 'gray_background'),
            callout('🧾', '「2차 비용」 탭에서 면허비를 묶음 단위로 한 번에 기입할 수 있습니다 — 묶음 총액을 첫 차량에 몰고 나머지는 n분의 1로 나눠집니다. 월 그룹은 정산 귀속월(정산 생성월) 기준입니다.', 'gray_background'),
            callout('🚦', '(변경) 통관·선적 진입 우회가 하나로 합쳐졌습니다 — 이제 승인 1건이면 통관·선적 둘 다 통과합니다(예전엔 각각 승인). B/L 발행(100%) 우회는 그대로 별도입니다. 승인 드롭다운도 「통관·선적 진입(50%)」·「B/L 발행(100%)」 2개로 정리됐습니다.', 'yellow_background'),
        ],
    ],
    '재무' => [
        'id' => $FINANCE,
        'marker' => '2026-07-01 신규 — 탁송비 명세서 일괄 기입',
        'blocks' => [
            h2('🚚 2026-07-01 신규 — 탁송비 명세서 일괄 기입'),
            callout('🚚', '2차 정산 시 탁송비를 업체 월명세서로 한 번에 기입할 수 있습니다. 차량 목록의 「명세서 기입」에서 엑셀 업로드 또는 붙여넣기 → 차량번호로 자동 매칭. 매칭 안 된 줄은 빨갛게 표시만 되고 기입되지 않습니다(유령 데이터 방지). 이미 2차 마감된 차량은 보호되어 값이 달라도 건드리지 않습니다.', 'gray_background'),
        ],
    ],
];

foreach ($jobs as $name => $job) {
    $pid = $job['id'];
    $blocks = allBlocks($pid, $token, $V, $BASE);

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

    // footer(running log) 앞에 삽입. 없으면 페이지 끝에 append.
    $footerIdx = null;
    foreach ($blocks as $i => $b) {
        if (($b['type'] ?? '') === 'callout' && str_contains(rt($b, 'callout'), 'running log')) {
            $footerIdx = $i;
        }
    }
    $body = ['children' => $job['blocks']];
    $where = '페이지 끝';
    if ($footerIdx !== null && $footerIdx > 0) {
        $body['after'] = $blocks[$footerIdx - 1]['id'];
        $where = "footer 위 (anchor={$blocks[$footerIdx - 1]['id']})";
    }
    echo "+ [$name] '{$job['marker']}' ".count($job['blocks'])."블록 삽입 ($where)\n";
    if ($apply) {
        notion('PATCH', "$BASE/blocks/$pid/children", $body, $token, $V);
        echo "  ✅ 삽입 완료\n";
    }
}

echo "\n".($apply ? "✅ 완료.\n" : "ℹ️ 확인만. 적용:  php scripts/notion-note-2026-07-01.php --apply\n");
