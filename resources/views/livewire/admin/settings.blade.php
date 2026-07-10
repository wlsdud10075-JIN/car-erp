<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $sidebarBrand = '';

    // 서류 양식 세트(=회사) 토글. system=SSANCAR(기본) / heyman / karaba.
    public string $companyTemplateSet = 'system';

    public bool $localeEnEnabled = false;

    public bool $alarmEnabled = false;

    // 🔒 락 관제 — 돈 흐름 락 토글. lock 키 => bool. 기본값·단일출처 = Setting::LOCK_DEFAULTS.
    public array $lockToggles = [];

    // item 9 — 알람 항목별 리드데이("며칠 전"). 항목 추가는 alarmLeadMeta() 에만.
    public array $alarmLeadDays = [];

    // 메일 발송 설정 (Gmail / AWS SES) — 현재 회사(companyTemplateSet) 기준. 앱 비밀번호는 암호화 저장.
    public string $mailChannel = 'gmail';

    public string $mailFromAddress = '';

    public string $mailFromName = '';

    public string $mailGmailPassword = '';   // 신규 입력용 — 저장된 비밀번호는 로드하지 않음(비밀 보호)

    public bool $mailGmailPasswordSet = false;

    // 카카오 알림톡(BizM) 설정 — 현재 회사(set) 기준. userkey 는 암호화 저장.
    public bool $alimtalkEnabled = false;              // 회사 마스터 on/off

    public string $alimtalkUserid = '';

    public string $alimtalkProfile = '';

    public string $alimtalkUserkey = '';               // 신규 입력용 — 저장값은 로드 안 함

    public bool $alimtalkUserkeySet = false;

    public array $alimtalkTmplIds = [];                // code => BizM 템플릿ID

    public array $alimtalkToggles = [];                // code => bool (개별 on/off)

    public string $alimtalkTestPhone = '';             // 테스트 발송 번호

    // 정산 파라미터 (2026-06-22) — Settlement 차등 tier/비율. key => 값. super 전용 내부설정(i18n 생략).
    public array $settlementParams = [];

    // 도장/서명/로고 역할 3종 — signature(서명), seal(직인), logo(상호 로고). 회사당 1장씩, 슬롯에 재사용.
    public array $stampRoles = ['signature', 'seal', 'logo'];

    public $signatureUpload = null;

    public $sealUpload = null;

    public $logoUpload = null;

    public array $stampPaths = [];   // role => 저장 경로|null

    public array $stampUrls = [];    // role => 미리보기 URL|null

    // 슬롯별 위치/크기 — "type::key" => ['doc','slot','role','dx','dy','w','h']
    public array $stampPositions = [];

    public function mount(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR';
        $this->companyTemplateSet = Setting::companyTemplateSet();
        $this->localeEnEnabled = (bool) Setting::get('locale_en_enabled', false);
        $this->alarmEnabled = (bool) Setting::get('alarm_enabled', false);
        foreach (Setting::LOCK_DEFAULTS as $lock => $default) {
            $this->lockToggles[$lock] = Setting::lockEnabled($lock);
        }
        foreach ($this->alarmLeadMeta() as $k => $m) {
            $this->alarmLeadDays[$k] = (int) Setting::get($m['key'], $m['default']);
        }
        $this->loadMailSettings();
        $this->loadAlimtalkSettings();
        foreach (\App\Models\Settlement::PARAM_DEFAULTS as $key => $default) {
            $this->settlementParams[$key] = (int) Setting::get($key, $default);
        }
        $this->refreshStamps();
        $this->loadStampPositions();
    }

    /** 정산 파라미터 화면 메타 (라벨/힌트) — blade foreach. */
    public function settlementParamMeta(): array
    {
        return [
            'settlement_freelance_ratio' => ['label' => '프리랜서 정산 비율 (%)', 'hint' => '총마진 × 이 비율. 기본 50'],
            'settlement_freelance_document_fee' => ['label' => '프리랜서 서류비 (원)', 'hint' => '실지급액에서 차감. 기본 50,000'],
            'settlement_employee_high_threshold' => ['label' => '사내직원 고율 트리거 — 매입금액 ≥ (원)', 'hint' => '이 매입금액 이상이면 비율제 적용. 기본 100,000,000(1억)'],
            'settlement_employee_high_rate' => ['label' => '사내직원 고율 (%)', 'hint' => '위 트리거 시 총마진 × 이 비율. 기본 25'],
            'settlement_employee_margin_threshold' => ['label' => '사내직원 건당 분기 — 총마진 (원)', 'hint' => '총마진이 이 값 미만/이상으로 건당액 분기. 기본 1,000,000(100만)'],
            'settlement_employee_amount_low' => ['label' => '사내직원 건당 — 총마진 기준 미만 (원)', 'hint' => '기본 100,000'],
            'settlement_employee_amount_high' => ['label' => '사내직원 건당 — 총마진 기준 이상 (원)', 'hint' => '기본 200,000'],
        ];
    }

    public function saveSettlementParams(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $meta = $this->settlementParamMeta();
        foreach (\App\Models\Settlement::PARAM_DEFAULTS as $key => $default) {
            $val = max(0, (int) ($this->settlementParams[$key] ?? $default));
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $val, 'type' => 'integer', 'description' => '정산 파라미터 — '.($meta[$key]['label'] ?? $key)],
            );
            $this->settlementParams[$key] = $val;
        }
        \App\Models\Settlement::flushParamMemo();
        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }

    // 현재 회사 표시명 (메일 설정 라벨용).
    public function currentCompanyLabel(): string
    {
        $set = Setting::companyTemplateSet();

        return $this->companyTemplateSetOptions()[$set] ?? $set;
    }

    // 메일 발송 설정 로드 — 현재 회사(set) 기준. 저장된 앱 비밀번호는 값 대신 존재여부만.
    private function loadMailSettings(): void
    {
        $set = Setting::companyTemplateSet();
        $this->mailChannel = Setting::get("mail_channel_{$set}", 'gmail') ?: 'gmail';
        $this->mailFromAddress = Setting::get("mail_from_address_{$set}", '') ?: '';
        $this->mailFromName = Setting::get("mail_from_name_{$set}", '') ?: '';
        $this->mailGmailPasswordSet = (bool) Setting::get("mail_gmail_app_password_{$set}");
        $this->mailGmailPassword = '';
    }

    // 메일 발송 설정 저장 — super 전용. 앱 비밀번호는 신규 입력이 있을 때만 암호화 갱신(공백=기존 유지).
    public function saveMail(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }

        $channel = in_array($this->mailChannel, ['gmail', 'ses'], true) ? $this->mailChannel : 'gmail';
        $from = trim($this->mailFromAddress);
        $name = trim($this->mailFromName);
        $newPassword = str_replace(' ', '', trim($this->mailGmailPassword));

        if ($from === '') {
            $this->addError('mailFromAddress', __('feature_settings.mail_from_required'));

            return;
        }
        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->addError('mailFromAddress', __('feature_settings.mail_from_invalid'));

            return;
        }
        // Gmail 방식 + 저장된 비번 없음 + 신규 입력도 없음 → 요구
        if ($channel === 'gmail' && ! $this->mailGmailPasswordSet && $newPassword === '') {
            $this->addError('mailGmailPassword', __('feature_settings.mail_password_required'));

            return;
        }

        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "mail_channel_{$set}"], ['value' => $channel, 'type' => 'string', 'description' => "메일 발송 방식 ({$set}) — gmail/ses"]);
        Setting::updateOrCreate(['key' => "mail_from_address_{$set}"], ['value' => $from, 'type' => 'string', 'description' => "메일 발신 주소 ({$set})"]);
        Setting::updateOrCreate(['key' => "mail_from_name_{$set}"], ['value' => $name, 'type' => 'string', 'description' => "메일 발신 표시명 ({$set})"]);

        if ($newPassword !== '') {
            Setting::updateOrCreate(
                ['key' => "mail_gmail_app_password_{$set}"],
                ['value' => \Illuminate\Support\Facades\Crypt::encryptString($newPassword), 'type' => 'string', 'description' => "Gmail 앱 비밀번호(암호화) ({$set})"],
            );
        }

        $this->mailChannel = $channel;
        $this->mailFromAddress = $from;
        $this->mailFromName = $name;
        $this->loadMailSettings();
        $this->dispatch('notify', message: __('feature_settings.mail_saved'), type: 'success');
    }

    /** 알림톡 11종 메타 (blade foreach — code/이름/수신자). */
    public function alimtalkTemplateMeta(): array
    {
        $meta = [];
        foreach (\App\Support\AlimtalkTemplates::TEMPLATES as $code => $t) {
            $meta[$code] = ['name' => $t['name'], 'recipient' => $t['recipient']];
        }

        return $meta;
    }

    // 알림톡 설정 로드 — 현재 회사(set) 기준. userkey 는 값 대신 존재여부만.
    private function loadAlimtalkSettings(): void
    {
        $set = Setting::companyTemplateSet();
        $this->alimtalkEnabled = (bool) Setting::get("alimtalk_enabled_{$set}", false);
        $this->alimtalkUserid = Setting::get("alimtalk_userid_{$set}", '') ?: '';
        $this->alimtalkProfile = Setting::get("alimtalk_profile_{$set}", '') ?: '';
        $this->alimtalkUserkeySet = (bool) Setting::get("alimtalk_userkey_{$set}");
        $this->alimtalkUserkey = '';
        $this->alimtalkTmplIds = [];
        $this->alimtalkToggles = [];
        foreach (array_keys(\App\Support\AlimtalkTemplates::TEMPLATES) as $code) {
            $this->alimtalkTmplIds[$code] = Setting::get("alimtalk_tmpl_{$code}_{$set}", '') ?: '';
            $this->alimtalkToggles[$code] = (bool) Setting::get("alimtalk_toggle_{$code}_{$set}", true);
        }
    }

    // 알림톡 설정 저장 — super 전용. userkey 는 신규 입력이 있을 때만 암호화 갱신(공백=기존 유지).
    public function saveAlimtalk(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }

        $set = Setting::companyTemplateSet();
        $userid = trim($this->alimtalkUserid);
        $profile = trim($this->alimtalkProfile);
        $newUserkey = trim($this->alimtalkUserkey);

        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => $this->alimtalkEnabled ? '1' : '0', 'type' => 'boolean', 'description' => "알림톡 사용 ({$set})"]);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => $userid, 'type' => 'string', 'description' => "알림톡 BizM userid ({$set})"]);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => $profile, 'type' => 'string', 'description' => "알림톡 발신프로필키 ({$set})"]);

        if ($newUserkey !== '') {
            Setting::updateOrCreate(
                ['key' => "alimtalk_userkey_{$set}"],
                ['value' => \Illuminate\Support\Facades\Crypt::encryptString($newUserkey), 'type' => 'string', 'description' => "알림톡 userkey(암호화) ({$set})"],
            );
        }

        foreach (array_keys(\App\Support\AlimtalkTemplates::TEMPLATES) as $code) {
            Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$code}_{$set}"], ['value' => trim($this->alimtalkTmplIds[$code] ?? ''), 'type' => 'string', 'description' => "알림톡 템플릿ID {$code} ({$set})"]);
            Setting::updateOrCreate(['key' => "alimtalk_toggle_{$code}_{$set}"], ['value' => ! empty($this->alimtalkToggles[$code]) ? '1' : '0', 'type' => 'boolean', 'description' => "알림톡 {$code} on/off ({$set})"]);
        }

        $this->loadAlimtalkSettings();
        $this->dispatch('notify', message: __('feature_settings.alimtalk_saved'), type: 'success');
    }

    // 테스트 발송 — 일일요약 템플릿을 입력 번호로. 결과 상태를 토스트로 안내.
    public function sendTestAlimtalk(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }

        $log = \App\Services\BizmAlimtalkService::active()->sendTest($this->alimtalkTestPhone);

        if ($log->status === 'sent') {
            $this->dispatch('notify', message: __('feature_settings.alimtalk_test_sent'), type: 'success');
        } else {
            $this->dispatch('notify', message: __('feature_settings.alimtalk_test_failed', ['reason' => $log->error ?: $log->status]), type: 'error');
        }
    }

    // 현재 template_set(=회사) — 기능설정 토글(company_template_set) 따라감. 도장도 선택 회사 기준.
    private function stampSet(): string
    {
        return Setting::companyTemplateSet();
    }

    // UI 슬롯 메타 — blade 에서 foreach.
    public function stampSlots(): array
    {
        return [
            ['role' => 'signature', 'prop' => 'signatureUpload', 'label' => __('feature_settings.stamp_signature_label'), 'sub' => __('feature_settings.stamp_signature_sub')],
            ['role' => 'seal', 'prop' => 'sealUpload', 'label' => __('feature_settings.stamp_seal_label'), 'sub' => __('feature_settings.stamp_seal_sub')],
            ['role' => 'logo', 'prop' => 'logoUpload', 'label' => __('feature_settings.stamp_logo_label'), 'sub' => __('feature_settings.stamp_logo_sub')],
        ];
    }

    // 슬롯별 위치/크기 로드 — 현재 회사(set) 기준. Setting override 없으면 슬롯 기본값.
    private function loadStampPositions(): void
    {
        $set = $this->stampSet();
        $this->stampPositions = [];
        foreach (\App\Services\Documents\StampSlots::all($set) as $type => $slots) {
            foreach ($slots as $slot) {
                $pos = \App\Services\Documents\StampSlots::position($set, $type, $slot);
                $this->stampPositions[$type.'::'.$slot['key']] = [
                    'doc' => \App\Services\Documents\StampSlots::DOC_LABELS[$type] ?? $type,
                    'slot' => $slot['sheet'].' · '.(\App\Services\Documents\StampSlots::ROLE_LABELS[$slot['role']] ?? $slot['role']),
                    'role' => $slot['role'],
                    'dx' => $pos['dx'], 'dy' => $pos['dy'], 'w' => $pos['w'], 'h' => $pos['h'],
                ];
            }
        }
    }

    /** blade 표시용 — 서류별 그룹핑된 슬롯 위치. */
    public function stampPositionGroups(): array
    {
        $groups = [];
        foreach ($this->stampPositions as $key => $p) {
            $groups[$p['doc']][$key] = $p;
        }

        return $groups;
    }

    // 슬롯 위치/크기 일괄 저장 — super 전용. stamp_pos_{set}_{type}_{key} = {dx,dy,w,h} JSON.
    public function saveStampPositions(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $set = $this->stampSet();
        foreach (\App\Services\Documents\StampSlots::all($set) as $type => $slots) {
            foreach ($slots as $slot) {
                $k = $type.'::'.$slot['key'];
                $p = $this->stampPositions[$k] ?? null;
                if (! $p) {
                    continue;
                }
                $json = json_encode([
                    'dx' => max(0, (int) $p['dx']),
                    'dy' => max(0, (int) $p['dy']),
                    'w' => max(1, (int) $p['w']),
                    'h' => max(1, (int) $p['h']),
                ]);
                Setting::updateOrCreate(
                    ['key' => "stamp_pos_{$set}_{$type}_{$slot['key']}"],
                    ['value' => $json, 'type' => 'string', 'description' => "도장 위치 {$type}/{$slot['key']} ({$set})"],
                );
            }
        }
        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }

    private function refreshStamps(): void
    {
        $set = $this->stampSet();
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        foreach ($this->stampRoles as $role) {
            $path = Setting::get('stamp_'.$set.'_'.$role);
            $this->stampPaths[$role] = $path;
            $this->stampUrls[$role] = null;
            if ($path) {
                try {
                    if ($disk->exists($path)) {
                        // 운영 private S3 는 ->url() 이 403 → 미리보기 깨짐. VehicleDocUrl 이 S3 면
                        // 임시 서명URL, 로컬이면 일반 URL 로 분기(사진/서류와 동일 단일출처).
                        $this->stampUrls[$role] = \App\Support\VehicleDocUrl::for($path);
                    }
                } catch (\Throwable $e) {
                    $this->stampUrls[$role] = null;   // 미리보기 URL 미지원 디스크 — 상태만 표시
                }
            }
        }
    }

    private function storeStamp(string $role, $file): void
    {
        $set = $this->stampSet();
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        if ($old = Setting::get('stamp_'.$set.'_'.$role)) {
            $disk->delete($old);   // 기존 업로드본 제거(확장자 바뀜 대비)
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs('stamps/'.$set, $role.'.'.$ext, config('filesystems.vehicle_docs_disk'));

        Setting::updateOrCreate(
            ['key' => 'stamp_'.$set.'_'.$role],
            ['value' => $path, 'type' => 'string', 'description' => '서류 도장/서명 ('.$role.', '.$set.')'],
        );
        $this->refreshStamps();
        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }

    public function updatedSignatureUpload(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->validate(['signatureUpload' => 'image|mimes:png,jpg,jpeg|max:2048'], ['signatureUpload' => __('feature_settings.stamp_invalid')]);
        $this->storeStamp('signature', $this->signatureUpload);
        $this->signatureUpload = null;
    }

    public function updatedSealUpload(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->validate(['sealUpload' => 'image|mimes:png,jpg,jpeg|max:2048'], ['sealUpload' => __('feature_settings.stamp_invalid')]);
        $this->storeStamp('seal', $this->sealUpload);
        $this->sealUpload = null;
    }

    public function updatedLogoUpload(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->validate(['logoUpload' => 'image|mimes:png,jpg,jpeg|max:2048'], ['logoUpload' => __('feature_settings.stamp_invalid')]);
        $this->storeStamp('logo', $this->logoUpload);
        $this->logoUpload = null;
    }

    public function removeStamp(string $role): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        if (! in_array($role, $this->stampRoles, true)) {
            return;
        }
        $set = $this->stampSet();
        if ($old = Setting::get('stamp_'.$set.'_'.$role)) {
            Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($old);
        }
        Setting::where('key', 'stamp_'.$set.'_'.$role)->delete();
        $this->refreshStamps();
        $this->dispatch('notify', message: __('feature_settings.stamp_removed'), type: 'success');
    }

    public function save(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }

        $brand = trim($this->sidebarBrand);
        if (mb_strlen($brand) > 12) {
            $brand = mb_substr($brand, 0, 12);
        }
        if ($brand === '') {
            $brand = 'SSANCAR';
        }

        Setting::updateOrCreate(
            ['key' => 'sidebar_brand'],
            [
                'value' => $brand,
                'type' => 'string',
                'description' => '사이드바 헤더 브랜드 텍스트 (최대 12자)',
            ],
        );

        $this->sidebarBrand = $brand;
        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }

    // i18n Phase 0 — 영어 활성/비활성 즉시 저장. super가 끄면 다음 요청부터 전사 한국어 복귀.
    public function updatedLocaleEnEnabled(bool $value): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }

        Setting::updateOrCreate(
            ['key' => 'locale_en_enabled'],
            [
                'value' => $value ? '1' : '0',
                'type' => 'boolean',
                'description' => '영어 UI 활성화 (다국어)',
            ],
        );

        // 사이드바·상단바(언어 스위처)는 이 컴포넌트 밖 blade라 갱신 못 함 → 풀 리로드로 즉시 반영.
        session()->flash('locale_toggle', $value
            ? __('feature_settings.locale_enabled_flash')
            : __('feature_settings.locale_disabled_flash'));

        $this->redirect(route('admin.settings'), navigate: false);
    }

    // ETA 통관서류 알람 on/off (배포 ≠ 작동). off면 alarms:scan 이 생성 건너뜀.
    public function updatedAlarmEnabled(bool $value): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }

        Setting::updateOrCreate(
            ['key' => 'alarm_enabled'],
            ['value' => $value ? '1' : '0', 'type' => 'boolean', 'description' => 'ETA 통관서류 알람 활성화'],
        );
        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }

    // 🔒 락 관제 — 토글 즉시 저장(회사별 {set}) + 변경 감사로그. 게이트 코드는 Setting::lockEnabled 로만 읽음.
    public function updatedLockToggles(mixed $value, string $key): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        if (! array_key_exists($key, Setting::LOCK_DEFAULTS)) {
            return;
        }

        $set = Setting::companyTemplateSet();
        $enabled = (bool) $value;
        $setting = Setting::updateOrCreate(
            ['key' => 'lock_'.$key.'_'.$set],
            ['value' => $enabled ? '1' : '0', 'type' => 'boolean', 'description' => '돈 흐름 락 '.$key.' ('.$set.')'],
        );

        // 돈 게이트 해제/설정은 민감 — 누가 언제 어느 락을 바꿨나 감사로그(Setting 행에 부착).
        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Setting::class,
            'auditable_id' => $setting->id,
            'action' => 'lock_toggle_changed',
            'column_name' => 'lock_'.$key,
            'old_value' => $enabled ? '0' : '1',
            'new_value' => $enabled ? '1' : '0',
            'ip_address' => request()?->ip(),
        ]);

        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }

    // 🔒 락 관제 화면 메타(라벨/설명) — blade foreach. 순서 = 매입등록·매입지급·선적진입·B/L.
    public function lockMeta(): array
    {
        return [
            'purchase_registration' => ['label' => __('feature_settings.lock_purchase_registration'), 'sub' => __('feature_settings.lock_purchase_registration_sub')],
            'purchase_payment' => ['label' => __('feature_settings.lock_purchase_payment'), 'sub' => __('feature_settings.lock_purchase_payment_sub')],
            'shipping_entry' => ['label' => __('feature_settings.lock_shipping_entry'), 'sub' => __('feature_settings.lock_shipping_entry_sub')],
            'bl_issue' => ['label' => __('feature_settings.lock_bl_issue'), 'sub' => __('feature_settings.lock_bl_issue_sub')],
        ];
    }

    // item 9 — 알람 항목별 리드데이 메타(라벨·Setting 키·기본값). 새 알람 종류는 여기 한 줄만 추가.
    public function alarmLeadMeta(): array
    {
        return [
            'eta' => ['key' => 'alarm_eta_lead_days', 'default' => 10, 'label' => __('feature_settings.alarm_lead_eta')],
            'document' => ['key' => 'alarm_doc_deadline_lead_days', 'default' => 5, 'label' => __('feature_settings.alarm_lead_document')],
        ];
    }

    public function saveAlarmParams(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        foreach ($this->alarmLeadMeta() as $k => $m) {
            $val = max(0, (int) ($this->alarmLeadDays[$k] ?? $m['default']));
            Setting::updateOrCreate(
                ['key' => $m['key']],
                ['value' => (string) $val, 'type' => 'integer', 'description' => '알람 리드데이 — '.$m['label']],
            );
            $this->alarmLeadDays[$k] = $val;
        }

        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }

    // 서류 양식 세트(=회사) 선택지 — value=폴더명, label=표시명. resources/templates/{value} 존재해야 함.
    public function companyTemplateSetOptions(): array
    {
        return [
            'system' => 'SSANCAR',
            'heyman' => 'HEYMAN',
            'karaba' => 'KARABA',
        ];
    }

    // 회사 양식 세트 토글 즉시 저장 — 이후 모든 서류 생성이 이 세트로. super 전용.
    public function updatedCompanyTemplateSet(string $value): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        if (! array_key_exists($value, $this->companyTemplateSetOptions())
            || ! is_dir(resource_path('templates/'.$value))) {
            $this->companyTemplateSet = Setting::companyTemplateSet();
            $this->dispatch('notify', message: __('feature_settings.company_set_invalid'), type: 'warning');

            return;
        }

        Setting::updateOrCreate(
            ['key' => 'company_template_set'],
            ['value' => $value, 'type' => 'string', 'description' => '서류 양식 세트(회사) — system/heyman/karaba'],
        );

        $this->refreshStamps();   // 도장도 선택 회사 기준으로 갱신
        $this->loadStampPositions();
        $this->dispatch('notify', message: __('feature_settings.saved'), type: 'success');
    }
}; ?>

