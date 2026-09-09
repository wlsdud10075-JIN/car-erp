<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\OllamaClient;
use Illuminate\Console\Command;

/**
 * 챗봇 검색 품질 측정 (jin 2026-09-09) — 「고쳤더니 나아졌나」를 느낌이 아니라 숫자로 본다.
 *
 * 🔑 **LLM 을 쓰지 않는다.** 재는 것은 **정답 카드가 top-k 안에 몇 위로 들어오나** 하나뿐이다.
 *    임베딩만 쓰면 빠르고 **같은 입력에 같은 결과**가 나온다 — 생성 답변을 채점하면 매번 흔들려
 *    변경의 효과를 귀속시킬 수 없다.
 *
 * 🧭 **4등급을 전부 잰다.** 그러면 「권한 때문에 안 나온 것」과 「검색이 못 찾은 것」이 표에서 저절로
 *    갈린다. 예) 「승인큐가 뭐야?」의 정답 카드는 `finance` 라 **영업(staff)에게는 원리상 안 나온다**
 *    — 버그가 아니라 설계다(2026-09-08 확정). 그래서 기대 카드 옆에 등급을 함께 찍는다.
 *
 * 🚫 후보 추림·중복 제거를 여기에 옮겨 적지 않는다 — `AssistantService::candidateChunks()` ·
 *    `selectTopChunks()` 를 그대로 부른다. 갈리면 **평가가 거짓말을 한다**(SKILLS §8 #44).
 *
 * 사용 예 (운영 색인을 로컬에 받아 재는 경우):
 *   php artisan assistant:eval --index=/tmp/index-erp-prod.json --topk=3 --dedup=1.0   # 전(baseline)
 *   php artisan assistant:eval --index=/tmp/index-erp-prod.json --topk=8               # 후
 */
class AssistantEval extends Command
{
    protected $signature = 'assistant:eval
        {--index= : 색인 파일 경로(미지정 시 .env ASSISTANT_INDEX_PATH)}
        {--topk= : 근거로 넘길 청크 수(미지정 시 설정값)}
        {--dedup= : 중복 제거 코사인 임계(1.0 이상 = 끔)}
        {--depth=20 : 정답 카드 순위를 이 깊이까지 찾아 표시}
        {--show=3 : 등급별로 실제 상위 N개를 함께 출력(0=안 함)}';

    protected $description = '챗봇 가이드 검색 품질 측정 — 질문별 정답 카드가 top-k 안에 몇 위인지';

    /**
     * 평가 질문과 기대 카드 (jin 2026-09-08 확정 5문항).
     * 기대 카드는 **`source` 부분문자열**로 적는다 — 사람에게 「어느 카드가 올라왔나」를 말해 주는 단위가 그것이다.
     */
    private const CASES = [
        [
            'q' => '차량 관리에서 정렬 기준이 어떻게 돼?',
            'expect' => ['차량관리 — 목록/개요', '자연어로 찾는 차량관리 안내'],
        ],
        [
            'q' => '인감, 직인 정보 어디서 봐?',
            'expect' => ['서류의 인감·직인 확인', '차량관리 - 서류 탭', 'E. 대시보드·관리·로그 › 기능설정'],
        ],
        [
            'q' => '승인큐가 뭐야?',
            'expect' => ['D. 재무·정산 › 승인 큐'],
        ],
        [
            'q' => '반입지가 뭐야?',
            'expect' => ['반입지·면장·서류의 의미와 자동 처리', 'C. 재고·통관·선적 › 재고관리'],
        ],
        [
            'q' => '자동입력되는 항목을 알려줘',
            'expect' => ['F. 개념 (크로스 · 단일 출처) › 자동 입력·계산·반영 항목 안내'],
        ],
    ];

    /** 등급 표본 — 실제 `User::assistantAudiences()` 를 통과시켜 얻는다(등급 규칙을 옮겨 적지 않기 위함). */
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

