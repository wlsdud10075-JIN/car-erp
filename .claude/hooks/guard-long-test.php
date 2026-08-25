<?php
/**
 * 긴 테스트 실행 가드 (2026-08-25, jin 지적) — 같은 전체 테스트를 로그도 없이 반복 실행해
 * 사용자를 20분 넘게 기다리게 한 일이 3~5회 반복됐다. 문서 규칙으로는 안 지켜졌다.
 *
 * 막는 것 = 「필터도 로그 리다이렉트도 없는 전체 테스트」 단 하나. 테스트 자체는 그대로 돌아간다.
 * ⚠️ fail-open — 이 스크립트가 어떤 이유로든 실패하면 조용히 통과시킨다(정상 작업을 막는 게 더 나쁘다).
 */
try {
    $raw = stream_get_contents(STDIN);
    $payload = json_decode((string) $raw, true);
    $cmd = (string) ($payload['tool_input']['command'] ?? '');

    if ($cmd === '') {
        exit(0);
    }

    // 대상 = artisan test / pest / phpunit 전체 실행
    $isFullRun = (bool) preg_match('/\b(artisan\s+test|vendor\/bin\/(pest|phpunit))\b/', $cmd);
    if (! $isFullRun) {
        exit(0);
    }

    // 범위를 좁힌 실행은 짧으니 통과 (--filter / --group / 경로 인자)
    // ⚠️ 정규식 이스케이프로 오탐을 낸 적이 있다(2026-08-25 설치 당일). 문자열 포함 검사로 단순하게 둔다.
    foreach (['--filter', '--group', '--testsuite', 'tests/', 'tests\\', '--version', '--help', '--list'] as $narrow) {
        if (str_contains($cmd, $narrow)) {
            exit(0);
        }
    }

    // 결과를 파일로 남기거나 백그라운드로 돌리면 통과
    if (str_contains($cmd, '>')) {
        exit(0);
    }

    fwrite(STDERR, <<<'MSG'
    [가드] 전체 테스트를 로그 없이 돌리려 하고 있다. 순차 약 6.6분이고, 같은 명령을 반복하면 그만큼 곱해진다.

    이렇게 할 것:
      1) 한 번만 돌리고 결과를 파일로 받는다 (run_in_background 권장):
         php artisan test > "$SCRATCH/test.log" 2>&1
      2) 이후 분석은 그 파일을 grep 한다 (재실행 금지):
         grep -nE "FAIL|⨯|Tests:|memory" "$SCRATCH/test.log"
      3) 특정 테스트만 보려면 --filter 를 쓴다 (이건 가드가 통과시킨다).

    그리고 1분 넘는 명령은 실행 전에 사용자에게 예상 시간을 먼저 알린다.
    MSG);
    exit(2);   // PreToolUse: exit 2 = 차단 + stderr 를 Claude 에게 전달
} catch (\Throwable $e) {
    exit(0);   // fail-open
}
