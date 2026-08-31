<?php

use App\Http\Controllers\Api\Internal\BoardRequestController;
use App\Http\Controllers\Api\Internal\InternalDocumentController;
use App\Http\Controllers\Api\Internal\InternalPortalController;
use App\Http\Controllers\Api\Internal\ShippingRequestController;
use App\Http\Controllers\Api\Internal\SigningRequestController;
use App\Http\Controllers\Api\PortalBuyerController;
use App\Http\Controllers\Api\PortalDocumentController;
use App\Http\Controllers\Api\PortalVehicleController;
use App\Http\Controllers\Webhook\PurchaseSyncController;
use App\Http\Middleware\VerifyBoardReadHmac;
use App\Http\Middleware\VerifyPortalReadHmac;
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
        // 환율 read (스코프 없음 — 전역값). board 가 car-erp 전신환매입률을 그대로 받아 씀.
        Route::get('rates', [InternalPortalController::class, 'rates'])->name('rates');
        Route::get('finance', [InternalPortalController::class, 'finance'])->name('finance');
        Route::get('receivables', [InternalPortalController::class, 'receivables'])->name('receivables');
        Route::get('sales', [InternalPortalController::class, 'sales'])->name('sales');
        Route::get('purchases', [InternalPortalController::class, 'purchases'])->name('purchases');
        Route::get('settlements', [InternalPortalController::class, 'settlements'])->name('settlements');
        Route::get('by-buyer', [InternalPortalController::class, 'byBuyer'])->name('by-buyer');
        // 월배치 미러 (2026-08-31) — 영업 본인이 그 달에 실제로 받은 금액. 권위 §12.
        //   조정(환수·특별지급)은 배치 총액에만 반영되므로 차량별 합계로는 통장 금액이 안 나온다.
        Route::get('payout-batches', [InternalPortalController::class, 'payoutBatches'])->name('payout-batches');
        // 연동 B v3 — board 드로어 바이어/컨사이니 드롭다운 (영업 본인 스코프).
        Route::get('buyers', [InternalPortalController::class, 'buyers'])->name('buyers');
        Route::get('consignees', [InternalPortalController::class, 'consignees'])->name('consignees');
        // ③ 선적·B/L 묶음 v2 (2026-06-30 회의 조건부 GO) — 권위 §5
        Route::get('shippable', [ShippingRequestController::class, 'shippable'])->name('shippable');          // 새로 묶을 차 후보
        // 포워딩사 명부 (2026-08-11) — board 는 고르기만 한다(신규 생성 없음). 활성 {id,name} 만.
        Route::get('forwarding-companies', [ShippingRequestController::class, 'forwardingCompanies'])->name('forwarding-companies');
        Route::get('bundles', [ShippingRequestController::class, 'bundles'])->name('bundles');                // 영속 묶음 + 미수집계
        Route::post('shipping-requests/sync', [ShippingRequestController::class, 'sync'])->name('sync');      // 선언형 재동기화
        Route::post('bundles/{batch}/bl-request', [ShippingRequestController::class, 'blRequest'])->name('bl-request');   // B/L요청
        Route::post('bundles/{batch}/bl-cancel', [ShippingRequestController::class, 'blCancel'])->name('bl-cancel');     // B/L요청 무름(영업 오발송 정정)
        Route::post('shipping-requests/change-request', [ShippingRequestController::class, 'changeRequest'])->name('change-request');   // in_progress 변경요청
        // @deprecated v1 단발 (board 미가동, sync 로 교체 예정)
        Route::post('shipping-request', [ShippingRequestController::class, 'store'])->name('shipping-request');
        // ①② 서류 다운로드 (선적 4종만, 프록시)
        Route::get('documents/{type}', [InternalDocumentController::class, 'show'])->name('documents');
        // 판매계약서 전자서명 세션 발급 (2026-07-10) — signed_url 반환, board 는 바이어에게 전달만. 권위 §10.
        Route::post('signing-requests', [SigningRequestController::class, 'store'])->name('signing-requests');
        // §10-2 — board 폴링용 서명 상태 조회(?vehicle_ids=1,2). board 칩 갱신용. 상태 메타만(PII·파일 X).
        Route::get('signing-requests', [SigningRequestController::class, 'status'])->name('signing-requests.status');
        // 재고 3분류 (2026-08-09) — board 「매입내역」(전량조회) 대체. erp/inventory 미러.
        Route::get('inventory', [InternalPortalController::class, 'inventory'])->name('inventory');
        // §11 요청·확인 신호(카톡 대체) — 입금요청/판매대금확인. 🚫금액 미수신(§11-2).
        Route::post('requests', [BoardRequestController::class, 'store'])->name('requests.store');
        Route::get('requests', [BoardRequestController::class, 'index'])->name('requests.index');
        Route::post('requests/{batch}/cancel', [BoardRequestController::class, 'cancel'])->name('requests.cancel');
    });

