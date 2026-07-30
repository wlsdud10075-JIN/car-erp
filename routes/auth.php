<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'auth.login')
        ->name('login');

    // 공개 회원가입 제거 (jin 2026-07-30) — 사내 ERP 라 누구나 가입할 이유가 없다.
    //   로그인 화면에 링크는 없었지만 /register 는 Laravel 표준 경로라 URL 직접 진입이 가능했다.
    //   실제로 막고 있던 건 users.role DB 기본값 '전체' 가 User::ROLES(영업/수출통관/재무/관리)에
    //   없어서 canAccessErp() 가 false 인 **우연**이었다 — 기본값을 정상 role 로 "정리" 하는 순간 열린다.
    //   ⚠️ verified 미들웨어는 방어가 아니다: User 가 MustVerifyEmail 을 구현하지 않아
    //     EnsureEmailIsVerified 가 통과시킨다(실측). 계정 생성은 /admin/users 에서만.
    //   가드 = PublicRegistrationDisabledTest.

    Volt::route('forgot-password', 'auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'auth.reset-password')
        ->name('password.reset');

});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'auth.confirm-password')
        ->name('password.confirm');
});

Route::post('logout', Logout::class)
    ->name('logout');
