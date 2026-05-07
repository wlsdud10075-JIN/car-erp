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

// ERP — 모든 인증 사용자 접근
Route::middleware(['auth', 'verified'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('dashboard', 'erp.dashboard')->name('dashboard');
    Volt::route('vehicles', 'erp.vehicles.index')->name('vehicles.index');
    Volt::route('buyers', 'erp.buyers.index')->name('buyers.index');
    Volt::route('consignees', 'erp.consignees.index')->name('consignees.index');
    // 캐시플로우: 인증 후 컴포넌트 내부에서 본인 여부 검증
    Volt::route('salesmen/{id}/cashflow', 'erp.salesmen.cashflow')->name('salesmen.cashflow');
});

// 관리자 — super/admin만
Route::middleware(['auth', 'verified', 'admin'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('forwarding-companies', 'erp.forwarding-companies.index')->name('forwarding-companies.index');
    Volt::route('salesmen', 'erp.salesmen.index')->name('salesmen.index');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('dashboard', 'admin.dashboard')->name('dashboard');
    Volt::route('users', 'admin.users.index')->name('users.index');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
