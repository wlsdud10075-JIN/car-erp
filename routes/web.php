<?php

use App\Http\Controllers\VehicleDocumentController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// 로그인 후 진입점 — 모두 ERP 대시보드로 (관리자 대시보드는 사이드바 기타관리에서 접근)
Route::get('dashboard', function () {
    return redirect()->route('erp.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// ERP — canAccessErp() (super/admin ∪ role 전체/영업/통관/정산/관리)
Route::middleware(['auth', 'verified', 'erp'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('dashboard', 'erp.dashboard')->name('dashboard');
    Volt::route('vehicles', 'erp.vehicles.index')->name('vehicles.index');
    Volt::route('buyers', 'erp.buyers.index')->name('buyers.index');
    Volt::route('consignees', 'erp.consignees.index')->name('consignees.index');

    // 차량별 서류 자동 생성 (단계 11)
    Route::get('vehicles/{id}/documents/{type}', [VehicleDocumentController::class, 'show'])
        ->name('vehicles.documents.show')
        ->whereNumber('id');
});

// 캐시플로우 — sales role + 컴포넌트 mount()에서 본인 ID 검증
Route::middleware(['auth', 'verified', 'sales'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('salesmen/{id}/cashflow', 'erp.salesmen.cashflow')->name('salesmen.cashflow');
});

// 정산 — settlement role 이상
Route::middleware(['auth', 'verified', 'settlement'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('settlements', 'erp.settlements.index')->name('settlements.index');
});

// 관리자 — super/admin만
// TODO: 추후 receivable role 신설 시 'receivables'는 별도 그룹으로 분리하고
//       'receivable' 미들웨어를 적용 (현재는 admin 권한으로만 운영)
Route::middleware(['auth', 'verified', 'admin'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('forwarding-companies', 'erp.forwarding-companies.index')->name('forwarding-companies.index');
    Volt::route('salesmen', 'erp.salesmen.index')->name('salesmen.index');
    Volt::route('receivables', 'erp.receivables.index')->name('receivables.index');
});

// 관리자 대시보드 — '관리' role 포함 read-only 접근 (큐 14-2)
Route::middleware(['auth', 'verified', 'admin-dashboard'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('dashboard', 'admin.dashboard')->name('dashboard');
});

// /admin 그 외 — super/admin만 (users, document-access-logs)
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('users', 'admin.users.index')->name('users.index');
    Volt::route('document-access-logs', 'admin.document-access-logs.index')->name('document-access-logs.index');
});

// 기능 설정 — super만
Route::middleware(['auth', 'verified', 'super-admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('settings', 'admin.settings')->name('settings');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
