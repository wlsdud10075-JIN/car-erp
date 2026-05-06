<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    //
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    <div>
        <h1 class="text-2xl font-bold text-zinc-800 dark:text-zinc-100">관리자 대시보드</h1>
        <p class="mt-1 text-sm text-zinc-500">사용자 관리 / 기능 설정 / 통계 위젯</p>
    </div>

    <div class="rounded-xl border border-dashed border-zinc-300 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
        <p class="text-zinc-500">관리자 위젯은 Phase B 이후 구현 예정.</p>
    </div>
</div>
