<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🛰️ 관제센터 읽기 채널 — HMAC 검증 (jin 2026-11-12 «관제센터는 오직 나만»).
 *
 * 서명 규칙은 ERP 끼리 채널과 같다(VerifyErpPeerHmac::canonical, 같은 헤더). 다른 것 = **시크릿(OPS_HMAC_SECRET)·nonce 네임스페이스**.
 *   정산 가져오기 시크릿을 재사용하지 않는다 — 한쪽만 폐기할 수 없게 되고 관제 면이 정산 면을 넘보게 된다.
 * 🔒 fail-closed — 시크릿이 비면 전부 401. 코드가 먼저 나가도 .env 를 넣은 서버만 열린다.
 * 서명하는 쪽 = App\Services\Ops\OpsHub(같은 시크릿).
 */
class VerifyOpsHmac
{
    private const WINDOW = 300;

    private const NONCE_PREFIX = 'ops_nonce:';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.ops.hmac_secret');
        if ($secret === '') {
            return $this->unauthorized();
        }
        $ts = (int) $request->header('X-Timestamp', '0');
        if ($ts <= 0 || abs(now()->timestamp - $ts) > self::WINDOW) {
            return $this->reject($request, 'timestamp');
        }
        $nonce = (string) $request->header('X-Nonce', '');
        if ($nonce === '' || ! Cache::add(self::NONCE_PREFIX.$nonce, 1, self::WINDOW)) {
            return $this->reject($request, 'nonce');
        }
        $provided = (string) $request->header('X-Board-Signature', '');
        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        $expected = hash_hmac('sha256', VerifyErpPeerHmac::canonical($request->getMethod(), $request->getPathInfo(), $request->query->all(), $ts, $request->getContent()), $secret);
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return $this->reject($request, 'signature');
        }

        return $next($request);
    }

    private function reject(Request $request, string $why): Response
    {
        Log::warning('[ops] HMAC 거부 — 401', ['ip' => $request->ip(), 'why' => $why]);

        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }
}
