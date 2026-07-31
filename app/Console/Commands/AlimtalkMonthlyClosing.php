<?php

namespace App\Console\Commands;

use App\Models\AlimtalkLog;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\SettlementCkBatch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 대표 월 결산 알림톡 (erp_monthly_closing) — 전월(귀속월) 결산.
 *
 * 🚨 발송 시점 = **월배치 정산이 최종 승인된 때** (jin 2026-07-31).
 *   종전엔 익월 첫 영업일에 무조건 나갔는데, 그 시점엔 전월 정산이 아직 확정 전이라
 *   총마진·지급총액·회사이익이 통째로 과소보고됐다("정산 전부 완료되기 전에는 나갈 수 없다" — jin).
 *   이제 스케줄 추측 대신 **확정 신호**(SettlementPayoutBatch::isMonthClosed)를 쓴다.
 *
 * 트리거 2경로 (둘 다 같은 게이트를 통과해야 발송):
 *   ① 배치 최종 승인 직후 (SettlementPayoutBatch::approveBy) — 빠른 경로
 *   ② 매일 09:00 스케줄 — ①이 실패했거나 마감이 늦어진 달을 따라잡는 재시도
 *
 * 집계 앵커 = **attributed_month**(완납월) — 배치가 정산을 고를 때 쓰는 것과 동일하다.
 *   confirmed_at(확정일)을 쓰면 "마감됐다"고 판정한 집합과 실제로 더하는 집합이 달라진다.
 */
class AlimtalkMonthlyClosing extends Command
{
    protected $signature = 'alimtalk:monthly-closing {month? : 귀속월 YYYY-MM (기본 = 지난달)}';

    protected $description = '대표 월 결산 알림톡 — 월배치 정산이 최종 승인된 달만 발송.';

    /** 이 달 결산을 이미 보냈는지 기록하는 Setting 키. */
    public static function sentKey(string $month): string
    {
        return "alimtalk_monthly_closing_sent_{$month}_".Setting::companyTemplateSet();
    }

    /** 마감이 이만큼 늦어지면 조용히 기다리지 말고 에러로 올린다(익월 15일). */
    private const ESCALATE_DAY = 15;

