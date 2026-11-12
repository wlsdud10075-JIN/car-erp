<?php

namespace App\Services\Ops;

use App\Console\Commands\AssistantHealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 관제센터 — 사이트 7칸의 요약을 모은다(jin 2026-11-12). 이 서버(허브, ssancarerp)는 직접 계산하고,
 * 나머지는 OPS_SITES(.env JSON)에 적힌 주소의 요약 API 를 서명해서 읽는다.
 *
 * ⏱️ 한 곳이 느리거나 죽어도 페이지가 멈추지 않게 — 사이트마다 timeout 4초, 결과는 5분 캐시(실패도 1분 캐시해 매번 4초씩 기다리지 않게).
 * 🔏 서명 = OpsSignature v2(nonce 포함). 11-12 전환 당일 세 곳 모두 v2 가 되어 v1 폴백은 지웠다.
 * 🖥️ url 없는 항목(회사 GPU PC) = `"probe":"assistant"` 면 assistant:health-check(5분마다 Ollama 100.110.133.112 핑)가 캐시에 적어 둔 판정을 보여 준다 — 그 PC 에 API 를 두지 않고도 「살아 있나」가 보인다.
 */
class OpsHub
{
    public const PATH = '/api/internal/ops/summary';

    public const CACHE_SECONDS = 300;

    /**
     * 사이트 목록 — config('services.ops.sites') = [{"key","name","kind":"erp|board|etc","url"|"self":true,"path"(기본 PATH),"host","notes":[…],"ssh","probe":"assistant"}, …]
     *   kind etc = GPU PC·ssancar.com 처럼 ERP/board 가 아닌 것. url 없는 항목은 메모(+probe 가 있으면 그 판정)만 보여 준다.
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
            return ($site['probe'] ?? '') === 'assistant'
                ? self::assistantProbe($at)
                : ['ok' => false, 'data' => null, 'error' => '요약 API 없음 — 메모만', 'fetched_at' => $at];
        }
        $secret = (string) config('services.ops.hmac_secret');
        if ($secret === '') {
            return ['ok' => false, 'data' => null, 'error' => 'OPS_HMAC_SECRET 미설정', 'fetched_at' => $at];
        }
        $path = (string) ($site['path'] ?? self::PATH);
        $url = rtrim((string) $site['url'], '/').$path;
        try {
            $res = Http::timeout(4)->connectTimeout(3)->acceptJson()
                ->withHeaders(OpsSignature::headers('GET', $path, [], '', $secret))->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => null, 'error' => '응답 없음 — '.mb_substr($e->getMessage(), 0, 120), 'fetched_at' => $at];
        }
        if ($res->status() === 404) {
            return ['ok' => false, 'data' => null, 'error' => '미연결(요약 API 없음)', 'fetched_at' => $at];
        }
        if ($res->status() === 401) {
            return ['ok' => false, 'data' => null, 'error' => '인증 거부 — 두 서버 OPS_HMAC_SECRET 확인(서명 v2)', 'fetched_at' => $at];
        }
        if (! $res->successful() || ! is_array($res->json('server'))) {
            return ['ok' => false, 'data' => null, 'error' => 'HTTP '.$res->status(), 'fetched_at' => $at];
        }

        return ['ok' => true, 'data' => $res->json(), 'error' => null, 'fetched_at' => $at];
    }

    /** 회사 GPU PC — assistant:health-check 캐시(ollama_ok·ollama_down_since·index_mtime·checked_at). 캐시가 없으면 감시 자체가 안 도는 것. */
    private static function assistantProbe(string $at): array
    {
        $h = Cache::get(AssistantHealthCheck::CACHE_KEY);
        if (! is_array($h) || ! isset($h['checked_at'])) {
            return ['ok' => false, 'data' => null, 'error' => '감시 기록 없음 — assistant:health-check 가 안 돌았거나 ASSISTANT_ENABLED 꺼짐', 'fetched_at' => $at];
        }
        $checked = (int) $h['checked_at'];
        $ollamaOk = (bool) ($h['ollama_ok'] ?? false);
        $downSince = $h['ollama_down_since'] ?? null;
        $indexAge = isset($h['index_mtime']) && $h['index_mtime'] ? round((time() - (int) $h['index_mtime']) / 3600, 1) : null;
        $anomalies = [];
        if (! $ollamaOk && $downSince && time() - (int) $downSince >= AssistantHealthCheck::OLLAMA_GRACE_SECONDS) {
            $anomalies[] = ['level' => 2, 'kind' => 'GPU PC 응답 없음', 'count' => 1, 'text' => 'Ollama(100.110.133.112:11434) '.date('m/d H:i', (int) $downSince).' 부터 응답 없음 — PC 꺼짐·Tailscale 끊김부터'];
        }

        return ['ok' => true, 'error' => null, 'fetched_at' => $at, 'data' => [
            'generated_at' => date('c', $checked), 'company' => 'gpu',
            'server' => ['version' => null, 'errors_today' => null, 'pending_migrations' => null, 'disk_used_pct' => null],
            'routines' => [], 'links' => [],
            'health' => [
                ['key' => 'ollama', 'ok' => $ollamaOk, 'detail' => 'Ollama 응답 '.($ollamaOk ? '정상' : '없음').' · 확인 '.date('m/d H:i', $checked)],
                ['key' => 'assistant_index', 'ok' => $indexAge !== null && $indexAge <= AssistantHealthCheck::INDEX_STALE_HOURS, 'detail' => $indexAge === null ? '색인 파일 없음' : "색인 {$indexAge}시간 전(챗봇 꺼둔 동안은 오래돼도 정상)"],
            ],
            'anomalies' => $anomalies,
        ]];
    }
}
