<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group heading="ERP" class="grid">
                    <flux:navlist.item icon="home" :href="route('erp.dashboard')" :current="request()->routeIs('erp.dashboard')" wire:navigate>대시보드</flux:navlist.item>
                    <flux:navlist.item icon="truck" :href="route('erp.vehicles.index')" :current="request()->routeIs('erp.vehicles.index')" wire:navigate>차량 관리</flux:navlist.item>
                    <flux:navlist.item icon="users" :href="route('erp.buyers.index')" :current="request()->routeIs('erp.buyers.index')" wire:navigate>바이어</flux:navlist.item>
                    <flux:navlist.item icon="identification" :href="route('erp.consignees.index')" :current="request()->routeIs('erp.consignees.index')" wire:navigate>컨사이니</flux:navlist.item>
                    @if(auth()->user()?->canAccessSettlement())
                    <flux:navlist.item icon="calculator" :href="route('erp.settlements.index')" :current="request()->routeIs('erp.settlements.index')" wire:navigate>정산</flux:navlist.item>
                    @endif
                    @if(auth()->user()?->canAccessAdmin())
                    <flux:navlist.item icon="building-office-2" :href="route('erp.forwarding-companies.index')" :current="request()->routeIs('erp.forwarding-companies.index')" wire:navigate>포워딩사</flux:navlist.item>
                    <flux:navlist.item icon="briefcase" :href="route('erp.salesmen.index')" :current="request()->routeIs('erp.salesmen.index')" wire:navigate>영업담당자</flux:navlist.item>
                    @elseif(auth()->user()?->canAccessSales())
                    @php $mySalesman = auth()->user()?->salesman; @endphp
                    @if($mySalesman)
                    <flux:navlist.item icon="chart-bar" :href="route('erp.salesmen.cashflow', $mySalesman->id)" :current="request()->routeIs('erp.salesmen.cashflow')" wire:navigate>내 캐시플로우</flux:navlist.item>
                    @endif
                    @endif
                </flux:navlist.group>

                @if(auth()->user()?->canAccessAdmin())
                <flux:navlist.group heading="기타관리" class="grid">
                    <flux:navlist.item icon="chart-bar" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>관리자 대시보드</flux:navlist.item>
                    <flux:navlist.item icon="user-group" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.index')" wire:navigate>사용자 관리</flux:navlist.item>
                </flux:navlist.group>
                @endif
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
