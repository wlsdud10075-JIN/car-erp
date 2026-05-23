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

// 승인 큐 — canApprove() (super/admin/관리)
Route::middleware(['auth', 'verified', 'approve'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('approvals', 'erp.approvals.index')->name('approvals.index');
});

// 정산 — settlement role 이상
Route::middleware(['auth', 'verified', 'settlement'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('settlements', 'erp.settlements.index')->name('settlements.index');
});

// 큐 19-F — 자금 이체 재무 확정 (settlement 미들웨어 통과 후 컴포넌트 mount 에서
// canConfirmFinanceTransfer() 추가 검증 — 관리 role 제외, 정산 + admin/super 만 허용).
Route::middleware(['auth', 'verified', 'settlement'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('transfers', 'erp.transfers.index')->name('transfers.index');
});

// 관리자 — super/admin만 (포워딩사·영업담당자)
Route::middleware(['auth', 'verified', 'admin'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('forwarding-companies', 'erp.forwarding-companies.index')->name('forwarding-companies.index');
    Volt::route('salesmen', 'erp.salesmen.index')->name('salesmen.index');
});

// 채권관리 — admin + 정산/관리 role (큐 14-2 보강: 채권 위험 모니터링 광범위 허용)
Route::middleware(['auth', 'verified', 'receivable'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('receivables', 'erp.receivables.index')->name('receivables.index');
});

// 관리자 대시보드 — '관리' role 포함 read-only 접근 (큐 14-2)
Route::middleware(['auth', 'verified', 'admin-dashboard'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('dashboard', 'admin.dashboard')->name('dashboard');
});

// /admin 그 외 — super/admin만 (users, document-access-logs, audit-logs)
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('users', 'admin.users.index')->name('users.index');
    Volt::route('document-access-logs', 'admin.document-access-logs.index')->name('document-access-logs.index');
    // 회의확장씬 Phase 3-1 (d-2) (2026-05-23) — 별건3 흡수: 감사 로그 UI.
    Volt::route('audit-logs', 'admin.audit-logs.index')->name('audit-logs.index');
});

// 회의확장씬 2026-05-22 — 항구 마스터는 admin + [관리] (canManagePorts).
// 라우트 'auth, verified' 만 — Volt mount() 가드로 권한 검증.
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('ports', 'admin.ports.index')->name('ports.index');
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
