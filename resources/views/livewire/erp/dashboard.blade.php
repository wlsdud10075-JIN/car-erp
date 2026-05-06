<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    //
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-3 md:p-6">
    <div>
        <h1 class="text-2xl font-bold text-zinc-800 dark:text-zinc-100">ERP 대시보드</h1>
        <p class="mt-1 text-sm text-zinc-500">중고차 수출 ERP — 진행상태/매입/판매/정산 현황</p>
    </div>

    <div class="rounded-xl border border-dashed border-zinc-300 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
        <p class="text-zinc-500">대시보드 위젯은 Phase B 이후 구현 예정.</p>
        <p class="mt-2 text-xs text-zinc-400">
            로그인 사용자: <span class="font-mono">{{ auth()->user()->email }}</span>
            (permission: <span class="font-mono">{{ auth()->user()->permission }}</span>,
            role: <span class="font-mono">{{ auth()->user()->role }}</span>)
        </p>
    </div>
</div>
