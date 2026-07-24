<?php

namespace App\Services\Assistant;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 사내 업무 도우미 오케스트레이터 (jin 2026-07-24).
 *
 * 파이프라인: 질문 → ① 키워드 라우팅(결정적) → ② 권한 게이트 → ③ 답변
 *   - B(미수·채권·자금) = AssistantQueries 로 DB 조회 → 서버가 숫자를 문장에 삽입(LLM 미경유, §불변식).
 *   - A(업무가이드) = index.json RAG → LLM(qwen3:8b)이 "참고자료 근거로만" 답변.
 *   - 매 질의 AuditLog 기록.
 *
 * v1 라우팅은 LLM tool-calling 이 아니라 키워드 분류(신뢰도·테스트 가능). LLM 은 A(RAG)에만.
 */
class AssistantService
{
    public function __construct(
        private OllamaClient $ollama,
        private AssistantQueries $queries,
    ) {}

    /** @return array{kind:string,answer:string,sources?:array,denied?:bool} */
    public function ask(string $question, User $user): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['kind' => 'error', 'answer' => '질문을 입력해 주세요.'];
        }

        $intent = $this->classify($question);
        $result = $this->dispatch($intent, $question, $user);

        AuditLog::create([
            'user_id' => $user->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'action' => 'assistant_query',
            'column_name' => $result['denied'] ?? false ? "{$intent}(denied)" : $intent,
            'new_value' => mb_substr($question, 0, 500),
            'ip_address' => request()?->ip(),
        ]);

        unset($result['denied']);

        return $result;
    }

    /** 결정적 키워드 분류. 더 구체적인 의도를 먼저 검사. */
    public function classify(string $q): string
    {
        $has = fn (array $kw) => (bool) array_filter($kw, fn ($k) => mb_strpos($q, $k) !== false);

        // 자금·이익 심화 (super/admin) — 미수보다 먼저
        if ($has(['자금', '현금', '통장', '굴리', '순이익', '손익', '이익', '자본', '밑천', '원금'])) {
            return 'capital_status';
        }
        $isReceivable = $has(['미수', '채권', '미수금', '받을 돈', '받을돈', '외상']);
        if ($isReceivable && $has(['인원', '담당자', '영업', '사원', '직원'])) {
            return 'receivable_by_salesman';
        }
        if ($isReceivable && $has(['바이어', '거래처', '고객', 'buyer'])) {
            return 'receivable_by_buyer';
        }
        if ($isReceivable) {
            return 'receivable_summary';
        }

        return 'guide';   // 그 외 = 업무 가이드 RAG
    }

    private function dispatch(string $intent, string $question, User $user): array
    {
        return match ($intent) {
            'capital_status' => $this->capital($user),
            'receivable_by_salesman' => $this->bySalesman(),
            'receivable_by_buyer' => $this->byBuyer(),
            'receivable_summary' => $this->summary(),
            default => $this->guide($question),
        };
    }

    // ── B: DB 조회 (숫자 서버 삽입) ──────────────────────────────

    private function capital(User $user): array
    {
        if (! $user->canViewCapital()) {
            return ['kind' => 'denied', 'denied' => true,
                'answer' => '자금 현황·회사 이익은 대표·최고관리자만 조회할 수 있습니다.'];
        }
        $d = $this->queries->capitalStatus();
        if (! ($d['has_data'] ?? false)) {
            return ['kind' => 'capital', 'answer' => '아직 입력된 통장 마감잔액이 없어 자금 현황을 계산할 수 없습니다. 업무 대시보드에서 통장 잔액을 먼저 입력해 주세요.'];
        }
        $lines = [
            '📊 자금 현황 ('.Carbon::parse($d['date'])->format('Y-m-d').' 기준)',
            '· 통장 현금: '.$this->won($d['cash_krw']),
            '· 굴리는 자금: '.$this->won($d['working_capital_krw']).' (현금+재고+미수−미지급)',
            '· 청산가치: '.$this->won($d['liquidation_krw']),
        ];
        if ($d['profit_krw'] !== null) {
            $sign = $d['profit_krw'] >= 0 ? '+' : '−';
            $lines[] = '· 손익: '.$sign.$this->won(abs($d['profit_krw'])).' (청산가치 − 투입원금)';
        } else {
            $lines[] = '· 손익: 투입원금 미설정 (기능설정에서 원금 입력 시 표시)';
        }

        return ['kind' => 'capital', 'answer' => implode("\n", $lines)];
    }

    private function bySalesman(): array
    {
        $rows = $this->queries->receivableBySalesman();
        if (! $rows) {
            return ['kind' => 'receivable', 'answer' => '현재 결제대기(유예)를 제외한 인원별 미수가 없습니다.'];
        }
        $body = collect($rows)->map(fn ($r, $i) => sprintf('%d. %s — %s (%d대)', $i + 1, $r['name'], $this->won($r['unpaid']), $r['count']))->implode("\n");

        return ['kind' => 'receivable', 'answer' => "👤 인원별 미수 현황 (결제대기 제외)\n".$body];
    }

    private function byBuyer(): array
    {
        $rows = $this->queries->receivableByBuyer();
        if (! $rows) {
            return ['kind' => 'receivable', 'answer' => '현재 결제대기(유예)를 제외한 바이어별 미수가 없습니다.'];
        }
        $body = collect($rows)->map(fn ($r, $i) => sprintf('%d. %s — %s (%d대)', $i + 1, $r['name'], $this->won($r['unpaid']), $r['count']))->implode("\n");

        return ['kind' => 'receivable', 'answer' => "🏢 바이어별 미수 현황 (결제대기 제외)\n".$body];
    }

    private function summary(): array
    {
        $s = $this->queries->receivableSummary();
        $lines = [
            '📋 채권관리 요약 (결제대기 제외)',
            '· 총 미수: '.$this->won($s['total_unpaid']),
            '· 선적전 미수: '.$this->won($s['before_shipping']['unpaid']).' ('.$s['before_shipping']['count'].'대)',
            '· 선적후 미수: '.$this->won($s['after_shipping']['unpaid']).' ('.$s['after_shipping']['count'].'대)',
            '· 결제대기(유예): '.$this->won($s['grace']['unpaid']).' ('.$s['grace']['count'].'대) — 채권 총액 제외분',
        ];

        return ['kind' => 'receivable', 'answer' => implode("\n", $lines)];
    }

    // ── A: 업무 가이드 RAG (LLM) ─────────────────────────────────

    private function guide(string $question): array
    {
        $path = (string) config('assistant.index_path');
        if ($path === '' || ! is_file($path)) {
            return ['kind' => 'guide', 'answer' => '업무 가이드 색인이 아직 준비되지 않았습니다. 관리자에게 문의해 주세요.'];
        }
        $kb = json_decode(file_get_contents($path), true) ?: [];
        // 스코프 필터 — ERP 챗봇은 ERP 가이드 청크만 (board 내용 혼입 방지, jin 2026-07-24).
        $scope = (string) config('assistant.index_scope');
        if ($scope !== '') {
            $kb = array_values(array_filter($kb, fn ($d) => mb_strpos((string) ($d['source'] ?? ''), $scope) !== false));
        }
        if (! $kb) {
            return ['kind' => 'guide', 'answer' => '업무 가이드 색인이 비어 있습니다.'];
        }

        try {
            $qEmb = $this->ollama->embed((string) config('assistant.emb_model'), $question);
            if (! $qEmb) {
                throw new \RuntimeException('임베딩 실패');
            }
            $scored = [];
            foreach ($kb as $i => $doc) {
                $scored[$i] = $this->cosine($qEmb, $doc['embedding'] ?? []);
            }
            arsort($scored);
            $top = array_slice($scored, 0, (int) config('assistant.rag_topk', 3), true);

            $ctx = '';
            $sources = [];
            foreach ($top as $i => $score) {
                $ctx .= "### {$kb[$i]['source']}\n{$kb[$i]['text']}\n\n";
                $sources[] = ['title' => $kb[$i]['source'], 'score' => round($score, 3)];
            }

            $sys = '당신은 SSANCAR 사내 업무 도우미다. 반드시 아래 [참고자료]를 근거로 한국어로 간결·정확하게 답하라. '
                .'질문과 관련된 규칙·절차·금지·예외·주의사항이 참고자료에 있으면 그것을 바탕으로 분명히 답하라. '
                .'참고자료 어디에도 관련 내용이 전혀 없을 때만 "해당 내용은 등록된 업무 가이드에 없습니다."라고 답하라. 지어내지 마라.';
            $answer = $this->ollama->chat((string) config('assistant.llm_model'), $sys, "[참고자료]\n{$ctx}\n[질문]\n{$question}");

            return ['kind' => 'guide', 'answer' => $answer ?: '(응답 없음)', 'sources' => $sources];
        } catch (\Throwable $e) {
            return ['kind' => 'error', 'answer' => '업무 가이드 조회 중 오류가 발생했습니다. 로컬 LLM(Ollama)이 실행 중인지 확인해 주세요.'];
        }
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $v) {
            $bv = $b[$i] ?? 0;
            $dot += $v * $bv;
            $na += $v * $v;
            $nb += $bv * $bv;
        }

        return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    private function won(int $n): string
    {
        return number_format($n).'원';
    }
}
