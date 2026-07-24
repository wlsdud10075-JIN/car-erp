<?php
/**
 * publish.php — 챗봇 지식카드를 Notion "사내 업무 가이드 › 🏢 ERP (car-erp)" 섹션 아래
 *   신규 「📇 기능 카드 (챗봇용)」 트리로 발행. 기존 워크플로우/에러 페이지는 안 건드린다.
 *   각 카드 = heading_2(제목) + 본문 문단들. (sync.php 가 H2 단위로 청크 → 카드 하나 = 검색 단위)
 *
 * 멱등: ERP 섹션에 "📇 기능 카드"가 이미 있으면 중단(중복 생성 방지).
 * 사용:  php publish.php            (dry-run, 쓰기 없음)
 *        php publish.php --apply    (실제 발행)
 */
$token = getenv('NOTION_TOKEN') ?: '';
if ($token === '') { fwrite(STDERR, "❌ NOTION_TOKEN 없음\n"); exit(1); }
$V = '2022-06-28';
$ERP_SECTION = '38f45d82-bd83-8161-932e-c3ab334bf2b5';   // 🏢 ERP (car-erp)
$PARENT_TITLE = '📇 기능 카드 (챗봇용)';
$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);   // 갱신: 기존 「📇 기능 카드」 아카이브 후 재생성
$cards = json_decode(file_get_contents(__DIR__ . '/cards.json'), true);
if (! $cards) { fwrite(STDERR, "❌ cards.json 로드 실패\n"); exit(1); }

function notion(string $m, string $u, array $b, string $t, string $v): array {
    for ($try = 0; $try < 6; $try++) {
        $c = curl_init($u);
        curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST=>$m, CURLOPT_RETURNTRANSFER=>1,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$t,'Content-Type: application/json','Notion-Version: '.$v],
            CURLOPT_POSTFIELDS=>$b ? json_encode($b, JSON_UNESCAPED_UNICODE) : ($m==='GET'?null:'{}')]);
        $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        if ($code === 429) { usleep(600000); continue; }
        $j = json_decode($r, true) ?: [];
        if ($code >= 300) { fwrite(STDERR, "❌ Notion $m ($code): ".($j['message']??$r)."\n"); exit(1); }
        usleep(350000);
        return $j;
    }
    fwrite(STDERR, "❌ 429 재시도 초과\n"); exit(1);
}
function kids(string $p, string $t, string $v): array {
    $o=[]; $cur=null;
    do { $r = notion('GET', "https://api.notion.com/v1/blocks/$p/children?page_size=100".($cur?"&start_cursor=$cur":''), [], $t, $v);
        foreach ($r['results'] as $b) $o[] = $b; $cur = ($r['has_more']??0) ? $r['next_cursor'] : null;
    } while ($cur); return $o;
}
function h2(string $s): array { return ['object'=>'block','type'=>'heading_2','heading_2'=>['rich_text'=>[['type'=>'text','text'=>['content'=>$s]]]]]; }
function para(string $label, string $val): array {
    return ['object'=>'block','type'=>'paragraph','paragraph'=>['rich_text'=>[
        ['type'=>'text','text'=>['content'=>$label.': '],'annotations'=>['bold'=>true]],
        ['type'=>'text','text'=>['content'=>$val]],
    ]]];
}

// ── 멱등 체크 (없으면 신규, 있으면 --force 로만 아카이브 후 재생성) ──
$existing = kids($ERP_SECTION, $token, $V);
foreach ($existing as $b) {
    if (($b['type']??'')==='child_page' && str_contains($b['child_page']['title']??'', '기능 카드')) {
        if (! $force) {
            fwrite(STDERR, "⚠️ 이미 존재: {$b['child_page']['title']} ({$b['id']}) — 중단(중복 방지). 갱신은 --force (기존 아카이브 후 재생성).\n");
            exit(1);
        }
        echo "♻️ 기존 「{$b['child_page']['title']}」 아카이브 (--force)".($apply ? "" : " [dry-run]")."\n";
        if ($apply) {
            notion('PATCH', "https://api.notion.com/v1/pages/{$b['id']}", ['archived' => true], $token, $V);
        }
    }
}

$totalCards = array_sum(array_map(fn($g)=>count($g['cards']), $cards));
echo ($apply?"▶ APPLY":"▶ DRY-RUN")." — 「{$PARENT_TITLE}」 아래 그룹 ".count($cards)."개 · 카드 {$totalCards}장\n";
foreach ($cards as $g) echo sprintf("   · %-26s 카드 %d장\n", $g['group'], count($g['cards']));

if (! $apply) { echo "\n(쓰기 없음. 실제 발행: php publish.php --apply)\n"; exit(0); }

// ── 부모 페이지 생성 ──
$parent = notion('POST', 'https://api.notion.com/v1/pages', [
    'parent' => ['page_id' => $ERP_SECTION],
    'properties' => ['title' => ['title' => [['text' => ['content' => $PARENT_TITLE]]]]],
    'children' => [
        ['object'=>'block','type'=>'paragraph','paragraph'=>['rich_text'=>[['type'=>'text','text'=>['content'=>
            '사내 챗봇(업무 도우미)이 질문에 답할 때 참고하는 기능 안내 카드입니다. 사이드바 탭별로 "어디서 · 무엇을 · 무엇을 적나 · 누가 · 어디에 반영되나"를 정리했습니다. 사람이 읽는 전체 워크플로우는 별도 페이지를 참고하세요.'
        ]]]]],
    ],
], $token, $V);
$parentId = $parent['id'];
echo "\n✅ 부모 페이지: {$PARENT_TITLE} ($parentId)\n";

// ── 그룹별 페이지 + 카드 블록 ──
foreach ($cards as $g) {
    $blocks = [];
    foreach ($g['cards'] as $card) {
        $blocks[] = h2($card['title']);
        foreach ($card['rows'] as [$label, $val]) $blocks[] = para($label, $val);
    }
    $page = notion('POST', 'https://api.notion.com/v1/pages', [
        'parent' => ['page_id' => $parentId],
        'properties' => ['title' => ['title' => [['text' => ['content' => $g['group']]]]]],
        'children' => $blocks,
    ], $token, $V);
    echo "   ✅ {$g['group']} — 카드 ".count($g['cards'])."장 ({$page['id']})\n";
}
echo "\n완료. Notion 새로고침 후 「{$PARENT_TITLE}」 확인. 이어서 재색인(sync.php) 필요.\n";
echo "PARENT_ID=$parentId\n";
