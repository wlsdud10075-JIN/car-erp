<?php

namespace App\Services\Ops;

use App\Http\Middleware\VerifyErpPeerHmac;
use Illuminate\Support\Str;

/**
 * 관제 채널 서명 — v2 = nonce 가 서명 안에 들어간다 (jin 2026-11-12 결정, ssancar 세션 지적: v1 은 ±300초 안에 nonce 만 바꾼 재전송이 가능했다).
 *   v2 canonical = METHOD\npath?query\nts\nnonce\nbody
 *   v1 canonical = METHOD\npath?query\nts\nbody        (VerifyErpPeerHmac::canonical — 전환 기간에만 받는다)
 * 받는 쪽(VerifyOpsHmac)은 v2 를 먼저 대조하고 안 맞으면 v1. 보내는 쪽(OpsHub·OpsBrainSync)은 v2, 상대가 아직 v1 이면 401 뒤 한 번 v1 로 다시.
 * 🗓️ board·ssancar.com 이 v2 를 받게 된 뒤 v1 분기를 지운다(ops-control-center.md §4).
 */
class OpsSignature
{
    public static function canonicalV2(string $method, string $path, array $query, int $ts, string $nonce, string $body = ''): string
    {
        ksort($query);

        return strtoupper($method)."\n".$path.'?'.http_build_query($query)."\n".$ts."\n".$nonce."\n".$body;
    }

    /** @return array{X-Timestamp: string, X-Nonce: string, X-Board-Signature: string} */
    public static function headers(string $method, string $path, array $query, string $body, string $secret, int $version = 2): array
    {
        $ts = now()->timestamp;
        $nonce = (string) Str::uuid();
        $canonical = $version === 2
            ? self::canonicalV2($method, $path, $query, $ts, $nonce, $body)
            : VerifyErpPeerHmac::canonical($method, $path, $query, $ts, $body);

        return ['X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $secret)];
    }
}
