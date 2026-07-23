<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
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

        return [
            '주간' => $start->format('Y-m-d').' ~ '.$end->format('m-d'),
            '판매건수' => number_format($saleCount),
            '매출액' => number_format($revenue).'원',
            '선적전건수' => number_format((clone $beforeQ)->count()),
            '선적전금액' => number_format((int) (clone $beforeQ)->sum('sale_unpaid_amount_krw_cache')).'원',
            '선적후건수' => number_format((clone $afterQ)->count()),
            '선적후금액' => number_format((int) (clone $afterQ)->sum('sale_unpaid_amount_krw_cache')).'원',
            '담당자실적' => $perSalesman,
        ];
    }
}
