<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ETA 잔금 완납 필요 알림톡 (erp_eta_balance_due) — 매일, 관리에게 목록형 1건.
 * 조건 = 도착(eta_date) 7일 이내 & 잔금 미완납(sale_unpaid_amount_krw_cache > 0) & B/L 미발급.
 *   도착 전 마지막 100% 완납 재촉(선적일 알림과 별개 유지). 차량 여러 대 한 통에, 상세는 채권관리.
 */
class AlimtalkEtaBalance extends Command
{
    protected $signature = 'alimtalk:eta-balance';

    protected $description = 'ETA(도착) 7일 전 잔금 미완납 차량 알림톡 — 관리.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::managers();
            if (empty($recipients)) {
                $this->info('eta-balance: 수신자(관리) 없음 — skip.');

                return self::SUCCESS;
            }

            $today = now()->startOfDay();
            $rows = Vehicle::query()
                ->where('sales_channel', 'export')
                ->whereNotNull('eta_date')
                ->whereBetween('eta_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->whereNull('bl_document')
                ->with('buyer')
                ->get();

            if ($rows->isEmpty()) {
                $this->info('eta-balance: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $cap = 20;   // 알림톡 본문 1000자 제한 — 목록 상한. 초과분은 "외 N건", 상세는 채권관리.
            $lines = $rows->take($cap)->map(function (Vehicle $v) use ($today) {
                $daysLeft = max(0, $today->diffInDays($v->eta_date, false));

                return sprintf(
                    '▶ %s · %s · 도착 %s(D-%d) · %s원',
                    $v->vehicle_number,
                    $v->buyer?->name ?? '-',
                    optional($v->eta_date)->format('Y-m-d') ?? '-',
                    $daysLeft,
                    number_format((int) $v->sale_unpaid_amount_krw_cache)
                );
            })->implode("\n");
            if ($rows->count() > $cap) {
                $lines .= "\n▶ 외 ".($rows->count() - $cap).'건';
            }

            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_eta_balance_due', $phone, ['잔금목록' => $lines]);
            }
            $this->info("eta-balance: {$rows->count()}대 목록 → ".count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:eta-balance 실패', ['error' => $e->getMessage()]);
            $this->error('eta-balance 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
