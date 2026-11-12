<?php

namespace App\Http\Middleware;

use App\Services\Ops\OpsSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🛰️ 관제센터 읽기 채널 — HMAC 검증 (jin 2026-11-12 «관제센터는 오직 나만»).
 *
 * 서명 = OpsSignature v2(nonce 포함, jin 11-12 결정). 전환 기간엔 v1(VerifyErpPeerHmac::canonical — nonce 밖)도 받는다 → 상대가 전부 v2 가 되면 v1 분기 삭제.
 * 다른 채널과 다른 것 = **시크릿(OPS_HMAC_SECRET)·nonce 네임스페이스**. 정산 가져오기 시크릿을 재사용하지 않는다.
 * 🔒 fail-closed — 시크릿이 비면 전부 401. 순서 = 시크릿 → ts → nonce → 서명.
 * 서명하는 쪽 = OpsHub(사이트 읽기) · OpsBrainSync(세컨드 브레인 올리기).
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
        $method = $request->getMethod();
        $path = $request->getPathInfo();
        $query = $request->query->all();
        $body = $request->getContent();
        $v2 = hash_hmac('sha256', OpsSignature::canonicalV2($method, $path, $query, $ts, $nonce, $body), $secret);
        $v1 = hash_hmac('sha256', VerifyErpPeerHmac::canonical($method, $path, $query, $ts, $body), $secret);   // 전환 기간 — 삭제 예정
        if ($provided === '' || (! hash_equals($v2, $provided) && ! hash_equals($v1, $provided))) {
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
