<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-linear-to-b from-blue-50 via-white to-white text-zinc-800 antialiased">
        {{-- 작업3 (2026-05-27) — 파란색 계열·중고차 수출 느낌·간결.
             --color-accent override 는 auth 컨테이너로 스코프 → Flux primary 버튼/링크가
             앱 전역 영향 없이 이 화면에서만 파란색.
             브랜드명은 기능설정의 사이드바 브랜드(sidebar_brand)와 동일 출처 — 한 곳에서 바꾸면 로그인·사이드바 함께 변경. --}}
        @php
            $authBrand = trim((string) \App\Models\Setting::get('sidebar_brand', 'SSANCAR')) ?: 'SSANCAR';
            $authBrandInitial = mb_strtoupper(mb_substr($authBrand, 0, 1));
        @endphp
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10"
             style="--color-accent: #2563eb; --color-accent-content: #1d4ed8; --color-accent-foreground: #ffffff;">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-3" wire:navigate>
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-600 text-2xl font-bold text-white shadow-sm shadow-blue-600/30">{{ $authBrandInitial }}</span>
                    <span class="flex flex-col items-center gap-0.5">
                        <span class="text-2xl font-bold tracking-tight text-blue-700">{{ $authBrand }}</span>
                        <span class="text-xs font-medium text-zinc-400">중고차 수출 ERP</span>
                    </span>
                </a>
                <div class="flex flex-col gap-6 rounded-2xl border border-blue-100 bg-white p-6 shadow-sm">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
