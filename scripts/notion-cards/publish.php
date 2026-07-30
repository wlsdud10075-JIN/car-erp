<?php

/**
 * publish.php — 챗봇 지식카드(cards.json)를 Notion "사내 업무 가이드 › 🏢 ERP (car-erp) › 📇 기능 카드" 로 발행.
 *   각 카드 = heading_2(제목) + audience 마커 + 본문 문단들.
 *   (색인기가 H2 단위로 청크 → 카드 하나 = 검색 단위 = 권한 판정 단위)
 *
 * ⭐ 단일 출처는 cards.json 이다. Notion 은 발행물이므로 손으로 고치지 않는다.
 *    Notion 을 직접 고치면 다음 발행 때 되돌아가 조용히 사라진다. 고칠 일이 있으면 cards.json 을 고치고 다시 발행한다.
 *
 * 🚨 audience 는 필수다. 하나라도 없으면 발행을 거부한다.
 *    미표기 카드가 색인에 들어가면 그날 색인이 fail-closed 로 멈추거나,
 *    전량 미표기일 때는 하위호환으로 전 청크가 staff 취급되어 권한 격리가 풀린다.
 *
 * 사용:
 *   php publish.php                        요약 + cards.json 자체 검증 (쓰기 없음)
 *   php publish.php --verify               라이브 Notion ↔ cards.json 대조 (읽기 전용)
 *   php publish.php --card "제목" --apply   카드 1장 갱신 — 라이브에 있으면 제자리 교체,
 *                                          없으면 그 그룹 끝에 신규 추가 (둘 다 나머지 카드 무손상)
 *   php publish.php --force --apply        전체 아카이브 후 재생성 (최후수단 — 페이지 ID 가 전부 바뀐다)
 *
 * ⚠️ --card --apply 는 "기존 본문 삭제 → 새 본문 삽입" 순서다. 삽입 단계에서 실패하면 그 카드가
 *    제목만 남고 마커가 없는 상태가 된다(= 색인 중단 조건). 그럴 땐 같은 명령을 다시 실행하면
 *    cards.json 에서 복원된다. 끝나면 항상 --verify 로 확인한다.
 */
$token = getenv('NOTION_TOKEN') ?: '';
if ($token === '') {
    fwrite(STDERR, "❌ NOTION_TOKEN 없음\n");
    exit(1);
}
$V = '2022-06-28';
$ERP_SECTION = '38f45d82-bd83-8161-932e-c3ab334bf2b5';   // 🏢 ERP (car-erp)
$PARENT_TITLE = '📇 기능 카드 (챗봇용)';
$AUDIENCES = ['staff', 'finance', 'executive', 'system'];
$MARKER = 'ASSISTANT_AUDIENCE=';
$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
$verify = in_array('--verify', $argv, true);
$cardArg = null;
foreach ($argv as $i => $a) {
    if ($a === '--card') {
        $cardArg = $argv[$i + 1] ?? null;
    }
}
$cards = json_decode(file_get_contents(__DIR__.'/cards.json'), true);
if (! $cards) {
    fwrite(STDERR, "❌ cards.json 로드 실패\n");
    exit(1);
}

function notion(string $m, string $u, array $b, string $t, string $v): array
{
    for ($try = 0; $try < 6; $try++) {
        $c = curl_init($u);
        curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$t, 'Content-Type: application/json', 'Notion-Version: '.$v],
            CURLOPT_POSTFIELDS => $b ? json_encode($b, JSON_UNESCAPED_UNICODE) : ($m === 'GET' ? null : '{}')]);
        $r = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if (in_array($code, [409, 429, 500, 502, 503, 504], true)) {
            usleep(600000 * ($try + 1));

            continue;
        }
        $j = json_decode((string) $r, true) ?: [];
        if ($code >= 300) {
            fwrite(STDERR, "❌ Notion $m ($code): ".($j['message'] ?? $r)."\n");
            exit(1);
        }
        usleep(350000);

        return $j;
    }
    fwrite(STDERR, "❌ Notion 재시도 초과\n");
    exit(1);
}
function kids(string $p, string $t, string $v): array
{
    $o = [];
    $cur = null;
    do {
        $r = notion('GET', "https://api.notion.com/v1/blocks/$p/children?page_size=100".($cur ? "&start_cursor=$cur" : ''), [], $t, $v);
        foreach ($r['results'] as $b) {
            $o[] = $b;
        }
        $cur = ($r['has_more'] ?? 0) ? $r['next_cursor'] : null;
    } while ($cur);

    return $o;
}

