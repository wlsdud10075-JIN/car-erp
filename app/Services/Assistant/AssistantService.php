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
    private const RAG_AUDIENCES = ['staff', 'finance', 'executive', 'system'];

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

        // 대표 전용 실적·자금. 구체적인 의도를 일반 자금보다 먼저 검사한다.
        if ($has(['매출', '판매실적', '판매 실적'])
            && $has(['인원', '담당자', '영업', '사원', '직원', '사람별'])) {
            return 'sales_by_salesman';
        }
        if ($has(['손익분기', '손익 분기', '본전', '원금 회복', '원금회복'])) {
            return 'break_even';
        }
        if ($has(['자금', '현금', '통장', '굴리', '순이익', '손익', '이익', '자본', '밑천', '원금', '청산가치', '청산 가치'])) {
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
        if ($has(['기능설정', '기능 설정', '시스템관리자', '시스템 관리자', '알림톡 로그', '알림톡 안내', 'Ollama', '챗봇 색인', '서버 로그'])) {
            return 'system_guide';
        }

        return 'guide';   // 그 외 = 업무 가이드 RAG
    }

    private function dispatch(string $intent, string $question, User $user): array
    {
        return match ($intent) {
            'capital_status' => $this->capital($user),
            'break_even' => $this->breakEven($user),
            'sales_by_salesman' => $this->salesBySalesman($question, $user),
            'receivable_by_salesman' => $this->bySalesman($user),
            'receivable_by_buyer' => $this->byBuyer($user),
            'receivable_summary' => $this->summary($user),
            'system_guide' => $user->canUseSystemAssistant()
                ? $this->guide($question, $user)
                : $this->denied('시스템 운영 정보'),
            default => $this->guide($question, $user),
        };
    }

    // ── B: DB 조회 (숫자 서버 삽입) ──────────────────────────────

    private function capital(User $user): array
    {
        if (! $user->canUseExecutiveAssistant()) {
            return $this->denied('자금 현황·회사 이익');
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
            $lines[] = '· 손익: 투입원금 미설정 (시스템관리자에게 원금 설정 요청)';
        }

        return ['kind' => 'capital', 'answer' => implode("\n", $lines)];
    }

    private function breakEven(User $user): array
    {
        if (! $user->canUseExecutiveAssistant()) {
            return $this->denied('손익분기 현황');
        }
        $d = $this->queries->capitalStatus();
        if (! ($d['has_data'] ?? false)) {
            return ['kind' => 'capital', 'answer' => '통장 마감잔액이 없어 손익분기 현황을 계산할 수 없습니다.'];
        }
        if ($d['principal_krw'] === null) {
            return ['kind' => 'capital', 'answer' => '투입원금이 설정되지 않아 손익분기를 계산할 수 없습니다. 시스템관리자에게 투입원금 설정을 요청해 주세요.'];
        }

        $difference = (int) $d['profit_krw'];
        $status = $difference >= 0
            ? '손익분기점을 '.$this->won($difference).' 초과했습니다.'
            : '손익분기점까지 '.$this->won(abs($difference)).' 부족합니다.';

        return ['kind' => 'capital', 'answer' => implode("\n", [
            '⚖️ 손익분기 현황 ('.Carbon::parse($d['date'])->format('Y-m-d').' 기준)',
            '· 기준: 청산가치 = 투입원금',
            '· 청산가치: '.$this->won($d['liquidation_krw']),
            '· 투입원금: '.$this->won($d['principal_krw']),
            '· '.$status,
        ])];
    }

    /**
     * 인원별 매출 — 거부가 아니라 스코프다 (jin 2026-07-29).
     *   최고관리자·업무관리자 = 전사 / [관리] = 본인이 담당하는 영업만 / 그 외 = 거부.
     */
    private function salesBySalesman(string $question, User $user): array
    {
        if (! $user->canUseSalesPerformanceAssistant()) {
            return $this->denied('인원별 매출 현황');
        }
        $scopeIds = $user->assistantSalesScopeIds();
        $period = $this->salesPeriod($question);
        $rows = $this->queries->salesBySalesman($period['from'], $period['to'], $scopeIds);
        $scopeLabel = $scopeIds === null ? '전사' : '담당 영업';
        if (! $rows) {
            return ['kind' => 'sales', 'answer' => "{$period['label']} {$scopeLabel} 인원별 매출이 없습니다."];
        }
        $body = collect($rows)->map(fn ($row, $i) => sprintf(
            '%d. %s — %s (%d대)',
            $i + 1,
            $row['name'],
            $this->won($row['sales_krw']),
            $row['count'],
        ))->implode("\n");

        return ['kind' => 'sales', 'answer' => "📈 {$period['label']} {$scopeLabel} 인원별 매출 현황\n".$body];
    }

    private function bySalesman(User $user): array
    {
        if (! $user->canUseFinanceAssistant()) {
            return $this->denied('인원별 미수 현황');
        }
        $rows = $this->queries->receivableBySalesman();
        if (! $rows) {
            return ['kind' => 'receivable', 'answer' => '현재 결제대기(유예)를 제외한 인원별 미수가 없습니다.'];
        }
        $body = collect($rows)->map(fn ($r, $i) => sprintf('%d. %s — %s (%d대)', $i + 1, $r['name'], $this->won($r['unpaid']), $r['count']))->implode("\n");

        return ['kind' => 'receivable', 'answer' => "👤 인원별 미수 현황 (결제대기 제외)\n".$body];
    }

    private function byBuyer(User $user): array
    {
        if (! $user->canUseFinanceAssistant()) {
            return $this->denied('바이어별 미수 현황');
        }
        $rows = $this->queries->receivableByBuyer();
        if (! $rows) {
            return ['kind' => 'receivable', 'answer' => '현재 결제대기(유예)를 제외한 바이어별 미수가 없습니다.'];
        }
        $body = collect($rows)->map(fn ($r, $i) => sprintf('%d. %s — %s (%d대)', $i + 1, $r['name'], $this->won($r['unpaid']), $r['count']))->implode("\n");

        return ['kind' => 'receivable', 'answer' => "🏢 바이어별 미수 현황 (결제대기 제외)\n".$body];
    }

    private function summary(User $user): array
    {
        if (! $user->canUseFinanceAssistant()) {
            return $this->denied('채권관리 요약');
        }
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

    private function guide(string $question, User $user): array
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
        // 권한 필터를 임베딩 검색보다 먼저 적용한다. LLM에는 허용된 청크만 전달된다.
        // 색인에 audience가 하나라도 있으면 새 형식으로 보고, 미표기 청크는 누락 사고 방지를 위해 제외한다.
        $usesAudienceMetadata = (bool) array_filter($kb, fn ($doc) => array_key_exists('audience', $doc));
        $allowedAudiences = $user->assistantAudiences();
        $kb = array_values(array_filter($kb, function ($doc) use ($allowedAudiences, $usesAudienceMetadata) {
            if (! array_key_exists('audience', $doc) && $usesAudienceMetadata) {
                return false;
            }
            $audience = $doc['audience'] ?? 'staff'; // audience가 전혀 없는 구 색인만 staff로 하위 호환
            $required = is_array($audience) ? $audience : [$audience];
            if (! $required || array_diff($required, self::RAG_AUDIENCES)) {
                return false; // 알 수 없는 등급은 fail-closed
            }

            return (bool) array_intersect($required, $allowedAudiences);
        }));
        if (! $kb) {
            return ['kind' => 'guide', 'answer' => '현재 권한으로 조회할 수 있는 업무 가이드가 없습니다.'];
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

    /** @return array{from:string,to:string,label:string} */
    private function salesPeriod(string $question): array
    {
        $today = Carbon::today();
        if (str_contains($question, '지난달') || str_contains($question, '지난 달') || str_contains($question, '전월')) {
            $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $label = '지난달';
        } elseif (str_contains($question, '지난 분기')) {
            $start = $today->copy()->subQuarter()->startOfQuarter();
            $end = $start->copy()->endOfQuarter();
            $label = '지난 분기';
        } elseif (str_contains($question, '이번 분기')) {
            $start = $today->copy()->startOfQuarter();
            $end = $today->copy()->endOfQuarter();
            $label = '이번 분기';
        } elseif (str_contains($question, '올해') || str_contains($question, '금년')) {
            $start = $today->copy()->startOfYear();
            $end = $today->copy()->endOfYear();
            $label = '올해';
        } else {
            $start = $today->copy()->startOfMonth();
            $end = $today->copy()->endOfMonth();
            $label = '이번 달';
        }

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => $label];
    }

    /** @return array{kind:string,answer:string,denied:true} */
    private function denied(string $subject): array
    {
        return [
            'kind' => 'denied',
            'denied' => true,
            'answer' => "{$subject} 정보는 현재 계정 권한으로 조회할 수 없습니다.",
        ];
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
