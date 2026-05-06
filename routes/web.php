<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// 로그인 후 진입점 — 권한별 분기
Route::get('dashboard', function () {
    $user = auth()->user();

    if ($user->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route('erp.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// ERP — 모든 인증 사용자 접근 (메뉴별 가드는 추후)
Route::middleware(['auth', 'verified'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('dashboard', 'erp.dashboard')->name('dashboard');
});

// 관리자 — super/admin만
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('dashboard', 'admin.dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
