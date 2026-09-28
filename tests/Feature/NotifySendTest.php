<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * notify:send — 회사 GPU PC 야간 스크립트가 서버를 통해 텔레그램을 보내는 통로 (jin 2026-09-28).
 * 토큰이 서버 밖으로 안 나가는 것이 이 명령의 존재 이유다.
 */
class NotifySendTest extends TestCase
{
    use RefreshDatabase;

    private function telegramOn(): void
    {
        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => Crypt::encryptString('123:ABC'), 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_chat_id'], ['value' => '55512345', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    public function test_sends_title_and_body(): void
    {
        $this->telegramOn();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->artisan('notify:send', ['--title' => '야간 조사', '--body' => "원인: X\n제안: Y"])->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains((string) $req['text'], '야간 조사') && str_contains((string) $req['text'], '제안: Y'));
    }

    public function test_empty_title_is_an_error(): void
    {
        $this->telegramOn();
        Http::fake();
        $this->artisan('notify:send', ['--body' => 'x'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_unconfigured_is_a_quiet_success(): void
    {
        Http::fake();
        $this->artisan('notify:send', ['--title' => 't', '--body' => 'b'])->assertSuccessful();
        Http::assertNothingSent();
    }
}
