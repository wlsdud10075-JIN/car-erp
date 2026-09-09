<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\OllamaClient;
use Illuminate\Console\Command;

/**
 * 챗봇 검색·답변 품질 측정 (jin 2026-09-09) — 「고쳤더니 나아졌나」를 느낌이 아니라 대조로 본다.
 *
 * 두 가지 모드가 있고 성격이 다르다.
 *
 *   ① 기본(검색) — **LLM 을 쓰지 않는다.** 재는 것은 「정답 카드가 top-k 안에 몇 위인가」 하나뿐이다.
 *      임베딩만 쓰면 빠르고 **같은 입력에 같은 결과**가 나온다 → 변경 효과를 귀속시킬 수 있다.
 *   ② `--llm`(답변) — 실제 프롬프트로 답변을 생성해 **사람이 전·후를 나란히 읽는다.**
 *      🚫 자동 채점하지 않는다 — qwen3:8b 출력은 실행마다 흔들려 점수가 튄다. 정직한 산출물은 «나란히 놓인 글»이다.
 *
 * 🧭 **4등급을 전부 잰다.** 그러면 「권한 때문에 안 나온 것」과 「검색이 못 찾은 것」이 표에서 저절로
 *    갈린다. 예) 「승인큐가 뭐야?」의 정답 카드는 `finance` 라 **영업(staff)에게는 원리상 안 나온다**
 *    — 버그가 아니라 설계다(2026-09-08 확정). 그래서 문항마다 필요한 등급을 함께 찍는다.
 *
 * 🚫 후보 추림·채점·중복 제거·문맥 조립·시스템 프롬프트를 여기에 옮겨 적지 않는다 —
 *    `AssistantService` 의 `candidateChunks()`·`scoreChunks()`·`selectTopChunks()`·`buildContext()`·
 *    `GUIDE_SYSTEM_PROMPT` 를 그대로 부른다. 갈리면 **평가가 거짓말하는 계기판**이 된다(SKILLS §8 #44).
 *
 * 문항은 `scripts/notion-cards/natural-language-eval.json`(Notion 세션 작성, dev-only)을 읽는다.
 * 🚫 문항·기대 카드를 이 파일에 손으로 적지 않는다 — 원고가 늘 때 평가만 낡는다.
 *
 * 사용 예 (운영 색인을 로컬에 받아 재는 경우):
 *   php artisan assistant:eval --index=/tmp/index-erp-prod.json --topk=3 --dedup=1.0   # 전(baseline)
 *   php artisan assistant:eval --index=/tmp/index-erp-prod.json --topk=8               # 후
 *   php artisan assistant:eval --index=... --llm --only=shipping-wait-start-1,queue-1  # 답변 대조
 */
class AssistantEval extends Command
{
    protected $signature = 'assistant:eval
        {--index= : 색인 파일 경로(미지정 시 .env ASSISTANT_INDEX_PATH)}
        {--cases= : 문항 JSON 경로(미지정 시 scripts/notion-cards/natural-language-eval.json)}
        {--topk= : 근거로 넘길 청크 수(미지정 시 설정값)}
        {--dedup= : 중복 제거 코사인 임계(1.0 이상 = 끔)}
        {--depth=20 : 정답 카드 순위를 이 깊이까지 찾아 표시}
        {--only= : 특정 문항 id 만(쉼표 구분)}
        {--ask= : 채점표에 없는 질문을 그대로 하나 물어본다(--llm 과 함께)}
        {--tier=staff : --ask 로 물을 때의 권한 등급}
        {--llm : 실제 프롬프트로 답변을 생성해 출력(사람이 전·후를 읽는 용도)}
        {--show=0 : 등급별 실제 상위 N개를 함께 출력(검색 모드에서만)}';

    protected $description = '챗봇 가이드 검색·답변 품질 측정 — 정답 카드 순위 표 / --llm 은 실제 답변 대조';

    private const DEFAULT_CASES = 'scripts/notion-cards/natural-language-eval.json';

    /**
     * 등급 표본 — 실제 `User::assistantAudiences()` 를 통과시켜 얻는다(등급 규칙을 옮겨 적지 않기 위함).
     * 값은 「그 등급을 대표하는 계정 속성」이다.
     */
    private const TIERS = [
        'staff' => ['permission' => 'user', 'role' => '영업'],
        'finance' => ['permission' => 'user', 'role' => '재무'],
        'executive' => ['permission' => 'admin', 'role' => '관리'],
        'system' => ['permission' => 'super', 'role' => '관리'],
    ];

