<?php

namespace App\Console\Commands;

use App\Http\Middleware\VerifyErpPeerHmac;
use App\Services\Ops\BrainCatalog;
use App\Services\Ops\OpsBrain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 세컨드 브레인 목록 만들기·올리기 — 개발 PC 에서 돈다 (관제센터 3단계, jin 2026-11-12).
 *   php artisan ops:brain-sync                       → storage/app/private/ops/brain.json 에 만들기만(로컬 화면에서 확인)
 *   php artisan ops:brain-sync --push=https://heymancar.com   → 만들고 허브에 서명 POST(gzip). 시크릿 = 이 PC .env 의 OPS_HMAC_SECRET(허브와 같은 값)
 * 메모리 폴더 기본값 = ~/.claude/projects/<레포 경로 슬러그>/memory (이 PC 에만 있다). --memory 로 바꾼다.
 */
class OpsBrainSync extends Command
{
    protected $signature = 'ops:brain-sync {--push= : 허브 주소(예 https://heymancar.com). 없으면 로컬 저장만} {--memory= : 메모리 폴더(기본 ~/.claude/projects/<슬러그>/memory)}';

    protected $description = '관제센터 세컨드 브레인 목록(교훈·메모리·문서·테스트)을 만들고 허브에 올린다';

    public function handle(): int
    {
        $memory = (string) ($this->option('memory') ?: self::defaultMemoryDir());
        if (! is_dir($memory)) {
            $this->warn("메모리 폴더 없음 — 메모리 덩어리는 비웁니다: {$memory}");
            $memory = '';
        }
        $catalog = BrainCatalog::build(base_path(), $memory ?: null);
        $json = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($catalog['clusters'] as $c) {
            $this->line(sprintf('  %-8s %4d건', $c['key'], $c['count']));
        }
        $this->line('총 '.number_format(strlen($json)).' bytes');

        $push = (string) $this->option('push');
        if ($push === '') {
            $r = OpsBrain::store($json);
            $this->info('로컬 저장: '.$r['items'].'건 → storage/app/private/'.OpsBrain::FILE);

            return self::SUCCESS;
        }

        $secret = (string) config('services.ops.hmac_secret');
        if ($secret === '') {
            $this->error('OPS_HMAC_SECRET 이 이 PC .env 에 없습니다(허브와 같은 값).');

            return self::FAILURE;
        }
        $body = gzencode($json, 6);
        $path = '/api/internal/ops/brain';
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', VerifyErpPeerHmac::canonical('POST', $path, [], $ts, $body), $secret);
        $res = Http::timeout(60)->withHeaders([
            'X-Timestamp' => (string) $ts, 'X-Nonce' => (string) Str::uuid(), 'X-Board-Signature' => 'sha256='.$sig,
            'Content-Type' => 'application/gzip',
        ])->withBody($body, 'application/gzip')->post(rtrim($push, '/').$path);
        if (! $res->successful()) {
            $this->error('올리기 실패 HTTP '.$res->status().' '.mb_substr($res->body(), 0, 200));

            return self::FAILURE;
        }
        $this->info('올렸습니다: '.json_encode($res->json(), JSON_UNESCAPED_UNICODE).' (gzip '.number_format(strlen($body)).' bytes)');

        return self::SUCCESS;
    }

    public static function defaultMemoryDir(): string
    {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        $slug = preg_replace('/[^A-Za-z0-9]/', '-', base_path());   // C:/xampp/htdocs/car-erp → C--xampp-htdocs-car-erp (Claude Code 와 같은 규칙 — 글자마다 -)

        return rtrim($home, '/\\').'/.claude/projects/'.$slug.'/memory';
    }
}
