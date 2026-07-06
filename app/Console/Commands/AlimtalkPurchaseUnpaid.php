<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 매입 미지급 안내 알림톡 (erp_purchase_unpaid) — 매일 아침, 관리에게 요약 1건.
 * 대상 = scopeAction('purchase_unpaid') 단일출처(payment_date 도래 & 확정 PBP 기준 미지급).
 */
class AlimtalkPurchaseUnpaid extends Command
{
    protected $signature = 'alimtalk:purchase-unpaid';

    protected $description = '매입 미지급(지급일 도래) 요약 알림톡 — 관리.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::managers();
            if (empty($recipients)) {
                $this->info('purchase-unpaid: 수신자(관리) 없음 — skip.');

                return self::SUCCESS;
            }

            $rows = Vehicle::query()->action('purchase_unpaid')->get();
            $count = $rows->count();
            if ($count === 0) {
                $this->info('purchase-unpaid: 대상 0건 — skip.');

                return self::SUCCESS;
            }
            $total = (int) $rows->sum(fn (Vehicle $v) => $v->purchase_unpaid_amount);

            $vars = ['건수' => number_format($count), '총액' => number_format($total).'원'];

            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_purchase_unpaid', $phone, $vars);
            }
            $this->info("purchase-unpaid: {$count}건, ".count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:purchase-unpaid 실패', ['error' => $e->getMessage()]);
            $this->error('purchase-unpaid 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
