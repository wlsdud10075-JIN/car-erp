<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $sidebarBrand = '';

    public bool $localeEnEnabled = false;

    public bool $alarmEnabled = false;

    public function mount(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR';
        $this->localeEnEnabled = (bool) Setting::get('locale_en_enabled', false);
        $this->alarmEnabled = (bool) Setting::get('alarm_enabled', false);
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
</div>
