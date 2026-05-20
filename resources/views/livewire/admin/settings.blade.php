<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $sidebarBrand = '';

    public function mount(): void
    {
        if (! auth()->user()?->isSuperAdmin()) {
            abort(403);
        }
        $this->sidebarBrand = Setting::get('sidebar_brand', 'SSANCAR') ?: 'SSANCAR';
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
}; ?>

<div wire:poll.30s class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">기능 설정</h1>
        <p class="mt-1 text-sm text-gray-500">시스템관리자(super) 전용 — 사이드바 브랜드 텍스트 등 전역 설정</p>
    </div>

    <div class="card max-w-xl">
        <div class="section-header">
            <span class="section-dot bg-violet-500"></span>
            <span class="section-title">브랜드</span>
        </div>

        <div class="mt-3">
            <label class="block text-sm font-medium text-gray-700">사이드바 브랜드 텍스트</label>
            <p class="mt-1 text-xs text-gray-500">사이드바 상단 로고 옆에 표시 (최대 12자). 예: SSANCAR / 산카 ERP</p>
            <input
                wire:model="sidebarBrand"
                type="text"
                maxlength="12"
                class="input-base mt-2 w-full"
                placeholder="SSANCAR"
            />
        </div>

        <div class="mt-4 flex justify-end">
            <button wire:click="save" class="btn-primary">저장</button>
        </div>
    </div>
</div>
