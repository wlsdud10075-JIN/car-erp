<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 큐 14-3 — 승인 권한 게이트.
 *
 * 회의록 §4 14-3 합의안: /erp/approvals 라우트 보호.
 * 허용: super / admin / role='관리' (User::canApprove()).
 */
class ApproveMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canApprove()) {
            abort(403, '승인 권한이 없습니다.');
        }

        return $next($request);
    }
}