    public function handle(AssistantService $svc, OllamaClient $ollama): int
    {
        if ($path = (string) $this->option('index')) {
            if (! is_file($path)) {
                $this->error("색인 파일이 없습니다: {$path}");

                return self::FAILURE;
            }
            config(['assistant.index_path' => $path]);
        }

        // --ask = 채점표 밖의 질문. 「이 질문엔 어떻게 답하나」를 바로 보려는 용도라 필수 사실은 없다.
        if ($ask = (string) $this->option('ask')) {
            $cases = [['id' => 'ask', 'question' => $ask, 'required_audience' => (string) $this->option('tier'),
                'expected_cards' => [], 'reference_facts' => []]];
            $topk = $this->option('topk') !== null ? (int) $this->option('topk') : (int) config('assistant.rag_topk', 8);
            $dedup = $this->option('dedup') !== null ? (float) $this->option('dedup') : (float) config('assistant.rag_dedup_cos', 0.90);
            $kbByTier = [];
            foreach (self::TIERS as $tier => $attrs) {
                $kbByTier[$tier] = $svc->candidateChunks((new User($attrs))->assistantAudiences());
            }
            $this->line('색인 = '.config('assistant.index_path'));
            $this->line(sprintf('topk=%d · 중복제거=%s · 예산=%d자', $topk, (string) $dedup, (int) config('assistant.rag_ctx_chars', 4000)));
            $this->newLine();

            return $this->runAnswers($svc, $ollama, $cases, $kbByTier, $topk, $dedup);
        }

        $casesPath = (string) ($this->option('cases') ?: base_path(self::DEFAULT_CASES));
        if (! is_file($casesPath)) {
            $this->warn("문항 파일이 없습니다: {$casesPath}");
            $this->line('※ 이 파일은 dev 전용(Notion 세션 작성)이라 master 체크아웃에는 없습니다. --cases 로 경로를 주세요.');

            return self::FAILURE;
        }
        $cases = json_decode((string) file_get_contents($casesPath), true)['cases'] ?? [];
        if ($only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))))) {
            $cases = array_values(array_filter($cases, fn ($c) => in_array($c['id'] ?? '', $only, true)));
        }
        if (! $cases) {
            $this->error('평가할 문항이 없습니다.');

            return self::FAILURE;
        }

        $topk = $this->option('topk') !== null ? (int) $this->option('topk') : (int) config('assistant.rag_topk', 3);
        $dedup = $this->option('dedup') !== null ? (float) $this->option('dedup') : (float) config('assistant.rag_dedup_cos', 0.90);
        $depth = max($topk, (int) $this->option('depth'));

        $this->line('색인 = '.config('assistant.index_path'));
        $this->line('문항 = '.$casesPath.' ('.count($cases).'문항)');
        $this->line(sprintf('topk=%d · 중복제거 임계=%s%s', $topk, $dedup >= 1.0 ? '끔' : (string) $dedup, $this->option('llm') ? ' · 답변 생성 ON' : ''));
        $this->newLine();

        // 등급별 후보 청크는 질문과 무관하므로 한 번만 만든다.
        $kbByTier = [];
        foreach (self::TIERS as $tier => $attrs) {
            $kbByTier[$tier] = $svc->candidateChunks((new User($attrs))->assistantAudiences());
        }
        $this->line('등급별 후보 청크: '.implode(' · ', array_map(
            fn ($t, $kb) => "{$t}=".count($kb),
            array_keys($kbByTier), $kbByTier
        )));
        $this->newLine();

        return $this->option('llm')
            ? $this->runAnswers($svc, $ollama, $cases, $kbByTier, $topk, $dedup)
            : $this->runRetrieval($svc, $ollama, $cases, $kbByTier, $topk, $dedup, $depth);
    }

    /** ① 검색 모드 — 정답 카드 순위 표. */
    private function runRetrieval(
        AssistantService $svc, OllamaClient $ollama, array $cases, array $kbByTier, int $topk, float $dedup, int $depth
    ): int {
        $hit = 0;
        $total = 0;
        $rows = [];

        foreach ($cases as $case) {
            $qEmb = $ollama->embed((string) config('assistant.emb_model'), (string) $case['question']);
            if (! $qEmb) {
                $this->error('임베딩 실패 — Ollama('.config('assistant.ollama').') 가 떠 있는지 확인하세요.');

                return self::FAILURE;
            }
            $need = (string) ($case['required_audience'] ?? 'staff');

            // 순위는 등급별로 한 번만 계산해 재사용한다.
            $rankedByTier = [];
            foreach ($kbByTier as $tier => $kb) {
                // 순위 측정에는 문맥 예산을 끈다(0) — 예산이 걸리면 「몇 위인가」가 왜곡된다.
                $rankedByTier[$tier] = array_keys($svc->selectTopChunks($kb, $svc->scoreChunks($kb, $qEmb), $depth, $dedup, 0));
            }

            foreach ((array) ($case['expected_cards'] ?? []) as $expect) {
                $row = [$case['id'], $need, $this->shorten($expect)];
                foreach ($kbByTier as $tier => $kb) {
                    $pos = $this->positionOf($kb, $rankedByTier[$tier], $expect);
                    if ($tier === $need) {
                        $total++;
                        if ($pos !== null && $pos <= $topk) {
                            $hit++;
                        }
                    }
                    $row[] = $pos === null ? '—' : ($pos <= $topk ? "✅ {$pos}" : (string) $pos);
                }
                $rows[] = $row;
            }

            if ((int) $this->option('show') > 0) {
                $kb = $kbByTier[$need];
                foreach (array_slice($rankedByTier[$need], 0, (int) $this->option('show')) as $r => $i) {
                    $rows[] = ['', '', sprintf('  · 실제 %d위: %s', $r + 1, $this->shorten((string) $kb[$i]['source'])), '', '', '', ''];
                }
            }
        }

        $this->table(['문항', '필요등급', '기대 카드', ...array_keys($kbByTier)], $rows);
        $this->line(sprintf(
            '<options=bold>필요 등급 기준 — 기대 카드 %d개 중 top-%d 안에 %d개 (%.0f%%)</>',
            $total, $topk, $hit, $total ? $hit / $total * 100 : 0
        ));
        $this->line('※ 「필요등급」 열이 그 문항을 실제로 묻는 사람의 등급이다. 그보다 낮은 등급의 「—」 는 권한 경계(버그 아님).');

        return self::SUCCESS;
    }

    /** ② 답변 모드 — 실제 프롬프트로 생성해 사람이 읽는다. 자동 채점하지 않는다. */
    private function runAnswers(
        AssistantService $svc, OllamaClient $ollama, array $cases, array $kbByTier, int $topk, float $dedup
    ): int {
        foreach ($cases as $case) {
            $need = (string) ($case['required_audience'] ?? 'staff');
            $kb = $kbByTier[$need] ?? $kbByTier['staff'];
            $qEmb = $ollama->embed((string) config('assistant.emb_model'), (string) $case['question']);
            if (! $qEmb) {
                $this->error('임베딩 실패 — Ollama 확인');

                return self::FAILURE;
            }
            $picked = $svc->selectTopChunks($kb, $svc->scoreChunks($kb, $qEmb), $topk, $dedup);
            ['ctx' => $ctx, 'sources' => $sources] = $svc->buildContext($kb, $picked);

            $answer = $ollama->chat(
                (string) config('assistant.llm_model'),
                AssistantService::GUIDE_SYSTEM_PROMPT,
                "[참고자료]\n{$ctx}\n[질문]\n{$case['question']}"
            );

            $this->line(str_repeat('=', 100));
            $this->line(sprintf('<options=bold>[%s · %s] %s</>', $case['id'], $need, $case['question']));
            $this->line('근거: '.implode(' / ', array_map(fn ($s) => $this->shorten($s['title']).' ('.$s['score'].')', $sources)));
            if ($facts = (array) ($case['reference_facts'] ?? [])) {
                $this->line('필수 사실: '.implode(' | ', $facts));
            }
            $body = trim((string) $answer);
            $this->line(sprintf('분량: %d자 · %d줄', mb_strlen($body), $body === '' ? 0 : substr_count($body, "\n") + 1));
            $this->newLine();
            $this->line($body ?: '(응답 없음)');
            $this->newLine();
        }

        $this->line(str_repeat('=', 100));

        return self::SUCCESS;
    }

    /** 기대 카드가 순위 목록의 몇 번째인가(1-based). 없으면 null. */
    private function positionOf(array $kb, array $ranked, string $expect): ?int
    {
        foreach ($ranked as $r => $i) {
            if (mb_strpos((string) ($kb[$i]['source'] ?? ''), $expect) !== false) {
                return $r + 1;
            }
        }

        return null;
    }

    /** 표에 들어가게 `사내 업무 가이드 › 🏢 ERP (car-erp) ›` 접두어를 떼어낸다. */
    private function shorten(string $source): string
    {
        return trim(preg_replace('/^.*?ERP \(car-erp\)\s*›\s*/u', '', $source));
    }
}
