<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 큐 14-2 — /admin/dashboard read-only 게이트.
 *
 * 회의록 2026-05-14-management-role-dashboard.md §2: '관리' role은
 * /admin/dashboard KPI·차트만 조회 가능 (업무 파악). /admin/users·settings·기능 토글은 차단.
 *
 * 허용: super / admin / role='관리'.
 */
class AdminDashboardMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canViewAdminDashboard()) {
            abort(403, '관리자 대시보드 접근 권한이 없습니다.');
        }

        return $next($request);
    }
}
