<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 챗봇 호스트(사내 GPU PC) 감시 — 2026-07-30, 색인 세션 요청.
 *
 * 사내 GPU PC(gpu-office, Tailscale 100.110.133.112)가 추론(Ollama)과 색인을 모두 담당하는데,
 * 그 PC 는 자기가 죽었다고 알릴 통로가 없다. 죽어도 챗봇은 **에러를 내지 않고 옛 답변만 계속 낸다** —
 * 그래서 아무도 모른다. 서버 쪽에서 두 지표로 감지한다.
 *
 *   ① 추론  : Ollama /api/tags 가 200 인가.
 *             ⚠️ 재부팅 직후 약 2분은 정상적으로 실패한다(일요일 04:00 통제 재부팅 + 윈도우 업데이트).
 *             그래서 **연속 5분 이상** 실패할 때만 이상으로 본다.
 *   ② 신선도: index-erp.json 의 mtime. 매일 03:00 갱신이므로 36시간 넘으면 이상.
 *             색인이 멈추는 건 의도된 동작일 때도 있다(Notion 에 마커 없는 페이지 → fail-closed).
 *             어느 쪽이든 사람이 알아야 하므로 똑같이 알린다.
 *
 * 결과는 캐시에 담고 사이드바(시스템관리자 전용)가 읽어 표시한다.
 * ⚠️ **TTL 을 두는 게 핵심**이다. forever 로 쓰면 스케줄러 자체가 죽었을 때 마지막 정상 상태가
 *    영원히 초록으로 남는다. 만료되면 화면이 "감시 미작동"으로 분기한다.
 *
 * ※ mtime 비교는 epoch(절대초) 기준이라 서버 시간대와 무관하다.
 *   (운영 서버는 Etc/UTC 라 date 가 KST-9h 로 보이지만 NTP 동기화 정상 — 2026-07-30 3사 실측.)
 */
class AssistantHealthCheck extends Command
{
    protected $signature = 'assistant:health-check';

    protected $description = '챗봇 호스트(Ollama 추론 + 색인 신선도) 상태를 점검해 캐시에 기록';

    /** 사이드바가 읽는 키. 없으면(=만료) 감시가 안 돌고 있다는 뜻이다. */
    public const CACHE_KEY = 'assistant.health';

    /** 스케줄 5분 주기의 6배 — 몇 번 걸러도 살아남되, 스케줄러가 죽으면 30분 안에 드러난다. */
    public const CACHE_TTL = 1800;

    /** Ollama 연속 실패를 이상으로 볼 최소 시간. 재부팅 복구가 약 1분 50초라 5분이면 오탐이 없다. */
    public const OLLAMA_GRACE_SECONDS = 300;

    /** 색인 무결성 검사 결과 캐시. mtime 이 같으면 재파싱하지 않는다. */
    private const AUDIT_KEY = 'assistant.health.index_audit';

    private const DOWN_SINCE_KEY = 'assistant.health.ollama_down_since';

    /** 색인 정체 판정 시간. 매일 03:00 갱신이므로 하루를 건너뛰어도 36시간이면 확실히 이상이다. */
    public const INDEX_STALE_HOURS = 36;

    public function handle(): int
    {
        // 챗봇 인프라가 없는 환경(로컬·미설정 서버)에서는 감시할 것이 없다.
        // ⚠️ 회사별 on/off(Setting 'assistant_enabled')로는 막지 않는다 — 토글이 꺼져 있어도
        //    색인은 계속 배포되므로, 신선도 감시는 살아 있어야 한다.
        if (! config('assistant.enabled')) {
            $this->info('assistant 비활성 환경 — skip');

            return self::SUCCESS;
        }

        $now = time();
        $problems = [];

        // ── ① 추론 (Ollama) ────────────────────────────────
        $ollamaOk = $this->pingOllama();
        $downSince = Cache::get(self::DOWN_SINCE_KEY);

        if ($ollamaOk) {
            Cache::forget(self::DOWN_SINCE_KEY);
            $downSince = null;
        } else {
            $downSince ??= $now;
            Cache::put(self::DOWN_SINCE_KEY, $downSince, 86400);
            $downFor = $now - $downSince;
            if ($downFor >= self::OLLAMA_GRACE_SECONDS) {
                $problems[] = sprintf('챗봇 추론 서버(Ollama) 응답 없음 — %d분째', intdiv($downFor, 60));
            }
        }

        // ── ② 색인 신선도 ──────────────────────────────────
        $path = (string) config('assistant.index_path');
        $mtime = ($path !== '' && is_file($path)) ? filemtime($path) : null;

        if ($mtime === null) {
            $problems[] = '챗봇 색인 파일이 없음'.($path !== '' ? " ({$path})" : ' (경로 미설정)');
        } else {
            $ageHours = ($now - $mtime) / 3600;
            if ($ageHours >= self::INDEX_STALE_HOURS) {
                $problems[] = sprintf('챗봇 색인이 %d시간째 그대로 — 색인이 멈췄거나 Notion 마커 오류로 중단됐을 수 있음', (int) $ageHours);
            }

            // ── ③ 색인 등급 표기 무결성 ────────────────────
            // 색인 파일이 바뀐 날에만 검사한다(2.6MB 파싱이라 5분마다 돌릴 일이 아니다).
            foreach ($this->auditIndexAudiences($path, $mtime) as $p) {
                $problems[] = $p;
            }
        }

        Cache::put(self::CACHE_KEY, [
            'checked_at' => $now,
            'ollama_ok' => $ollamaOk,
            'ollama_down_since' => $downSince,
            'index_mtime' => $mtime,
            'problems' => $problems,
        ], self::CACHE_TTL);

        if ($problems) {
            // 화면을 안 보는 시간대를 대비해 로그에도 남긴다(알림 수단이 아니라 사후 추적용).
            Log::warning('[assistant:health-check] '.implode(' / ', $problems));
            foreach ($problems as $p) {
                $this->warn('⚠️ '.$p);
            }

            return self::SUCCESS;   // 이상은 "알릴 상태"이지 커맨드 실패가 아니다(cron 이 계속 돌아야 한다).
        }

        $this->info(sprintf('정상 — Ollama OK, 색인 %.1f시간 전', $mtime ? ($now - $mtime) / 3600 : 0));

        return self::SUCCESS;
    }

