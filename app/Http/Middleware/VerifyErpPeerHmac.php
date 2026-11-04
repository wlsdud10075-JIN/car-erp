<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🔗 ERP ↔ ERP 읽기 채널(회사 서버끼리) — HMAC 검증 (jin 2026-11-04 «헤이맨 정산을 싼카로 가져오기»).
 *
 * 서명 방식은 board·포털과 같다(VerifyPortalReadHmac 와 같은 정규 문자열·같은 헤더 이름).
 * 다른 것 = **시크릿(ERP_PEER_HMAC_SECRET) · nonce 네임스페이스 · throttle**. board·포털 시크릿을 재사용하지 않는다 —
 * 그 채널의 전 API 면을 넘기게 되고 한쪽만 폐기할 수 없다(VerifyPortalReadHmac 머리 주석).
 *
 * 🔒 fail-closed — 시크릿이 비면 전부 401. 3사에 코드가 먼저 나가도 안전하고, .env 를 넣은 서버만 열린다.
 * 서명하는 쪽 = App\Services\Payout\HeymanPayoutClient(같은 시크릿).
 */
class VerifyErpPeerHmac
{
    private const WINDOW = 300;

    private const NONCE_PREFIX = 'erp_peer_nonce:';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.erp_peer.hmac_secret');
        if ($secret === '') {
            Log::warning('[erp-peer] ERP_PEER_HMAC_SECRET 미설정 — 거부');

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
        $expected = hash_hmac('sha256', self::canonical($request->getMethod(), $request->getPathInfo(), $request->query->all(), $ts, $request->getContent()), $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return $this->reject($request, 'signature');
        }

        return $next($request);
    }

    /** 서명 대상 — 받는 쪽(여기)과 보내는 쪽(HeymanPayoutClient)이 같은 함수를 쓴다(헤더·정규화가 갈리지 않게). */
    public static function canonical(string $method, string $path, array $query, int $ts, string $body = ''): string
    {
        ksort($query);

        return strtoupper($method)."\n".$path.'?'.http_build_query($query)."\n".$ts."\n".$body;
    }

    private function reject(Request $request, string $why): Response
    {
        Log::warning('[erp-peer] HMAC 거부 — 401', ['ip' => $request->ip(), 'why' => $why]);

        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        return response()->json(['error' => 'unauthorized', 'message' => 'Unauthorized'], 401);
    }
}
