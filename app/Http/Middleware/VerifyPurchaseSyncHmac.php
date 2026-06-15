<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 연동 B 수신 — board 발신 요청의 HMAC 서명 검증.
 *
 * 계약 (board SKILLS §12 ↔ docs/integration/purchase-sync-receiver.md):
 *   헤더  : X-Board-Signature: sha256=<hex>
 *   서명대상: 수신 raw body 그대로 ($request->getContent())
 *   검증  : hash_hmac('sha256', $rawBody, $secret) 와 hash_equals(타이밍-세이프) 비교.
 *
 * ⚠️ 절대 $request->all() 재직렬화로 재계산하지 말 것 — 바이트가 달라져 불일치.
 *    세션/CSRF 없는 순수 API 라우트. 불일치·시크릿 미설정 → 401.
 */
class VerifyPurchaseSyncHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.purchase_sync.hmac_secret');

        // 안전밸브: 시크릿 미설정이면 인증 자체가 불가 → 전부 거부.
        if ($secret === '') {
            Log::warning('[purchase-sync] CAR_ERP_HMAC_SECRET 미설정 — 수신 거부');

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $header = (string) $request->header('X-Board-Signature', '');
        $provided = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning('[purchase-sync] HMAC 서명 불일치 — 401', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