        $topk = $this->option('topk') !== null ? (int) $this->option('topk') : (int) config('assistant.rag_topk', 3);
        $dedup = $this->option('dedup') !== null ? (float) $this->option('dedup') : (float) config('assistant.rag_dedup_cos', 0.90);
        $depth = max($topk, (int) $this->option('depth'));
        $show = (int) $this->option('show');

        $this->line('색인 = '.config('assistant.index_path'));
        $this->line(sprintf('topk=%d · 중복제거 임계=%s · 순위 탐색 깊이=%d', $topk, $dedup >= 1.0 ? '끔' : (string) $dedup, $depth));
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

        $inTopK = 0;
        $expectedTotal = 0;

        foreach (self::CASES as $n => $case) {
            $qEmb = $ollama->embed((string) config('assistant.emb_model'), $case['q']);
            if (! $qEmb) {
                $this->error('임베딩 실패 — Ollama('.config('assistant.ollama').') 가 떠 있는지 확인하세요.');

                return self::FAILURE;
            }

            $this->line(sprintf('<options=bold>Q%d. %s</>', $n + 1, $case['q']));
            $rows = [];

            foreach ($case['expect'] as $expect) {
                $row = [$this->shorten($expect)];
                foreach ($kbByTier as $tier => $kb) {
                    $ranked = $this->rank($svc, $kb, $qEmb, $depth, $dedup);
                    $pos = null;
                    foreach ($ranked as $r => $i) {
                        if (mb_strpos((string) ($kb[$i]['source'] ?? ''), $expect) !== false) {
                            $pos = $r + 1;
                            break;
                        }
                    }
                    if ($tier === 'staff') {
                        $expectedTotal++;
                        if ($pos !== null && $pos <= $topk) {
                            $inTopK++;
                        }
                    }
                    $row[] = $pos === null ? '—' : ($pos <= $topk ? "✅ {$pos}" : (string) $pos);
                }
                $rows[] = $row;
            }

            $this->table(['기대 카드', ...array_keys($kbByTier)], $rows);

            if ($show > 0) {
                $kb = $kbByTier['staff'];
                $ranked = $this->rank($svc, $kb, $qEmb, $depth, $dedup);
                $this->line('  staff 실제 상위:');
                foreach (array_slice($ranked, 0, $show) as $r => $i) {
                    $this->line(sprintf('    %d. %s', $r + 1, $this->shorten((string) $kb[$i]['source'])));
                }
            }
            $this->newLine();
        }

        $this->line(sprintf(
            '<options=bold>staff 기준 — 기대 카드 %d개 중 top-%d 안에 %d개 (%.0f%%)</>',
            $expectedTotal, $topk, $inTopK, $expectedTotal ? $inTopK / $expectedTotal * 100 : 0
        ));
        $this->line('※ finance/executive/system 열의 「—」 는 그 등급에 그 카드가 없다는 뜻일 수도 있다(권한 경계, 버그 아님).');

        return self::SUCCESS;
    }

    /**
     * 질문 하나에 대한 순위 목록(청크 인덱스). 중복 제거까지 **본 코드와 같은 함수**로 통과시킨다.
     *
     * @return array<int,int> 0-based 순위 => 청크 인덱스
     */
    private function rank(AssistantService $svc, array $kb, array $qEmb, int $depth, float $dedup): array
    {
        $scored = [];
        foreach ($kb as $i => $doc) {
            $scored[$i] = $this->cosine($qEmb, $doc['embedding'] ?? []);
        }
        arsort($scored);

        return array_keys($svc->selectTopChunks($kb, $scored, $depth, $dedup));
    }

    /** 본 코드와 같은 계산 — 서비스의 private 을 못 쓰므로 같은 식을 쓴다(값 검증은 테스트가 한다). */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] ** 2;
            $nb += $b[$i] ** 2;
        }

        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /** 표에 들어가게 `사내 업무 가이드 › 🏢 ERP (car-erp) ›` 접두어를 떼어낸다. */
    private function shorten(string $source): string
    {
        return trim(preg_replace('/^.*?ERP \(car-erp\)\s*›\s*/u', '', $source));
    }
}
