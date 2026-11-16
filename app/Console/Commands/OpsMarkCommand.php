<?php

namespace App\Console\Commands;

use App\Models\OpsMark;
use App\Services\Ops\OpsSignature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 관제센터 「이 루틴을 했다」 기록 (jin 2026-11-16).
 *   php artisan ops:mark nightly-investigate                    → 이 서버 DB 에 기록(GPU PC 가 heymanerp 에 SSH 로 부른다)
 *   php artisan ops:mark scene-check --push=https://heymancar.com  → 허브에 서명 POST(개발 PC — 씬 점검 스킬 끝에서). 시크릿 = OPS_HMAC_SECRET
 *   --status=failed --note="…" 로 실패·메모를 남긴다.
 */
class OpsMarkCommand extends Command
{
    protected $signature = 'ops:mark {key : 루틴 키(OpsRoutines — 예 scene-check)} {--status=ok : ok | failed | todo} {--note= : 메모(500자)} {--push= : 허브 주소. 없으면 이 서버 DB 에}';

    protected $description = '관제센터 루틴 완료 기록(사람 루틴·서버 밖 루틴)';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $status = (string) $this->option('status');
        $note = $this->option('note') !== null ? mb_substr((string) $this->option('note'), 0, 500) : null;
        if (! OpsMark::validKey($key) || ! in_array($status, OpsMark::STATUSES, true)) {
            $this->error('키는 영소문자·숫자·하이픈, status 는 ok|failed|todo 입니다.');

            return self::FAILURE;
        }

        $push = (string) $this->option('push');
        if ($push === '') {
            OpsMark::create(['key' => $key, 'status' => $status, 'note' => $note, 'source' => 'command', 'created_at' => now()]);
            $this->info("기록했습니다: {$key} {$status}");

            return self::SUCCESS;
        }

        $secret = (string) config('services.ops.hmac_secret');
        if ($secret === '') {
            $this->error('OPS_HMAC_SECRET 이 이 PC .env 에 없습니다(허브와 같은 값).');

            return self::FAILURE;
        }
        $body = json_encode(['key' => $key, 'status' => $status, 'note' => $note], JSON_UNESCAPED_UNICODE);
        $path = '/api/internal/ops/mark';
        $res = Http::timeout(20)->withHeaders(OpsSignature::headers('POST', $path, [], $body, $secret) + ['Content-Type' => 'application/json'])
            ->withBody($body, 'application/json')->post(rtrim($push, '/').$path);
        if (! $res->successful()) {
            $this->error('올리기 실패 HTTP '.$res->status().' '.mb_substr($res->body(), 0, 200));

            return self::FAILURE;
        }
        $this->info("허브에 기록했습니다: {$key} {$status}");

        return self::SUCCESS;
    }
}
