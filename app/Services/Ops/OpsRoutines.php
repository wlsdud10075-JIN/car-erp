<?php

namespace App\Services\Ops;

use Carbon\Carbon;

/**
 * 관제센터 「루틴」 칸 — 예약 작업을 사람 말로(목업 그대로, jin 11-12 «영어로 되어 있고 뭘 뜻하는지 모르겠다»).
 *
 * 작업 이름은 코드 커맨드가 아니라 **한글 묶음**으로 보여 준다. 묶음 하나 = 커맨드 여러 개일 수 있다(알림톡 평일 9종).
 * 데이터 = 사이트마다 OpsSummary::routines()(ScheduledTaskRun 기록). 기록이 없는 묶음도 줄은 나온다(「기록 없음」) — 뭐가 돌아야 하는지가 보여야 빠진 걸 안다.
 * ⚠️ routes/console.php 에 커맨드를 더하면 여기 GROUPS 에도 넣는다 — 가드 = OpsControlCenterTest::test_every_scheduled_command_has_a_korean_routine_label.
 */
class OpsRoutines
{
    /** @var array<string, array{when: string, commands: list<string>}> */
    public const GROUPS = [
        'DB 백업' => ['when' => '매일 03:00', 'commands' => ['db:backup']],
        '공휴일 수집' => ['when' => '매일 04:10', 'commands' => ['holidays:sync']],
        '예약 작업 기록 정리(90일)' => ['when' => '매일 04:50', 'commands' => ['model:prune']],
        '캐시 재계산(미수·위험도·진행상태)' => ['when' => '매일 05:00', 'commands' => ['vehicles:rebuild-caches']],
        '할일 알람 스캔' => ['when' => '매일 06:00', 'commands' => ['alarms:scan']],
        '시스템 점검(아침 요약 텔레그램)' => ['when' => '매일 08:00', 'commands' => ['system:health-check']],
        '알림톡 정기 발송 9종(평일)' => ['when' => '평일 09:00 · 09:10 따라잡기', 'commands' => ['alimtalk:pickup', 'alimtalk:deregistration', 'alimtalk:purchase-unpaid', 'alimtalk:sale-unpaid', 'alimtalk:eta-balance', 'alimtalk:shipping-due', 'alimtalk:deposit-cash', 'alimtalk:daily-summary', 'alimtalk:receivable-status']],
        '알림톡 주간·월간(자금보고·도착후미수·주간요약·월결산)' => ['when' => '월 09:00 · 금 18:00 · 매일 09:00 판정', 'commands' => ['alimtalk:capital-weekly', 'alimtalk:arrived-unpaid-weekly', 'alimtalk:weekly-summary', 'alimtalk:monthly-closing']],
        '알림톡 도달 결과 조회' => ['when' => '매시', 'commands' => ['alimtalk:poll-report']],
        '환율 스냅샷(네이버)' => ['when' => '매일 09:00', 'commands' => ['exchange:snapshot-daily']],
        'GPU PC 감시(Ollama 핑)' => ['when' => '5분마다', 'commands' => ['assistant:health-check']],
    ];

    /** 기록이 안 남는 외부 루틴 — 줄만 보여 준다(어디서 도는지 잊지 않게) */
    public const EXTERNAL = [
        ['label' => 'Notion 가이드 발행 · 야간 조사 (회사 GPU PC)', 'when' => '03:30 조사 · 04:30 발행 · 08:00 텔레그램', 'note' => 'GPU PC 작업 스케줄러 — 기록 연결은 후속'],
        ['label' => '세컨드 브레인 목록 올리기 (jin PC)', 'when' => '매일 10:10', 'note' => '관제 화면 머리줄 「기준 시각」으로 확인'],
        ['label' => '자동 보안 업데이트 (서버 apt)', 'when' => '02:30~02:45', 'note' => 'systemd 타이머 — 기록 연결은 후속'],
    ];

    /** 보여 주지 않는 커맨드(Laravel 샘플 등) */
    public const HIDDEN = ['inspire'];

