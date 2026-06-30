<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /admin/users 진입 게이트 — super/admin + 관리(본인 팀 영업만).
 * 실제 스코프·escalation 차단은 컴포넌트(canManageUserAccount)에서 매 액션 재검증.
 */
class ManageUsersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canManageUsers()) {
            abort(403, '접근 권한이 없습니다.');
        }

        return $next($request);
    }
}
