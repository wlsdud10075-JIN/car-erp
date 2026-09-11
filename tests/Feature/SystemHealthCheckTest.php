<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\DailyExchangeRate;
use App\Models\Setting;
use App\Services\SystemHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 아침 점검 요약 2단계 (jin 2026-09-11).
 *
 * 지키는 것:
 *   ① 「며칠째 안 도는가」를 본다 — 에러가 안 나도 잡힌다(2026-09-11 환율 사고 재현)
 *   ② 기록이 없는 것은 X 가 아니다 — 새 회사·미가동 항목을 고장으로 찍으면 매일 빨개진다
 *   ③ 항목 on/off·임계일이 배포 없이 설정으로 바뀐다
 *   ④ 요약 토글이 꺼지면 안 보낸다 / 미설정이면 아무 일도 안 일어난다
 *   ⑤ 받는 사람이 한국어를 쓴다 — 서버 로케일에 안 맡긴다
 */
class SystemHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function telegramOn(bool $daily = true): void
    {
        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => Crypt::encryptString('123:ABC'), 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_chat_id'], ['value' => '55512345', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => 'telegram_daily_summary'], ['value' => $daily ? '1' : '0', 'type' => 'boolean']);
    }

    private function rowFor(string $key): array
    {
        foreach (app(SystemHealthReport::class)->rows() as $r) {
            if ($r['key'] === $key) {
                return $r;
            }
        }

        $this->fail("점검 항목 {$key} 이 목록에 없다");
    }

    // ── ① 조용히 멈춘 것을 잡는다 ──────────────────────────────────────

    public function test_a_silently_stalled_exchange_snapshot_is_flagged(): void
    {
        // 2026-09-11 실사고 재현 — 예외도 로그도 없이 스냅샷만 멈춘 상태.
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => now()->subDays(9)->toDateString(), 'rate' => 1300, 'source' => 'naver']);

        $row = $this->rowFor('exchange');

        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('9', $row['detail']);
    }

    public function test_a_fresh_snapshot_is_healthy(): void
    {
        // 정상 나이는 2일이다 — 스냅샷이 09:00 에 `rate_date=어제`를 쓰고 점검은 08:00 에 돈다.
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => now()->subDays(2)->toDateString(), 'rate' => 1300, 'source' => 'naver']);

        $this->assertTrue($this->rowFor('exchange')['ok']);
    }

    public function test_undelivered_alerts_are_flagged_by_count_not_by_age(): void
    {
        AlimtalkLog::create(['template_code' => 'erp_x', 'phone' => '01000000000', 'status' => 'failed', 'message' => 'x']);

        $row = $this->rowFor('alimtalk_failed');

        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('1', $row['detail']);
    }

    // ── ② 기록 없음 ≠ 고장 ─────────────────────────────────────────────

    public function test_no_record_is_not_a_failure(): void
    {
        // 갓 붙인 회사는 어느 표에도 행이 없다. 이걸 X 로 찍으면 매일 빨갛게 뜬다.
        foreach (['exchange', 'alimtalk_sent', 'holidays'] as $key) {
            $row = $this->rowFor($key);
            $this->assertTrue($row['ok'], "{$key}: 기록이 없는 것을 고장으로 찍었다");
        }
    }

    // ── ③ 설정으로 바뀐다 ──────────────────────────────────────────────

    public function test_an_item_can_be_turned_off(): void
    {
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => now()->subDays(30)->toDateString(), 'rate' => 1300, 'source' => 'naver']);
        $this->assertFalse($this->rowFor('exchange')['ok']);

        Setting::updateOrCreate(['key' => 'health_check_exchange_enabled'], ['value' => '0', 'type' => 'boolean']);

        $keys = array_column(app(SystemHealthReport::class)->rows(), 'key');
        $this->assertNotContains('exchange', $keys, '끈 항목이 목록에 남아 있다');
    }

    public function test_the_threshold_is_configurable(): void
    {
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => now()->subDays(5)->toDateString(), 'rate' => 1300, 'source' => 'naver']);
        $this->assertFalse($this->rowFor('exchange')['ok']);   // 기본 2일 → 이상

        Setting::updateOrCreate(['key' => 'health_check_exchange_days'], ['value' => '10', 'type' => 'integer']);

        $this->assertTrue($this->rowFor('exchange')['ok'], '임계를 늘렸는데 판정이 안 따라왔다');
    }

    public function test_the_assistant_index_is_off_by_default(): void
    {
        // 2026-09-11 현재 board OCR 과 GPU 경합으로 챗봇이 꺼져 있다 — 켜두면 매일 X 가 된다.
        $this->assertFalse(SystemHealthReport::enabled('assistant_index'));

        $keys = array_column(app(SystemHealthReport::class)->rows(), 'key');
        $this->assertNotContains('assistant_index', $keys);
    }

    // ── ④ 발송 게이트 ──────────────────────────────────────────────────

    public function test_nothing_is_sent_when_telegram_is_not_configured(): void
    {
        Http::fake();

        $this->artisan('system:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_daily_summary_is_off(): void
    {
        Http::fake();
        $this->telegramOn(daily: false);

        $this->artisan('system:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_send_failure_does_not_fail_the_command(): void
    {
        // 알림 한 통 때문에 스케줄러가 실패로 끝나면 안 된다.
        Http::fake(['api.telegram.org/*' => Http::response('nope', 500)]);
        $this->telegramOn();

        $this->artisan('system:health-check')->assertSuccessful();
    }

    // ── ⑤ 본문 ─────────────────────────────────────────────────────────

    public function test_a_healthy_day_still_sends_one_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->telegramOn();
        // ⚠️ 백업 점검은 **실제 파일시스템**을 본다 — 개발 PC 의 묵은 백업 파일 때문에
        //    「정상인 날」을 만들 수 없다. 파일 의존 항목만 끄고 검사한다.
        Setting::updateOrCreate(['key' => 'health_check_db_backup_enabled'], ['value' => '0', 'type' => 'boolean']);

        $this->artisan('system:health-check')->assertSuccessful();

        // 조용한 날이 「정상」인지 「감시 사망」인지 구분하려면 이 한 통이 있어야 한다.
        Http::assertSent(fn ($r) => str_contains($r['text'], '🟢'));
    }

    public function test_a_bad_day_explains_what_it_means_in_korean(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->telegramOn();
        app()->setLocale('en');   // 서버 로케일이 영어여도 한국어로 나가야 한다
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => now()->subDays(30)->toDateString(), 'rate' => 1300, 'source' => 'naver']);

        $this->artisan('system:health-check')->assertSuccessful();

        Http::assertSent(function ($r) {
            $text = $r['text'];

            return str_contains($text, '🔴')
                && str_contains($text, '마감환율')
                // 숫자만 보면 사람은 안 움직인다 — 「그래서 무슨 일이 생기나」가 붙어야 한다.
                && str_contains($text, '옛 환율이 그대로 들어갑니다')
                && ! str_contains($text, 'Closing rates');
        });
    }

    public function test_every_check_has_a_label_and_a_reason_in_both_locales(): void
    {
        foreach (array_keys(SystemHealthReport::CHECKS) as $key) {
            foreach (['ko', 'en'] as $loc) {
                app()->setLocale($loc);
                foreach (["health.item.{$key}", "health.why.{$key}"] as $k) {
                    $this->assertNotSame($k, __($k), "{$loc}: {$k} 번역이 없다 — 키 문자열이 그대로 나간다");
                }
            }
        }
    }
}
