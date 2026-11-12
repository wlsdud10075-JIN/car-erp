<?php

namespace App\Services\Ops;

use App\Http\Middleware\VerifyErpPeerHmac;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 관제센터 — 사이트 6칸의 요약을 모은다(jin 2026-11-12). 이 서버(허브, ssancarerp)는 직접 계산하고,
 * 나머지는 OPS_SITES(.env JSON)에 적힌 주소의 `/api/internal/ops/summary` 를 서명해서 읽는다.
 *
 * ⏱️ 한 곳이 느리거나 죽어도 페이지가 멈추지 않게 — 사이트마다 timeout 4초, 결과는 5분 캐시(실패도 1분 캐시해 매번 4초씩 기다리지 않게).
 * 🧩 board 는 같은 경로·같은 서명으로 board 세션이 붙이면 저절로 들어온다. 아직이면 「미연결」.
 */
class OpsHub
{
    public const PATH = '/api/internal/ops/summary';

    public const CACHE_SECONDS = 300;

    /**
     * 사이트 목록 — config('services.ops.sites') = [{"key":"heyman-erp","name":"heymanerp","kind":"erp","url":"https://heysellcar.com"}, …]
     * 허브 자신은 url 없이 "self":true.
     *
     * @return list<array>
     */
    public static function sites(): array
    {
        $raw = config('services.ops.sites');
        $list = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($list) ? array_values(array_filter($list, fn ($s) => is_array($s) && isset($s['key'], $s['name']))) : [];
    }

    /** @return list<array{site: array, ok: bool, data: ?array, error: ?string, fetched_at: string}> */
    public static function collect(bool $fresh = false): array
    {
        $out = [];
        foreach (self::sites() as $site) {
            $key = 'ops:site:'.$site['key'];
            if ($fresh) {
                Cache::forget($key);
            }
            $hit = Cache::get($key);
            if ($hit === null) {
                $hit = self::fetch($site);
                Cache::put($key, $hit, $hit['ok'] ? self::CACHE_SECONDS : 60);
            }
            $out[] = ['site' => $site] + $hit;
        }

        return $out;
    }

    private static function fetch(array $site): array
    {
        $at = now()->toIso8601String();
        if (! empty($site['self'])) {
            return ['ok' => true, 'data' => OpsSummary::collect(), 'error' => null, 'fetched_at' => $at];
        }
        if (empty($site['url'])) {
            return ['ok' => false, 'data' => null, 'error' => '미설치', 'fetched_at' => $at];
        }
        $secret = (string) config('services.ops.hmac_secret');
        if ($secret === '') {
            return ['ok' => false, 'data' => null, 'error' => 'OPS_HMAC_SECRET 미설정', 'fetched_at' => $at];
        }
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', VerifyErpPeerHmac::canonical('GET', self::PATH, [], $ts), $secret);
        try {
            $res = Http::timeout(4)->connectTimeout(3)->acceptJson()->withHeaders([
                'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(), 'X-Board-Signature' => 'sha256='.$sig,
            ])->get(rtrim((string) $site['url'], '/').self::PATH);
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => null, 'error' => '응답 없음 — '.mb_substr($e->getMessage(), 0, 120), 'fetched_at' => $at];
        }
        if ($res->status() === 404) {
            return ['ok' => false, 'data' => null, 'error' => '미연결(요약 API 없음)', 'fetched_at' => $at];
        }
        if ($res->status() === 401) {
            return ['ok' => false, 'data' => null, 'error' => '인증 거부 — 두 서버 OPS_HMAC_SECRET 확인', 'fetched_at' => $at];
        }
        if (! $res->successful() || ! is_array($res->json('server'))) {
            return ['ok' => false, 'data' => null, 'error' => 'HTTP '.$res->status(), 'fetched_at' => $at];
        }

        return ['ok' => true, 'data' => $res->json(), 'error' => null, 'fetched_at' => $at];
    }
}