// ── 블록 빌더 ────────────────────────────────────────────
function h2(string $s): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $s]]]]];
}
function h3(string $s): array
{
    return ['object' => 'block', 'type' => 'heading_3', 'heading_3' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $s]]]]];
}
function bul(string $s): array
{
    return ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $s]]]]];
}
function plainpara(string $s): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $s]]]]];
}
function para(string $label, string $val): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [
        ['type' => 'text', 'text' => ['content' => $label.': '], 'annotations' => ['bold' => true]],
        ['type' => 'text', 'text' => ['content' => $val]],
    ]]];
}

/** 카드 1장 → 블록 배열 (h2 포함). 마커는 반드시 h2 바로 다음 첫 줄. */
function cardBlocks(array $card): array
{
    global $MARKER;
    $b = [h2($card['title']), plainpara($MARKER.$card['audience'])];
    foreach ($card['rows'] as [$label, $val]) {
        $b[] = para($label, $val);
    }
    foreach ($card['extra'] ?? [] as $e) {
        if (isset($e['h3'])) {
            $b[] = h3($e['h3']);
        } elseif (isset($e['bul'])) {
            $b[] = bul($e['bul']);
        } else {
            fwrite(STDERR, '❌ 알 수 없는 extra 항목: '.json_encode($e, JSON_UNESCAPED_UNICODE)."\n");
            exit(1);
        }
    }

    return $b;
}

// ── cards.json 자체 검증 (audience 필수) ─────────────────
$titles = [];
$bad = [];
foreach ($cards as $g) {
    foreach ($g['cards'] as $c) {
        $a = $c['audience'] ?? null;
        if (! in_array($a, $AUDIENCES, true)) {
            $bad[] = "{$g['group']} / {$c['title']} → ".var_export($a, true);
        }
        if (isset($titles[$c['title']])) {
            $bad[] = "제목 중복: {$c['title']}";
        }
        $titles[$c['title']] = $g['group'];
    }
}
if ($bad) {
    fwrite(STDERR, '❌ cards.json 검증 실패 — audience 는 '.implode('|', $AUDIENCES)." 중 하나여야 하고 제목은 고유해야 합니다:\n");
    foreach ($bad as $x) {
        fwrite(STDERR, "   · $x\n");
    }
    exit(1);
}
$totalCards = count($titles);

// ── 라이브 읽기 (verify / --card 공용) ───────────────────
/** 그룹 페이지의 블록을 카드 단위로 자른다. [ ['title'=>..,'h2id'=>..,'blockIds'=>[..],'audience'=>..,'rows'=>[..],'extra'=>[..]] ] */
function liveCards(string $pageId, string $t, string $v): array
{
    global $MARKER;
    $out = [];
    $cur = null;
    foreach (kids($pageId, $t, $v) as $b) {
        $type = $b['type'] ?? '';
        $rt = $b[$type]['rich_text'] ?? [];
        $text = '';
        foreach ($rt as $r) {
            $text .= $r['plain_text'] ?? '';
        }
        $text = trim($text);
        if ($type === 'heading_2') {
            if ($cur) {
                $out[] = $cur;
            }
            $cur = ['title' => $text, 'h2id' => $b['id'], 'blockIds' => [], 'audience' => null, 'rows' => [], 'extra' => []];

            continue;
        }
        if (! $cur || $text === '') {
            continue;
        }
        $cur['blockIds'][] = $b['id'];
        if ($type === 'paragraph' && str_starts_with($text, $MARKER)) {
            $cur['audience'] = trim(substr($text, strlen($MARKER)));
        } elseif ($type === 'paragraph' && count($rt) >= 2 && ! empty($rt[0]['annotations']['bold'])) {
            $label = rtrim(trim($rt[0]['plain_text'] ?? ''), ':');
            $val = '';
            for ($i = 1; $i < count($rt); $i++) {
                $val .= $rt[$i]['plain_text'] ?? '';
            }
            $cur['rows'][] = [trim($label), trim($val)];
        } elseif ($type === 'heading_3') {
            $cur['extra'][] = ['h3' => $text];
        } elseif ($type === 'bulleted_list_item') {
            $cur['extra'][] = ['bul' => $text];
        } else {
            $cur['extra'][] = ['?'.$type => $text];
        }
    }
    if ($cur) {
        $out[] = $cur;
    }

    return $out;
}

