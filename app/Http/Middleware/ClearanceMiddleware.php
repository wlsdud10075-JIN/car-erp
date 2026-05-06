<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClearanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canAccessClearance()) {
            abort(403, '통관 데이터에 접근할 권한이 없습니다.');
        }

        return $next($request);
    }
}
