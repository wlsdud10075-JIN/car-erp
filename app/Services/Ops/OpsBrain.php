<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * 세컨드 브레인 — 허브(ssancarerp)에 올라온 목록 JSON 을 읽는다 (관제센터 3단계, jin 2026-11-12).
 * 파일 = storage/app/private/ops/brain.json (BrainCatalog 가 만든 것, `ops:brain-sync --push` 가 올림). 🔒 보는 사람 = 관제 담당자뿐(화면 게이트).
 * index() = 제목만(본문 제외, 10분 캐시 — 페이지마다 수 MB 를 안 읽게) · body() = 그 한 건의 본문만.
 */
class OpsBrain
{
    public const FILE = 'ops/brain.json';

    public const MAX_BYTES = 30_000_000;

    public static function store(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['clusters']) || ! is_array($data['clusters'])) {
            throw new \InvalidArgumentException('목록 JSON 이 아닙니다(clusters 없음)');
        }
        Storage::disk('local')->put(self::FILE, $json);
        Cache::forget('ops:brain:index');

        return ['clusters' => count($data['clusters']), 'items' => array_sum(array_map(fn ($c) => count($c['items'] ?? []), $data['clusters'])), 'bytes' => strlen($json)];
    }

    /** @return array{generated_at: ?string, repo_head: ?string, clusters: array<string, array>}|null */
    public static function index(): ?array
    {
        return Cache::remember('ops:brain:index', 600, function () {
            $data = self::read();
            if (! $data) {
                return null;
            }
            foreach ($data['clusters'] as $k => $c) {
                $data['clusters'][$k]['items'] = array_map(fn ($i) => array_diff_key($i, ['body' => 1]), $c['items'] ?? []);
            }

            return $data;
        }) ?: null;
    }

    public static function body(string $cluster, string $id): ?array
    {
        $data = self::read();
        foreach ($data['clusters'][$cluster]['items'] ?? [] as $i) {
            if (($i['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    private static function read(): ?array
    {
        if (! Storage::disk('local')->exists(self::FILE)) {
            return null;
        }
        $data = json_decode((string) Storage::disk('local')->get(self::FILE), true);

        return is_array($data) && isset($data['clusters']) ? $data : null;
    }
}
