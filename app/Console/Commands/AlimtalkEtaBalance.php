<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ETA 잔금 완납 필요 알림톡 (erp_eta_balance_due) — 매일, 관리에게 차량 단위.
 * 조건 = 도착(eta_date) 7일 이내 & 잔금 미완납(sale_unpaid_amount_krw_cache > 0) & B/L 미발급.
 *   도착 전 마지막 100% 완납 재촉 (jin: 선적일 알림과 별개 유지).
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

            $svc = BizmAlimtalkService::active();
            $sent = 0;
            foreach ($rows as $v) {
                $daysLeft = max(0, $today->diffInDays($v->eta_date, false));
                $vars = [
                    '차량번호' => (string) $v->vehicle_number,
                    '바이어' => (string) ($v->buyer?->name ?? '-'),
                    '도착일' => optional($v->eta_date)->format('Y-m-d') ?? '-',
                    '남은일수' => (string) $daysLeft,
                    '미수금액' => number_format((int) $v->sale_unpaid_amount_krw_cache).'원',
                ];
                foreach ($recipients as $phone) {
                    $svc->send('erp_eta_balance_due', $phone, $vars, ['vehicle_id' => $v->id]);
                    $sent++;
                }
            }
            $this->info("eta-balance: {$rows->count()}대, {$sent}건 발송 시도.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:eta-balance 실패', ['error' => $e->getMessage()]);
            $this->error('eta-balance 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
