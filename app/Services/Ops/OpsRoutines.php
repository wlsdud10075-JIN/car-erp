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
    /**
     * kinds = 이 묶음이 도는 사이트 종류(기본 erp). 다른 종류 칸은 빈칸(「기록 없음」으로 오해하지 않게).
     * board 목록 = board 세션 회신(11-16, board dev 58724c8 — routines[] 구현).
     *
     * @var array<string, array{when: string, commands: list<string>, kinds?: list<string>}>
     */
    public const GROUPS = [
        'DB 백업' => ['when' => '매일 03:00', 'commands' => ['db:backup'], 'kinds' => ['erp', 'board']],
        '공휴일 수집' => ['when' => '매일 04:10', 'commands' => ['holidays:sync']],
        '예약 작업 기록 정리(90일)' => ['when' => '매일 04:50', 'commands' => ['model:prune'], 'kinds' => ['erp', 'board']],
        '캐시 재계산(미수·위험도·진행상태)' => ['when' => '매일 05:00', 'commands' => ['vehicles:rebuild-caches']],
        '할일 알람 스캔' => ['when' => '매일 06:00', 'commands' => ['alarms:scan']],
        '시스템 점검(아침 요약 텔레그램)' => ['when' => '매일 08:00', 'commands' => ['system:health-check']],
        '알림톡 정기 발송 9종(평일)' => ['when' => '평일 09:00~11:30 나눠서 · 각 +10분 따라잡기', 'commands' => ['alimtalk:pickup', 'alimtalk:deregistration', 'alimtalk:purchase-unpaid', 'alimtalk:sale-unpaid', 'alimtalk:eta-balance', 'alimtalk:shipping-due', 'alimtalk:deposit-cash', 'alimtalk:daily-summary', 'alimtalk:receivable-status']],
        '알림톡 주간·월간(자금보고·도착후미수·주간요약·월결산)' => ['when' => '월 09:00 자금보고·11:50 도착후미수 · 금 18:00 · 매일 09:00 판정', 'commands' => ['alimtalk:capital-weekly', 'alimtalk:arrived-unpaid-weekly', 'alimtalk:weekly-summary', 'alimtalk:monthly-closing']],
        '알림톡 도달 결과 조회' => ['when' => '매시', 'commands' => ['alimtalk:poll-report']],
        '환율 스냅샷(네이버)' => ['when' => '매일 09:00', 'commands' => ['exchange:snapshot-daily']],
        'GPU PC 감시(Ollama 핑)' => ['when' => '5분마다', 'commands' => ['assistant:health-check']],
        // ── board ──
        '요청 결과 휴대폰 알림 · 탁송 배차 예약 발송' => ['when' => '매분', 'commands' => ['board:push-request-results', 'board:transport-send'], 'kinds' => ['board']],
        '관제 하트비트 · GPU PC 응답 확인' => ['when' => '매분 · 5분', 'commands' => ['ops-heartbeat', 'ops-gpu-ping'], 'kinds' => ['board']],
        '바이어 회신·상담 승격·검차 사진 확인(respond.io·ssancar.com)' => ['when' => '2분마다', 'commands' => ['board:poll-verdicts', 'board:poll-promotions', 'board:poll-ssancar-media'], 'kinds' => ['board']],
        '업무일지 마감 알림' => ['when' => '5분마다', 'commands' => ['board:work-journal-cutoff'], 'kinds' => ['board']],
        '환율 갱신 · 챗봇 색인·GPU 점검' => ['when' => '매시 정각', 'commands' => ['board:refresh-rates', 'board:assistant-health'], 'kinds' => ['board']],
        '검차 큐 일일 대사(ssancar.com)' => ['when' => '매일 07:20', 'commands' => ['board:inspection-reconcile'], 'kinds' => ['board']],
        'board→ERP 전송 감사' => ['when' => '매일 07:40', 'commands' => ['board:purchase-sync-audit'], 'kinds' => ['board']],
        '지역 검차 알림톡' => ['when' => '기능설정 시각 있을 때만', 'commands' => ['board:alimtalk-region-inspection'], 'kinds' => ['board']],
    ];

    /** 1시간 넘게 「도는 중」이면 멈춤 의심 — 3사 실측 최장 9초(11-16), 넉넉하게 */
    public const STUCK_MINUTES = 60;

    /**
     * 서버 밖에서 도는 루틴 — 실제 기록으로 판정한다(11-16, 구: 설명 줄만).
     *   mark  = GPU PC 스크립트가 끝에 heymanerp 로 `ops:mark <키>` 를 부른다(어느 사이트 기록이든 합쳐 본다)
     *   brain = 허브의 세컨드 브레인 목록 시각 · apt = 사이트마다 OpsSummary::external() 의 apt_run_at
     *   max_age_hours 안에 성공 기록이 없으면 「멈춤?」.
     */
    public const EXTERNAL = [
        'nightly-investigate' => ['label' => '야간 조사 (회사 GPU PC)', 'when' => '매일 03:30 · 끝나면 텔레그램', 'source' => 'mark', 'max_age_hours' => 30],
        'nightly-notion' => ['label' => 'Notion 가이드 대조·발행 (회사 GPU PC) — 발행 뒤 불일치 0 이면 성공', 'when' => '매일 04:30', 'source' => 'mark', 'max_age_hours' => 30],
        'brain-sync' => ['label' => '세컨드 브레인 목록 올리기 (jin PC)', 'when' => '매일 10:10', 'source' => 'brain', 'max_age_hours' => 30],
        'apt' => ['label' => '자동 보안 업데이트 (서버 apt)', 'when' => '매일 02:30~02:45', 'source' => 'apt', 'max_age_hours' => 30],
    ];

    /**
     * 🧑 우리 루틴 — 주 1회, **토요일 10:00 이 PC 에서 자동 실행**(일요일 재시도) (jin 2026-11-16 «아무도 안 쓰는 주말에 자동으로 하면 고칠 시간이 있다»).
     *   회차 = 가장 최근 토요일 00:00 부터. 그 뒤 마지막 기록이 ok → ✓ · todo(할 일 남음) → 주의 · failed → 실패.
     *   기록이 없으면: 주말엔 「대기」, 평일엔 「지난 주말 안 돌았음」(PC 꺼짐·8001 꺼짐) 주의.
     *   기록 = 주말 작업(`ops:mark <키> --push`) · 스킬 끝 · 관제 [했음] 버튼(손으로 끝냈을 때).
     *   🔀 「Notion 가이드 반영 확인」은 빠졌다 — 새벽 04:30 대조·발행이 매일 하고, 결과는 서버 밖 루틴 「Notion 가이드 대조·발행」 줄이 판정한다.
     */
    public const MANUAL = [
        'scene-check' => ['label' => '씬 점검 (화면 회귀)', 'how' => '토 10:00 자동 · 실패하면 텔레그램 → 「씬 점검 실패 봐줘」'],
        'memory-doc-cleanup' => ['label' => '메모리·문서 정리', 'how' => '토 10:00 측정·후보 표 자동 · 후보가 있으면 /memory-cleanup 으로 정리'],
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
            $columns[] = ['key' => $s['site']['key'], 'name' => $s['site']['name'], 'ok' => (bool) $s['ok'], 'kind' => $kind];
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
                $by[$col['key']] = in_array($col['kind'], $g['kinds'] ?? ['erp'], true)
                    ? self::cell($g['commands'], $byCmd[$col['key']] ?? null, $col['ok'])
                    : ['state' => 'na', 'text' => '', 'title' => '이 사이트에서는 돌지 않는다'];
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

    /**
     * 모든 사이트 요약의 marks 를 키별로 합친다(최신순). 사이트 하나가 marks 를 못 보내도(테이블 없음 → error) 나머지로.
     *
     * @return array<string, list<array{status: string, at: string, note: ?string, source: string, site: string}>>
     */
    public static function marks(array $sites): array
    {
        $out = [];
        foreach ($sites as $s) {
            foreach ((array) ($s['data']['marks'] ?? []) as $key => $list) {
                if (! is_string($key) || ! is_array($list) || array_is_list($list) === false) {
                    continue;
                }
                foreach ($list as $m) {
                    if (is_array($m) && isset($m['at'], $m['status'])) {
                        $out[$key][] = $m + ['site' => $s['site']['name'] ?? ''];
                    }
                }
            }
        }
        foreach ($out as $k => $list) {
            usort($list, fn ($x, $y) => strcmp($y['at'], $x['at']));
            $out[$k] = $list;
        }

        return $out;
    }

    /**
     * 서버 밖 루틴 줄 — span 이면 한 칸(사이트 무관), 아니면 사이트별(apt).
     *
     * @return list<array{key: string, label: string, when: string, span: bool, cell: ?array, by: array}>
     */
    public static function externalRows(array $sites, ?string $brainGeneratedAt): array
    {
        $marks = self::marks($sites);
        $rows = [];
        foreach (self::EXTERNAL as $key => $e) {
            $row = ['key' => $key, 'label' => $e['label'], 'when' => $e['when'], 'span' => true, 'cell' => null, 'by' => []];
            if ($e['source'] === 'mark') {
                $row['cell'] = self::ageCell($marks[$key] ?? [], $e['max_age_hours']);
            } elseif ($e['source'] === 'brain') {
                $row['cell'] = self::ageCell($brainGeneratedAt ? [['status' => 'ok', 'at' => $brainGeneratedAt]] : [], $e['max_age_hours']);
            } else {   // apt — ERP 사이트마다
                $row['span'] = false;
                foreach ($sites as $s) {
                    if (! in_array($s['site']['kind'] ?? 'erp', ['erp', 'board'], true)) {
                        continue;
                    }
                    $at = $s['data']['external']['apt_run_at'] ?? null;
                    $installed = $s['data']['external']['apt_installed_at'] ?? null;
                    $cell = ! $s['ok'] ? ['state' => 'none', 'text' => '—', 'title' => '사이트 응답 없음']
                        : (array_key_exists('external', (array) $s['data']) ? self::ageCell($at ? [['status' => 'ok', 'at' => $at]] : [], $e['max_age_hours'])
                            : ['state' => 'none', 'text' => '—', 'title' => '이 사이트는 아직 안 보낸다']);
                    if ($installed) {
                        $cell['title'] = trim($cell['title'].' 마지막 설치 '.self::at($installed));
                    }
                    $row['by'][$s['site']['key']] = $cell;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 우리 루틴 줄 — 이번 회차(가장 최근 토요일부터) 판정.
     *
     * @return list<array{key: string, label: string, how: string, state: string, text: string, title: string, warn: bool}>
     */
    public static function manualRows(array $sites, ?Carbon $now = null): array
    {
        $now ??= now();
        $cycleStart = $now->copy()->startOfDay();
        while ($cycleStart->dayOfWeekIso !== 6) {   // 가장 최근 토요일(오늘이 토요일이면 오늘)
            $cycleStart->subDay();
        }
        $weekend = $now->dayOfWeekIso >= 6;
        $marks = self::marks($sites);
        $rows = [];
        foreach (self::MANUAL as $key => $m) {
            $list = $marks[$key] ?? [];
            $cur = array_values(array_filter($list, fn ($x) => Carbon::parse($x['at'])->gte($cycleStart)));
            $row = ['key' => $key, 'label' => $m['label'], 'how' => $m['how']];
            if ($cur !== []) {
                $x = $cur[0];
                $at = Carbon::parse($x['at'])->timezone(config('app.timezone'))->isoFormat('MM/DD(dd) HH:mm');
                $note = trim((string) ($x['note'] ?? ''));
                $rows[] = $row + match ($x['status']) {
                    'ok' => ['state' => 'ok', 'warn' => false, 'text' => '✓ '.$at.($note !== '' ? ' · '.$note : ''), 'title' => (string) ($x['source'] ?? '')],
                    'todo' => ['state' => 'warn', 'warn' => true, 'text' => '할 일 있음 · '.$at.($note !== '' ? ' · '.$note : ''), 'title' => $m['how']],
                    default => ['state' => 'failed', 'warn' => true, 'text' => '실패 · '.$at.($note !== '' ? ' · '.$note : ''), 'title' => $m['how']],
                };

                continue;
            }
            $last = $list[0]['at'] ?? null;
            $rows[] = $row + ($weekend
                ? ['state' => 'none', 'warn' => false, 'text' => '이번 주말 대기'.($last ? ' · 지난번 '.self::at($last) : ''), 'title' => '토 10:00 자동(못 돌면 일요일 재시도)']
                : ['state' => 'warn', 'warn' => true, 'text' => '지난 주말 안 돌았음'.($last ? ' · 마지막 '.self::at($last) : ' · 기록 없음'), 'title' => '그 시각에 이 PC·로컬 서버(8001)가 켜져 있었나 — 손으로 돌리고 [했음]']);
        }

        return $rows;
    }

    /** 마지막 성공이 max 시간 안이면 성공, 넘으면 「멈춤?」, 마지막이 실패면 실패, 기록 없으면 회색 */
    private static function ageCell(array $list, int $maxHours): array
    {
        if ($list === []) {
            return ['state' => 'none', 'text' => '기록 없음', 'title' => '11-16 부터 쌓임 — 다음 실행부터'];
        }
        $latest = $list[0];
        if (($latest['status'] ?? '') === 'failed') {
            return ['state' => 'failed', 'text' => '실패 · '.self::at($latest['at']), 'title' => (string) ($latest['note'] ?? '')];
        }
        $ok = Carbon::parse($latest['at'])->gte(now()->subHours($maxHours));

        return ['state' => $ok ? 'ok' : 'failed', 'text' => ($ok ? '성공 ' : '멈춤? 마지막 ').self::at($latest['at']),
            'title' => $ok ? (string) ($latest['note'] ?? '') : $maxHours.'시간 넘게 새 기록이 없다'];
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

            return ['state' => 'failed', 'text' => '실패 '.count($failed).'/'.count($found).' · '.self::at($f['started_at'] ?? null), 'title' => implode(' / ', array_map(fn ($r) => $r['command'].': '.mb_substr((string) ($r['error'] ?? ''), 0, 120), $failed)).self::lastOk($failed)];
        }
        $running = array_values(array_filter($found, fn ($r) => ($r['status'] ?? '') === 'running'));
        if ($running !== []) {
            $since = max(array_column($running, 'started_at'));
            if ($since && Carbon::parse($since)->lt(now()->subMinutes(self::STUCK_MINUTES))) {   // 기록이 「도는 중」인 채로 1시간 = 죽었거나 걸렸다
                return ['state' => 'failed', 'text' => '멈춤 의심 · '.self::at($since).' 시작', 'title' => self::STUCK_MINUTES.'분 넘게 끝나지 않았다'.self::lastOk($found)];
            }

            return ['state' => 'running', 'text' => '도는 중 · '.self::at($since), 'title' => ''];
        }
        $latest = max(array_map(fn ($r) => $r['finished_at'] ?? $r['started_at'] ?? '', $found));
        $partial = count($found) < count($commands) ? ' ('.count($found).'/'.count($commands).' 기록)' : '';

        return ['state' => 'ok', 'text' => '성공 '.self::at($latest).$partial, 'title' => implode(', ', array_column($found, 'command'))];
    }

    /** 「 · 마지막 성공 11/15 09:00」 — 실패·멈춤 칸에 붙인다(언제부터 깨졌나). 성공 기록이 없으면 「마지막 성공 없음」 */
    private static function lastOk(array $runs): string
    {
        $oks = array_filter(array_map(fn ($r) => $r['last_ok_at'] ?? null, $runs));

        return ' · 마지막 성공 '.($oks !== [] ? self::at(max($oks)) : '없음(기록 이후)');
    }

    private static function at(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format('m/d H:i') : '';
    }
}
