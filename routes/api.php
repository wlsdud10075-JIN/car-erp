<?php

use App\Http\Controllers\Api\Internal\InternalDocumentController;
use App\Http\Controllers\Api\Internal\InternalPortalController;
use App\Http\Controllers\Api\Internal\ShippingRequestController;
use App\Http\Controllers\Webhook\PurchaseSyncController;
use App\Http\Middleware\VerifyBoardReadHmac;
use App\Http\Middleware\VerifyPurchaseSyncHmac;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — 시스템 간 호출 전용 (세션/role 미들웨어 없음)
|--------------------------------------------------------------------------
| 연동 B 수신: board(매입보드) → car-erp(heyman) 매입 동기화.
| 인증 = HMAC 서명(VerifyPurchaseSyncHmac). rate limit 으로 무차별 시도 차단.
| 수신 스펙(권위) = docs/integration/purchase-sync-receiver.md.
*/

Route::middleware([VerifyPurchaseSyncHmac::class, 'throttle:30,1'])
    ->post('/internal/purchase-sync', PurchaseSyncController::class)
    ->name('api.internal.purchase-sync');

/*
| board 영업 포털 읽기 API (HMAC GET) — purchase-sync 의 역방향.
| 인증 = VerifyBoardReadHmac(별도 READ 시크릿·replay 방지). 본인격리 = SalesmanResolver.
| 권위 스펙 = docs/integration/board-portal-api.md. ④ 재무 읽기(이번 단계). ③선적요청·①②서류는 후속.
*/
Route::middleware([VerifyBoardReadHmac::class, 'throttle:board-read'])
    ->prefix('internal/board')
    ->name('api.internal.board.')
    ->group(function () {
        Route::get('finance', [InternalPortalController::class, 'finance'])->name('finance');
        Route::get('receivables', [InternalPortalController::class, 'receivables'])->name('receivables');
        Route::get('sales', [InternalPortalController::class, 'sales'])->name('sales');
        Route::get('purchases', [InternalPortalController::class, 'purchases'])->name('purchases');
        Route::get('settlements', [InternalPortalController::class, 'settlements'])->name('settlements');
        Route::get('by-buyer', [InternalPortalController::class, 'byBuyer'])->name('by-buyer');
        // ③ 선적요청
        Route::get('shippable', [ShippingRequestController::class, 'shippable'])->name('shippable');
        Route::post('shipping-request', [ShippingRequestController::class, 'store'])->name('shipping-request');
        // ①② 서류 다운로드 (선적 4종만, 프록시)
        Route::get('documents/{type}', [InternalDocumentController::class, 'show'])->name('documents');
    });
