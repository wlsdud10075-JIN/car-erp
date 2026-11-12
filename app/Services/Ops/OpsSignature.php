<?php

namespace App\Services\Ops;

use Illuminate\Support\Str;

/**
 * 관제 채널 서명 — v2 = nonce 가 서명 안에 들어간다 (jin 2026-11-12 결정, ssancar 세션 지적: v1 은 ±300초 안에 nonce 만 바꾼 재전송이 가능했다).
 *   v2 canonical = METHOD\npath?query\nts\nnonce\nbody
 * (v1 = nonce 밖 canonical 은 11-12 전환 당일 세 곳 모두 v2 로 넘어가 지웠다. VerifyErpPeerHmac::canonical 은 정산·포털 채널용으로 남아 있다.)
 */
class OpsSignature
{
    public static function canonicalV2(string $method, string $path, array $query, int $ts, string $nonce, string $body = ''): string
    {
        ksort($query);

        return strtoupper($method)."\n".$path.'?'.http_build_query($query)."\n".$ts."\n".$nonce."\n".$body;
    }

    /** @return array{X-Timestamp: string, X-Nonce: string, X-Board-Signature: string} */
    public static function headers(string $method, string $path, array $query, string $body, string $secret): array
    {
        $ts = now()->timestamp;
        $nonce = (string) Str::uuid();
        $canonical = self::canonicalV2($method, $path, $query, $ts, $nonce, $body);

        return ['X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $secret)];
    }
}