    public function handle(): int
    {
        try {
            $month = (string) ($this->argument('month') ?: now()->subMonthNoOverflow()->format('Y-m'));

            $recipients = AlimtalkRecipients::forBroadcast('erp_monthly_closing');
            if (empty($recipients)) {
                $this->info('monthly-closing: 수신자(대표) 없음 — skip.');

                return self::SUCCESS;
            }

            if (Setting::get(self::sentKey($month))) {
                $this->info("monthly-closing: {$month} 은 이미 발송함 — skip.");

                return self::SUCCESS;
            }

            if ($reason = $this->blockedReason($month)) {
                // 왜 안 나갔는지 로그 화면에 남긴다 — laravel.log 에만 남기면 아무도 모른다(SKILLS §8 #38).
                foreach ($recipients as $phone) {
                    AlimtalkLog::create([
                        'template_code' => 'erp_monthly_closing',
                        'phone' => preg_replace('/[^0-9]/', '', $phone),
                        'status' => 'skipped',
                        'error' => $reason,
                    ]);
                }
                $this->info("monthly-closing: {$month} — {$reason}");

                return self::SUCCESS;
            }

            $vars = self::buildVars($month);
            $svc = BizmAlimtalkService::active();
            $sent = 0;
            foreach ($recipients as $phone) {
                $log = $svc->send('erp_monthly_closing', $phone, $vars);
                $sent += $log->status === 'sent' ? 1 : 0;
            }

            // ⚠️ 발송에 성공했을 때만 도장 — 실패(미설정·게이트 off)면 다음 날 다시 시도한다.
            if ($sent > 0) {
                Setting::updateOrCreate(['key' => self::sentKey($month)], ['value' => now()->toDateTimeString(), 'type' => 'string']);
            }
            $this->info("monthly-closing: {$month} — ".count($recipients).'명 발송 시도, 성공 '.$sent.'건.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:monthly-closing 실패', ['error' => $e->getMessage()]);
            $this->error('monthly-closing 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * 발송을 막는 사유(한글) — 없으면 null.
     *
     * 마감 전 발송이 이 기능의 원래 사고였으므로 게이트는 여기 하나로 모은다.
     */
    private function blockedReason(string $month): ?string
    {
        if (! SettlementPayoutBatch::isMonthClosed($month)) {
            // 마냥 기다리다 영영 안 나가는 걸 막는다 — 늦어지면 사람이 알아채게 올린다.
            if (now()->day >= self::ESCALATE_DAY && now()->format('Y-m') !== $month) {
                Log::error('alimtalk:monthly-closing — 월배치 정산이 아직 마감되지 않아 결산 보고가 지연되고 있습니다.', [
                    'month' => $month,
                ]);
            }

            return "{$month} 월배치 정산이 아직 최종 승인되지 않아 대기 중";
        }

        if (self::settlementsFor($month)->isEmpty()) {
            // 정산이 0건이면 "전부 확정"이 자동으로 참이 되어 마진 0원짜리 보고가 나간다.
            return "{$month} 귀속 정산이 없어 보고할 결산이 없음";
        }

        return null;
    }

    /**
     * 그 달 귀속 정산 — 배치가 쓰는 앵커(attributed_month, NULL 이면 confirmed_at fallback)와 동일.
     *
     * @return Collection<int, Settlement>
     */
    public static function settlementsFor(string $month): Collection
    {
        [$start, $end] = SettlementCkBatch::monthRange($month);
        $monthStart = $month.'-01';

        return Settlement::query()
            ->whereIn('settlement_status', ['confirmed', 'paid'])
            ->where(function ($q) use ($monthStart, $start, $end) {
                $q->whereBetween('attributed_month', [$monthStart.' 00:00:00', $monthStart.' 23:59:59'])
                    ->orWhere(function ($q2) use ($start, $end) {
                        // ⚠️ SettlementPayoutBatch::submitForMonth 과 **글자 그대로 같은 fallback** 이어야 한다.
                        //    다르면 "마감됐다"고 판정한 집합과 실제로 더하는 집합이 어긋난다.
                        $q2->whereNull('attributed_month')
                            ->whereRaw('COALESCE(confirmed_at, created_at) >= ?', [$start])
                            ->whereRaw('COALESCE(confirmed_at, created_at) < ?', [$end]);
                    });
            })
            ->with('salesman')
            ->get();
    }

    /** @param  string|null  $month  귀속월 YYYY-MM (기본 = 지난달) */
    public static function buildVars(?string $month = null): array
    {
        $month ??= now()->subMonthNoOverflow()->format('Y-m');
        $anchor = Carbon::parse($month.'-01');
        $start = $anchor->copy()->startOfMonth();
        $end = $anchor->copy()->endOfMonth();

        // 매출은 판매일 기준 달력월 — 정산 귀속월(완납월)과 축이 다르지만, 대표가 보는 "그 달 매출"의 정의다.
        $saleRows = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()])
            ->get(['sale_price', 'currency', 'exchange_rate']);
        $revenue = (int) $saleRows->sum(fn (Vehicle $v) => $v->currency === 'KRW'
            ? (int) $v->sale_price
            : (int) ((float) $v->sale_price * (float) ($v->exchange_rate ?? 0)));

        $settlements = self::settlementsFor($month);

        $totalMargin = (int) $settlements->sum(fn (Settlement $s) => (int) $s->total_margin);
        $totalPayout = (int) $settlements->sum(fn (Settlement $s) => (int) $s->actual_payout);
        // 회사이익(회사순이익) = 총마진 − 실지급 + 환차. 관리자 대시보드 companyProfit 과 동일 공식(같은 정산셋 기준).
        $fxSum = (int) $settlements->sum(fn (Settlement $s) => (int) ($s->exchange_difference_krw ?? 0));
        $companyProfit = $totalMargin - $totalPayout + $fxSum;

        $perSalesman = $settlements->groupBy(fn (Settlement $s) => $s->salesman?->name ?? '미지정')
            ->map(fn ($g) => (int) $g->sum(fn (Settlement $s) => (int) $s->actual_payout))
            ->sortDesc()
            ->map(fn ($amt, $name) => "▶ {$name}: ".number_format($amt).'원')
            ->implode("\n");
        if ($perSalesman === '') {
            $perSalesman = '- 전월 정산 없음';
        }

        return [
            '대상월' => $anchor->format('Y년 n월').'분',
            '총매출' => number_format($revenue).'원',
            '총마진' => number_format($totalMargin).'원',
            '지급총액' => number_format($totalPayout).'원',
            '회사이익' => number_format($companyProfit).'원',
            '인원별지급' => $perSalesman,
        ];
    }
}