/*
| ssancar.com 바이어 포털 읽기 API (2026-08-25)
| 요청서 = Desktop\연구소\ERP_SSANCAR_WEB\ERP_요청_포털_읽기_엔드포인트_v1.0.md
| 합의 = ERP_연동_통합정리 v1.2~v1.9 · 진입점 = docs/integration/ssancar-portal-collab.md
|
| 🔑 board 채널과 **완전히 분리**한다 — 시크릿·미들웨어·nonce·throttle 넷 다.
|    같은 시크릿을 주면 board 의 전 API 면을 통째로 넘기게 되고 한쪽만 폐기할 수 없다(v1.2 Q6).
| 🚫 증분·툼스톤·페이지네이션을 만들지 말 것 — 전량 pull + 차집합이 합의다(v1.2 Q7).
|    툼스톤 없는 증분은 삭제를 영원히 못 잡는다.
*/
Route::middleware([VerifyPortalReadHmac::class])
    ->prefix('internal/portal')
    ->name('api.internal.portal.')
    ->group(function () {
        // ⚠️ throttle 은 **그룹이 아니라 라우트마다** 건다. 그룹에 걸고 안쪽에 또 걸면
        //    미들웨어는 **대체가 아니라 누적**이라 서류 호출이 전량 pull 의 한도까지 같이 깎는다.
        Route::get('vehicles', [PortalVehicleController::class, 'vehicles'])
            ->middleware('throttle:portal-read')
            ->name('vehicles');

        /*
        | 바이어 명부 (2026-08-28) — 차량 응답이 주는 `buyer_id` 에 **이름을 붙일 유일한 경로**.
        | 🔑 throttle 은 **차량과 같은 버킷**(`portal-read`, 30/분·IP). 같은 6시간 크론이
        |    회당 2요청만 하므로 서로를 굶기지 않는다. 서류를 따로 뗀 이유는 바이어 브라우저의
        |    순간 폭주였는데, 명부에는 그 축이 없다.
        */
        Route::get('buyers', [PortalBuyerController::class, 'buyers'])
            ->middleware('throttle:portal-read')
            ->name('buyers');

        /*
        | 서류 실물 (2026-08-27) — 통로가 둘인 이유는 PortalDocumentController 주석 참조.
        |   생성물(통관 SET)은 여기서 바이트로 스트림하고, 저장물(수출신고서·선적사진)은
        |   짧은 만료 서명 URL 만 발급한다.
        | 🔑 게이트는 Vehicle 한 곳(portalDocumentsBlocker / clearanceSetBlocker)이고
        |    목록 응답과 **같은 메서드**를 서빙 시점에 다시 부른다.
        | 🚨 **throttle 을 따로 쓴다**(`portal-docs`) — 같은 버킷이면 바이어가 화면을 몇 번 열 때
        |    30/분을 다 써서 **정기 전량 pull 이 429** 로 굶는다. 그게 미러의 전부다.
        */
        Route::middleware('throttle:portal-docs')->group(function () {
            Route::get('vehicles/{vehicle}/clearance-set', [PortalDocumentController::class, 'clearanceSet'])
                ->name('vehicles.clearance-set');
            Route::get('vehicles/{vehicle}/files', [PortalDocumentController::class, 'files'])
                ->name('vehicles.files');
        });
    });
