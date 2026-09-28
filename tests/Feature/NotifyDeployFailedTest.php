<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 배포 실패 텔레그램 (jin 2026-09-28, §12-1 A). deploy.yml 실패 스텝이 서버에서 부른다.
 * 토큰은 서버 기능설정(DB)에 있어 GitHub 시크릿이 필요 없다. 발송 실패·미설정은 흡수(exit 0).
 */
class NotifyDeployFailedTest extends TestCase
{
    use RefreshDatabase;

    private function telegramOn(): void
    {
        Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => Crypt::encryptString('123:ABC'), 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_chat_id'], ['value' => '55512345', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '1', 'type' => 'boolean']);
    }

    public function test_sends_job_and_run_link_in_korean(): void
    {
        $this->telegramOn();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->artisan('notify:deploy-failed', ['--job' => 'deploy-karaba', '--run' => '123456', '--sha' => 'abcdef0123'])
            ->assertSuccessful();

        Http::assertSent(function ($req) {
            $text = (string) ($req['text'] ?? '');

            return str_contains($text, '배포 실패')
                && str_contains($text, 'deploy-karaba')
                && str_contains($text, 'actions/runs/123456')
                && str_contains($text, 'abcdef0');
        });
    }

    public function test_unconfigured_telegram_is_a_quiet_success(): void
    {
        Http::fake();
        $this->artisan('notify:deploy-failed', ['--job' => 'deploy'])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_master_switch_off_means_no_send(): void
    {
        $this->telegramOn();
        Setting::updateOrCreate(['key' => 'telegram_enabled'], ['value' => '0', 'type' => 'boolean']);
        Http::fake();

        $this->artisan('notify:deploy-failed', ['--job' => 'deploy'])->assertSuccessful();
        Http::assertNothingSent();
    }
}