/** 「📇 기능 카드」 부모 + 그룹 페이지 목록 */
function findTree(string $section, string $parentTitle, string $t, string $v): ?array
{
    foreach (kids($section, $t, $v) as $b) {
        if (($b['type'] ?? '') === 'child_page' && str_contains($b['child_page']['title'] ?? '', '기능 카드')) {
            $groups = [];
            foreach (kids($b['id'], $t, $v) as $k) {
                if (($k['type'] ?? '') === 'child_page') {
                    $groups[$k['child_page']['title']] = $k['id'];
                }
            }

            return ['id' => $b['id'], 'title' => $b['child_page']['title'], 'groups' => $groups];
        }
    }

    return null;
}

// ── --verify : 라이브 ↔ cards.json 대조 (읽기 전용) ──────
if ($verify) {
    $tree = findTree($ERP_SECTION, $PARENT_TITLE, $token, $V);
    if (! $tree) {
        fwrite(STDERR, "❌ 「기능 카드」 페이지를 Notion 에서 못 찾음\n");
        exit(1);
    }
    echo "🔎 대조 — 라이브 「{$tree['title']}」 ↔ cards.json\n\n";
    $problems = 0;
    $repoGroups = array_column($cards, 'group');
    foreach (array_diff($repoGroups, array_keys($tree['groups'])) as $g) {
        echo "  ❌ 라이브에 없는 그룹: $g\n";
        $problems++;
    }
    foreach (array_diff(array_keys($tree['groups']), $repoGroups) as $g) {
        echo "  ❌ cards.json 에 없는 그룹: $g\n";
        $problems++;
    }
    foreach ($cards as $g) {
        if (! isset($tree['groups'][$g['group']])) {
            continue;
        }
        $live = [];
        foreach (liveCards($tree['groups'][$g['group']], $token, $V) as $lc) {
            $live[$lc['title']] = $lc;
        }
        foreach ($g['cards'] as $c) {
            if (! isset($live[$c['title']])) {
                echo "  ❌ 라이브에 없는 카드: {$g['group']} / {$c['title']}\n";
                $problems++;

                continue;
            }
            $l = $live[$c['title']];
            unset($live[$c['title']]);
            if ($l['audience'] !== $c['audience']) {
                echo "  ❌ audience 불일치: {$c['title']} — 라이브 ".var_export($l['audience'], true)." / repo {$c['audience']}\n";
                $problems++;
            }
            $norm = fn ($rows) => array_map(fn ($r) => preg_replace('/\s+/u', ' ', $r[0].': '.$r[1]), $rows);
            $lr = $norm($l['rows']);
            $rr = $norm($c['rows']);
            if ($lr !== $rr) {
                echo "  ❌ 본문 불일치: {$c['title']}\n";
                foreach (array_diff($lr, $rr) as $x) {
                    echo '       ＋라이브만: '.mb_substr($x, 0, 100)."\n";
                }
                foreach (array_diff($rr, $lr) as $x) {
                    echo '       －repo만  : '.mb_substr($x, 0, 100)."\n";
                }
                $problems++;
            }
            if (($l['extra'] ?: []) != ($c['extra'] ?? [])) {
                echo "  ❌ 부가블록 불일치: {$c['title']} (라이브 ".count($l['extra']).'개 / repo '.count($c['extra'] ?? [])."개)\n";
                $problems++;
            }
        }
        foreach ($live as $t2 => $_) {
            echo "  ❌ cards.json 에 없는 카드: {$g['group']} / $t2\n";
            $problems++;
        }
    }
    echo "\n".($problems === 0
        ? "✅ 정합 — 라이브와 cards.json 이 완전히 같습니다 (카드 {$totalCards}장).\n"
        : "⚠️ 불일치 {$problems}건. cards.json 을 고쳐 맞춘 뒤 --card 로 재발행하세요.\n");
    exit($problems === 0 ? 0 : 1);
}

