<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * 회사별 메일 발송 설정 단일 출처 — 기능설정(admin/settings)에 회사(set)별로 저장된
 * 발송 방식(Gmail/SES)·발신주소·앱 비밀번호를 읽어 실제 발송까지 담당.
 *
 * 배포 모델상 서버 1개 = 회사 1개라 companyTemplateSet() 기준 현재 회사 설정을 쓴다.
 * - gmail: 런타임 SMTP mailer(smtp.gmail.com:465 SSL + 앱 비밀번호)로 발송
 * - ses:   config/mail.php 의 ses mailer(앱 AWS 자격증명) 사용, 발신주소만 회사값
 */
class CompanyMailConfig
{
    private const COMPANY_LABELS = ['system' => 'SSANCAR', 'heyman' => 'HEYMAN', 'karaba' => 'KARABA'];

    public function __construct(
        public string $set,
        public string $channel,
        public string $fromAddress,
        public string $fromName,
        public ?string $appPassword,
    ) {}

    public static function active(): self
    {
        $set = Setting::companyTemplateSet();
        $channel = Setting::get("mail_channel_{$set}", 'gmail') ?: 'gmail';
        $from = (string) (Setting::get("mail_from_address_{$set}", '') ?: '');
        $name = (string) (Setting::get("mail_from_name_{$set}", '') ?: '');

        $pw = null;
        if ($enc = Setting::get("mail_gmail_app_password_{$set}")) {
            try {
                $pw = Crypt::decryptString($enc);
            } catch (\Throwable $e) {
                $pw = null;
            }
        }

        return new self($set, in_array($channel, ['gmail', 'ses'], true) ? $channel : 'gmail', $from, $name, $pw);
    }

    /** 발송 가능 여부 — 발신주소 필수, gmail 은 앱 비밀번호도 필수. */
    public function isConfigured(): bool
    {
        if ($this->fromAddress === '') {
            return false;
        }

        return $this->channel === 'ses' ? true : ! empty($this->appPassword);
    }

    public function companyLabel(): string
    {
        return self::COMPANY_LABELS[$this->set] ?? strtoupper($this->set);
    }

    /** 실제 발송 — 회사 방식대로 mailer 구성. 실패 시 예외를 던져 호출측이 로그·토스트 처리. */
    public function send(Mailable $mailable): void
    {
        $mailable->from($this->fromAddress, $this->fromName !== '' ? $this->fromName : $this->companyLabel());

        if ($this->channel === 'ses') {
            Mail::mailer('ses')->send($mailable);

            return;
        }

        // gmail — 런타임 SMTP mailer(앱 비밀번호). Mail 매니저 경유라 테스트에서 Mail::fake 로 가로챔.
        config()->set('mail.mailers.company_gmail', [
            'transport' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => $this->fromAddress,
            'password' => (string) $this->appPassword,
            'timeout' => 15,
        ]);
        Mail::mailer('company_gmail')->send($mailable);
    }
}
