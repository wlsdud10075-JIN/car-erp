<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * board 영업 포털 읽기 API — HMAC 검증 (purchase-sync 의 GET 버전).
 *
 * 계약 (권위 = docs/integration/board-portal-api.md §1):
 *   헤더     : X-Board-Signature: sha256=<hex> · X-Timestamp: <unix sec> · X-Nonce: <uuid>
 *   서명대상  : METHOD\nPATH?SORTED_QUERY\nTIMESTAMP\nBODY   (쿼리 ksort + http_build_query)
 *   replay   : |now - timestamp| ≤ 300초 + nonce 5분 캐시(재사용 거부)
 *   시크릿   : CAR_ERP_READ_HMAC_SECRET (쓰기 secret 과 분리). 미설정 → 전부 401.
 *
 * ⚠️ salesman_email 은 쿼리에 담겨 서명 대상에 포함 = board 가 위조 못 함.
 */
class VerifyBoardReadHmac
{
    private const WINDOW = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.board_read.hmac_secret');
        if ($secret === '') {
            Log::warning('[board-read] CAR_ERP_READ_HMAC_SECRET 미설정 — 거부');

            return response()->json(['error' => 'unauthorized', 'message' => 'Unauthorized'], 401);
        }

        // replay — timestamp 윈도우
        $ts = (int) $request->header('X-Timestamp', '0');
        if ($ts <= 0 || abs(now()->timestamp - $ts) > self::WINDOW) {
            return $this->reject($request, 'timestamp');
        }

        // replay — nonce 1회성 (Cache::add 가 false 면 이미 사용됨)
        $nonce = (string) $request->header('X-Nonce', '');
        if ($nonce === '' || ! Cache::add('board_read_nonce:'.$nonce, 1, self::WINDOW)) {
            return $this->reject($request, 'nonce');
        }

        // canonical string
        $query = $request->query->all();
        ksort($query);
        $canonical = $request->getMethod()."\n"
            .$request->getPathInfo().'?'.http_build_query($query)."\n"
            .$ts."\n"
            .$request->getContent();

        $expected = hash_hmac('sha256', $canonical, $secret);
        $header = (string) $request->header('X-Board-Signature', '');
        $provided = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return $this->reject($request, 'signature');
        }

        return $next($request);
    }

    private function reject(Request $request, string $why): Response
    {
        Log::warning('[board-read] HMAC 거부 — 401', ['ip' => $request->ip(), 'why' => $why]);

        return response()->json(['error' => 'unauthorized', 'message' => 'Unauthorized'], 401);
    }
}
