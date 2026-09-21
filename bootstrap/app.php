<?php

use App\Http\Middleware\AdminDashboardMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApproveMiddleware;
use App\Http\Middleware\ClearanceMiddleware;
use App\Http\Middleware\ErpMiddleware;
use App\Http\Middleware\ManageUsersMiddleware;
use App\Http\Middleware\OperationLogsMiddleware;
use App\Http\Middleware\ReceivableMiddleware;
use App\Http\Middleware\SalesMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SettlementMiddleware;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // i18n Phase 0 — 모든 web 요청에서 사용자 언어 적용
        $middleware->web(append: [SetLocale::class]);

        // 🍪 화면 표시 설정 2개는 **암호화하지 않는다** (jin 2026-09-21).
        //    veh_cols   = 차량목록에서 보이는 컬럼 키 목록
        //    veh_mobile = 이 브라우저가 폰인가(0/1)
        //    민감정보가 아니고, 서버가 **첫 요청부터** 읽어야 한다 —
        //    이게 없으면 100행 첫 화면이 36칸 × 두 벌(데스크탑 표 + 모바일 카드)로 나간다.
        //    🚫 여기에 개인정보·권한에 관한 값을 넣지 말 것(평문으로 브라우저에 남는다).

        // 🍪 화면 표시 설정 2개는 **암호화하지 않는다** (jin 2026-09-21).
        //    veh_cols   = 차량목록에서 보이는 컬럼 키 목록
        //    veh_mobile = 이 브라우저가 폰인가(0/1)
        //    민감정보가 아니고, 서버가 **첫 요청부터** 읽어야 한다 —
        //    이게 없으면 100행 첫 화면이 36칸 × 두 벌(데스크탑 표 + 모바일 카드)로 나간다.
        //    🚫 여기에 개인정보·권한에 관한 값을 넣지 말 것(평문으로 브라우저에 남는다).
        $middleware->encryptCookies(except: ['veh_cols', 'veh_mobile']);

        // NICE 게이트웨이(이식) — 외부 박스(heymanerp 등)가 CSRF 토큰 없이 POST. Django @csrf_exempt 동일.
        $middleware->validateCsrfTokens(except: ['provide/*', 'a/payout/*', 'sign/*']);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'admin-dashboard' => AdminDashboardMiddleware::class,
            'manage-users' => ManageUsersMiddleware::class,
            'approve' => ApproveMiddleware::class,
            'operation-logs' => OperationLogsMiddleware::class,
            'receivable' => ReceivableMiddleware::class,
            'super-admin' => SuperAdminMiddleware::class,
            'erp' => ErpMiddleware::class,
            'sales' => SalesMiddleware::class,
            'clearance' => ClearanceMiddleware::class,
            'settlement' => SettlementMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
