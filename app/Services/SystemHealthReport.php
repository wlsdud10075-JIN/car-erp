<?php

namespace App\Services;

use App\Models\AlimtalkLog;
use App\Models\DailyExchangeRate;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;

/**
 * 매일 아침 점검 — 「며칠째 안 도는가」를 본다 (jin 2026-09-11).
 *
 * 🔑 **에러 알림과 축이 다르다.** 2026-09-11 환율 사고는 예외도 로그도 없이 조용히 멈췄다
 * (SKILLS §8 #88) — 「터졌나」를 보는 감시로는 원리상 못 잡는다. 그래서 여기선
 * **「마지막 성공이 언제였나」**만 본다. 잡이 실제로 실패하는 경우는 3단계
 * (ScheduledTaskFailed 리스너)가 즉시 따로 알린다. 둘을 섞지 말 것.
 *
 * ⚠️ **오탐이 나면 사람은 알림 전체를 무시한다** — 그러면 없는 것보다 나쁘다(있다고 믿게 되므로).
 * 그래서 임계값은 **정상 공백을 실제 스케줄로 계산해** 넉넉히 잡았고, 전부 기능설정에서
 * 배포 없이 조정할 수 있다. 항목별 on/off 도 마찬가지다.
 */
class SystemHealthReport
{
    /**
     * 점검 항목. days = 「마지막 성공」이 이 일수를 넘기면 X.
     *
     * 기본 임계는 **정상 공백**을 근거로 정했다 — 근거 없이 줄이지 말 것:
     *  - exchange  : 스냅샷이 09:00 에 `rate_date=어제`를 쓰고 점검은 08:00(그 전)이라 **정상도 항상 2일**이다.
     *                주말도 매일 돌아 공백이 없다(커맨드 주석 — weekdays 로 하면 금요일이 유실된다).
     *                ⇒ 2일이면 한 번만 놓쳐도 다음 아침에 잡힌다.
     *  - alimtalk  : 평일 09:00 만 발송 + **대상 0건이면 행조차 안 생긴다**. 연휴·비수기를 덮으려면 넉넉해야 한다.
     *  - holidays  : 한 해치를 받아두므로 며칠 멈춰도 당장 지장이 없다. 「완전히 죽었나」만 본다.
     *  - backup    : 매일 03:00. 하루라도 비면 바로 알아야 하는 유일한 항목이다.
     *  - assistant : 🔴 **기본 off** — 2026-09-11 현재 board OCR 과 GPU 경합으로 챗봇이 꺼져 있다.
     *                켜두면 매일 X 로 떠서 요약 전체를 안 읽게 된다. GPU 정리 후 기능설정에서 켠다.
     *
     * @var array<string, array{days:int, default_on:bool}>
     */
    public const CHECKS = [
        'exchange' => ['days' => 2, 'default_on' => true],
        'alimtalk_sent' => ['days' => 7, 'default_on' => true],
        'alimtalk_failed' => ['days' => 0, 'default_on' => true],   // days 미사용(건수 기준)
        'holidays' => ['days' => 10, 'default_on' => true],
        'db_backup' => ['days' => 2, 'default_on' => true],
        'assistant_index' => ['days' => 3, 'default_on' => false],
        // board→ERP 전송 감사 (2026-09-28, 야간 배치 실행기 1단계 — §12-1 D). board 의
        //   `board:purchase-sync-audit`(07:40) 가 남긴 JSON 을 읽는다. 판정은 그 명령의 SQL, 여기선 세기만.
        //   경로(BOARD_AUDIT_JSON)가 비면 행 자체가 안 생긴다 — board 가 없는 회사(karaba)에
        //   「기록 없음」이 매일 뜨면 소음이다. days = JSON 이 이 일수보다 오래되면 X(감사 명령이 죽으면
        //   조용해지는 것을 막는다 — 침묵이 정상으로 읽히면 안 된다).
        'board_sync_stalled' => ['days' => 1, 'default_on' => true],
        'board_sync_integrity' => ['days' => 1, 'default_on' => true],
    ];

