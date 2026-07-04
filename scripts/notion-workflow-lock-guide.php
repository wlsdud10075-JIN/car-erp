<?php

/**
 * Notion "사내 업무 가이드 · ERP" 허브 밑에 신규 2페이지 생성/갱신:
 *   🔄 전체 워크플로우 (한눈에)   — 파이프라인 인덱스 + 부서 페이지 link_to_page (재설명 금지, drift 방지)
 *   ⚠️ 에러 & 잠금(락) 찾아보기     — 증상 → 원인(게이트/락) → 해결·우회 카탈로그 (핵심 산출물)
 *
 * 기존 부서 페이지(영업/재무/수출통관/관리)는 건드리지 않음 — 신규 페이지만 생성.
 * 멱등: 같은 title 자식 있으면 기존 블록 clear 후 재append(중복 페이지 방지).
 *
 * 확인(dry-run):  php scripts/notion-workflow-lock-guide.php
 * 실제 발행:       php scripts/notion-workflow-lock-guide.php --apply
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$apply = in_array('--apply', $argv, true);
$HUB_TITLE = '사내 업무 가이드';
$V = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

if (str_contains($token, '여기에_')) {
    fwrite(STDERR, "❌ NOTION_TOKEN 설정 필요\n");
    exit(1);
}

// ── HTTP ────────────────────────────────────────────────────
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

// ── block builders ──────────────────────────────────────────
function seg(string $t, array $ann = []): array
{
    $s = ['type' => 'text', 'text' => ['content' => $t]];
    if ($ann) {
        $s['annotations'] = $ann;
    }

    return $s;
}
function tx(string $t): array
{
    return [seg($t)];
}
function h2(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx($t)]];
}
function h3(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_3', 'heading_3' => ['rich_text' => tx($t)]];
}
function para(string $t): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => tx($t)]];
}
function pararich(array $segs): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => $segs]];
}
function bul(string $t): array
{
    return ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx($t)]];
}
function callout(string $e, string $t, string $c = 'gray_background'): array
{
    return ['object' => 'block', 'type' => 'callout', 'callout' => ['icon' => ['type' => 'emoji', 'emoji' => $e], 'color' => $c, 'rich_text' => tx($t)]];
}
function divider(): array
{
    return ['object' => 'block', 'type' => 'divider', 'divider' => (object) []];
}
function linkpage(string $pid): array
{
    return ['object' => 'block', 'type' => 'link_to_page', 'link_to_page' => ['type' => 'page_id', 'page_id' => $pid]];
}
// 표: $rows[0]=헤더, 각 행은 셀 문자열 배열(개수 = 열 수 일치)
function table(array $rows): array
{
    $width = count($rows[0]);
    $trs = [];
    foreach ($rows as $r) {
        $cells = [];
        foreach ($r as $c) {
            $cells[] = [seg((string) $c)];
        }
        $trs[] = ['object' => 'block', 'type' => 'table_row', 'table_row' => ['cells' => $cells]];
    }

    return ['object' => 'block', 'type' => 'table', 'table' => [
        'table_width' => $width, 'has_column_header' => true, 'has_row_header' => false, 'children' => $trs,
    ]];
}

// ── 콘텐츠: 워크플로우 인덱스 ────────────────────────────────
function buildWorkflow(?string $finId, ?string $cleId, ?string $mgrId, ?string $catalogId): array
{
    $b = [];
    $b[] = callout('🔄', '한눈에 보는 전체 업무 흐름입니다. 단계마다 담당 role이 다르고, 판매 입금률에 따라 진행 게이트가 걸립니다. 상세 절차는 각 부서 가이드로 이동하세요(아래 링크). 여기서는 순서·담당·게이트만 요약합니다.', 'blue_background');
    $b[] = h2('전체 파이프라인 (car-erp v4)');
    $b[] = bul('① 매입  [관리·영업] — 차량 등록 · 매입가 · 매도비 · 비용');
    $b[] = bul('② 말소  [관리] — 말소신청 · 등록증 · 위임장');
    $b[] = bul('③ 판매 · 입금  [재무] — 판매가 · 통화 · 환율 · 바이어, 입금 확정(계약금/중도금/잔금)');
    $b[] = bul('④ 반입(선적) · 통관  [수출통관] — 입금률 50% 이상 필요 · 통관 바이어 · 포워딩사 · 면장 · 수출신고서');
    $b[] = bul('⑤ B/L 발급  [수출통관] — 잔금 100% 완납 필요 · B/L 문서 업로드 → 거래완료');
    $b[] = bul('⑥ DHL · 거래완료  [수출통관] — 수취인 · 발송신청');
    $b[] = bul('⑦ 정산 · 2차 정산  [재무 확정 → 관리 지급] — 마진 자동계산 · confirmed · paid · closed');
    $b[] = divider();
    $b[] = h2('판매채널 3종');
    $b[] = bul('export(수출) — 다중통화, 통관 · B/L · DHL 풀 흐름');
    $b[] = bul('heyman(헤이맨) / carpul(카풀) — 국내 바이어, 원화 정산, 통관 · B/L · DHL 흐름 없음');
    $b[] = divider();
    $b[] = h2('🚦 진행 게이트 (막히면 아래 「에러 & 잠금」으로)');
    $b[] = callout('🟡', '입금률 50% 이상 → 반입(선적) · 통관 진입 가능. (미달 시 관리/관리자 미입금 우회 승인 — 진입 1건이면 통관·선적 둘 다 통과)', 'yellow_background');
    $b[] = callout('🔴', '잔금 100% 완납 → B/L 발급 가능. (미완납은 관리/관리자 우회 승인 — B/L 발행 단계 별도. 진입 우회로는 안 뚫림)', 'red_background');
    if ($catalogId) {
        $b[] = para('👉 저장이 막히거나 왜 안 되는지 찾을 때:');
        $b[] = linkpage($catalogId);
    }
    $b[] = divider();
    $b[] = h2('📖 부서별 상세 가이드');
    if ($mgrId) {
        $b[] = para('👉 관리(통합) — 등록 · 판매 · 통관 · 정산 전체:');
        $b[] = linkpage($mgrId);
    }
    if ($finId) {
        $b[] = para('👉 재무 — 입금 · 지급 · 미수금 · 정산:');
        $b[] = linkpage($finId);
    }
    if ($cleId) {
        $b[] = para('👉 수출통관 — 반입 · 통관 · B/L · DHL:');
        $b[] = linkpage($cleId);
    }
    $b[] = divider();
    $b[] = callout('🕒', 'SSANCAR 사내 업무 가이드 · 전체 워크플로우 인덱스 · 2026-07-04 생성(자동). 상세는 부서 페이지가 단일 출처 — 이 페이지는 흐름·게이트 요약만.', 'gray_background');

    return $b;
}

// ── 콘텐츠: 에러 & 잠금 카탈로그 ────────────────────────────
function buildLockCatalog(?string $workflowId): array
{
    $b = [];
    $b[] = callout('⚠️', '"왜 저장이 안 되지 / 왜 막히지" 할 때 여기서 증상으로 찾으세요. 대부분 버그가 아니라 의도된 게이트·잠금(락)입니다. 각 표: 증상(화면에 뜨는 문구) → 원인 → 해결·우회(누가 승인).', 'red_background');

    $b[] = h2('A. 입금률 게이트 — 진행 단계 진입 차단');
    $b[] = table([
        ['증상 (화면 문구)', '원인', '해결 · 우회'],
        ['"판매 입금률 < 50% (미수율 XX%) 차량은 통관·선적 진입 불가"', 'C5 게이트 — 판매 입금률 50% 미만', '50% 이상 입금, 또는 관리/관리자 [미입금 우회] 승인. 진입 우회 1건이면 통관·선적 둘 다 통과'],
        ['"환율 미입력 외화 차량은 통관 진입 불가"', '외화차 환율 0 → 미수율 평가 불가', '환율 입력(송금받을때 기준), 또는 관리자 미입금 우회 승인'],
        ['"B/L 발행 차단 — 미수율 XX% (잔금 100% 미완납)"', 'G1 게이트 — 잔금 100% 미완납 (화물 인도권)', '100% 완납, 또는 관리/관리자 우회 승인 \'B/L 발행\' 단계. ※진입(50%) 우회로는 안 뚫림 — 별도 승인'],
        ['"B/L 발행 전 판매 정보(판매가) 입력 필수"', '판매가 없어 미수율 평가 불가', '판매가·판매일·바이어·환율 먼저 입력'],
    ]);

    $b[] = h2('B. 신규 거래 승인');
    $b[] = table([
        ['증상', '원인', '해결'],
        ['"이 바이어는 미수 잔존 차량이 있습니다. 신규 거래는 관리자 승인이 필요합니다."', '미수 잔존 바이어로 신규 차량 등록 시도', '[신규 거래 승인 요청] → 관리 승인 후 등록. 승인은 차량번호 1건에 바인딩(다른 번호로 저장 시 재차단)'],
    ]);

    $b[] = h2('C. 회계 잠금 — 확정/지급 후 데이터 보호');
    $b[] = table([
        ['증상', '원인', '해결'],
        ['매입가·판매가·환율·비용 등이 회색(수정 안 됨)', 'Ledger 잠금 — 재무 확정 잔금이 있는 차량의 회계영향 필드 21종 변경 차단', 'super/admin·관리(본인팀) [🔓 잠금 해제] → 사유 10자 이상 → 1회 변경(저장 즉시 재잠금, AuditLog 기록)'],
        ['"재무 확정 잔금이 있는 차량은 admin/super만 삭제할 수 있습니다."', '확정 잔금 차량 삭제 차단', 'admin/super만 삭제 가능'],
        ['정산 삭제가 막힘(토스트)', 'confirmed/paid/2차완료(closed) 정산 삭제 자체 차단 — 감사추적·스냅샷 보존', '삭제 가능한 정산은 대기(pending)/계산중뿐'],
        ['확정된 잔금(FP/PBP) 금액·날짜 수정 안 됨', 'paid 스냅샷 잠금 — confirmed_at 이후 amount/payment_date/transfer_id 변경 차단', '비용 보정은 2차 정산 절차 안에서만'],
    ]);

    $b[] = h2('D. 정산 직무 분리 · 2차 봉인');
    $b[] = table([
        ['증상', '원인', '해결'],
        ['재무가 지급(paid) 처리를 직접 못 함', '직무 분리 — 재무는 confirmed까지, paid는 관리/관리자 승인', '승인 요청 흐름(본인 요청·본인 지급 차단)'],
        ['2차 마감(closed) 후 비용이 안 바뀜 / 일괄기입 skip=settlement_closed', '2차 closed 봉인(회계 잠금)', '개별 [🔓 잠금 해제]로만 소급 변경'],
    ]);

    $b[] = h2('E. 저장 CHECK 제약 (DB) · 동작 변경');
    $b[] = table([
        ['증상', '원인', '해결'],
        ['판매가만 넣고 저장 실패(MySQL 4025)', 'chk_sale_required — sale_price>0이면 sale_date·buyer·환율>0 필수', '판매가 입력 시 판매일·바이어·환율 항상 함께'],
        ['적립금 사용이 잔액 초과 시 막힘', 'SavingsStatus 잔액 음수 차단(DB CHECK + service)', '바이어×통화 잔액 범위 내에서만 사용'],
        ['"매입가 저장했는데 재무처리 대기에 자동으로 안 떠요"', '매입 자동 PBP Draft 폐기(2026-07-03) — 의도된 변경, 버그 아님', '미지급은 대시보드 「매입 미지급」 KPI + 편집 「매입 미지급 요약」 박스로 확인. 실제 지급 시 재무가 /erp/transfers 매입 잔금 탭 [신규 입력]으로 직접 기록·확정'],
        ['동시에 같은 차량 열면 "○○○님이 수정 중 — 읽기 전용" 배너, 저장 막힘', '동시 편집 잠금(관리 여럿이 한 영업 담당)', '앞사람이 편집 닫으면 자동 해제 후 이어서 편집'],
    ]);

    $b[] = divider();
    if ($workflowId) {
        $b[] = para('👉 전체 흐름·게이트 요약:');
        $b[] = linkpage($workflowId);
    }
    $b[] = callout('🕒', 'SSANCAR 사내 업무 가이드 · 에러 & 잠금 카탈로그 · 2026-07-04 생성(자동). 새 게이트/락 추가 시 여기 표에 한 줄씩.', 'gray_background');

    return $b;
}

// ── 페이지 트리 ─────────────────────────────────────────────
function childPages(string $pid, string $t, string $v): array
{
    $out = [];
    $cursor = null;
    do {
        $url = "https://api.notion.com/v1/blocks/$pid/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] as $blk) {
            if (($blk['type'] ?? '') === 'child_page') {
                $out[$blk['child_page']['title']] = $blk['id'];
            }
        }
        $cursor = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cursor);

    return $out;
}
function clearBlocks(string $pid, string $t, string $v, bool $apply): int
{
    $ids = [];
    $cursor = null;
    do {
        $url = "https://api.notion.com/v1/blocks/$pid/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] as $blk) {
            if (($blk['type'] ?? '') === 'child_page') {
                continue;
            }
            $ids[] = $blk['id'];
        }
        $cursor = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cursor);
    if ($apply) {
        foreach ($ids as $id) {
            notion('DELETE', "https://api.notion.com/v1/blocks/$id", [], $t, $v);
        }
    }

    return count($ids);
}
function appendBlocks(string $pid, array $blocks, string $t, string $v): void
{
    foreach (array_chunk($blocks, 90) as $chunk) {
        notion('PATCH', "https://api.notion.com/v1/blocks/$pid/children", ['children' => $chunk], $t, $v);
    }
}
// 신규/갱신: parent 밑에 title 자식 있으면 clear+append, 없으면 생성. 페이지 id 반환(apply 아니면 기존 id 또는 null).
function upsertPage(string $parentId, string $title, string $emoji, array $blocks, string $t, string $v, bool $apply, string $BASE): ?string
{
    $existing = null;
    foreach (childPages($parentId, $t, $v) as $ti => $id) {
        if ($ti === $title) {
            $existing = $id;
            break;
        }
    }
    if ($existing) {
        $del = clearBlocks($existing, $t, $v, $apply);
        if ($apply) {
            appendBlocks($existing, $blocks, $t, $v);
            echo "   ✔ '$title' — 기존 {$del}블록 삭제 + ".count($blocks)."블록 발행 (id=$existing)\n";
        } else {
            echo "   + '$title' — 기존 있음, {$del}블록 삭제 + ".count($blocks)."블록 재발행 예정 (id=$existing)\n";
        }

        return $existing;
    }
    if ($apply) {
        $pg = notion('POST', "$BASE/pages", [
            'parent' => ['type' => 'page_id', 'page_id' => $parentId],
            'icon' => ['type' => 'emoji', 'emoji' => $emoji],
            'properties' => ['title' => ['title' => tx($title)]],
            'children' => array_slice($blocks, 0, 90),
        ], $t, $v);
        if (count($blocks) > 90) {
            appendBlocks($pg['id'], array_slice($blocks, 90), $t, $v);
        }
        echo "   ✔ '$title' — 신규 생성 (id={$pg['id']})\n";

        return $pg['id'];
    }
    echo "   + '$title' — 신규 생성 예정 (".count($blocks)."블록)\n";

    return null;
}

// ── 실행 ────────────────────────────────────────────────────
echo "▶ '$HUB_TITLE' 허브 검색...\n";
$ps = notion('POST', "$BASE/search", ['query' => $HUB_TITLE, 'filter' => ['property' => 'object', 'value' => 'page']], $token, $V);
$hubId = null;
foreach ($ps['results'] as $p) {
    foreach (($p['properties'] ?? []) as $prop) {
        if (($prop['type'] ?? '') === 'title' && ($prop['title'][0]['plain_text'] ?? '') === $HUB_TITLE) {
            $hubId = $p['id'];
            break 2;
        }
    }
}
if (! $hubId) {
    fwrite(STDERR, "❌ 허브 '$HUB_TITLE' 없음.\n");
    exit(1);
}
echo "   • 허브 id=$hubId\n";

// ERP 허브 찾기 (허브 직속 자식 중 title에 'ERP' 포함)
$hubKids = childPages($hubId, $token, $V);
echo '▶ 허브 하위: '.implode(', ', array_keys($hubKids))."\n";
$erpId = null;
foreach ($hubKids as $ti => $id) {
    if (str_contains($ti, 'ERP')) {
        $erpId = $id;
        break;
    }
}
if (! $erpId) {
    fwrite(STDERR, "❌ ERP 허브 페이지(title에 'ERP') 없음. 구조 확인 필요.\n");
    exit(1);
}
echo "   • ERP 허브 id=$erpId\n";

// ERP 밑 부서 페이지 (링크 대상)
$erpKids = childPages($erpId, $token, $V);
echo '▶ ERP 하위: '.implode(', ', array_keys($erpKids))."\n";
$finId = null;
$cleId = null;
$mgrId = null;
foreach ($erpKids as $ti => $id) {
    if (str_starts_with($ti, '재무')) {
        $finId = $id;
    } elseif (str_starts_with($ti, '수출통관')) {
        $cleId = $id;
    } elseif (str_starts_with($ti, '관리')) {
        $mgrId = $id;
    }
}
echo '   • 링크 대상 — 재무='.($finId ?: '없음').' / 수출통관='.($cleId ?: '없음').' / 관리='.($mgrId ?: '없음')."\n";

echo "\n▶ ".($apply ? "발행 시작...\n" : "[확인 모드] 발행 계획 (실제 변경 X):\n");

// 카탈로그 먼저 upsert (workflow 가 링크로 참조). 상호 링크: 2회차 실행부터 양방향 완성.
$catalogTitle = '⚠️ 에러 & 잠금(락) 찾아보기';
$workflowTitle = '🔄 전체 워크플로우 (한눈에)';

// 기존 workflow id 미리 조회(카탈로그가 역링크할 수 있게)
$existingWorkflowId = null;
foreach ($erpKids as $ti => $id) {
    if ($ti === $workflowTitle) {
        $existingWorkflowId = $id;
    }
}

$catalogId = upsertPage($erpId, $catalogTitle, '⚠️', buildLockCatalog($existingWorkflowId), $token, $V, $apply, $BASE);
$workflowId = upsertPage($erpId, $workflowTitle, '🔄', buildWorkflow($finId, $cleId, $mgrId, $catalogId), $token, $V, $apply, $BASE);

echo "\n".($apply
    ? "✅ 발행 완료.\n   ※ 상호 링크(카탈로그↔워크플로우) 완성하려면 한 번 더 --apply 실행하세요(멱등).\n"
    : "ℹ️  확인만 했습니다. 실제 발행:  php scripts/notion-workflow-lock-guide.php --apply\n");