    /**
     * @param  list<array{site: array, ok: bool, data: ?array}>  $sites  OpsHub::collect()
     * @return array{columns: list<array{key: string, name: string}>, rows: list<array{label: string, when: string, by: array<string, array{state: string, text: string, title: string}>}>, unknown: list<string>}
     */
    public static function rows(array $sites): array
    {
        $columns = [];
        $byCmd = [];   // site key → command → run
        foreach ($sites as $s) {
            $kind = $s['site']['kind'] ?? 'erp';
            if (! in_array($kind, ['erp', 'board'], true)) {
                continue;
            }
            $columns[] = ['key' => $s['site']['key'], 'name' => $s['site']['name'], 'ok' => (bool) $s['ok']];
            foreach ((array) ($s['data']['routines'] ?? []) as $r) {
                if (is_array($r) && isset($r['command'])) {
                    $byCmd[$s['site']['key']][$r['command']] = $r;
                }
            }
        }

        $known = array_merge(self::HIDDEN, ...array_map(fn ($g) => $g['commands'], array_values(self::GROUPS)));
        $unknown = [];
        foreach ($byCmd as $cmds) {
            foreach (array_keys($cmds) as $c) {
                if (! in_array($c, $known, true)) {
                    $unknown[] = $c;
                }
            }
        }
        $unknown = array_values(array_unique($unknown));

        $rows = [];
        foreach (self::GROUPS as $label => $g) {
            $by = [];
            foreach ($columns as $col) {
                $by[$col['key']] = self::cell($g['commands'], $byCmd[$col['key']] ?? null, $col['ok']);
            }
            $rows[] = ['label' => $label, 'when' => $g['when'], 'by' => $by];
        }
        foreach ($unknown as $c) {   // 묶음에 없는 새 커맨드 — 그대로 보여 준다(숨기면 영영 모른다)
            $by = [];
            foreach ($columns as $col) {
                $by[$col['key']] = self::cell([$c], $byCmd[$col['key']] ?? null, $col['ok']);
            }
            $rows[] = ['label' => $c.' (이름 미등록)', 'when' => '', 'by' => $by];
        }

        return ['columns' => $columns, 'rows' => $rows, 'unknown' => $unknown];
    }

    /** 묶음 한 칸 — 멤버 커맨드들의 마지막 실행을 하나로: 실패 하나라도 있으면 실패, 도는 중, 전부 성공, 기록 없음 */
    private static function cell(array $commands, ?array $runs, bool $siteOk): array
    {
        if (! $siteOk) {
            return ['state' => 'none', 'text' => '—', 'title' => '사이트 응답 없음'];
        }
        if ($runs === null) {   // board 처럼 예약 작업 기록을 아직 안 보내는 사이트
            return ['state' => 'none', 'text' => '—', 'title' => '이 사이트는 아직 예약 작업 기록을 안 보낸다(후속)'];
        }
        $found = array_values(array_filter(array_map(fn ($c) => $runs[$c] ?? null, $commands)));
        if ($found === []) {
            return ['state' => 'none', 'text' => '기록 없음', 'title' => '11-12 부터 쌓임 — 다음 실행부터'];
        }
        $failed = array_values(array_filter($found, fn ($r) => ($r['status'] ?? '') === 'failed'));
        if ($failed !== []) {
            $f = $failed[0];

            return ['state' => 'failed', 'text' => '실패 '.count($failed).'/'.count($found).' · '.self::at($f['started_at'] ?? null), 'title' => implode(' / ', array_map(fn ($r) => $r['command'].': '.mb_substr((string) ($r['error'] ?? ''), 0, 120), $failed))];
        }
        if (array_filter($found, fn ($r) => ($r['status'] ?? '') === 'running') !== []) {
            return ['state' => 'running', 'text' => '도는 중 · '.self::at(max(array_column($found, 'started_at'))), 'title' => ''];
        }
        $latest = max(array_map(fn ($r) => $r['finished_at'] ?? $r['started_at'] ?? '', $found));
        $partial = count($found) < count($commands) ? ' ('.count($found).'/'.count($commands).' 기록)' : '';

        return ['state' => 'ok', 'text' => '성공 '.self::at($latest).$partial, 'title' => implode(', ', array_column($found, 'command'))];
    }

    private static function at(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format('m/d H:i') : '';
    }
}
