<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $sidebarBrand = '';

    public bool $localeEnEnabled = false;

    public function mount(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR';
        $this->localeEnEnabled = (bool) Setting::get('locale_en_enabled', false);
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
        $this->dispatch('notify', message: '저장 완료 — 새로고침하면 사이드바에 반영됩니다.', type: 'success');
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
            ? '영어가 활성화되었습니다. 상단바의 한국어 / English 버튼으로 본인 화면을 전환하세요.'
            : '영어가 비활성화되었습니다.');

        $this->redirect(route('admin.settings'), navigate: false);
    }
}; ?>

<div wire:poll.30s class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">기능 설정</h1>
        <p class="mt-1 text-sm text-gray-500">시스템관리자(super) 전용 — 전역 설정</p>
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
                <span class="section-title">브랜드</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3">
            <label class="block text-sm font-medium text-gray-700">사이드바 브랜드 텍스트</label>
            <p class="mt-1 text-xs text-gray-500">사이드바 상단 로고 옆에 표시 (최대 12자). 예: SSANCAR / 산카 ERP</p>
            <input
                wire:model="sidebarBrand"
                type="text"
                maxlength="12"
                class="input-base mt-2 w-full"
                placeholder="SSANCAR"
            />
            <div class="mt-4 flex justify-end">
                <button wire:click="save" class="btn-primary">저장</button>
            </div>
        </div>
    </div>

    {{-- 언어 (다국어) 그룹 --}}
    <div class="card max-w-xl" x-data="{ open: true }">
        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
            <span class="flex items-center gap-2">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">언어 (다국어)</span>
            </span>
            <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-transition class="mt-3 space-y-3">
            <p class="text-xs text-gray-500">활성화한 언어만 사용자가 상단바에서 선택할 수 있습니다. 끄면 해당 언어 사용자는 한국어로 돌아갑니다.</p>

            {{-- 기본 언어 (항상 켜짐) --}}
            <div class="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 px-3 py-2">
                <span class="text-sm text-gray-700">한국어 <span class="text-xs text-gray-400">(기본)</span></span>
                <span class="badge badge-gray">항상 켜짐</span>
            </div>

            {{-- 영어 토글 --}}
            <label class="flex cursor-pointer items-center justify-between rounded-md border border-gray-100 px-3 py-2">
                <span class="text-sm text-gray-700">English <span class="text-xs text-gray-400">(영어)</span></span>
                <input type="checkbox" wire:model.live="localeEnEnabled" class="peer sr-only">
                <span class="relative h-5 w-9 rounded-full bg-gray-300 transition-colors peer-checked:bg-violet-600
                             after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4"></span>
            </label>
        </div>
    </div>
</div>
