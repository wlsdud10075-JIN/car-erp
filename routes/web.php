<?php

use App\Http\Controllers\BuyerDocumentController;
use App\Http\Controllers\PayoutApprovalController;
use App\Http\Controllers\ProvideNiceLookupController;
use App\Http\Controllers\SignController;
use App\Http\Controllers\SignedContractController;
use App\Http\Controllers\VehicleDocumentController;
use App\Http\Controllers\VehicleExportController;
use App\Http\Controllers\VehicleTemplateController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// NICE 게이트웨이 (Django ssancar-erp 이식) — ssancarerp 박스(heymancar.com)에서 NICE 직접 2단계 호출.
// heymanerp 등 다른 박스는 NICE IP 화이트리스트 밖이라 이 경로를 그대로 경유. 인증·토큰 없음(Django @csrf_exempt 동일, CSRF 제외=bootstrap/app.php).
Route::post('provide/api/nice-lookup', ProvideNiceLookupController::class);

// 국내 바이어 말소등록증 전달 링크 (2026-07-04) — 알림톡으로 보낸 만료 서명 링크. 로그인 없음(signed 서명이 인가).
// 차량 id + 고정 문서(말소등록증)만 서명 → 파일 경로는 URL 에 안 실림(IDOR/traversal 차단). 3일 만료(발급측 지정).
Route::get('d/deregistration/{vehicle}', [BuyerDocumentController::class, 'deregistration'])
    ->middleware('signed')
    ->name('buyer.deregistration');

// 월배치 정산지급 — 대표가 카톡 알림톡 버튼으로 바로 승인/반려 (2026-07-08). 로그인 없음(signed 서명이 인가).
//   서명 = 배치 id + 승인자 u(권한 바인딩) + 5일 만료. show(GET)=내역만 표시(자동처리 X, 카톡 프리페치 방어),
//   decide(POST)=실제 승인/반려(canDecide 재검증=1회용·계단·상태 가드). CSRF 제외=bootstrap/app.php(a/payout/*).
Route::get('a/payout/{batch}', [PayoutApprovalController::class, 'show'])
    ->middleware('signed')
    ->name('payout.approve.show');
Route::post('a/payout/{batch}/decide', [PayoutApprovalController::class, 'decide'])
    ->middleware('signed')
    ->name('payout.approve.decide');

// 판매계약서 전자서명 (2026-07-10 풀회의, ERP 직접호스팅). 로그인 없음(signed 서명이 인가 — 만료·변조불가).
//   sign_token(추측불가 DB 핸들) + signed 미들웨어 병행. show(GET)=계약 PDF 미리보기 + 서명패드 + 이메일칸,
//   preview(GET)=발급시 캐시한 PDF inline(iframe src), submit(POST)=서명 확정(멱등). CSRF 제외=bootstrap/app.php(sign/*).
Route::get('sign/{token}', [SignController::class, 'show'])->middleware('signed')->name('sign.show');
Route::get('sign/{token}/preview', [SignController::class, 'preview'])->middleware('signed')->name('sign.preview');
Route::post('sign/{token}/submit', [SignController::class, 'submit'])->middleware('signed')->name('sign.submit');

