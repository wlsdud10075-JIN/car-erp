<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 큐 14-2 보강 — /erp/receivables 접근 게이트.
 *
 * 허용: super / admin / role='정산' / role='관리'.
 * 회의록 14 §누락 보강 + CLAUDE.md 9단계 TODO 활성화.
 */
class ReceivableMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canViewReceivables()) {
            abort(403, '채권관리 접근 권한이 없습니다.');
        }

        return $next($request);
    }
}