// ── --card : 카드 1장 제자리 갱신 ────────────────────────
if ($cardArg !== null) {
    if (! isset($titles[$cardArg])) {
        fwrite(STDERR, "❌ cards.json 에 없는 카드: {$cardArg}\n   (제목은 정확히 일치해야 합니다)\n");
        exit(1);
    }
    $group = $titles[$cardArg];
    $card = null;
    foreach ($cards as $g) {
        foreach ($g['cards'] as $c) {
            if ($c['title'] === $cardArg) {
                $card = $c;
            }
        }
    }
    $tree = findTree($ERP_SECTION, $PARENT_TITLE, $token, $V);
    if (! $tree || ! isset($tree['groups'][$group])) {
        fwrite(STDERR, "❌ 라이브에서 그룹 페이지를 못 찾음: {$group}\n");
        exit(1);
    }
    $liveGroup = liveCards($tree['groups'][$group], $token, $V);
    $target = null;
    foreach ($liveGroup as $lc) {
        if ($lc['title'] === $cardArg) {
            $target = $lc;
        }
    }

    // ── 신규 카드 = 그룹 페이지 끝에 추가 (--force 없이) ──
    if (! $target) {
        $new = cardBlocks($card);   // h2 포함
        $last = end($liveGroup) ?: null;
        // 마지막 카드의 최종 블록 뒤에 붙인다. 그룹이 비었으면 after 없이 append.
        $after = $last ? (end($last['blockIds']) ?: $last['h2id']) : null;
        echo ($apply ? '▶ APPLY' : '▶ DRY-RUN')." — 「{$group}」 / {$cardArg}  ★신규 추가\n";
        echo '   그룹 끝에 '.count($new)."블록 추가 (audience={$card['audience']})\n";
        if (! $apply) {
            echo "\n(쓰기 없음. 실제 추가: --apply)\n";
            exit(0);
        }
        $body = ['children' => $new];
        if ($after !== null) {
            $body['after'] = $after;
        }
        notion('PATCH', "https://api.notion.com/v1/blocks/{$tree['groups'][$group]}/children", $body, $token, $V);
        echo "✅ 추가 완료. 다음 03:00 색인에 반영됩니다(긴급하면 색인 세션에 재색인 요청).\n";
        echo "   확인: php publish.php --verify\n";
        exit(0);
    }

    // ── 기존 카드 = 본문만 제자리 교체 (h2 유지 → 페이지 내 위치 보존) ──
    $new = cardBlocks($card);
    array_shift($new);
    echo ($apply ? '▶ APPLY' : '▶ DRY-RUN')." — 「{$group}」 / {$cardArg}\n";
    echo '   기존 본문 '.count($target['blockIds']).'블록 삭제 → 새 본문 '.count($new)."블록 삽입 (audience={$card['audience']})\n";
    if (! $apply) {
        echo "\n(쓰기 없음. 실제 갱신: --apply)\n";
        exit(0);
    }
    foreach ($target['blockIds'] as $bid) {
        notion('DELETE', "https://api.notion.com/v1/blocks/$bid", [], $token, $V);
    }
    notion('PATCH', "https://api.notion.com/v1/blocks/{$tree['groups'][$group]}/children",
        ['children' => $new, 'after' => $target['h2id']], $token, $V);
    echo "✅ 완료. 다음 03:00 색인에 반영됩니다(긴급하면 색인 세션에 재색인 요청).\n";
    echo "   확인: php publish.php --verify\n";
    exit(0);
}

