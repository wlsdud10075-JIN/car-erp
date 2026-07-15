<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * 회사별 카카오 알림톡(BizM) 발송 설정 단일 출처 — 기능설정(admin/settings)에 회사(set)별로 저장된
 * 계정(userid·발신프로필·userkey)·템플릿ID·on/off 를 읽는다. CompanyMailConfig 와 동일 패턴.
 *
 * 배포 모델상 서버 1개 = 회사 1개라 companyTemplateSet() 기준 현재 회사 설정을 쓴다.
 * - userid    : BizM 계정 아이디 (발송 헤더). 필수.
 * - profile   : 발신프로필키 (car-erp 전용 프로필). 필수.
 * - userkey   : 잔액조회 등 부가 API용(발송엔 불필요) — 암호화 저장.
 * - tmplIds   : 11종 템플릿의 BizM 발급 코드. 코드별로 있어야 해당 알림 발송 가능.
 * - enabled   : 회사 마스터 on/off (배포 ≠ 작동 — 기본 off).
 * - toggles   : 알림 11종 개별 on/off (기본 on).
 */
class AlimtalkConfig
{
    public function __construct(
        public string $set,
        public string $userid,
        public string $profile,
        public ?string $userkey,
        public bool $enabled,
        public array $tmplIds,
        public array $toggles,
        // 이 회사의 발신프로필 템플릿이 아이템리스트형인가(마이그레이션 게이트).
        // false(기본)=기본형 평문 발송 / true=아이템리스트형 9종에 header+items payload 발송.
        // 프로필 교체와 함께 켜야 안전(옛 기본형 프로필에 item-list payload 쏘면 형식불일치 반려).
        public bool $itemlist = false,
    ) {}

    public static function active(): self
    {
        $set = Setting::companyTemplateSet();
        $userid = (string) (Setting::get("alimtalk_userid_{$set}", '') ?: '');
        $profile = (string) (Setting::get("alimtalk_profile_{$set}", '') ?: '');
        $enabled = (bool) Setting::get("alimtalk_enabled_{$set}", false);
        $itemlist = (bool) Setting::get("alimtalk_itemlist_{$set}", false);

        $userkey = null;
        if ($enc = Setting::get("alimtalk_userkey_{$set}")) {
            try {
                $userkey = Crypt::decryptString($enc);
            } catch (\Throwable $e) {
                $userkey = null;
            }
        }

        $tmplIds = [];
        $toggles = [];
        foreach (array_keys(AlimtalkTemplates::TEMPLATES) as $code) {
            $tmplIds[$code] = (string) (Setting::get("alimtalk_tmpl_{$code}_{$set}", '') ?: '');
            $toggles[$code] = (bool) Setting::get("alimtalk_toggle_{$code}_{$set}", true);   // 기본 켜짐
        }

        return new self($set, $userid, $profile, $userkey, $enabled, $tmplIds, $toggles, $itemlist);
    }

    /** 발송 계정 설정 여부 — userid + profile 필수(userkey 는 잔액조회 전용이라 발송엔 불필요). */
    public function isConfigured(): bool
    {
        return $this->userid !== '' && $this->profile !== '';
    }

    public function tmplId(string $code): string
    {
        return $this->tmplIds[$code] ?? '';
    }

    /** 이 알림을 실제 보낼 수 있는가 — 마스터 on + 계정 설정 + 개별 on + 해당 tmplId 존재. */
    public function canSend(string $code): bool
    {
        return $this->enabled
            && $this->isConfigured()
            && ($this->toggles[$code] ?? false)
            && $this->tmplId($code) !== '';
    }

    public function companyLabel(): string
    {
        return ['system' => 'SSANCAR', 'heyman' => 'HEYMAN', 'karaba' => 'KARABA'][$this->set] ?? strtoupper($this->set);
    }
}