    /** board 감사 JSON 을 읽는 항목 — 경로 미설정이면 rows() 에서 통째로 뺀다. */
    public const BOARD_KEYS = ['board_sync_stalled', 'board_sync_integrity'];

    /** @var array{ok:bool, data:?array, detail:?string}|null 요청당 1회만 파싱 */
    private ?array $boardAudit = null;

    /** @return array<int, array{key:string, ok:bool, detail:string}> 꺼진 항목은 아예 안 들어온다 */
    public function rows(): array
    {
        $rows = [];
        foreach (array_keys(self::CHECKS) as $key) {
            if (! self::enabled($key)) {
                continue;
            }
            if (in_array($key, self::BOARD_KEYS, true) && self::boardAuditPath() === null) {
                continue;
            }
            $rows[] = ['key' => $key] + $this->check($key);
        }

        return $rows;
    }

    public static function enabled(string $key): bool
    {
        return (bool) Setting::get("health_check_{$key}_enabled", self::CHECKS[$key]['default_on'] ?? false);
    }

    public static function days(string $key): int
    {
        return (int) Setting::get("health_check_{$key}_days", self::CHECKS[$key]['days'] ?? 2);
    }

    /** @return array{ok:bool, detail:string} */
    private function check(string $key): array
    {
        return match ($key) {
            'exchange' => $this->fromDate(
                DailyExchangeRate::where('source', 'naver')->max('rate_date'),
                self::days('exchange'),
            ),
            'alimtalk_sent' => $this->fromDate(
                AlimtalkLog::max('created_at'),
                self::days('alimtalk_sent'),
            ),
            'alimtalk_failed' => $this->fromCount(AlimtalkLog::needsAttention()->count()),
            'holidays' => $this->fromDate(
                Setting::get('holidays_auto_synced_at'),
                self::days('holidays'),
            ),
            'db_backup' => $this->fromFile(
                $this->latestBackup(),
                self::days('db_backup'),
            ),
            'assistant_index' => $this->fromFile(
                storage_path('app/index-erp.json'),
                self::days('assistant_index'),
            ),
            'board_sync_stalled' => $this->boardRow('stalled'),
            'board_sync_integrity' => $this->boardRow('integrity'),
            default => ['ok' => true, 'detail' => '-'],
        };
    }

    /** `BOARD_AUDIT_JSON` — 비면 null. board 가 같은 박스에 있는 회사만 설정(heymanerp `/var/www/board/…`, ssancarerp `/var/www/board-ssancar/…`). */
    public static function boardAuditPath(): ?string
    {
        $p = trim((string) config('services.board_read.audit_json', ''));

        return $p === '' ? null : $p;
    }

    /**
     * board 감사 JSON 한 행. 형태는 board 명령 명세(§9 board-07 전달문) 그대로:
     *   {generated_at, counts:{stalled, synced_without_erp_id, erp_id_without_synced, missing_in_erp}, missing_in_erp:[]|null, errors:[]}
     *
     * 🚫 여기서 판정하지 않는다 — 세는 것은 board 의 SQL, 이 행은 그 숫자를 옮겨 적을 뿐이다.
     *    「missing_in_erp 가 null」= board 가 ERP 존재 확인에 실패한 것 → 0 이 아니라 X 다(모르는 것을 정상으로 찍지 않는다).
     */
    private function boardRow(string $which): array
    {
        $audit = $this->loadBoardAudit();
        if ($audit['data'] === null) {
            return ['ok' => $audit['ok'], 'detail' => (string) $audit['detail']];
        }
        $c = $audit['data']['counts'] ?? [];
        $n = fn (string $k) => (int) ($c[$k] ?? 0);

        if ($which === 'stalled') {
            $missingUnknown = array_key_exists('missing_in_erp', $audit['data']) && $audit['data']['missing_in_erp'] === null;
            if ($missingUnknown) {
                $err = implode(' / ', array_map('strval', (array) ($audit['data']['errors'] ?? [])));

                return ['ok' => false, 'detail' => __('health.board_audit_failed', ['err' => $err !== '' ? $err : '-'])];
            }
            $s = $n('stalled');
            $m = $n('missing_in_erp');
            // 2026-10-06 — ERP 에서 지워진 차(deleted_in_erp)는 실패가 아니라 참고 건수. board 가 아직 안 보내면 0.
            $d = $n('deleted_in_erp');
            $ok = $s === 0 && $m === 0;
            $detail = $ok ? __('health.none') : __('health.board_stalled', ['s' => $s, 'm' => $m]);
            if ($d > 0) {
                $detail .= ' '.__('health.board_deleted_note', ['d' => $d]);
            }

            return ['ok' => $ok, 'detail' => $detail];
        }

        $a = $n('synced_without_erp_id');
        $b = $n('erp_id_without_synced');

        return [
            'ok' => $a === 0 && $b === 0,
            'detail' => ($a === 0 && $b === 0) ? __('health.none') : __('health.board_integrity', ['a' => $a, 'b' => $b]),
        ];
    }

