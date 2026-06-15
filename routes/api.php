<?php

use App\Http\Controllers\Webhook\PurchaseSyncController;
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
