<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 판매 미입금 안내 알림톡 (erp_sale_unpaid) — 매일 아침, 차량 단위 → 관리.
 * 대상 = scopeAction('sale_unpaid') 단일출처(= grace 10일 유예 제외 이미 반영).
 * 차량마다 관리 전원에게 발송(요약은 일일요약이 별도 담당).
 */
class AlimtalkSaleUnpaid extends Command
{
    protected $signature = 'alimtalk:sale-unpaid';

    protected $description = '판매 미입금 차량 안내 알림톡(차량 단위) — 관리.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::managers();
            if (empty($recipients)) {
                $this->info('sale-unpaid: 수신자(관리) 없음 — skip.');

                return self::SUCCESS;
            }

            $rows = Vehicle::query()->action('sale_unpaid')->with('buyer')->get();
            if ($rows->isEmpty()) {
                $this->info('sale-unpaid: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            $sent = 0;
            foreach ($rows as $v) {
                $vars = [
                    '차량번호' => (string) $v->vehicle_number,
                    '바이어' => (string) ($v->buyer?->name ?? '-'),
                    '미수금액' => number_format((int) $v->sale_unpaid_amount_krw_cache).'원',
                ];
                foreach ($recipients as $phone) {
                    $svc->send('erp_sale_unpaid', $phone, $vars, ['vehicle_id' => $v->id]);
                    $sent++;
                }
            }
            $this->info("sale-unpaid: {$rows->count()}대 × ".count($recipients)."명 = {$sent}건 발송 시도.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:sale-unpaid 실패', ['error' => $e->getMessage()]);
            $this->error('sale-unpaid 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