// 사내 ERP — 별도 소개(랜딩) 화면 없이 첫 접속은 로그인으로. 로그인 상태면 대시보드로.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
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
    // 회의확장씬 큐 15 / G5 (2026-05-23) — 영업담당자별 재고관리.
    Volt::route('inventory', 'erp.inventory.index')->name('inventory.index');

    // ETA 통관서류 알림함 (2026-06-18) — 컴포넌트 mount 에서 canAccessClearance 가드.
    Volt::route('alarms', 'erp.alarms.index')->name('alarms.index');

    // board 영업포털 선적요청 목록 (2026-06-19) — 배치별 묶음·상태추적. mount/transition 에서 canAccessClearance 가드.
    Volt::route('shipping-requests', 'erp.shipping-requests.index')->name('shipping-requests.index');

    // 차량 일괄적재 빈 양식 다운로드 (super/admin 마이그레이션 도구). 'import-template' 리터럴이라
    // 아래 {id}(whereNumber) 라우트와 충돌 없음. 데이터 없는 빈 양식이라 PII·회계 노출 0.
    Route::get('vehicles/import-template', [VehicleTemplateController::class, 'download'])
        ->name('vehicles.import-template')
        ->middleware('admin');

    // 차량 데이터 export (고정 화이트리스트 + PII 마스킹) — 2026-06-29 라운드테이블 조건부 GO.
    // 'export' 리터럴이라 {id}(whereNumber) 라우트와 충돌 없음. 전 ERP role(canScopeVehicle 스코핑:
    // 영업 본인/관리 팀/통관·재무·admin 전체) + rate limit(분3/일100). 정산 컬럼은 컨트롤러에서
    // canAccessSettlement role 만 허용. (erp 그룹 미들웨어로 auth·verified·erp 이미 적용)
    Route::get('vehicles/export', [VehicleExportController::class, 'download'])
        ->name('vehicles.export')
        ->middleware('throttle:data-export');

    // 차량별 서류 자동 생성 (단계 11)
    Route::get('vehicles/{id}/documents/{type}', [VehicleDocumentController::class, 'show'])
        ->name('vehicles.documents.show')
        ->middleware('throttle:vehicle-docs')   // claudereview A — 대량열람 억제
        ->whereNumber('id');

    // 다중차량 선적 서류 (#3) — ?ids=1,2,3. 'documents' 리터럴이라 위 {id} 라우트와 충돌 없음.
    // 1요청=최대 30대라 단일보다 빡빡한 limiter 적용.
    Route::get('vehicles/documents/{type}', [VehicleDocumentController::class, 'showMulti'])
        ->name('vehicles.documents.multi')
        ->middleware('throttle:vehicle-docs-multi');

    // 전자서명 서명본 열람 (ERP 내부, canScopeVehicle 가드). 바이어용 공개 signed URL 과 별개.
    Route::get('signed-contracts/{signedContract}/pdf', [SignedContractController::class, 'pdf'])
        ->name('signed-contracts.pdf')
        ->whereNumber('signedContract');

    // 업로드된 말소신청서 원본 파일 개별 다운로드 (선적요청 묶음 다운로드용)
    Route::get('vehicles/{id}/deregistration-file', [VehicleDocumentController::class, 'deregistrationFile'])
        ->name('vehicles.deregistration-file')
        ->middleware('throttle:vehicle-docs')
        ->whereNumber('id');
});

// 캐시플로우 — sales role + 컴포넌트 mount()에서 본인 ID 검증
Route::middleware(['auth', 'verified', 'sales'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('salesmen/{id}/cashflow', 'erp.salesmen.cashflow')->name('salesmen.cashflow');
});

// 승인 큐 — canApprove() (super/admin/관리/업무관리자)
Route::middleware(['auth', 'verified', 'approve'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('approvals', 'erp.approvals.index')->name('approvals.index');
    // Phase 2 — 월배치 정산지급 승인큐 (제출자 [관리]/업무관리자 상태확인 + 승인자 결정)
    Volt::route('payout-batches', 'erp.payout-batches.index')->name('payout-batches.index');
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

// 관리자 — super/admin만 (영업담당자)
Route::middleware(['auth', 'verified', 'admin'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('salesmen', 'erp.salesmen.index')->name('salesmen.index');
});

// 포워딩사(선적현황) — admin + [관리] (canManageForwarding). mount 가드로 검증 (2026-07-08 jin, 항구와 동일).
Route::middleware(['auth', 'verified'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('forwarding-companies', 'erp.forwarding-companies.index')->name('forwarding-companies.index');
});

// 채권관리 — admin + 정산/관리 role (큐 14-2 보강: 채권 위험 모니터링 광범위 허용)
Route::middleware(['auth', 'verified', 'receivable'])->prefix('erp')->name('erp.')->group(function () {
    Volt::route('receivables', 'erp.receivables.index')->name('receivables.index');
});

// 관리자 대시보드 — '관리' role 포함 read-only 접근 (큐 14-2)
Route::middleware(['auth', 'verified', 'admin-dashboard'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('dashboard', 'admin.dashboard')->name('dashboard');
});

// 사용자 관리 — super/admin + 관리(본인 팀 영업만, 컴포넌트 가드). 2026-06-30 jin.
Route::middleware(['auth', 'verified', 'manage-users'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('users', 'admin.users.index')->name('users.index');
});

// /admin 그 외 — super/admin만 (document-access-logs, audit-logs)
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('document-access-logs', 'admin.document-access-logs.index')->name('document-access-logs.index');
    // 회의확장씬 Phase 3-1 (d-2) (2026-05-23) — 별건3 흡수: 감사 로그 UI.
    Volt::route('audit-logs', 'admin.audit-logs.index')->name('audit-logs.index');
    // 알림톡 발송·도달 로그 (2026-07-13) — 전송결과 폴링 결과 + 미도달 확인.
    Volt::route('alimtalk-logs', 'admin.alimtalk-logs.index')->name('alimtalk-logs.index');
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

    // i18n Phase 0 — 언어 전환 (영어는 기능설정에서 켜진 경우만 허용)
    Route::post('locale', function (Request $request) {
        $locale = $request->input('locale');
        if (in_array($locale, User::LOCALES, true)) {
            if ($locale === 'en' && ! Setting::get('locale_en_enabled', false)) {
                $locale = 'ko';
            }
            $user = $request->user();
            $user->locale = $locale;
            $user->save();
        }

        return back();
    })->name('locale.update');
});

require __DIR__.'/auth.php';
