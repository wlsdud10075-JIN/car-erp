<?php

use App\Http\Middleware\AdminDashboardMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApproveMiddleware;
use App\Http\Middleware\ClearanceMiddleware;
use App\Http\Middleware\ErpMiddleware;
use App\Http\Middleware\ManageUsersMiddleware;
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

        // NICE 게이트웨이(이식) — 외부 박스(heymanerp 등)가 CSRF 토큰 없이 POST. Django @csrf_exempt 동일.
        $middleware->validateCsrfTokens(except: ['provide/*']);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'admin-dashboard' => AdminDashboardMiddleware::class,
            'manage-users' => ManageUsersMiddleware::class,
            'approve' => ApproveMiddleware::class,
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
