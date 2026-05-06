<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canAccessAdmin()) {
            abort(403, '접근 권한이 없습니다.');
        }

        return $next($request);
    }
}
