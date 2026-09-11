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
    ];

    /** @return array<int, array{key:string, ok:bool, detail:string}> 꺼진 항목은 아예 안 들어온다 */
    public function rows(): array
    {
        $rows = [];
        foreach (array_keys(self::CHECKS) as $key) {
            if (! self::enabled($key)) {
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
            default => ['ok' => true, 'detail' => '-'],
        };
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