// ── 요약 (cards.json 기준 — 쓰기 전 항상 보여준다) ───────
echo ($apply ? '▶ APPLY' : '▶ DRY-RUN')." — 「{$PARENT_TITLE}」 아래 그룹 ".count($cards)."개 · 카드 {$totalCards}장\n";
foreach ($cards as $g) {
    $a = [];
    foreach ($g['cards'] as $c) {
        $a[$c['audience']] = ($a[$c['audience']] ?? 0) + 1;
    }
    ksort($a);
    $s = [];
    foreach ($a as $k => $v) {
        $s[] = "$k $v";
    }
    echo sprintf("   · %-26s 카드 %2d장  (%s)\n", $g['group'], count($g['cards']), implode(', ', $s));
}
echo "   ✅ audience 전량 표기 — 색인 fail-closed 위험 없음\n";

// ── 전체 발행 ────────────────────────────────────────────
$existing = kids($ERP_SECTION, $token, $V);
foreach ($existing as $b) {
    if (($b['type'] ?? '') === 'child_page' && str_contains($b['child_page']['title'] ?? '', '기능 카드')) {
        if (! $force) {
            fwrite(STDERR, "\n⚠️ 이미 존재: {$b['child_page']['title']} ({$b['id']}) — 전체 발행 중단(중복 방지).\n");
            fwrite(STDERR, "   대조만 하려면   → php publish.php --verify\n");
            fwrite(STDERR, "   카드 1장 갱신은 → php publish.php --card \"제목\" --apply  (권장)\n");
            fwrite(STDERR, "   전체 재생성은   → --force (기존 아카이브 후 재생성, 페이지 ID 전부 변경)\n");
            exit(1);
        }
        echo "♻️ 기존 「{$b['child_page']['title']}」 아카이브 (--force)".($apply ? '' : ' [dry-run]')."\n";
        if ($apply) {
            notion('PATCH', "https://api.notion.com/v1/pages/{$b['id']}", ['archived' => true], $token, $V);
        }
    }
}

if (! $apply) {
    echo "\n(쓰기 없음. 대조=--verify · 카드 1장=--card \"제목\" --apply · 전체 재생성=--force --apply)\n";
    exit(0);
}

$parent = notion('POST', 'https://api.notion.com/v1/pages', [
    'parent' => ['page_id' => $ERP_SECTION],
    'properties' => ['title' => ['title' => [['text' => ['content' => $PARENT_TITLE]]]]],
    'children' => [
        plainpara('사내 챗봇(업무 도우미)이 질문에 답할 때 참고하는 기능 안내 카드입니다. 사이드바 탭별로 "어디서 · 무엇을 · 무엇을 적나 · 누가 · 어디에 반영되나"를 정리했습니다. 사람이 읽는 전체 워크플로우는 별도 페이지를 참고하세요.'),
    ],
], $token, $V);
$parentId = $parent['id'];
echo "\n✅ 부모 페이지: {$PARENT_TITLE} ($parentId)\n";

foreach ($cards as $g) {
    $blocks = [];
    foreach ($g['cards'] as $card) {
        foreach (cardBlocks($card) as $b) {
            $blocks[] = $b;
        }
    }
    $page = notion('POST', 'https://api.notion.com/v1/pages', [
        'parent' => ['page_id' => $parentId],
        'properties' => ['title' => ['title' => [['text' => ['content' => $g['group']]]]]],
        'children' => $blocks,
    ], $token, $V);
    echo "   ✅ {$g['group']} — 카드 ".count($g['cards'])."장 ({$page['id']})\n";
}
echo "\n완료. 확인: php publish.php --verify  → 이어서 재색인.\n";
echo "PARENT_ID=$parentId\n";
