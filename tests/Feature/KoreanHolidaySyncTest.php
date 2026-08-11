<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\KoreanHolidayService;
use App\Support\AlimtalkRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 공휴일 자동 수집 (jin 2026-08-11) — 한국천문연구원 특일 API.
 *
 * 공휴일은 달력에 늘 있는 정보다. 사람이 옮겨 적게 하면 **결국 안 적게 되고, 그날 담당자에게
 * 알림이 간다.** 그래서 자동 수집이 주 출처이고, 수기는 회사 휴무일 전용으로 남긴다.
 *
 * 🛟 이 테스트가 지키는 핵심 = **실패해도 기존 값을 지우지 않는다.**
 *    빈 응답을 성공으로 저장하면 그 해 공휴일이 통째로 사라져, 설·추석에 담당자 폰이 울린다.
 */
class KoreanHolidaySyncTest extends TestCase
{
    use RefreshDatabase;

    private function withKey(): void
    {
        config(['services.holiday.key' => 'TESTKEY']);
    }

    private function body(array $items): array
    {
        return ['response' => ['header' => ['resultCode' => '00'], 'body' => ['items' => ['item' => $items]]]];
    }

    private function item(int $locdate, string $name, string $isHoliday = 'Y'): array
    {
        return ['dateKind' => '01', 'dateName' => $name, 'isHoliday' => $isHoliday, 'locdate' => $locdate, 'seq' => 1];
    }

    public function test_sync_stores_holidays_and_feeds_the_judgement(): void
    {
        $this->withKey();
        Http::fake(['*' => Http::response($this->body([
            $this->item(20260217, '설날'),
            $this->item(20260525, '부처님오신날'),
            $this->item(20261005, '대체공휴일'),
        ]))]);

        $n = app(KoreanHolidayService::class)->syncYear(2026);

        $this->assertSame(3, $n);
        $this->assertSame(['2026-02-17' => '설날', '2026-05-25' => '부처님오신날', '2026-10-05' => '대체공휴일'],
            KoreanHolidayService::cached(2026));

        // 수기 등록이 비어 있어도 판정이 걸린다 — 그게 자동화의 목적이다.
        $this->assertSame([], AlimtalkRecipients::holidays());
        $this->assertTrue(AlimtalkRecipients::isHoliday(Carbon::parse('2026-02-17')));
        $this->assertTrue(AlimtalkRecipients::isHoliday(Carbon::parse('2026-10-05')), '대체공휴일이 안 잡혔다');
        $this->assertFalse(AlimtalkRecipients::isHoliday(Carbon::parse('2026-02-18')));
    }

    /** 국경일이어도 쉬지 않는 날(제헌절 등)은 공휴일이 아니다 — isHoliday=N 은 버린다. */
    public function test_non_rest_days_are_ignored(): void
    {
        $this->withKey();
        Http::fake(['*' => Http::response($this->body([
            $this->item(20260717, '제헌절', 'N'),
            $this->item(20260815, '광복절'),
        ]))]);

        app(KoreanHolidayService::class)->syncYear(2026);

        $this->assertSame(['2026-08-15' => '광복절'], KoreanHolidayService::cached(2026));
    }

    /** 결과가 1건이면 `item` 이 배열이 아니라 객체로 온다 — 이 부류 API 의 고질병. */
    public function test_single_item_comes_as_an_object(): void
    {
        $this->withKey();
        Http::fake(['*' => Http::response(['response' => ['body' => ['items' => [
            'item' => $this->item(20260101, '1월1일'),
        ]]]])]);

        $this->assertSame(1, app(KoreanHolidayService::class)->syncYear(2026));
        $this->assertSame(['2026-01-01' => '1월1일'], KoreanHolidayService::cached(2026));
    }

    /**
     * 🛟 **빈 응답·장애로 기존 저장분을 지우지 않는다.**
     * 0건을 성공으로 저장하면 그 해 공휴일이 사라져 설·추석에 담당자에게 알림이 간다.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function badResponseProvider(): array
    {
        return [
            '빈 items(문자열)' => [['response' => ['body' => ['items' => '']]]],
            '빈 배열' => [['response' => ['body' => ['items' => ['item' => []]]]]],
            '전부 근무일' => [['response' => ['body' => ['items' => ['item' => [
                ['dateName' => '제헌절', 'isHoliday' => 'N', 'locdate' => 20260717],
            ]]]]]],
            '에러 응답' => [['OpenAPI_ServiceResponse' => ['cmmMsgHeader' => ['errMsg' => 'SERVICE_KEY_IS_NOT_REGISTERED_ERROR']]]],
        ];
    }

    #[DataProvider('badResponseProvider')]
    public function test_bad_response_keeps_previous_data(mixed $body): void
    {
        $this->withKey();
        Setting::updateOrCreate(
            ['key' => KoreanHolidayService::cacheKey(2026)],
            ['value' => json_encode(['2026-02-17' => '설날'], JSON_UNESCAPED_UNICODE), 'type' => 'string'],
        );

        Http::fake(['*' => Http::response($body)]);

        $this->assertNull(app(KoreanHolidayService::class)->syncYear(2026), '빈 결과를 성공으로 처리했다');
        $this->assertSame(['2026-02-17' => '설날'], KoreanHolidayService::cached(2026), '기존 저장분이 지워졌다');
    }

    public function test_http_failure_keeps_previous_data(): void
    {
        $this->withKey();
        Setting::updateOrCreate(
            ['key' => KoreanHolidayService::cacheKey(2026)],
            ['value' => json_encode(['2026-02-17' => '설날'], JSON_UNESCAPED_UNICODE), 'type' => 'string'],
        );
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->assertNull(app(KoreanHolidayService::class)->syncYear(2026));
        $this->assertSame(['2026-02-17' => '설날'], KoreanHolidayService::cached(2026));
    }

    /** 키가 없으면 아무것도 안 하고 조용히 넘어간다 — 스케줄을 빨갛게 만들지 않는다. */
    public function test_without_a_key_it_is_inert(): void
    {
        config(['services.holiday.key' => '']);
        Http::fake();

        $this->assertFalse(KoreanHolidayService::isConfigured());
        $this->assertNull(app(KoreanHolidayService::class)->syncYear(2026));
        $this->artisan('holidays:sync')->assertSuccessful();
        Http::assertNothingSent();

        // 그래도 내장 고정 공휴일은 지켜진다(바닥).
        $this->assertTrue(AlimtalkRecipients::isHoliday(Carbon::parse('2026-01-01')));
    }

    /** 커맨드는 올해+내년을 받는다 — 1월 1일에 그 해가 비어 있지 않게. */
    public function test_command_syncs_this_year_and_next(): void
    {
        $this->withKey();
        Carbon::setTestNow('2026-12-20 04:10');
        Http::fake(['*' => Http::response($this->body([$this->item(20260101, '1월1일')]))]);

        $this->artisan('holidays:sync')->assertSuccessful();

        $years = [];
        foreach (Http::recorded() as [$req]) {
            parse_str(parse_url($req->url(), PHP_URL_QUERY) ?: '', $q);
            $years[] = (int) ($q['solYear'] ?? 0);
        }
        $this->assertSame([2026, 2027], $years);

        Carbon::setTestNow();
    }
}