    /**
     * 색인 등급 표기 무결성 — 색인 세션이 매번 수동으로 보던 "청크 수 == audience 수" 를 자동화한다.
     *
     * 🚨 이게 가장 조용한 사고다. `AssistantService::guide()` 는
     *   - **전량 미표기**면 구 색인으로 보고 전 청크를 staff 취급한다 → 영업이 대표·시스템 자료를 검색하게 된다.
     *   - **부분 표기**면 미표기 청크를 통째로 버린다 → 챗봇이 "자료가 없다"고만 답한다.
     *   둘 다 예외도 로그도 없이 벌어진다.
     *
     * 판정 기준은 `guide()` 와 같은 순서(스코프 필터 → 등급 확인)로 맞춘다. 실제로 챗봇이 쓰는 집합만 본다.
     * 2.6MB 파싱이라 **mtime 이 바뀐 경우에만** 다시 검사하고, 결과는 캐시에 남겨 매 실행마다 재사용한다.
     *
     * @return string[]
     */
    private function auditIndexAudiences(string $path, int $mtime): array
    {
        $cached = Cache::get(self::AUDIT_KEY);
        if (is_array($cached) && ($cached['mtime'] ?? null) === $mtime) {
            return $cached['problems'];
        }

        $problems = [];
        $kb = json_decode((string) file_get_contents($path), true);

        if (! is_array($kb)) {
            $problems[] = '챗봇 색인 파일을 읽을 수 없음 (JSON 손상)';
        } else {
            // guide() 와 동일하게 스코프를 먼저 적용한다 — board 청크는 ERP 챗봇이 쓰지 않는다.
            $scope = (string) config('assistant.index_scope');
            if ($scope !== '') {
                $kb = array_values(array_filter($kb, fn ($d) => mb_strpos((string) ($d['source'] ?? ''), $scope) !== false));
            }

            $total = count($kb);
            $tagged = 0;
            $invalid = 0;
            foreach ($kb as $doc) {
                if (! array_key_exists('audience', $doc)) {
                    continue;
                }
                $tagged++;
                $required = is_array($doc['audience']) ? $doc['audience'] : [$doc['audience']];
                if (! $required || array_diff($required, AssistantService::RAG_AUDIENCES)) {
                    $invalid++;
                }
            }

            if ($total === 0) {
                $problems[] = '챗봇 색인에 이 회사(ERP) 청크가 하나도 없음 — 색인 스코프나 배포를 확인하세요';
            } elseif ($tagged === 0) {
                $problems[] = sprintf('🚨 챗봇 색인 %d청크가 전부 등급 미표기 — 전 직원이 대표·시스템 자료까지 검색하게 됩니다', $total);
            } elseif ($tagged < $total) {
                $problems[] = sprintf('🚨 챗봇 색인 등급이 일부만 표기됨 (%d/%d) — 미표기 청크는 검색에서 통째로 제외됩니다', $tagged, $total);
            }
            if ($invalid > 0) {
                $problems[] = sprintf('챗봇 색인에 알 수 없는 등급이 %d건 — 해당 청크는 검색에서 제외됩니다', $invalid);
            }
        }

        Cache::put(self::AUDIT_KEY, ['mtime' => $mtime, 'problems' => $problems], 7 * 86400);

        return $problems;
    }

    private function pingOllama(): bool
    {
        try {
            return Http::timeout(5)
                ->connectTimeout(5)
                ->get(rtrim((string) config('assistant.ollama'), '/').'/api/tags')
                ->successful();
        } catch (\Throwable $e) {
            return false;   // 연결 실패·타임아웃 — 그 자체가 신호다
        }
    }
}
