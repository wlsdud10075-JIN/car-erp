<?php

/**
 * 🧹 주간 메모리·문서 측정 (jin 2026-11-16 «토요일에 자동으로» · «측정·후보 표만»).
 *
 * 읽기만 한다 — 메모리·문서를 고치지 않는다. 정리(옮기기)는 jin 이 표를 보고 `/memory-cleanup` 스킬로.
 *   php scripts/weekly/memory-measure.php            → 표를 storage/logs/weekly/memory-YYYYMMDD.md 에 쓰고, 마지막 줄에 JSON 요약
 *   마지막 줄 JSON = {"status":"ok|todo","note":"…","report":"경로"} — 주말 실행기가 이걸로 `ops:mark memory-doc-cleanup` 를 남긴다.
 * 정본 압축 방법 = 메모리 feedback_memory_compaction_method.md (지우지 말고 옮기고 포인터).
 */
date_default_timezone_set('Asia/Seoul');

const MEMORY_MAX_LINES = 150;      // 200 줄부터 뒤가 조용히 잘린다 — 넘기 전에
const MEMORY_MAX_BYTES = 24_000;
const LONG_LINE_CHARS = 400;
const SKILLS_MAX_BYTES = 200_000;

$repo = dirname(__DIR__, 2);
$home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
$slug = preg_replace('/[^A-Za-z0-9]/', '-', str_replace('\\', '/', $repo));
$memDir = $argv[1] ?? rtrim($home, '/\\').'/.claude/projects/'.$slug.'/memory';
$index = $memDir.'/MEMORY.md';
if (! is_file($index)) {
    fwrite(STDERR, "MEMORY.md 없음: {$index}\n");
    echo json_encode(['status' => 'failed', 'note' => 'MEMORY.md 없음'], JSON_UNESCAPED_UNICODE)."\n";
    exit(1);
}

$lines = preg_split('/\r?\n/', (string) file_get_contents($index));
$bytes = filesize($index);
$rows = [];   // [종류, 행, 내용 앞부분, 제안]

foreach ($lines as $i => $l) {
    $no = $i + 1;
    if (preg_match('/^\s*[-*>]\s*✅/u', $l)) {
        $rows[] = ['끝난 일', $no, mb_substr($l, 0, 70), '_ARCHIVE.md 로 옮기고 색인에서 빼기(남은 일이 적혀 있으면 그 줄만 남김)'];
    }
    if (mb_strlen($l) > LONG_LINE_CHARS) {
        $rows[] = ['긴 줄 '.mb_strlen($l).'자', $no, mb_substr($l, 0, 70), '세부는 project_*.md 나 _ARCHIVE.md 로, 색인엔 한 줄 + 링크'];
    }
}

// 깨진 [[링크]] — 메모리 파일의 name: 또는 파일 이름과 맞는 게 없으면
$names = [];
foreach (glob($memDir.'/*.md') ?: [] as $f) {
    $names[strtolower(basename($f, '.md'))] = true;
    if (preg_match('/^name:\s*(.+)$/m', (string) file_get_contents($f, false, null, 0, 600), $m)) {
        $names[strtolower(trim($m[1]))] = true;
    }
}
foreach ($lines as $i => $l) {
    if (preg_match_all('/\[\[([^\]]+)\]\]/u', $l, $mm)) {
        foreach ($mm[1] as $link) {
            $k = strtolower(trim($link));
            if (! isset($names[$k]) && ! isset($names[str_replace('-', '_', $k)]) && ! isset($names[str_replace('_', '-', $k)])) {
                $rows[] = ['깨진 링크', $i + 1, '[['.$link.']]', '파일이 없다 — 이름이 바뀌었으면 고치고, 아직 안 쓴 메모면 그대로 둬도 됨'];
            }
        }
    }
}

$notes = [];
$over = [];
if (count($lines) > MEMORY_MAX_LINES) {
    $over[] = 'MEMORY '.count($lines).'줄(기준 '.MEMORY_MAX_LINES.')';
}
if ($bytes > MEMORY_MAX_BYTES) {
    $over[] = 'MEMORY '.number_format($bytes).'B(기준 '.number_format(MEMORY_MAX_BYTES).'B)';
}

// 레포 문서 — 제안만
$skills = $repo.'/SKILLS.md';
$skillsBytes = is_file($skills) ? filesize($skills) : 0;
if ($skillsBytes > SKILLS_MAX_BYTES) {
    $over[] = 'SKILLS.md '.round($skillsBytes / 1024).'KB(기준 '.round(SKILLS_MAX_BYTES / 1024).'KB) — §8 트림 제안(jin 승인)';
}
$order = [];
if (is_file($skills) && preg_match_all('/^### (\d+)\./m', (string) file_get_contents($skills), $sm)) {
    $prev = 0;
    foreach ($sm[1] as $n) {
        if ((int) $n < $prev) {
            $order[] = $prev.'→'.$n;
        }
        $prev = (int) $n;
    }
}
if ($order !== []) {
    $notes[] = 'SKILLS §8 번호 순서 어긋남 '.count($order).'곳('.implode(', ', array_slice($order, 0, 3)).') — 트림 때 같이';
}
$claude = $repo.'/CLAUDE.md';
$master = trim((string) @shell_exec('git -C '.escapeshellarg($repo).' rev-parse --short=8 origin/master 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
if (is_file($claude) && preg_match('/현재 상태 \(([0-9-]+)\)\*\*: .*?master\(`([0-9a-f]+)`/us', (string) file_get_contents($claude), $cm) && $master !== '' && ! str_starts_with($master, $cm[2])) {
    $notes[] = "CLAUDE.md 「현재 상태」 = {$cm[1]} `{$cm[2]}` · 실제 master `{$master}` — 갱신할지 jin 에게";
}

$candidates = count($rows) + count($over);
$status = $candidates > 0 ? 'todo' : 'ok';
$summary = 'MEMORY '.count($lines).'줄·'.round($bytes / 1024, 1).'KB · 후보 '.count($rows).'줄'.($over ? ' · 기준 넘음 '.count($over) : '');

$md = "# 메모리·문서 주간 측정 — ".date('Y-m-d H:i')."\n\n"
    ."정리는 이 표를 보고 `/memory-cleanup` 으로(옮기기 전에 jin 확인). 이 측정은 아무것도 고치지 않았다.\n\n"
    .'- MEMORY.md: '.count($lines).'줄 / '.number_format($bytes)." B (기준 ".MEMORY_MAX_LINES.'줄 · '.number_format(MEMORY_MAX_BYTES)." B)\n"
    .'- SKILLS.md: '.number_format($skillsBytes).' B (기준 '.number_format(SKILLS_MAX_BYTES)." B)\n"
    .($over ? "- ⚠️ 기준 넘음: ".implode(' · ', $over)."\n" : '')
    .implode('', array_map(fn ($n) => "- 💡 {$n}\n", $notes))
    ."\n## 정리 후보 (".count($rows).")\n\n| 종류 | 행 | 지금 내용(앞부분) | 제안 |\n|---|---|---|---|\n"
    .implode('', array_map(fn ($r) => '| '.$r[0].' | '.$r[1].' | '.str_replace('|', '\\|', $r[2]).' | '.$r[3]." |\n", $rows));

$outDir = $repo.'/storage/logs/weekly';
@mkdir($outDir, 0775, true);
$out = $outDir.'/memory-'.date('Ymd').'.md';
file_put_contents($out, $md);
echo $md;
echo json_encode(['status' => $status, 'note' => $summary, 'report' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
