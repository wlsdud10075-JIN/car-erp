<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 대한민국 공휴일 자동 수집 — 한국천문연구원 「특일 정보」(공공데이터포털).
 *
 * 왜 만들었나 (jin 2026-08-11): 공휴일을 수기로만 받으면 **매년 안 적게 되고, 그날 담당자에게
 * 알림이 간다**. 달력에 늘 있는 정보를 사람이 옮겨 적을 이유가 없다.
 * 자동으로 잡히는 것 = 설날·추석·부처님오신날(음력) · **대체공휴일** · 임시공휴일 · 선거일.
 * 수기로 남는 것 = **회사 휴무일**(창립기념일·단체휴가 등) 뿐이다.
 *
 * 🚫 **발송 경로에서 API 를 부르지 않는다.** 하루 한 번 커맨드가 받아 Setting 에 넣어두고,
 *    판정은 그 저장분만 읽는다 — 알림톡이 외부 API 응답 시간·장애에 묶이면 안 된다.
 *
 * 🛟 **실패 방향이 안전하다.** 키 미설정·API 장애면 아무것도 덮어쓰지 않고 직전 저장분을 계속 쓴다.
 *    그마저 없으면 `AlimtalkRecipients::FIXED_HOLIDAYS`(내장 8일)가 바닥을 받친다.
 *    최악이라도 "공휴일을 근무일로 오인해 담당자에게 알림이 감" 수준이고, 발송이 멈추지는 않는다.
 */
class KoreanHolidayService
{
    /**
     * 공공데이터포털 — 한국천문연구원 특일 정보 「공휴일 정보 조회」.
     * 활용가이드 v1.4 + 실호출 확인(2026-08-11): `solYear` 필수 / `solMonth` 선택 /
     * `numOfRows` 기본 10 이라 반드시 올려야 한 해가 다 온다.
     */
    private const URL = 'https://apis.data.go.kr/B090041/openapi/service/SpcdeInfoService/getRestDeInfo';

    /** 활용기간 만료일(수기 입력) — 24개월 뒤 조용히 끊기는 걸 막는 유일한 신호. */
    public static function expiresAtKey(): string
    {
        return 'holiday_api_expires_at';
    }

    /** 연도별 저장 키. 공휴일은 전국 공통이라 회사(set)로 안 가른다. */
    public static function cacheKey(int $year): string
    {
        return "holidays_auto_{$year}";
    }

    public static function lastSyncedKey(): string
    {
        return 'holidays_auto_synced_at';
    }

    public static function isConfigured(): bool
    {
        return trim((string) config('services.holiday.key', '')) !== '';
    }

