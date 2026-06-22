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

    public bool $localeEnEnabled = false;

    public bool $alarmEnabled = false;

    // 도장/서명 역할 2종 — signature(서명: 말소계약서·선적인보이스), seal(직인: 판매인보이스·계약서).
    public array $stampRoles = ['signature', 'seal'];

    public $signatureUpload = null;

    public $sealUpload = null;

    public array $stampPaths = [];   // role => 저장 경로|null

    public array $stampUrls = [];    // role => 미리보기 URL|null

    public function mount(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR';
        $this->localeEnEnabled = (bool) Setting::get('locale_en_enabled', false);
        $this->alarmEnabled = (bool) Setting::get('alarm_enabled', false);
        $this->refreshStamps();
    }

    // 현재 template_set(=회사) — 배포별 1개 회사라 이 화면은 자기 회사 도장만 관리.
    private function stampSet(): string
    {
        return config('company.template_set', 'system');
    }

    // UI 슬롯 메타 — blade 에서 foreach.
    public function stampSlots(): array
    {
        return [
            ['role' => 'signature', 'prop' => 'signatureUpload', 'label' => __('feature_settings.stamp_signature_label'), 'sub' => __('feature_settings.stamp_signature_sub')],
            ['role' => 'seal', 'prop' => 'sealUpload', 'label' => __('feature_settings.stamp_seal_label'), 'sub' => __('feature_settings.stamp_seal_sub')],
        ];
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
                        $this->stampUrls[$role] = $disk->url($path);
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
</div>
