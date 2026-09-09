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
    /** 색인 청크가 가질 수 있는 등급. 감시(AssistantHealthCheck)도 이 목록을 단일출처로 쓴다. */
    public const RAG_AUDIENCES = ['staff', 'finance', 'executive', 'system'];

    /**
     * 가이드(RAG) 시스템 프롬프트 — **단일 출처**. 평가 커맨드(`assistant:eval --llm`)가 같은 것을 쓴다.
     *
     * 🚫 여기 문구를 다른 곳에 옮겨 적지 말 것 — 갈리면 「평가에선 잘 나오는데 실제 챗봇은 다른 답」이 된다.
     *
     * 🔀 **2026-09-09 개조** (jin). 구 프롬프트는 «간결·정확하게» + «어디에도 관련 내용이 전혀 없을 때만
     *    없다고 하라» 두 마디뿐이라, 실제 답변이 **한 문단으로 끝나고 필수 사실을 빠뜨렸다**
     *    (실측: 정렬 질문에서 「초기화 복원」·「동률 미보장」 누락 / 승인 큐에서 「본인 승인 불가」·
     *    「송금 미실행」 누락). 근거를 더 줘도 프롬프트가 그대로면 **요약만 길어질 뿐**이라 세트로 고친다.
     *
     * 넣은 것 5가지 — ①구조(직접답→조건·절차→주의·한계) ②근거 카드 제목 밝히기(어느 카드를 실제로
     * 썼는지 사람이 검증할 수 있어야 한다) ③갈래 나누기(회사·권한 차이를 하나로 뭉치지 않기)
     * ④반대 정보(「자동인 것」을 물으면 「자동이 아닌 것」도) ⑤**부분 부재**(질문의 일부만 자료가 없으면
     * 그 항목만 없다고 하고 나머지는 답한다 — 구 문구로는 이 경우를 다룰 수 없었다).
     *
     * 🔀 **2026-09-09 2차 개조 (jin 제보)** — 1차의 «참고자료에 있는 만큼 빠뜨리지 말고 적는다» 가
     *    «다 옮겨라» 로 읽혀 답이 **25줄 나열**이 됐다(「선적요청은 어떻게 해?」 한 마디에 관련 없는
     *    Proforma 번호 규칙·EMS 탭·달력까지 따라 나왔다). 게다가 모델이 시키지 않은 「3) 추가 정보」
     *    섹션을 스스로 만들어 붙였다.
     *    고친 것 = ①**필요한 것만**을 첫 규칙으로 올림 ②부분별 줄 수 상한 ③섹션 3개 고정(제목 신설 금지)
     *    ④**서식 기호 금지** — 위젯이 `{{ }}` + `whitespace-pre-wrap` 로 **평문을 그대로** 뿌려서
     *      `**굵게**` 가 글자로 보인다(마크다운 렌더링이 없다) ⑤근거 카드는 맨 끝 한 줄로만.
     *    ⚠️ 「빠뜨리지 말라」를 약화했으므로 **필수 사실 커버가 떨어지지 않았는지 반드시 측정**할 것
     *    (`assistant:eval --llm`). 짧아지기만 하고 사실을 놓치면 개선이 아니다.
     *
     * ⚠️ **길이도 비용이다** — 이 프롬프트가 길어지면 그만큼 근거 예산(`rag_ctx_chars`)이 줄어든다.
     */
    public const GUIDE_SYSTEM_PROMPT = <<<'PROMPT'
        당신은 SSANCAR 사내 업무 도우미다. 아래 [참고자료]에 적힌 사실만 근거로 한국어로 답하라. 지어내지 마라.

        가장 중요한 규칙 — 질문에 답하는 데 필요한 것만 쓴다. 참고자료에 있다고 다 옮기지 마라.
        질문과 상관없는 기능·화면·수치를 늘어놓으면 나쁜 답이다. 짧고 정확한 쪽을 택하라.

        정확히 다음 세 부분으로만 쓴다. 다른 제목을 만들지 마라.
        1) 직접 답 — 질문이 묻는 것(뜻·위치·방법·가능 여부)을 2~3줄로 답한다. 위치만 안내하고 끝내지 마라.
        2) 조건·절차 — 그 답이 성립하는 조건·순서·권한·예외. 질문과 직접 관련된 것만 5줄 이내.
        3) 주의·한계 — 헷갈리기 쉬운 점이나 사람이 직접 해야 하는 것을 1~3줄. 없으면 「없음」이라고 쓴다.

        - 별표나 샵 같은 서식 기호를 쓰지 마라. 화면이 글자 그대로 보여주므로 지저분해진다.
        - 조건에 따라 답이 갈리면 갈래를 나눠 적는다. 회사·권한·화면에 따라 다르면 그 차이를 유지하고 하나의 규칙으로 뭉치지 마라.
        - 「자동으로 되는 것」을 물으면 참고자료가 밝힌 「자동이 아닌 것·사람이 넣어야 하는 것」도 함께 적는다.
        - 목록을 답할 때는 참고자료에 있는 범위만 적고 그것이 전체라고 단정하지 마라.
        - 질문에 여러 항목이 있으면 항목별로 답하고, 참고자료에 없는 항목은 그 항목만 「ERP에 확인된 정보가 없습니다」라고 적는다. 나머지 항목은 정상적으로 답한다.
        - 참고자료에 관련 내용이 하나도 없을 때만 「해당 내용은 등록된 업무 가이드에 없습니다」라고 답한다.
        - 맨 끝에 「근거 카드: 」로 시작하는 한 줄을 적고 실제로 쓴 카드 제목만 최대 2개 나열한다. 답에 쓰지 않은 카드는 적지 마라.
        - 같은 문장을 두 부분에 되풀이하지 마라.
        PROMPT;

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

    /**
     * 「얼마」를 묻는 게 아니라 「되나·왜·어떻게」를 묻는 질문인가 (jin 2026-09-09 제보).
     *
     * 🚨 **실사고** — 「선적대기 허용 항로인데 미수여도 묶음 착수돼? B/L도 가능해?」를 물었더니
     *    채권 KPI 표(총 미수 640,732,308원 …)가 나왔다. 「미수」라는 낱말 하나 때문에 **규칙 질문이
     *    DB 조회로 잡힌** 것이다. 같은 형태로 「손익분기가 뭐야?」→금액표 · 「채권관리 어디서 봐?」→금액표.
     *
     * 🔑 **B(DB 조회)는 「얼마」를 답하는 경로**고 가이드는 「되나·왜·어디서」를 답하는 경로다.
     *    그래서 **금액을 묻는 신호가 없고 규칙을 묻는 신호만 있으면** DB 조회로 보내지 않는다.
     * ⚠️ **금액 신호가 우선**이다 — 「이번달 미수금이 얼마나 되나?」 는 `되나` 가 있어도 금액 질문이다.
     *    이 순서를 뒤집으면 멀쩡히 돌던 조회가 통째로 가이드로 새어 **숫자를 못 받는다**.
     * 🚫 신호가 **둘 다 없으면 종전대로 DB 조회**다(「미수금」 한 마디 = 지금도 금액표가 맞다).
     *    여기서 기본값을 가이드로 바꾸면 잘 쓰던 조회가 조용히 죽는다.
     */
    private function asksRuleNotNumber(string $q): bool
    {
        $has = fn (array $kw) => (bool) array_filter($kw, fn ($k) => mb_strpos($q, $k) !== false);

        // 금액을 요구하는 신호 — 하나라도 있으면 종전 라우팅을 그대로 둔다.
        if ($has(['얼마', '총액', '합계', '몇'])) {
            return false;
        }

        // 규칙·절차·정의·가능여부를 묻는 신호.
        return $has([
            '되나', '되니', '되냐', '되는', '되면', '돼?', '되어도', '해도 되', '가능',
            '왜', '어떻게', '어디서', '어디에', '어디야', '무엇', '뭐야', '무슨', '뭔지',
            '조건', '기준', '규칙', '방법', '절차', '뜻', '의미', '차이',
            // 🚫 '언제' 는 넣지 않는다 — 「손익분기 언제 넘어?」 처럼 **시점을 계산해 주는 DB 질문**이
            //    규칙 질문으로 새어 버린다. 시점을 묻는 규칙 질문(「정산 언제 확정해?」)은 그 자체로
            //    금액 낱말이 없어 어차피 가이드로 간다.
            '막히', '차단', '안 되', '안되', '안돼', '못 하', '못하',
            '허용', '예외',
        ]);
    }

    /** 결정적 키워드 분류. 더 구체적인 의도를 먼저 검사. */
    public function classify(string $q): string
    {
        $has = fn (array $kw) => (bool) array_filter($kw, fn ($k) => mb_strpos($q, $k) !== false);

        $isSystemGuide = $has(['기능설정', '기능 설정', '시스템관리자', '시스템 관리자', '알림톡 로그', '알림톡 안내', 'Ollama', '챗봇 색인', '서버 로그']);

        // 🧭 규칙 질문은 DB 조회(B) 분기를 통째로 건너뛴다 — 위 asksRuleNotNumber 참조.
        //    ⚠️ system_guide 는 살려야 한다. 그냥 'guide' 로 떨어뜨리면 system 등급 청크가
        //       검색에서 빠져 「기능설정에서 왜 안 보여?」 같은 질문이 답을 못 찾는다.
        if ($this->asksRuleNotNumber($q)) {
            return $isSystemGuide ? 'system_guide' : 'guide';
        }

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
        if ($isSystemGuide) {
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

    /**
     * 색인 로드 + 스코프·등급 필터 — `guide()` 와 평가 커맨드(`assistant:eval`)의 **단일 출처**.
     *
     * 🚫 조건을 옮겨 적지 말 것(SKILLS §8 #44) — 갈리면 「평가에선 찾는데 실제 챗봇은 못 찾는」
     *    형태가 되고, 그건 평가가 거짓말을 하는 것이라 원래 버그보다 고치기 어렵다.
     *
     * @param  array<int,string>  $allowedAudiences  `User::assistantAudiences()` 결과
     * @return array<int, array<string, mixed>>
     */
    public function candidateChunks(array $allowedAudiences): array
    {
        $path = (string) config('assistant.index_path');
        if ($path === '' || ! is_file($path)) {
            return [];
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

        return array_values(array_filter($kb, function ($doc) use ($allowedAudiences, $usesAudienceMetadata) {
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
    }

    /**
     * 점수 내림차순으로 k 개를 고르되 **이미 고른 것과 거의 같은 청크는 건너뛴다** (jin 2026-09-09).
     *
     * 🧭 **왜 필요한가** — 「자동 입력·계산·반영 항목 안내」가 `공통 ›` 과 `기능 카드 › F. 개념 ›`
     *    양쪽에 거의 같은 내용으로 실려 있다. top-3 중 두 자리를 같은 말이 먹으면 **실질 근거가 1장**이다.
     *
     * 🔬 **임계값 근거 = 운영 색인 실측**(2026-09-09, 113청크 · staff 쌍 1,953개):
     *    p50 0.602 · p90 0.686 · p99 0.789 · **max 0.927 = 바로 그 중복 쌍**(색인에서 가장 닮은 쌍).
     *    2위는 0.876(진행 게이트의 워크플로우판 ↔ 부서가이드판)으로 **서로 보완하는 다른 글**이다.
     *    => 기본 0.90 은 «거의 같은 글» 하나만 걸러낸다.
     * 🚫 **제목이 같으면 중복으로 보지 말 것** — 실측 제목중복 3그룹 중 둘(「개요」 4개 ·
     *    「자주 하는 실수」 2개)은 내용이 전혀 다르다(cos 0.57~0.75). 제목 기준은 멀쩡한 근거를 떨어뜨린다.
     * ⚠️ 1.0 이상이면 중복 제거를 끄는 것이다(전후 대조·긴급 원복용).
     *
     * 📏 **문맥 예산이 개수보다 먼저다** (jin 2026-09-09 실측).
     *    청크 길이가 10배까지 차이 나서 **개수는 안전한 손잡이가 아니다** — 같은 `topk=4` 인데
     *    질문에 따라 문맥이 3,315자(인감)와 **9,023자**(정렬)로 갈린다. 모델 상주 컨텍스트는
     *    `ctx=4096`(실측 `/api/ps`)이고 **초과분은 조용히 잘린다** — 잘리는 쪽이 앞이라
     *    **점수가 가장 높은 근거가 사라진다**. 에러도 로그도 없다.
     *    ⇒ 그래서 예산(문자 수)을 넘기는 청크는 건너뛰고 **뒤의 더 작은 청크를 담는다**(`break` 아님).
     *    첫 청크는 예산을 넘겨도 담는다 — 근거 0장으로 답하게 만들 수는 없다.
     *    ⚠️ 0 이면 예산을 끄는 것이다(순위 측정처럼 「전부 나열」이 필요할 때).
     *
     * @param  array<int, array<string, mixed>>  $kb  후보 청크 (candidateChunks 결과)
     * @param  array<int, float>  $scored  청크 인덱스 => 질문과의 코사인. **내림차순 정렬돼 있어야 한다.**
     * @return array<int, float> 고른 것만 남긴 [인덱스 => 점수] (순서 보존)
     */
    public function selectTopChunks(array $kb, array $scored, int $k, ?float $dedupCos = null, ?int $ctxChars = null): array
    {
        $threshold = $dedupCos ?? (float) config('assistant.rag_dedup_cos', 0.90);
        $budget = $ctxChars ?? (int) config('assistant.rag_ctx_chars', 4000);
        $picked = [];
        $used = 0;

        foreach ($scored as $i => $score) {
            if (count($picked) >= $k) {
                break;
            }
            if ($threshold < 1.0) {
                $tooClose = false;
                foreach (array_keys($picked) as $j) {
                    if ($this->cosine($kb[$i]['embedding'] ?? [], $kb[$j]['embedding'] ?? []) >= $threshold) {
                        $tooClose = true;   // 먼저 고른 쪽이 점수가 높다 — 그것을 남긴다
                        break;
                    }
                }
                if ($tooClose) {
                    continue;
                }
            }
            $len = mb_strlen((string) ($kb[$i]['text'] ?? ''));
            if ($budget > 0 && $picked !== [] && $used + $len > $budget) {
                continue;   // 이 청크는 예산 초과 — 뒤의 더 작은 근거에 자리를 준다
            }
            $picked[$i] = $score;
            $used += $len;
        }

        return $picked;
    }

    /**
     * 질문 임베딩과 후보 청크의 코사인 — **내림차순 정렬된** [청크 인덱스 => 점수].
     * 평가 커맨드가 같은 채점을 쓰게 하려고 공개한다(SKILLS §8 #44).
     *
     * @return array<int, float>
     */
    public function scoreChunks(array $kb, array $qEmb): array
    {
        $scored = [];
        foreach ($kb as $i => $doc) {
            $scored[$i] = $this->cosine($qEmb, $doc['embedding'] ?? []);
        }
        arsort($scored);

        return $scored;
    }

    /**
     * 고른 청크로 LLM 에 넘길 [참고자료] 문자열과 출처 목록을 만든다.
     *
     * @param  array<int, float>  $picked  selectTopChunks 결과
     * @return array{ctx:string, sources:array<int, array{title:string, score:float}>}
     */
    public function buildContext(array $kb, array $picked): array
    {
        $ctx = '';
        $sources = [];
        foreach ($picked as $i => $score) {
            $ctx .= "### {$kb[$i]['source']}\n{$kb[$i]['text']}\n\n";
            $sources[] = ['title' => $kb[$i]['source'], 'score' => round($score, 3)];
        }

        return ['ctx' => $ctx, 'sources' => $sources];
    }

    private function guide(string $question, User $user): array
    {
        $path = (string) config('assistant.index_path');
        if ($path === '' || ! is_file($path)) {
            return ['kind' => 'guide', 'answer' => '업무 가이드 색인이 아직 준비되지 않았습니다. 관리자에게 문의해 주세요.'];
        }
        $kb = $this->candidateChunks($user->assistantAudiences());
        if (! $kb) {
            return ['kind' => 'guide', 'answer' => '현재 권한으로 조회할 수 있는 업무 가이드가 없습니다.'];
        }

        try {
            $qEmb = $this->ollama->embed((string) config('assistant.emb_model'), $question);
            if (! $qEmb) {
                throw new \RuntimeException('임베딩 실패');
            }
            $scored = $this->scoreChunks($kb, $qEmb);
            $top = $this->selectTopChunks($kb, $scored, (int) config('assistant.rag_topk', 3));
            ['ctx' => $ctx, 'sources' => $sources] = $this->buildContext($kb, $top);

            $answer = $this->ollama->chat(
                (string) config('assistant.llm_model'),
                self::GUIDE_SYSTEM_PROMPT,
                "[참고자료]\n{$ctx}\n[질문]\n{$question}"
            );

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