    /**
     * 활용기간 만료까지 남은 일수. 미입력이면 null(경고 안 함).
     * 음수면 이미 지난 것 — 그 뒤로는 수집이 조용히 실패하고 저장분만 늙어간다.
     */
    public static function daysUntilExpiry(): ?int
    {
        $raw = trim((string) (Setting::get(self::expiresAtKey(), '') ?: ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays(Carbon::parse($raw)->startOfDay(), false);
    }

    /**
     * 저장된 자동 수집분 — 'YYYY-MM-DD' => 이름.
     *
     * @return array<string, string>
     */
    public static function cached(int $year): array
    {
        $raw = Setting::get(self::cacheKey($year));
        $rows = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($rows) ? $rows : [];
    }

    /**
     * 한 해치를 받아 저장한다. 성공 시 저장된 날짜 수, 실패면 null(저장분은 건드리지 않는다).
     *
     * ⚠️ **빈 응답을 성공으로 보지 않는다.** 0건을 저장하면 그 해 공휴일이 통째로 사라진다 —
     *    API 가 일시적으로 빈 배열을 주는 경우가 있어 방어한다.
     */
    public function syncYear(int $year): ?int
    {
        if (! self::isConfigured()) {
            return null;
        }

        try {
            // ⚠️ data.go.kr 은 **Encoding / Decoding 두 가지 키**를 준다.
            //    Encoding 키(`%3D` 처럼 %XX 를 품음)를 쿼리 빌더에 넘기면 **다시 인코딩돼**
            //    `%253D` 가 되고 인증이 실패한다. 반대로 Decoding 키를 그냥 붙이면 `+` 가
            //    공백으로 해석돼 또 실패한다. ⇒ 어느 쪽을 붙여넣어도 되게 여기서 갈라준다.
            $key = trim((string) config('services.holiday.key'));
            $serviceKey = str_contains($key, '%') ? $key : rawurlencode($key);

            $res = Http::timeout(20)->get(self::URL.'?serviceKey='.$serviceKey.'&'.http_build_query([
                'solYear' => $year,
                'numOfRows' => 100,   // 기본 10 — 안 올리면 한 해가 잘려 온다(실측 22건)
                '_type' => 'json',
            ]));

            if ($res->failed()) {
                Log::warning('holiday sync http fail', ['year' => $year, 'status' => $res->status()]);

                return null;
            }

            $days = $this->parse($res->json());
            if ($days === []) {
                Log::warning('holiday sync empty', ['year' => $year, 'body' => mb_substr($res->body(), 0, 300)]);

                return null;
            }

            ksort($days);
            Setting::updateOrCreate(
                ['key' => self::cacheKey($year)],
                ['value' => json_encode($days, JSON_UNESCAPED_UNICODE), 'type' => 'string',
                    'description' => "{$year}년 공휴일 자동 수집(한국천문연구원)"],
            );
            Setting::updateOrCreate(
                ['key' => self::lastSyncedKey()],
                ['value' => now()->format('Y-m-d H:i'), 'type' => 'string', 'description' => '공휴일 마지막 자동 수집'],
            );

            return count($days);
        } catch (\Throwable $e) {
            // cron 무음 실패 방지 — 예외는 흡수하고(스케줄 전체가 안 죽게) 로그만 남긴다.
            Log::warning('holiday sync failed', ['year' => $year, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 응답 → ['YYYY-MM-DD' => 이름].
     *
     * ⚠️ 이 부류 API 의 고질적 변덕 두 가지를 방어한다:
     *   ① 결과가 1건이면 `item` 이 **배열이 아니라 객체**로 온다.
     *   ② 결과가 0건이면 `items` 가 배열이 아니라 **빈 문자열** `""` 로 온다.
     *
     * @param  mixed  $json
     * @return array<string, string>
     */
    private function parse($json): array
    {
        $items = data_get($json, 'response.body.items.item');
        if ($items === null || $items === '' || $items === []) {
            return [];
        }
        if (! array_is_list((array) $items)) {
            $items = [$items];   // 1건일 때 객체로 오는 케이스
        }

        $out = [];
        foreach ($items as $it) {
            $locdate = (string) data_get($it, 'locdate', '');
            // 공휴일(쉬는 날)만 — `isHoliday=N` 은 근무일이라 버린다.
            //   ⚠️ 어떤 날이 Y 인지 **코드로 단정하지 말 것.** 활용가이드 v1.4(2020)는 제헌절이
            //   안 온다고 적었지만 실호출(2026-08-11)에선 2026·2027 모두 `Y` 로 왔다(대체공휴일까지).
            //   법이 바뀌면 API 가 먼저 반영된다 — 우리는 그 값을 그대로 따른다.
            if (! preg_match('/^\d{8}$/', $locdate) || strtoupper((string) data_get($it, 'isHoliday', 'N')) !== 'Y') {
                continue;
            }
            $date = substr($locdate, 0, 4).'-'.substr($locdate, 4, 2).'-'.substr($locdate, 6, 2);
            $out[$date] = (string) (data_get($it, 'dateName') ?: '공휴일');
        }

        return $out;
    }
}
