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
