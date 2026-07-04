<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 기능설정 「메일 발송 (Gmail / AWS SES)」 섹션 스모크.
 * - 컴포넌트 렌더(super)
 * - SES 저장(앱 비밀번호 불필요)
 * - Gmail 저장은 앱 비밀번호 필수 + 암호화 저장(공백 제거)
 * - 발신 주소 형식 검증
 */
class MailSettingsSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    public function test_renders_and_saves_ses_without_password(): void
    {
        $this->actingAs($this->super());

        Volt::test('admin.settings')
            ->assertOk()
            ->set('mailChannel', 'ses')
            ->set('mailFromAddress', 'sales@heyman.com')
            ->set('mailFromName', 'HEYMAN')
            ->call('saveMail')
            ->assertHasNoErrors();

        $set = Setting::companyTemplateSet();
        $this->assertSame('ses', Setting::get("mail_channel_{$set}"));
        $this->assertSame('sales@heyman.com', Setting::get("mail_from_address_{$set}"));
    }

    public function test_gmail_requires_password_then_stores_encrypted(): void
    {
        $this->actingAs($this->super());

        Volt::test('admin.settings')
            ->set('mailChannel', 'gmail')
            ->set('mailFromAddress', 'sales@heyman.com')
            ->set('mailGmailPassword', '')
            ->call('saveMail')
            ->assertHasErrors('mailGmailPassword');

        Volt::test('admin.settings')
            ->set('mailChannel', 'gmail')
            ->set('mailFromAddress', 'sales@heyman.com')
            ->set('mailGmailPassword', 'abcd efgh ijkl mnop')
            ->call('saveMail')
            ->assertHasNoErrors();

        $set = Setting::companyTemplateSet();
        $stored = Setting::get("mail_gmail_app_password_{$set}");
        $this->assertNotNull($stored);
        $this->assertSame('abcdefghijklmnop', Crypt::decryptString($stored));
    }

    public function test_rejects_invalid_from_address(): void
    {
        $this->actingAs($this->super());

        Volt::test('admin.settings')
            ->set('mailChannel', 'ses')
            ->set('mailFromAddress', 'not-an-email')
            ->call('saveMail')
            ->assertHasErrors('mailFromAddress');
    }
}
