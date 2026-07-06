<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 대표 월 결산 알림톡 (erp_monthly_closing) — 매월 10일, 전월(귀속월) 결산.
 * 전월 매출 + 전월 정산(총마진·실지급) + 인원별 지급(가변).
 *   전월 정산 = settlement_status ∈ {confirmed, paid} & confirmed_at 이 전월. 마진·지급은 computed accessor 합.
 *   (귀속월 앵커는 급여배치 confirmed_at+10일과 근사 — 상세 project_settlement_payroll_batch.)
 */
class AlimtalkMonthlyClosing extends Command
{
    protected $signature = 'alimtalk:monthly-closing';

    protected $description = '대표 월 결산 알림톡(전월) — 매출·마진·정산 지급.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::admins();
            if (empty($recipients)) {
                $this->info('monthly-closing: 수신자(대표) 없음 — skip.');

                return self::SUCCESS;
            }

            $vars = self::buildVars();
            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_monthly_closing', $phone, $vars);
            }
            $this->info('monthly-closing: '.count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:monthly-closing 실패', ['error' => $e->getMessage()]);
            $this->error('monthly-closing 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    public static function buildVars(): array
    {
        $prev = now()->subMonthNoOverflow();
        $start = $prev->copy()->startOfMonth();
        $end = $prev->copy()->endOfMonth();

        // 전월 매출 (KRW)
        $saleRows = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()])
            ->get(['sale_price', 'currency', 'exchange_rate']);
        $revenue = (int) $saleRows->sum(fn (Vehicle $v) => $v->currency === 'KRW'
            ? (int) $v->sale_price
            : (int) ((float) $v->sale_price * (float) ($v->exchange_rate ?? 0)));

        // 전월 정산 (confirmed/paid, confirmed_at 전월) — 마진·지급은 accessor 합.
        $settlements = Settlement::query()
            ->whereIn('settlement_status', ['confirmed', 'paid'])
            ->whereBetween('confirmed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->with('salesman')
            ->get();

        $totalMargin = (int) $settlements->sum(fn (Settlement $s) => (int) $s->total_margin);
        $totalPayout = (int) $settlements->sum(fn (Settlement $s) => (int) $s->actual_payout);

        $perSalesman = $settlements->groupBy(fn (Settlement $s) => $s->salesman?->name ?? '미지정')
            ->map(fn ($g) => (int) $g->sum(fn (Settlement $s) => (int) $s->actual_payout))
            ->sortDesc()
            ->map(fn ($amt, $name) => "▶ {$name}: ".number_format($amt).'원')
            ->implode("\n");
        if ($perSalesman === '') {
            $perSalesman = '- 전월 정산 없음';
        }

        return [
            '대상월' => $prev->format('Y년 n월').'분',
            '총매출' => number_format($revenue).'원',
            '총마진' => number_format($totalMargin).'원',
            '지급총액' => number_format($totalPayout).'원',
            '인원별지급' => $perSalesman,
        ];
    }
}
