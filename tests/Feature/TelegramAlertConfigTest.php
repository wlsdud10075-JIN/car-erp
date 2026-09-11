<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\TelegramNotifier;
use App\Support\TelegramConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 시스템 장애 텔레그램 1단계 — 설정·발송 뼈대 (jin 2026-09-11).
 *
 * 지키는 것 4가지:
 *   ① 미설정 = 완전 inert (CI 가 실제로 텔레그램을 치면 안 된다)
 *   ② 마스터 off = 정기·긴급 전부 정지 / 요약 토글만 off = 긴급은 살아 있음
 *   ③ 발송 실패가 호출자를 절대 안 죽인다 (fail-open — 알림이 본체를 죽이면 본말전도)
 *   ④ 3사가 같은 대화방을 쓰므로 본문에 회사 라벨이 반드시 붙는다
 */
class TelegramAlertConfigTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 토큰·chat_id 를 저장된 상태로 만든다. */
    private function configure(bool $enabled = true, bool $daily = true): void
    {
        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => Crypt::encryptString('123:ABC'), 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_chat_id'], ['value' => '55512345', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => $enabled ? '1' : '0', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => 'telegram_daily_summary'], ['value' => $daily ? '1' : '0', 'type' => 'boolean']);
    }

    // ── ① 미설정 = inert ────────────────────────────────────────────────

    public function test_nothing_is_sent_when_not_configured(): void
    {
        Http::fake();

        $this->assertFalse(TelegramConfig::active()->isConfigured());
        $this->assertFalse(TelegramNotifier::active()->send('제목', '본문'));

        Http::assertNothingSent();
    }

    public function test_test_send_is_also_inert_when_not_configured(): void
    {
        Http::fake();

        // force 는 마스터만 우회한다 — 토큰이 없으면 force 여도 안 나간다.
        $this->assertFalse(TelegramNotifier::active()->send('제목', '', force: true));

        Http::assertNothingSent();
    }

    public function test_a_broken_token_falls_back_to_unconfigured(): void
    {
        Http::fake();
        // APP_KEY 교체 등으로 복호화가 깨진 경우 — 잘못된 토큰으로 치느니 미설정으로 떨어뜨린다.
        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => 'not-encrypted', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_chat_id'], ['value' => '55512345', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '1', 'type' => 'boolean']);

        $this->assertFalse(TelegramConfig::active()->isConfigured());
        $this->assertFalse(TelegramNotifier::active()->send('제목'));

        Http::assertNothingSent();
    }

    // ── ② 스위치 두 개 ──────────────────────────────────────────────────

    public function test_master_off_stops_everything(): void
    {
        Http::fake();
        $this->configure(enabled: false, daily: true);

        $cfg = TelegramConfig::active();
        $this->assertTrue($cfg->isConfigured());
        $this->assertFalse($cfg->canSend());
        $this->assertFalse($cfg->canSendDailySummary());   // 요약 토글이 켜져 있어도 마스터가 이긴다

        $this->assertFalse(TelegramNotifier::active()->send('제목'));
        Http::assertNothingSent();
    }

    public function test_daily_summary_off_keeps_urgent_alive(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure(enabled: true, daily: false);

        $cfg = TelegramConfig::active();
        $this->assertFalse($cfg->canSendDailySummary());   // 아침 요약은 안 간다
        $this->assertTrue($cfg->canSend());                // 긴급은 그대로 간다

        $this->assertTrue(TelegramNotifier::active()->send('긴급'));
        Http::assertSentCount(1);
    }

    public function test_test_send_bypasses_the_master_switch(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure(enabled: false);

        // 설정을 막 넣은 사람이 「켜기 전에」 도착을 확인할 수 있어야 한다.
        $this->assertTrue(TelegramNotifier::active()->send('테스트', '', force: true));
        Http::assertSentCount(1);
    }

    // ── ③ fail-open ────────────────────────────────────────────────────

    public function test_a_failed_send_never_throws(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('nope', 500)]);
        $this->configure();

        // 예외가 새면 이 알림을 부른 배치·요청이 같이 죽는다.
        $this->assertFalse(TelegramNotifier::active()->send('제목', '본문'));
    }

    public function test_a_connection_error_never_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->configure();

        $this->assertFalse(TelegramNotifier::active()->send('제목'));
    }

    // ── ④ 본문 ─────────────────────────────────────────────────────────

    public function test_body_always_carries_the_company_label(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure();

        TelegramNotifier::active()->send('마감환율 멈춤', '09-09 이후 2일');

        $label = TelegramConfig::active()->companyLabel();
        Http::assertSent(function ($request) use ($label) {
            $text = $request['text'] ?? '';

            // 3사가 같은 대화방을 쓴다 — 라벨이 없으면 어느 회사인지 모른다.
            return str_contains($text, '['.$label.']')
                && str_contains($text, '마감환율 멈춤')
                && str_contains($text, '09-09 이후 2일')
                && ($request['chat_id'] ?? '') === '55512345';
        });
    }

    public function test_an_overlong_body_is_truncated_not_dropped(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure();

        // 텔레그램 상한(4096)을 넘기면 API 가 통째로 거부한다 — 잘려도 도착하는 편이 낫다.
        $this->assertTrue(TelegramNotifier::active()->send('제목', str_repeat('가', 9000)));

        Http::assertSent(fn ($request) => mb_strlen($request['text']) <= 4096);
    }

    // ── 설정 화면 ───────────────────────────────────────────────────────

    public function test_screen_saves_and_encrypts_the_token(): void
    {
        $this->actingAs($this->super());

        Volt::test('admin.settings')
            ->set('telegramToken', 'secret-token')
            ->set('telegramChatId', '999')
            ->set('telegramEnabled', true)
            ->call('saveTelegram')
            ->assertHasNoErrors();

        $stored = Setting::get('telegram_bot_token');
        $this->assertNotSame('secret-token', $stored, '토큰이 평문으로 저장되면 안 된다');
        $this->assertSame('secret-token', Crypt::decryptString($stored));
        $this->assertTrue(TelegramConfig::active()->canSend());
    }

    public function test_blank_token_keeps_the_existing_one(): void
    {
        $this->actingAs($this->super());
        $this->configure();

        Volt::test('admin.settings')
            ->set('telegramToken', '')          // 입력칸을 비워 둔 채 다른 항목만 저장
            ->set('telegramChatId', '777')
            ->call('saveTelegram')
            ->assertHasNoErrors();

        $this->assertSame('123:ABC', TelegramConfig::active()->token);
        $this->assertSame('777', TelegramConfig::active()->chatId);
    }

    public function test_cannot_turn_on_without_token_or_chat_id(): void
    {
        $this->actingAs($this->super());

        // 「켰는데 아무것도 안 온다」를 만들지 않는다.
        Volt::test('admin.settings')
            ->set('telegramEnabled', true)
            ->set('telegramChatId', '')
            ->call('saveTelegram')
            ->assertHasErrors('telegramChatId');

        Volt::test('admin.settings')
            ->set('telegramEnabled', true)
            ->set('telegramChatId', '123')
            ->set('telegramToken', '')
            ->call('saveTelegram')
            ->assertHasErrors('telegramToken');

        $this->assertFalse(TelegramConfig::active()->enabled);
    }

    public function test_the_token_value_is_never_sent_to_the_browser(): void
    {
        $this->actingAs($this->super());
        $this->configure();

        $c = Volt::test('admin.settings');

        $this->assertSame('', $c->get('telegramToken'), '토큰 값을 화면으로 내려보내면 안 된다');
        $this->assertTrue($c->get('telegramTokenSet'), '저장 여부는 보여야 한다');
    }

    /**
     * 화면 자체가 super 전용이다 — `mount()` 가 먼저 403 을 낸다.
     * `saveTelegram`·`sendTelegramTest` 안의 `isSuperAdmin()` 가드는 그 위의 이중 방어다
     * (SKILLS §8 #26 — mutating 엔드포인트는 매번 재인가). 여기선 화면 차단을 단언한다.
     */
    public function test_non_super_cannot_open_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]));

        Volt::test('admin.settings')->assertStatus(403);
    }

    /** 저장·테스트 메서드에 super 가드가 남아 있는지 정적으로 확인한다(이중 방어가 지워지면 실패). */
    public function test_mutating_methods_keep_their_own_super_guard(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/admin/settings.blade.php'));

        foreach (['saveTelegram', 'sendTelegramTest'] as $method) {
            $pos = strpos($src, "public function {$method}(");
            $this->assertNotFalse($pos, "{$method} 을 찾지 못했다");
            $head = substr($src, $pos, 220);
            $this->assertStringContainsString('isSuperAdmin()', $head, "{$method} 의 super 가드가 사라졌다");
            $this->assertStringContainsString('abort(403)', $head, "{$method} 의 abort(403) 이 사라졌다");
        }
    }
}