<div wire:poll.30s class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">{{ __('feature_settings.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('feature_settings.subtitle') }}</p>
    </div>

    @if (session('locale_toggle'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">
            {{ session('locale_toggle') }}
        </div>
    @endif

    {{-- 브랜드 그룹 --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">{{ __('feature_settings.brand_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3">
            <label class="block text-sm font-medium text-gray-700">{{ __('feature_settings.brand_label') }}</label>
            <p class="mt-1 text-xs text-gray-500">{{ __('feature_settings.brand_hint') }}</p>
            <input
                wire:model="sidebarBrand"
                type="text"
                maxlength="12"
                class="input-base mt-2 w-full"
                placeholder="SSANCAR"
            />
            <div class="mt-4 flex justify-end">
                <button wire:click="save" class="btn-primary">{{ __('common.save') }}</button>
            </div>
        </div>
    </div>

    {{-- 서류 양식 세트(=회사) 그룹 — 어느 회사 양식으로 서류 생성할지. super 전용 --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ __('feature_settings.company_set_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3">
            <label class="block text-sm font-medium text-gray-700">{{ __('feature_settings.company_set_label') }}</label>
            <p class="mt-1 text-xs text-gray-500">{{ __('feature_settings.company_set_hint') }}</p>
            <select wire:model.live="companyTemplateSet" class="input-base mt-2 w-full">
                @foreach($this->companyTemplateSetOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- 메일 발송 (Gmail / AWS SES) 그룹 — 현재 회사 발신 설정. super 전용 --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-sky-500"></span>
                <span class="section-title">{{ __('feature_settings.mail_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-3">
            <p class="text-xs text-gray-500">{{ __('feature_settings.mail_hint') }}</p>
            <div class="rounded-md border border-sky-100 bg-sky-50 px-3 py-1.5 text-xs font-medium text-sky-700">
                {{ __('feature_settings.mail_company_current', ['company' => $this->currentCompanyLabel()]) }}
            </div>

            {{-- 발송 방식 --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('feature_settings.mail_channel_label') }}</label>
                <select wire:model.live="mailChannel" class="input-base mt-1 w-full">
                    <option value="gmail">{{ __('feature_settings.mail_channel_gmail') }}</option>
                    <option value="ses">{{ __('feature_settings.mail_channel_ses') }}</option>
                </select>
            </div>

            {{-- 발신 주소 --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('feature_settings.mail_from_address_label') }}</label>
                <input wire:model="mailFromAddress" type="email" class="input-base mt-1 w-full" placeholder="name@company.com" autocomplete="off" />
                <p class="mt-1 text-xs text-gray-500">
                    {{ $mailChannel === 'ses' ? __('feature_settings.mail_from_address_hint_ses') : __('feature_settings.mail_from_address_hint_gmail') }}
                </p>
                @error('mailFromAddress') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            {{-- 발신 표시명 --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('feature_settings.mail_from_name_label') }}</label>
                <input wire:model="mailFromName" type="text" maxlength="60" class="input-base mt-1 w-full" placeholder="{{ $this->currentCompanyLabel() }}" />
                <p class="mt-1 text-xs text-gray-400">{{ __('feature_settings.mail_from_name_hint') }}</p>
            </div>

            {{-- Gmail 앱 비밀번호 (gmail 방식만) / SES 안내 --}}
            @if ($mailChannel === 'gmail')
                <div>
                    <label class="block text-sm font-medium text-gray-700">
                        {{ __('feature_settings.mail_gmail_password_label') }}
                        @if ($mailGmailPasswordSet)
                            <span class="badge badge-green">{{ __('feature_settings.mail_gmail_password_set') }}</span>
                        @endif
                    </label>
                    <input wire:model="mailGmailPassword" type="password" class="input-base mt-1 w-full font-mono" placeholder="xxxx xxxx xxxx xxxx" autocomplete="new-password" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('feature_settings.mail_gmail_password_hint') }}</p>
                    @error('mailGmailPassword') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <div class="mt-2 rounded-md border border-amber-100 bg-amber-50 px-3 py-1.5 text-xs text-amber-700">{{ __('feature_settings.mail_gmail_2fa_note') }}</div>
                </div>
            @else
                <div class="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-xs text-gray-600">{{ __('feature_settings.mail_ses_note') }}</div>
            @endif

            <div class="flex justify-end pt-1">
                <button wire:click="saveMail" class="btn-primary">{{ __('common.save') }}</button>
            </div>
        </div>
    </div>

    {{-- 카카오 알림톡 (BizM) 그룹 — 현재 회사 발신 설정. super 전용 --}}
    <div class="card max-w-xl" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-yellow-400"></span>
                <span class="section-title">{{ __('feature_settings.alimtalk_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-3">
            <p class="text-xs text-gray-500">{{ __('feature_settings.alimtalk_hint') }}</p>
            <div class="rounded-md border border-amber-100 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700">
                {{ __('feature_settings.mail_company_current', ['company' => $this->currentCompanyLabel()]) }}
            </div>

            {{-- 마스터 on/off --}}
            <label class="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 px-3 py-2">
                <span class="text-sm font-medium text-gray-700">{{ __('feature_settings.alimtalk_enabled_label') }}
                    <span class="text-xs font-normal text-gray-400">{{ __('feature_settings.alimtalk_enabled_sub') }}</span>
                </span>
                <input type="checkbox" wire:model="alimtalkEnabled" class="h-4 w-4 rounded border-gray-300" />
            </label>

            {{-- 계정 (userid / 발신프로필 / userkey) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('feature_settings.alimtalk_userid_label') }}</label>
                <input wire:model="alimtalkUserid" type="text" class="input-base mt-1 w-full font-mono" autocomplete="off" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('feature_settings.alimtalk_profile_label') }}</label>
                <input wire:model="alimtalkProfile" type="text" class="input-base mt-1 w-full font-mono" autocomplete="off" />
                <p class="mt-1 text-xs text-gray-500">{{ __('feature_settings.alimtalk_profile_hint') }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">
                    {{ __('feature_settings.alimtalk_userkey_label') }}
                    @if ($alimtalkUserkeySet)
                        <span class="badge badge-green">{{ __('feature_settings.mail_gmail_password_set') }}</span>
                    @endif
                </label>
                <input wire:model="alimtalkUserkey" type="password" class="input-base mt-1 w-full font-mono" autocomplete="new-password" />
                <p class="mt-1 text-xs text-gray-400">{{ __('feature_settings.alimtalk_userkey_hint') }}</p>
            </div>

            {{-- 11종 템플릿ID + 개별 on/off --}}
            <div class="rounded-md border border-gray-100">
                <div class="border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600">{{ __('feature_settings.alimtalk_templates_label') }}</div>
                <div class="divide-y divide-gray-50">
                    @foreach ($this->alimtalkTemplateMeta() as $code => $meta)
                        <div class="flex items-center gap-2 px-3 py-2">
                            <input type="checkbox" wire:model="alimtalkToggles.{{ $code }}" class="h-4 w-4 rounded border-gray-300" title="{{ __('feature_settings.alimtalk_toggle_title') }}" />
                            <div class="w-28 shrink-0">
                                <div class="text-xs font-medium text-gray-700">{{ $meta['name'] }}</div>
                                <div class="text-[10px] text-gray-400">{{ $meta['recipient'] === 'admin' ? __('feature_settings.alimtalk_recipient_admin') : ($meta['recipient'] === '영업' ? __('feature_settings.alimtalk_recipient_sales') : ($meta['recipient'] === 'dealer' ? __('feature_settings.alimtalk_recipient_dealer') : __('feature_settings.alimtalk_recipient_manage'))) }} · <span class="font-mono">{{ $code }}</span></div>
                            </div>
                            <input wire:model="alimtalkTmplIds.{{ $code }}" type="text" class="input-base w-full font-mono text-xs" placeholder="BizM tmplId" autocomplete="off" />
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end pt-1">
                <button wire:click="saveAlimtalk" class="btn-primary">{{ __('common.save') }}</button>
            </div>

            {{-- 테스트 발송 --}}
            <div class="mt-2 rounded-md border border-amber-100 bg-amber-50 px-3 py-2.5">
                <div class="text-xs font-semibold text-amber-800">{{ __('feature_settings.alimtalk_test_label') }}</div>
                <p class="mt-0.5 text-[11px] text-amber-700">{{ __('feature_settings.alimtalk_test_hint') }}</p>
                <div class="mt-2 flex gap-2">
                    <input wire:model="alimtalkTestPhone" type="tel" class="input-base w-full text-sm" placeholder="010-0000-0000" autocomplete="off" />
                    <button wire:click="sendTestAlimtalk" class="btn-primary shrink-0 whitespace-nowrap">{{ __('feature_settings.alimtalk_test_btn') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 정산 파라미터 그룹 (2026-06-22) — 차등 tier/비율. super 전용 --}}
    <div class="card max-w-xl" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">정산 파라미터</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-3">
            <p class="text-xs text-gray-500">프리랜서 비율·사내직원 차등(건당/고율) 기준. 변경 시 이후 정산(미확정)에 자동 반영, 확정·지급된 건은 스냅샷 보존.</p>
            @foreach ($this->settlementParamMeta() as $key => $meta)
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ $meta['label'] }}</label>
                    <input
                        wire:model="settlementParams.{{ $key }}"
                        type="number" min="0" step="1"
                        class="input-base mt-1 w-full"
                    />
                    <p class="mt-1 text-xs text-gray-400">{{ $meta['hint'] }}</p>
                </div>
            @endforeach
            <div class="flex justify-end pt-1">
                <button wire:click="saveSettlementParams" class="btn-primary">{{ __('common.save') }}</button>
            </div>
        </div>
    </div>

    {{-- 언어 (다국어) 그룹 --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ __('feature_settings.lang_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-3">
            <p class="text-xs text-gray-500">{{ __('feature_settings.lang_hint') }}</p>

            {{-- 기본 언어 (항상 켜짐) --}}
            <div class="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 px-3 py-2">
                <span class="text-sm text-gray-700">{{ __('feature_settings.ko_label') }} <span class="text-xs text-gray-400">{{ __('feature_settings.ko_default') }}</span></span>
                <span class="badge badge-gray">{{ __('feature_settings.always_on') }}</span>
            </div>

            {{-- 영어 토글 --}}
            <label class="flex cursor-pointer items-center justify-between rounded-md border border-gray-100 px-3 py-2">
                <span class="text-sm text-gray-700">{{ __('feature_settings.en_label') }} <span class="text-xs text-gray-400">{{ __('feature_settings.en_sub') }}</span></span>
                <input type="checkbox" wire:model.live="localeEnEnabled" class="peer sr-only">
                <span class="relative h-5 w-9 rounded-full bg-gray-300 transition-colors peer-checked:bg-violet-600
                             after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4"></span>
            </label>
        </div>
    </div>

    {{-- 🔒 락 관제 그룹 — 돈 흐름 진행 잠금 토글 (super 전용) --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-rose-500"></span>
                <span class="section-title">{{ __('feature_settings.lock_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-2">
            <p class="text-xs text-gray-500">{{ __('feature_settings.lock_hint') }}</p>
            @foreach($this->lockMeta() as $lock => $m)
            <label class="flex cursor-pointer items-center justify-between gap-3 rounded-md border border-gray-100 px-3 py-2">
                <span class="text-sm text-gray-700">{{ $m['label'] }}
                    <span class="mt-0.5 block text-xs text-gray-400">{{ $m['sub'] }}</span>
                </span>
                <input type="checkbox" wire:model.live="lockToggles.{{ $lock }}" class="peer sr-only">
                <span class="relative h-5 w-9 shrink-0 rounded-full bg-gray-300 transition-colors peer-checked:bg-amber-500
                             after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4"></span>
            </label>
            @endforeach
        </div>
    </div>

    {{-- 알람 그룹 --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">{{ __('feature_settings.alarm_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-3">
            <p class="text-xs text-gray-500">{{ __('feature_settings.alarm_hint') }}</p>
            <label class="flex cursor-pointer items-center justify-between rounded-md border border-gray-100 px-3 py-2">
                <span class="text-sm text-gray-700">{{ __('feature_settings.alarm_label') }} <span class="text-xs text-gray-400">{{ __('feature_settings.alarm_sub') }}</span></span>
                <input type="checkbox" wire:model.live="alarmEnabled" class="peer sr-only">
                <span class="relative h-5 w-9 rounded-full bg-gray-300 transition-colors peer-checked:bg-amber-500
                             after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4"></span>
            </label>

            {{-- item 9 — 알람 항목별 "며칠 전" 리드데이 --}}
            <div class="space-y-2 rounded-md border border-gray-100 px-3 py-2">
                <p class="text-xs font-medium text-gray-600">{{ __('feature_settings.alarm_lead_title') }}</p>
                @foreach($this->alarmLeadMeta() as $k => $m)
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm text-gray-700">{{ $m['label'] }}</span>
                    <div class="flex items-center gap-1">
                        <input type="number" min="0" wire:model="alarmLeadDays.{{ $k }}" class="input-base w-20 text-right" />
                        <span class="text-xs text-gray-400">{{ __('feature_settings.alarm_lead_unit') }}</span>
                    </div>
                </div>
                @endforeach
                <div class="flex justify-end pt-1">
                    <button wire:click="saveAlarmParams" class="btn-primary text-xs" wire:loading.attr="disabled" wire:target="saveAlarmParams">{{ __('common.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 도장 · 서명 그룹 --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-rose-500"></span>
                <span class="section-title">{{ __('feature_settings.stamp_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-3">
            <p class="text-xs text-gray-500">{{ __('feature_settings.stamp_hint') }}</p>

            @foreach ($this->stampSlots() as $slot)
                <div class="rounded-md border border-gray-100 px-3 py-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-700">
                            {{ $slot['label'] }}
                            <span class="text-xs text-gray-400">{{ $slot['sub'] }}</span>
                        </span>
                        @if (($stampPaths[$slot['role']] ?? null))
                            <span class="badge badge-green">{{ __('feature_settings.stamp_uploaded') }}</span>
                        @else
                            <span class="badge badge-gray">{{ __('feature_settings.stamp_default') }}</span>
                        @endif
                    </div>

                    @if (($stampUrls[$slot['role']] ?? null))
                        <div class="mt-2">
                            <img src="{{ $stampUrls[$slot['role']] }}" alt="{{ $slot['role'] }}" class="max-h-20 rounded border border-gray-200 bg-white p-1">
                        </div>
                    @endif

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <label class="btn-primary cursor-pointer text-sm">
                            <span wire:loading.remove wire:target="{{ $slot['prop'] }}">{{ __('feature_settings.stamp_upload_btn') }}</span>
                            <span wire:loading wire:target="{{ $slot['prop'] }}">…</span>
                            <input type="file" wire:model="{{ $slot['prop'] }}" accept="image/png,image/jpeg" class="hidden">
                        </label>
                        @if (($stampPaths[$slot['role']] ?? null))
                            <button type="button" wire:click="removeStamp('{{ $slot['role'] }}')" class="text-sm text-gray-500 underline hover:text-rose-600">
                                {{ __('feature_settings.stamp_remove_btn') }}
                            </button>
                        @endif
                    </div>

                    @error($slot['prop'])
                        <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>
    </div>

    {{-- 도장 위치 조정 그룹 — 서류별 슬롯에 dx/dy 오프셋·크기(W/H). super 전용 --}}
    <div class="card max-w-3xl" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-rose-400"></span>
                <span class="section-title">{{ __('feature_settings.stamp_pos_section') }}</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-4">
            <p class="text-xs text-gray-500">{{ __('feature_settings.stamp_pos_hint') }}</p>

            @foreach ($this->stampPositionGroups() as $docLabel => $slots)
                <div class="rounded-md border border-gray-100 p-3">
                    <div class="mb-2 text-sm font-semibold text-gray-700">{{ $docLabel }}</div>
                    <div class="space-y-2">
                        @foreach ($slots as $key => $p)
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="w-40 shrink-0 text-gray-600">{{ $p['slot'] }}</span>
                                <label class="flex items-center gap-1">X
                                    <input type="number" wire:model="stampPositions.{{ $key }}.dx" class="input-base w-16 px-1 py-0.5 text-right"></label>
                                <label class="flex items-center gap-1">Y
                                    <input type="number" wire:model="stampPositions.{{ $key }}.dy" class="input-base w-16 px-1 py-0.5 text-right"></label>
                                <label class="flex items-center gap-1">W
                                    <input type="number" wire:model="stampPositions.{{ $key }}.w" class="input-base w-16 px-1 py-0.5 text-right"></label>
                                <label class="flex items-center gap-1">H
                                    <input type="number" wire:model="stampPositions.{{ $key }}.h" class="input-base w-16 px-1 py-0.5 text-right"></label>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="flex justify-end">
                <button wire:click="saveStampPositions" class="btn-primary">{{ __('common.save') }}</button>
            </div>
        </div>
    </div>
</div>
