<?php

use App\Http\Middleware\AdminDashboardMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApproveMiddleware;
use App\Http\Middleware\ClearanceMiddleware;
use App\Http\Middleware\ErpMiddleware;
use App\Http\Middleware\SalesMiddleware;
use App\Http\Middleware\SettlementMiddleware;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'admin-dashboard' => AdminDashboardMiddleware::class,
            'approve' => ApproveMiddleware::class,
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
