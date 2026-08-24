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
            $rows = Vehicle::query()->action('purchase_unpaid')->get();
            $count = $rows->count();
            if ($count === 0) {
                $this->info('purchase-unpaid: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            // 🎯 사람마다 **자기가 볼 수 있는 차만** 세어 보낸다 (jin 2026-08-24).
            //    영업은 본인 담당분의 건수·총액만 받는다 — 남의 차 매입 금액이 안 섞인다.
            $targets = AlimtalkRecipients::scopedFor('erp_purchase_unpaid', $rows);
            if (empty($targets)) {
                $this->info('purchase-unpaid: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            foreach ($targets as $phone => $mine) {
                $svc->send('erp_purchase_unpaid', $phone, [
                    '건수' => number_format($mine->count()),
                    '총액' => number_format((int) $mine->sum(fn (Vehicle $v) => $v->purchase_unpaid_amount)).'원',
                ]);
            }
            $this->info("purchase-unpaid: {$count}건, ".count($targets).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:purchase-unpaid 실패', ['error' => $e->getMessage()]);
            $this->error('purchase-unpaid 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
