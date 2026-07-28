<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 운영 로그 열람 게이트 (jin 2026-07-28).
 *
 * 대상: /admin/document-access-logs · /admin/audit-logs · /admin/mail-delivery-logs
 * 허용: 시스템관리자 · 최고관리자 · 업무관리자 · role='관리' (User::canViewOperationLogs()).
 *
 * ⚠️ 알림톡 로그·알림톡 안내는 이 게이트가 아니다 — 그 둘은 super 전용(SuperAdminMiddleware /
 *    컴포넌트 isSuperAdmin 가드)으로 계속 유지한다.
 */
class OperationLogsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canViewOperationLogs()) {
            abort(403, '운영 로그 열람 권한이 없습니다.');
        }

        return $next($request);
    }
}
