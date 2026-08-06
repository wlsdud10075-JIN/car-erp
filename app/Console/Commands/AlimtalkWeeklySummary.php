<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 대표 주간 현황 알림톡 (erp_weekly_summary) — 매주 금 18:00.
 * 이번 주 매출 + 현재 미수(선적 전/후) + 담당자별 실적(가변 여러 줄).
 */
class AlimtalkWeeklySummary extends Command
{
    protected $signature = 'alimtalk:weekly-summary';

    protected $description = '대표 주간 현황 알림톡 — 이번 주 매출 + 미수 + 담당자별 실적.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::forBroadcast('erp_weekly_summary');
            if (empty($recipients)) {
                $this->info('weekly-summary: 수신자(대표) 없음 — skip.');

                return self::SUCCESS;
            }

            $vars = self::buildVars();
            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_weekly_summary', $phone, $vars);
            }
            $this->info('weekly-summary: '.count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:weekly-summary 실패', ['error' => $e->getMessage()]);
            $this->error('weekly-summary 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    public static function buildVars(): array
    {
        $start = now()->startOfWeek();
        $end = now()->endOfWeek();

        $saleRows = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()])
            ->with('salesman')
            ->get(['id', 'sale_price', 'currency', 'exchange_rate', 'salesman_id']);

        $krw = fn (Vehicle $v) => $v->currency === 'KRW'
            ? (int) $v->sale_price
            : (int) ((float) $v->sale_price * (float) ($v->exchange_rate ?? 0));

        $saleCount = $saleRows->count();
        $revenue = (int) $saleRows->sum($krw);

        // 담당자별 실적 (가변) — 이번 주 판매대수·매출. 담당자 없는 건은 '미지정'.
        $perSalesman = $saleRows->groupBy(fn (Vehicle $v) => $v->salesman?->name ?? '미지정')
            ->map(fn ($g) => ['cnt' => $g->count(), 'krw' => (int) $g->sum($krw)])
            ->sortByDesc('krw')
            ->map(fn ($row, $name) => "▶ {$name}: {$row['cnt']}대 · ".number_format($row['krw']).'원')
            ->implode("\n");
        if ($perSalesman === '') {
            $perSalesman = '- 이번 주 판매 없음';
        }

        $beforeQ = Vehicle::query()->action('receivable_before_shipping');
        $afterQ = Vehicle::query()->action('receivable_after_shipping');
        $beforeSum = (int) (clone $beforeQ)->sum('sale_unpaid_amount_krw_cache');
        $afterSum = (int) (clone $afterQ)->sum('sale_unpaid_amount_krw_cache');

        // 미수율 % (jin 2026-08-06) — 일일 요약과 동일 규칙. ⚠️ 분모를 선적전·선적후 합계로 통일한다
        //   (각자 분모면 더할 수 없는 숫자인데 나란히 놓으면 더해서 읽힌다 — AlimtalkDailySummary 주석 참조).
        //   본문은 정확한 원 단위, 카드는 억 단위 축약 — 카드 description 20자 컷 때문(SKILLS §8 #35).
        $denomKrw = Vehicle::aggregateSaleTotalKrw($beforeQ) + Vehicle::aggregateSaleTotalKrw($afterQ);
        $beforePct = Vehicle::aggregateUnpaidRatioPct($beforeQ, $denomKrw);
        $afterPct = Vehicle::aggregateUnpaidRatioPct($afterQ, $denomKrw);
        $pct = fn (?float $p): string => $p === null ? '' : ' ('.$p.'%)';

        return [
            '주간' => $start->format('Y-m-d').' ~ '.$end->format('m-d'),
            '판매건수' => number_format($saleCount),
            '매출액' => number_format($revenue).'원',
            '선적전건수' => number_format((clone $beforeQ)->count()),
            '선적전금액' => number_format($beforeSum).'원'.$pct($beforePct),
            '선적후건수' => number_format((clone $afterQ)->count()),
            '선적후금액' => number_format($afterSum).'원'.$pct($afterPct),
            '담당자실적' => $perSalesman,
            AlimtalkTemplates::CARD_VARS_KEY => [
                '선적전금액' => AlimtalkTemplates::cardMoney($beforeSum).$pct($beforePct),
                '선적후금액' => AlimtalkTemplates::cardMoney($afterSum).$pct($afterPct),
            ],
        ];
    }
}
