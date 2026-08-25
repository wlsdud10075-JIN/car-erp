<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ssancar.com 바이어 포털 읽기 API — HMAC 검증.
 *
 * 🔑 **board 채널을 재사용하지 않는 이유**(ERP 가 v1.2 Q6 에서 직접 못 박은 것):
 *   ① board 는 시크릿이 하나고 호출자 구분이 `salesman_email` **쿼리 파라미터**뿐이다.
 *      호출자가 스스로 채우는 값이라, 같은 시크릿을 ssancar 에 주면 **board 의 전 API 면을
 *      통째로 넘기게 되고 한쪽만 폐기할 방법이 없다.**
 *   ② 인가 축이 다르다 — board 는 영업 스코프, 포털은 **바이어 축**이라
 *      `salesman_email` 이 무의미하고 그 파라미터를 키로 쓰는 throttle 도 무력해진다.
 *
 * ⇒ **서명 방식은 board 와 동일**(ssancar 요청 v1.0 §1 — "새로 만들지 말아 달라").
 *    다른 것은 **시크릿 · nonce 네임스페이스 · throttle 키 · 스코프** 넷뿐이다.
 *
 * 계약:
 *   헤더     : X-Board-Signature: sha256=<hex> · X-Timestamp: <unix sec> · X-Nonce
 *   서명대상  : METHOD\nPATH?SORTED_QUERY\nTIMESTAMP\nBODY   (쿼리 ksort + http_build_query)
 *   replay   : |now - timestamp| ≤ 300초 + nonce 300초 캐시(재사용 거부)
 *   시크릿   : SSANCAR_PORTAL_HMAC_SECRET — **미설정이면 전부 401**(fail-closed).
 *
 * 🔒 fail-closed 라서 **시크릿을 넣기 전에 배포해도 안전**하다. 3사에 코드가 먼저 나가고
 *    jin 이 그 회사 시크릿을 넣는 순간 그 회사만 열린다.
 *
 * ⚠️ nonce 접두사를 board 와 공유하면 **한쪽 채널의 nonce 가 다른 쪽을 막는다.** 따로 쓴다.
 */
class VerifyPortalReadHmac
{
    private const WINDOW = 300;

    private const NONCE_PREFIX = 'portal_read_nonce:';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.ssancar_portal.hmac_secret');
        if ($secret === '') {
            Log::warning('[portal-read] SSANCAR_PORTAL_HMAC_SECRET 미설정 — 거부');

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

        $query = $request->query->all();
        ksort($query);
        $canonical = $request->getMethod()."\n"
            .$request->getPathInfo().'?'.http_build_query($query)."\n"
            .$ts."\n"
            .$request->getContent();

        $provided = (string) $request->header('X-Board-Signature', '');
        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;

        if ($provided === '' || ! hash_equals(hash_hmac('sha256', $canonical, $secret), $provided)) {
            return $this->reject($request, 'signature');
        }

        return $next($request);
    }

    private function reject(Request $request, string $why): Response
    {
        Log::warning('[portal-read] HMAC 거부 — 401', ['ip' => $request->ip(), 'why' => $why]);

        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        return response()->json(['error' => 'unauthorized', 'message' => 'Unauthorized'], 401);
    }
}
