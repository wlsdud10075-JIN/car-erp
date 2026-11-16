<?php

/**
 * 씬 점검 — 읽기 전용 DB 조회 헬퍼 (로컬 전용, dev 전용 · master 제외).
 *
 * 입력 = argv[1] 에 SQL 한 줄(사람이 터미널에서 — ASCII 만 쓸 때)
 *        또는 argv 없이 stdin 의 JSON {"sql": "...", "bindings": [...]} (lib.js 가 쓰는 길 — Windows 에서 한글 인자가 UTF-8 로 안 올 수 있다).
 * 출력 = stdout 에 JSON 배열(행 목록). 실패 시 {"error": "..."} + exit 1.
 *
 * 막는 것: APP_ENV != local · SELECT 로 시작하지 않는 문장 · 세미콜론(다중 문장) · INTO OUTFILE/DUMPFILE · FOR UPDATE.
 * 그리고 MySQL 이면 READ ONLY 트랜잭션 안에서 돌리고 항상 rollback 한다(문자열 검사가 놓친 것까지 DB 가 거절).
 *
 * 🚫 실명 컬럼(users.name·salesmen.name·buyers 의 담당자 등)을 SELECT 해서 결과 파일에 남기지 말 것(README 함정).
 */
$root = getenv('CAR_ERP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fail = function (string $msg): void {
    fwrite(STDOUT, json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE));
    exit(1);
};

if (! app()->environment('local')) {
    $fail('local only (APP_ENV='.app()->environment().')');
}

$sql = null;
$bindings = [];
// argv 가 있으면 stdin 을 안 읽는다 — 터미널·에이전트 Bash 에서 stdin 을 먼저 읽으면 EOF 를 기다리며 멈춘다(§8 #112 부류).
if (isset($argv[1]) && $argv[1] !== '-') {
    $sql = (string) $argv[1];
} else {
    $in = json_decode((string) stream_get_contents(STDIN), true);
    if (! is_array($in) || ! isset($in['sql'])) {
        $fail('stdin must be JSON {"sql":..., "bindings":[...]} (or pass SQL as argv[1])');
    }
    $sql = (string) $in['sql'];
    $bindings = array_values((array) ($in['bindings'] ?? []));
}
if ($sql === null || trim($sql) === '') {
    $fail('no sql');
}

$trim = trim($sql);
if (! preg_match('/^select\s/i', $trim)) {
    $fail('SELECT only');
}
if (str_contains($trim, ';')) {
    $fail('single statement only (no ";")');
}
if (preg_match('/\binto\s+(out|dump)file\b|\bfor\s+update\b|\block\s+in\s+share\s+mode\b|\bsleep\s*\(|\bbenchmark\s*\(/i', $trim)) {
    $fail('forbidden clause');
}

$db = Illuminate\Support\Facades\DB::connection();
$isMysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
try {
    if ($isMysql) {
        $db->getPdo()->exec('START TRANSACTION READ ONLY');
    }
    $rows = $db->select($trim, $bindings);
    if ($isMysql) {
        $db->getPdo()->exec('ROLLBACK');
    }
} catch (Throwable $e) {
    if ($isMysql) {
        try {
            $db->getPdo()->exec('ROLLBACK');
        } catch (Throwable) {
        }
    }
    $fail(mb_substr($e->getMessage(), 0, 300));
}

fwrite(STDOUT, json_encode($rows, JSON_UNESCAPED_UNICODE));