    /** JSON 을 한 번만 읽는다. 파일 없음 = 기록 없음(정상) / 오래됨·깨짐 = X. */
    private function loadBoardAudit(): array
    {
        if ($this->boardAudit !== null) {
            return $this->boardAudit;
        }
        $path = self::boardAuditPath();
        if ($path === null || ! File::exists($path)) {
            return $this->boardAudit = ['ok' => true, 'data' => null, 'detail' => __('health.no_record')];
        }
        $fresh = $this->fromDate(now()->setTimestamp(File::lastModified($path)), self::days('board_sync_stalled'));
        if (! $fresh['ok']) {
            return $this->boardAudit = ['ok' => false, 'data' => null, 'detail' => $fresh['detail']];
        }
        $data = json_decode((string) File::get($path), true);
        if (! is_array($data) || ! isset($data['counts']) || ! is_array($data['counts'])) {
            return $this->boardAudit = ['ok' => false, 'data' => null, 'detail' => __('health.board_audit_failed', ['err' => 'invalid json'])];
        }

        return $this->boardAudit = ['ok' => true, 'data' => $data, 'detail' => null];
    }

    /**
     * 날짜형 판정 — 「마지막 성공」이 임계일보다 오래면 X.
     *
     * 🚫 **값이 없으면 X 가 아니라 「없음」**이다. 새로 붙인 회사·아직 한 번도 안 돈 항목은
     *    고장이 아니다. 이걸 X 로 찍으면 karaba 처럼 데이터가 적은 회사가 매일 빨갛게 된다.
     */
    private function fromDate(mixed $value, int $days): array
    {
        $at = $this->toDate($value);
        if (! $at) {
            return ['ok' => true, 'detail' => __('health.no_record')];
        }

        $age = (int) $at->copy()->startOfDay()->diffInDays(now()->startOfDay());

        return [
            'ok' => $age <= $days,
            'detail' => $age <= $days
                ? $at->format('m-d')
                : __('health.stale', ['date' => $at->format('m-d'), 'days' => $age]),
        ];
    }

    private function fromFile(?string $path, int $days): array
    {
        if ($path === null || ! File::exists($path)) {
            return ['ok' => true, 'detail' => __('health.no_record')];
        }

        return $this->fromDate(now()->setTimestamp(File::lastModified($path)), $days);
    }

    private function fromCount(int $n): array
    {
        return [
            'ok' => $n === 0,
            'detail' => $n === 0 ? __('health.none') : __('health.count', ['n' => $n]),
        ];
    }

    private function toDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 최신 백업 파일 경로. 레포 `db:backup` 산출물만 본다(서버 쉘 스크립트 백업은 경로가 다르다). */
    private function latestBackup(): ?string
    {
        $dir = storage_path('backups/db');
        if (! File::isDirectory($dir)) {
            return null;
        }
        $files = File::files($dir);
        if ($files === []) {
            return null;
        }
        usort($files, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());

        return $files[0]->getPathname();
    }
}
