<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 대표 채권 현황 알림톡 (erp_receivable_status) — 매일 09:00. jin 2026-08-20 신설.
 *
 * 일일요약에서 **돈 이야기를 떼어낸** 템플릿. 설계 핵심 = **분모가 하나다**.
 *   미수로 잡힌 차(잔금 남은 차 전부)의 판매금액을 100 으로 놓고 유예·선적전·선적후·입금액을 쪼갠다
 *   ⇒ 네 줄을 그냥 더하면 100% 가 된다(반올림 ±0.1). 모수가 섞여 오독되는 문제가 원천적으로 안 생긴다.
 *
 * ⚠️ 총 미수금 = 선적전 + 선적후 (유예 제외 — 2026-07-06 "결제대기는 아직 채권 아님").
 *    유예는 같은 분모를 쓰되 미수 합계엔 안 들어간다 — "아직 기다리는 돈" 으로 내용만 알린다(jin).
 * ⚠️ 기간 필터 없음 — 미수는 재고라 기간으로 자르지 않는다. 채권관리·관리자 대시보드와 같은 기준.
 */
class AlimtalkReceivableStatus extends Command
{
    protected $signature = 'alimtalk:receivable-status';

    protected $description = '대표 채권 현황 알림톡 — 미수 차량의 판매금액 대비 유예·선적전·선적후·입금액.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::forBroadcast('erp_receivable_status');
            if (empty($recipients)) {
                $this->info('receivable-status: 수신자(대표) 없음 — skip.');

                return self::SUCCESS;
            }

            $vars = self::buildVars();
            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_receivable_status', $phone, $vars);
            }
            $this->info('receivable-status: '.count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:receivable-status 실패', ['error' => $e->getMessage()]);
            $this->error('receivable-status 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * 채권 % 의 **공통 분모** — 미수로 잡힌 차(잔금 남은 차 전부, 유예 포함)의 판매총액 KRW.
     *
     * ⚠️ 주간요약(`AlimtalkWeeklySummary`)도 이 분모를 쓴다. 각자 계산하면 금요일 % 와 매일 % 가
     *    갈려서, 같은 항목인데 알림톡마다 다른 숫자가 찍힌다(SKILLS §8 #45 — 공식 복제).
     * ℹ️ 환율 미입력 외화는 캐시가 null 이라 애초에 이 집합에 안 들어온다(분자와 같은 기준).
     *
     * @return array{0:int,1:int} [판매총액, 대상 대수]
     */
    public static function shareBase(): array
    {
        $rows = Vehicle::query()->where('sale_price', '>', 0)
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->get(['sale_price', 'transport_fee', 'sale_other_costs', 'commission', 'auto_loading',
                'tax_dc', 'currency', 'exchange_rate']);

        return [
            (int) round($rows->sum(fn ($v) => $v->currency === 'KRW'
                ? $v->sale_total_amount
                : $v->sale_total_amount * ($v->exchange_rate ?: 0))),
            $rows->count(),
        ];
    }

    /** 공통 분모 기준 % 표기 — ' (12.3%)' 또는 ''(분모 0). 주간요약과 공유. */
    public static function sharePct(int $part, int $denominator): string
    {
        return $denominator > 0 ? ' ('.round($part / $denominator * 100, 1).'%)' : '';
    }

    /** 템플릿 변수 산정(테스트 재사용). 전부 현재 시점 잔액 — 기간 개념 없음. */
    public static function buildVars(): array
    {
        [$totalSale, $targetCount] = self::shareBase();

        $sum = fn ($q) => (int) $q->sum('sale_unpaid_amount_krw_cache');
        $graceQ = Vehicle::query()->where('sale_unpaid_amount_krw_cache', '>', 0)->onlyReceivableGrace();
        $beforeQ = Vehicle::query()->action('receivable_before_shipping');
        $afterQ = Vehicle::query()->action('receivable_after_shipping');

        $graceSum = $sum(clone $graceQ);
        $beforeSum = $sum(clone $beforeQ);
        $afterSum = $sum(clone $afterQ);
        $unpaid = $beforeSum + $afterSum;              // 총 미수금 (유예 제외)
        $paid = $totalSale - $unpaid - $graceSum;      // 실제 들어온 돈

        // % 는 **전부 같은 분모**($totalSale). 그래서 네 값을 더하면 100 이 된다.
        //   소수 1자리 유지 — 정수로 반올림하면 합이 100 에서 더 크게 어긋난다.
        $pct = fn (int $part): string => self::sharePct($part, $totalSale);
        $n = fn (int $v): string => number_format($v);

        return [
            '날짜' => now()->format('Y-m-d'),
            '대상대수' => $n($targetCount),
            '총판매금액' => $n($totalSale).'원',
            '유예건수' => $n((clone $graceQ)->count()),
            '유예금액' => $n($graceSum).'원'.$pct($graceSum),
            '선적전건수' => $n((clone $beforeQ)->count()),
            '선적전금액' => $n($beforeSum).'원'.$pct($beforeSum),
            '선적후건수' => $n((clone $afterQ)->count()),
            '선적후금액' => $n($afterSum).'원'.$pct($afterSum),
            '입금액' => $n(max(0, $paid)).'원'.$pct(max(0, $paid)),
            // 🚫 요약칸은 금액 표기만 — % 를 넣으면 K140 으로 발송 반려된다(SKILLS §8 #40).
            '미수합계' => $n($unpaid).'원',
            // 카드는 description 20자 컷이라 억 단위 축약(SKILLS §8 #35). 본문은 원 단위 그대로.
            AlimtalkTemplates::CARD_VARS_KEY => [
                '총판매금액' => AlimtalkTemplates::cardMoney($totalSale),
                '유예금액' => AlimtalkTemplates::cardMoney($graceSum).$pct($graceSum),
                '선적전금액' => AlimtalkTemplates::cardMoney($beforeSum).$pct($beforeSum),
                '선적후금액' => AlimtalkTemplates::cardMoney($afterSum).$pct($afterSum),
                '입금액' => AlimtalkTemplates::cardMoney(max(0, $paid)).$pct(max(0, $paid)),
                '미수합계' => $n($unpaid).'원',
            ],
        ];
    }
}
